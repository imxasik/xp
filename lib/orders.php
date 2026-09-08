<?php
declare(strict_types=1);
require_once __DIR__ . '/notify.php';

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
    $svc = service_status();
    $order['manual'] = service_applies($order, $offer);
    $order['service_state'] = $svc['state'];
    $order['category'] = $offer['category'] ?? 'recharge';
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
    order_notify_created($order, $user);
    return ['ok' => true, 'order' => $order, 'invoice' => $inv['invoice'], 'service' => $svc];
}

function order_notify_created(array $order, array $user): void
{
    $paid = in_array($order['status'], ['processing', 'completed'], true);
    notify_admin('order', 'নতুন অর্ডার: ' . $order['title'],
        $order['number'] . ' · ৳' . $order['price'] . ' · ' . ($user['name'] ?? '') . ' (' . ($user['phone'] ?? '') . ')' . ($paid ? ' · পেইড' : ' · পেমেন্ট বাকি'),
        ['order_id' => $order['id']]);
    if ($order['status'] === 'completed') {
        notify_user($user['id'], 'order', 'অর্ডার সম্পন্ন ✅', $order['title'] . ' → ' . $order['number'], ['order_id' => $order['id']]);
    } elseif ($order['status'] === 'processing') {
        $svc = service_status();
        $msg = $order['title'] . ' → ' . $order['number'] . '। ' . ($svc['message'] ?? '');
        notify_user($user['id'], 'order', 'অর্ডার গৃহীত, প্রসেসিং চলছে ⏳', $msg, ['order_id' => $order['id']]);
    } else {
        notify_user($user['id'], 'payment', 'পেমেন্ট বাকি', $order['title'] . ' — পেমেন্ট করে TrxID জমা দিন।', ['order_id' => $order['id']]);
    }
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
        $fresh = store_find('orders', fn($x) => $x['id'] === $orderId) ?: $order;
        notify_admin('payment', 'পেমেন্ট কনফার্মড: ' . $fresh['title'], $fresh['number'] . ' · ৳' . $fresh['price'] . ' — এখন অফার হিট করুন', ['order_id' => $orderId]);
        if ($fresh['status'] === 'completed') {
            notify_user($fresh['user_id'], 'order', 'অর্ডার সম্পন্ন ✅', $fresh['title'] . ' → ' . $fresh['number'], ['order_id' => $orderId]);
        } else {
            $svc = service_status();
            notify_user($fresh['user_id'], 'order', 'পেমেন্ট পেয়েছি, প্রসেসিং চলছে ⏳', $fresh['title'] . ' → ' . $fresh['number'] . '। ' . ($svc['message'] ?? ''), ['order_id' => $orderId]);
        }
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
    // Manual (drive/house-hit) orders wait for admin — never auto-complete.
    if (!empty($order['manual'])) {
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
        $o['stock_counted'] = true;
        return $o;
    });
}

function order_admin_set(string $id, string $status, string $note = ''): ?array
{
    $row = store_update('orders', $id, function ($o) use ($status, $note) {
        $o['status'] = $status;
        $o['admin_note'] = $note;
        if (in_array($status, ['completed', 'failed', 'cancelled'], true)) {
            $o['processed_at'] = xp_now();
        }
        return $o;
    });
    if ($row) {
        if ($status === 'completed') {
            if (!empty($row['offer_id']) && empty($row['stock_counted'])) {
                store_update('offers', $row['offer_id'], function ($of) {
                    if (($of['stock'] ?? 0) > 0) $of['stock'] = (int)$of['stock'] - 1;
                    $of['sold'] = (int)($of['sold'] ?? 0) + 1;
                    return $of;
                });
                $row = store_update('orders', $id, function ($o) { $o['stock_counted'] = true; return $o; }) ?: $row;
            }
            notify_user($row['user_id'], 'order', 'অফার হিট হয়েছে ✅', $row['title'] . ' → ' . $row['number'] . ($note ? ' · ' . $note : ''), ['order_id' => $id]);
        } elseif ($status === 'failed' || $status === 'cancelled') {
            // Refund wallet-paid orders
            if (!empty($row['invoice_id']) && empty($row['refunded'])) {
                $inv = store_find('invoices', fn($i) => $i['id'] === $row['invoice_id']);
                if ($inv && $inv['method'] === 'wallet' && $inv['status'] === 'paid') {
                    wallet_credit($row['user_id'], (float)$row['price'], 'অর্ডার ফেরত: ' . $row['title'], $id);
                    $row = store_update('orders', $id, function ($o) { $o['refunded'] = true; return $o; }) ?: $row;
                }
            }
            notify_user($row['user_id'], 'order', 'অর্ডার ব্যর্থ ❌', $row['title'] . ' → ' . $row['number'] . ($note ? ' · কারণ: ' . $note : '') . (!empty($row['refunded']) ? ' · টাকা ওয়ালেটে ফেরত দেওয়া হয়েছে' : ''), ['order_id' => $id]);
        } else {
            notify_user($row['user_id'], 'order', 'অর্ডার আপডেট: ' . $status, $row['title'] . ' → ' . $row['number'], ['order_id' => $id]);
        }
    }
    return $row;
}
