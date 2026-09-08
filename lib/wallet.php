<?php
declare(strict_types=1);

function wallet_credit(string $uid, float $amount, string $note, string $ref = ''): array
{
    $user = store_update('users', $uid, function ($u) use ($amount) {
        $u['wallet'] = round((float)($u['wallet'] ?? 0) + $amount, 2);
        return $u;
    });
    $tx = [
        'id' => xp_id('w_'),
        'user_id' => $uid,
        'type' => 'credit',
        'amount' => $amount,
        'note' => $note,
        'ref' => $ref,
        'balance' => $user['wallet'] ?? 0,
        'created_at' => xp_now(),
    ];
    store_push('wallet', $tx);
    return $tx;
}

function wallet_debit(string $uid, float $amount, string $note, string $ref = ''): array
{
    $user = store_find('users', fn($u) => $u['id'] === $uid);
    if (!$user || (float)$user['wallet'] < $amount) {
        return ['ok' => false, 'error' => 'ওয়ালেট ব্যালেন্স অপর্যাপ্ত'];
    }
    $updated = store_update('users', $uid, function ($u) use ($amount) {
        $u['wallet'] = round((float)$u['wallet'] - $amount, 2);
        return $u;
    });
    $tx = [
        'id' => xp_id('w_'),
        'user_id' => $uid,
        'type' => 'debit',
        'amount' => $amount,
        'note' => $note,
        'ref' => $ref,
        'balance' => $updated['wallet'] ?? 0,
        'created_at' => xp_now(),
        'ok' => true,
    ];
    store_push('wallet', $tx);
    return $tx;
}
