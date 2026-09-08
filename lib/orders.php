<?php
declare(strict_types=1);

function offer_by_id(string $id): ?array
{
    return store_find('offers', fn($o) => $o['id'] === $id);
}

function order_create(array $user, array $payload): array
{
    $type = $payload['type'] ?? 'offer';
    $number = preg_replace('/\D+/', '', $payload['number'] ?? '');
    if (!preg_match('/^01[3-9]\d{8}$/', $number)) {
        return ['ok' => false, 'error' => 'প্রাপকের নম্বর সঠিক নয়'];
    }
    $method = $payload['method'] ?? 'wallet';
    $offer = null;
    $title = 'রিচার্জ';
    $operator = $payload['operator'] ?? '';
    $amount = (float)($payload['amount'] ?? 0);
    $price = $amount;

    if ($type === 'offer') {
        $offer = offer_by_id($payload['offer_id'] ?? '');
        if (!$offer || empty($offer['active'])) {
            return ['ok' => false, 'error' => 'অফার পাওয়া যায়নি'];
        }
        $title = $offer['title'];
        $operator = $offer['operator'];
        $amount = (float)$offer['face_value'];
        $price = (float)$offer['price'];
        if (($offer['stock'] ?? 0) <= 0 && ($offer['stock'] ?? 0) !== -1) {
            return ['ok' => false, 'error' => 'স্টক শেষ'];
        }
    } else {
        if ($amount < 10) {
            return ['ok' => false, 'error' => 'সর্বনিম্ন রিচার্জ ১০ টাকা'];
        }
        $ops = xp_read('operators', []);
        $op = null;
        foreach ($ops as $o) {
            if ($o['code'] === $operator) {
                $op = $o;
                break;
            }
        }
        if (!$op) {
            return ['ok' => false, 'error' => 'অপারেটর বেছে নিন'];
        }
        $rate = (float)($op['recharge_rate'] ?? 100);
        $price = round($amount * $rate / 100, 2);
        $title = $op['name'] . ' রিচার্জ ৳' . $amount;
    }

    $order = [
        'id' => xp_id('ord_'),
        'user_id' => $user['id'],
        'type' => $type,
        'offer_id' => $offer['id'] ?? null,
        'title' => $title,
        'operator' => $operator,
        'number' => $number,
        'face_value' => $amount,
        'price' => $price,
        'status' => 'awaiting_payment',
        'note' => trim((string)($payload['note'] ?? '')),
        'created_at' => xp_now(),
        'processed_at' => null,
    ];
    store_push('orders', $order);

    $inv = gw_invoice($user, $price, $method, 'order', ['order_id' => $order['id']]);
    if (empty($inv['ok'])) {
        store_update('orders', $order['id'], function ($o) {
            $o['status'] = 'cancelled';
            return $o;
        });
        return $inv;
    }
    $order = store_update('orders', $order['id'], function ($o) use ($inv) {
        $o['invoice_id'] = $inv['invoice']['id'];
        if ($inv['invoice']['status'] === 'paid') {
            $o['status'] = 'processing';
        }
        return $o;
    });
    if ($order['status'] === 'processing') {
        order_auto_process($order);
        $order = store_find('orders', fn($x) => $x['id'] === $order['id']) ?: $order;
    }
    return ['ok' => true, 'order' => $order, 'invoice' => $inv['invoice']];
}

function order_mark_paid(string $orderId): void
{
    $order = store_update('orders', $orderId, function ($o) {
        if ($o['status'] === 'awaiting_payment') {
            $o['status'] = 'processing';
        }
        return $o;
    });
    if ($order) {
        order_auto_process($order);
    }
}

function order_auto_process(array $order): void
{
    $settings = xp_settings();
    if (empty($settings['auto_process_orders'])) {
        return;
    }
    if (($order['status'] ?? '') !== 'processing') {
        return;
    }
    if (!empty($order['offer_id'])) {
        store_update('offers', $order['offer_id'], function ($of) {
            if (($of['stock'] ?? 0) > 0) {
                $of['stock'] = (int)$of['stock'] - 1;
            }
            $of['sold'] = (int)($of['sold'] ?? 0) + 1;
            return $of;
        });
    }
    store_update('orders', $order['id'], function ($o) {
        $o['status'] = 'completed';
        $o['processed_at'] = xp_now();
        $o['auto'] = true;
        return $o;
    });
}

function order_admin_set(string $id, string $status, string $note = ''): ?array
{
    return store_update('orders', $id, function ($o) use ($status, $note) {
        $o['status'] = $status;
        $o['admin_note'] = $note;
        if (in_array($status, ['completed', 'failed', 'cancelled'], true)) {
            $o['processed_at'] = xp_now();
        }
        return $o;
    });
}
