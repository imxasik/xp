<?php
declare(strict_types=1);

/**
 * Custom XPPay gateway — no third party.
 * Auto rules:
 *  - Unique invoice + paycode
 *  - Wallet instant
 *  - External (bKash/Nagad/...) : trx id uniqueness + amount match + optional auto-approve if trx format valid
 *  - Merchant callback endpoint /api/gateway.php?action=confirm (admin secret)
 */

function gw_methods(): array
{
    return array_values(array_filter(xp_read('gateways', []), fn($g) => !empty($g['enabled'])));
}

function gw_invoice(array $user, float $amount, string $method, string $purpose, array $meta = []): array
{
    $gateways = xp_read('gateways', []);
    $gw = null;
    foreach ($gateways as $g) {
        if ($g['code'] === $method) {
            $gw = $g;
            break;
        }
    }
    if (!$gw || empty($gw['enabled'])) {
        return ['ok' => false, 'error' => 'পেমেন্ট মাধ্যম পাওয়া যায়নি'];
    }
    if ($amount < 1) {
        return ['ok' => false, 'error' => 'সর্বনিম্ন ১ টাকা'];
    }
    $paycode = strtoupper(substr($method, 0, 2)) . random_int(100000, 999999);
    $inv = [
        'id' => xp_id('inv_'),
        'user_id' => $user['id'],
        'method' => $method,
        'amount' => round($amount, 2),
        'purpose' => $purpose,
        'meta' => $meta,
        'paycode' => $paycode,
        'merchant' => $gw['merchant'] ?? '',
        'instructions' => $gw['instructions'] ?? '',
        'status' => $method === 'wallet' ? 'paid' : 'pending',
        'trx_id' => '',
        'sender' => '',
        'created_at' => xp_now(),
        'paid_at' => $method === 'wallet' ? xp_now() : null,
    ];
    if ($method === 'wallet') {
        $deb = wallet_debit($user['id'], $amount, $purpose, $inv['id']);
        if (empty($deb['ok'])) {
            return $deb;
        }
    }
    store_push('invoices', $inv);
    return ['ok' => true, 'invoice' => $inv];
}

function gw_submit_trx(string $invoiceId, string $trx, string $sender, array $user): array
{
    $trx = strtoupper(trim($trx));
    $sender = preg_replace('/\D+/', '', $sender);
    if (strlen($trx) < 6) {
        return ['ok' => false, 'error' => 'ট্রানজেকশন আইডি সঠিক নয়'];
    }
    $dup = store_find('invoices', fn($i) => strtoupper($i['trx_id'] ?? '') === $trx && $i['status'] === 'paid');
    if ($dup) {
        return ['ok' => false, 'error' => 'এই ট্রানজেকশন আগে ব্যবহার হয়েছে'];
    }
    $inv = store_find('invoices', fn($i) => $i['id'] === $invoiceId && $i['user_id'] === $user['id']);
    if (!$inv) {
        return ['ok' => false, 'error' => 'ইনভয়েস পাওয়া যায়নি'];
    }
    if ($inv['status'] === 'paid') {
        return ['ok' => true, 'invoice' => $inv];
    }
    $settings = xp_settings();
    $auto = !empty($settings['auto_approve_payments']);
    $validFmt = (bool)preg_match('/^[A-Z0-9]{8,20}$/', $trx);
    $status = ($auto && $validFmt) ? 'paid' : 'review';
    $updated = store_update('invoices', $invoiceId, function ($i) use ($trx, $sender, $status) {
        $i['trx_id'] = $trx;
        $i['sender'] = $sender;
        $i['status'] = $status;
        if ($status === 'paid') {
            $i['paid_at'] = xp_now();
        }
        return $i;
    });
    if ($status === 'paid') {
        gw_fulfill($updated);
    }
    return ['ok' => true, 'invoice' => $updated];
}

function gw_admin_confirm(string $invoiceId, bool $approve, string $note = ''): array
{
    $inv = store_find('invoices', fn($i) => $i['id'] === $invoiceId);
    if (!$inv) {
        return ['ok' => false, 'error' => 'ইনভয়েস নেই'];
    }
    $updated = store_update('invoices', $invoiceId, function ($i) use ($approve, $note) {
        $i['status'] = $approve ? 'paid' : 'rejected';
        $i['admin_note'] = $note;
        $i['paid_at'] = $approve ? xp_now() : $i['paid_at'];
        return $i;
    });
    if ($approve) {
        gw_fulfill($updated);
    }
    return ['ok' => true, 'invoice' => $updated];
}

function gw_fulfill(array $inv): void
{
    if (($inv['purpose'] ?? '') === 'wallet_topup') {
        wallet_credit($inv['user_id'], (float)$inv['amount'], 'ওয়ালেট রিচার্জ', $inv['id']);
        return;
    }
    if (($inv['purpose'] ?? '') === 'order' && !empty($inv['meta']['order_id'])) {
        order_mark_paid($inv['meta']['order_id']);
    }
}

function gw_secret_confirm(string $secret, string $trx, float $amount, string $paycode): array
{
    $settings = xp_settings();
    if (!$secret || $secret !== ($settings['gateway_secret'] ?? '')) {
        return ['ok' => false, 'error' => 'অননুমোদিত'];
    }
    $inv = store_find('invoices', fn($i) => $i['paycode'] === $paycode && abs((float)$i['amount'] - $amount) < 0.01 && $i['status'] !== 'paid');
    if (!$inv) {
        return ['ok' => false, 'error' => 'মিল পাওয়া যায়নি'];
    }
    $updated = store_update('invoices', $inv['id'], function ($i) use ($trx) {
        $i['trx_id'] = strtoupper($trx);
        $i['status'] = 'paid';
        $i['paid_at'] = xp_now();
        $i['auto'] = true;
        return $i;
    });
    gw_fulfill($updated);
    return ['ok' => true, 'invoice' => $updated];
}
