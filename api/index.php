<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/lib/store.php';
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/wallet.php';
require_once dirname(__DIR__) . '/lib/gateway.php';
require_once dirname(__DIR__) . '/lib/orders.php';
require_once dirname(__DIR__) . '/lib/notify.php';
require_once dirname(__DIR__) . '/lib/seed.php';
xp_seed_if_empty();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
if ($_POST) {
    $input = array_merge($input, $_POST);
}

function need_user(): array
{
    $u = xp_user();
    if (!$u) {
        xp_json_out(['ok' => false, 'error' => 'লগইন করুন'], 401);
    }
    unset($u['password']);
    return $u;
}

function xp_public_settings(): array
{
    $s = xp_settings();
    unset($s['gateway_secret']);
    return $s;
}

function need_admin(): array
{
    $a = xp_admin();
    if (!$a) {
        xp_json_out(['ok' => false, 'error' => 'অ্যাডমিন লগইন প্রয়োজন'], 401);
    }
    unset($a['password']);
    return $a;
}

switch ($action) {
    case 'boot':
        $u = xp_user();
        if ($u) unset($u['password']);
        xp_json_out([
            'ok' => true,
            'csrf' => xp_csrf(),
            'user' => $u,
            'admin' => xp_admin() ? true : false,
            'settings' => xp_public_settings(),
            'service' => service_status(),
            'notif_unread' => $u ? notifs_unread(notifs_for_user($u['id']), notif_seen_of($u)) : 0,
            'operators' => xp_read('operators'),
            'categories' => xp_read('categories'),
            'gateways' => array_map(function ($g) {
                unset($g['secret']);
                return $g;
            }, gw_methods()),
            'banners' => array_values(array_filter(xp_read('banners'), fn($b) => !empty($b['active']))),
            'offers' => array_values(array_filter(xp_read('offers'), fn($o) => !empty($o['active']))),
        ]);

    case 'register':
        xp_json_out(auth_register($input['name'] ?? '', $input['phone'] ?? '', $input['password'] ?? ''));

    case 'login':
        xp_json_out(auth_login($input['phone'] ?? '', $input['password'] ?? ''));

    case 'logout':
        auth_logout();
        xp_json_out(['ok' => true]);

    case 'me':
        $u = need_user();
        $orders = array_values(array_filter(xp_read('orders'), fn($o) => $o['user_id'] === $u['id']));
        $wallet = array_values(array_filter(xp_read('wallet'), fn($w) => $w['user_id'] === $u['id']));
        $invoices = array_values(array_filter(xp_read('invoices'), fn($i) => $i['user_id'] === $u['id']));
        usort($orders, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        xp_json_out(['ok' => true, 'user' => $u, 'orders' => $orders, 'wallet' => $wallet, 'invoices' => $invoices, 'service' => service_status()]);

    case 'notifications':
        $u = need_user();
        $list = notifs_for_user($u['id']);
        $unread = notifs_unread($list, notif_seen_of($u));
        if (!empty($input['mark_read'])) {
            store_update('users', $u['id'], fn($x) => array_merge($x, notif_seen_mark()));
        }
        xp_json_out(['ok' => true, 'list' => $list, 'unread' => $unread, 'seen_at' => $u['notif_seen_at'] ?? null, 'seen_ts' => $u['notif_seen_ts'] ?? 0, 'service' => service_status()]);

    case 'service':
        xp_json_out(['ok' => true, 'service' => service_status()]);

    case 'order':
        $u = need_user();
        xp_json_out(order_create($u, $input));

    case 'pay_trx':
        $u = need_user();
        xp_json_out(gw_submit_trx($input['invoice_id'] ?? '', $input['trx_id'] ?? '', $input['sender'] ?? '', $u));

    case 'topup':
        $u = need_user();
        $amount = (float)($input['amount'] ?? 0);
        $min = (float)(xp_settings()['min_topup'] ?? 20);
        if ($amount < $min) {
            xp_json_out(['ok' => false, 'error' => "সর্বনিম্ন টপআপ ৳{$min}"]);
        }
        $method = $input['method'] ?? 'bkash';
        if ($method === 'wallet') {
            xp_json_out(['ok' => false, 'error' => 'ওয়ালেট দিয়ে টপআপ নয়']);
        }
        xp_json_out(gw_invoice($u, $amount, $method, 'wallet_topup'));

    case 'admin_login':
        xp_json_out(admin_login($input['username'] ?? '', $input['password'] ?? ''));

    case 'admin_boot':
        $adm = need_admin();
        $alist = notifs_for_admin();
        xp_json_out([
            'ok' => true,
            'admin' => $adm,
            'service' => service_status(),
            'notifications' => $alist,
            'notif_unread' => notifs_unread($alist, notif_seen_of($adm)),
            'settings' => xp_settings(),
            'operators' => xp_read('operators'),
            'offers' => xp_read('offers'),
            'orders' => array_reverse(xp_read('orders')),
            'users' => array_map(function ($u) { unset($u['password']); return $u; }, xp_read('users')),
            'invoices' => array_reverse(xp_read('invoices')),
            'gateways' => xp_read('gateways'),
            'categories' => xp_read('categories'),
            'banners' => xp_read('banners'),
            'wallet' => array_reverse(xp_read('wallet')),
        ]);

    case 'admin_save_offer':
        need_admin();
        $o = $input;
        if (empty($o['id'])) {
            $o['id'] = xp_id('of_');
            $o['sold'] = 0;
            $o['created_at'] = xp_now();
            $o['active'] = !empty($o['active']);
            $o['featured'] = !empty($o['featured']);
            $o['face_value'] = (float)($o['face_value'] ?? 0);
            $o['price'] = (float)($o['price'] ?? 0);
            $o['stock'] = (int)($o['stock'] ?? 0);
            store_push('offers', $o);
        } else {
            store_update('offers', $o['id'], function ($old) use ($o) {
                foreach (['title','description','operator','category','validity'] as $k) {
                    if (isset($o[$k])) $old[$k] = $o[$k];
                }
                $old['face_value'] = (float)($o['face_value'] ?? $old['face_value']);
                $old['price'] = (float)($o['price'] ?? $old['price']);
                $old['stock'] = (int)($o['stock'] ?? $old['stock']);
                $old['active'] = !empty($o['active']);
                $old['featured'] = !empty($o['featured']);
                return $old;
            });
        }
        xp_json_out(['ok' => true, 'offers' => xp_read('offers')]);

    case 'admin_delete_offer':
        need_admin();
        store_delete('offers', $input['id'] ?? '');
        xp_json_out(['ok' => true, 'offers' => xp_read('offers')]);

    case 'admin_notifications':
        $adm = need_admin();
        $alist = notifs_for_admin();
        $unread = notifs_unread($alist, notif_seen_of($adm));
        if (!empty($input['mark_read'])) {
            store_update('admins', $adm['id'], fn($x) => array_merge($x, notif_seen_mark()));
        }
        xp_json_out(['ok' => true, 'list' => $alist, 'unread' => $unread, 'seen_at' => $adm['notif_seen_at'] ?? null, 'seen_ts' => $adm['notif_seen_ts'] ?? 0]);

    case 'admin_poll':
        // lightweight: counts only, for the badge
        $adm = need_admin();
        $alist = notifs_for_admin();
        $orders = xp_read('orders');
        $pendingOrders = count(array_filter($orders, fn($o) => $o['status'] === 'processing'));
        $reviewInv = count(array_filter(xp_read('invoices'), fn($i) => $i['status'] === 'review'));
        xp_json_out(['ok' => true, 'unread' => notifs_unread($alist, notif_seen_of($adm)), 'pending_orders' => $pendingOrders, 'review_invoices' => $reviewInv, 'latest' => $alist[0] ?? null]);

    case 'admin_broadcast':
        need_admin();
        $title = trim((string)($input['title'] ?? ''));
        $body = trim((string)($input['body'] ?? ''));
        if ($title === '') xp_json_out(['ok' => false, 'error' => 'শিরোনাম দিন']);
        $uid = trim((string)($input['user_id'] ?? ''));
        if ($uid !== '') {
            xp_json_out(['ok' => true, 'n' => notify_user($uid, 'info', $title, $body)]);
        }
        xp_json_out(['ok' => true, 'n' => notify('all', null, 'info', $title, $body)]);

    case 'admin_delete_user':
        need_admin();
        $uid = (string)($input['user_id'] ?? '');
        $usr = store_find('users', fn($x) => $x['id'] === $uid);
        if (!$usr) xp_json_out(['ok' => false, 'error' => 'ইউজার নেই']);
        store_delete('users', $uid);
        if (!empty($input['purge'])) {
            foreach (['orders', 'invoices', 'wallet'] as $f) {
                store_replace($f, array_filter(xp_read($f), fn($r) => ($r['user_id'] ?? '') !== $uid));
            }
            store_replace('notifications', array_filter(xp_read('notifications'), fn($r) => ($r['user_id'] ?? '') !== $uid));
        }
        xp_json_out(['ok' => true, 'users' => array_map(function ($u) { unset($u['password']); return $u; }, xp_read('users'))]);

    case 'admin_user_status':
        need_admin();
        $uid = (string)($input['user_id'] ?? '');
        $st = ($input['status'] ?? 'active') === 'blocked' ? 'blocked' : 'active';
        $row = store_update('users', $uid, function ($x) use ($st) { $x['status'] = $st; return $x; });
        xp_json_out(['ok' => (bool)$row]);

    case 'admin_order':
        need_admin();
        $row = order_admin_set($input['id'] ?? '', $input['status'] ?? 'processing', $input['note'] ?? '');
        xp_json_out(['ok' => (bool)$row, 'order' => $row]);

    case 'admin_invoice':
        need_admin();
        xp_json_out(gw_admin_confirm($input['id'] ?? '', !empty($input['approve']), $input['note'] ?? ''));

    case 'admin_settings':
        need_admin();
        $s = xp_settings();
        foreach (['site_name','tagline','phone','whatsapp','notice','gateway_secret','theme'] as $k) {
            if (isset($input[$k])) $s[$k] = $input[$k];
        }
        $s['auto_approve_payments'] = !empty($input['auto_approve_payments']);
        $s['auto_process_orders'] = !empty($input['auto_process_orders']);
        $s['min_topup'] = (float)($input['min_topup'] ?? $s['min_topup']);
        if (isset($input['service_hours']) && is_array($input['service_hours'])) {
            $sh = $input['service_hours'];
            $clean = [
                'enabled' => !empty($sh['enabled']),
                'start' => preg_match('/^\d{2}:\d{2}$/', (string)($sh['start'] ?? '')) ? $sh['start'] : '08:00',
                'end' => preg_match('/^\d{2}:\d{2}$/', (string)($sh['end'] ?? '')) ? $sh['end'] : '22:00',
                'scope' => ($sh['scope'] ?? 'drive') === 'all' ? 'all' : 'drive',
                'avg_minutes' => trim((string)($sh['avg_minutes'] ?? '৫–১৫')) ?: '৫–১৫',
                'prayer_breaks' => [],
            ];
            foreach ((array)($sh['prayer_breaks'] ?? []) as $b) {
                if (!is_array($b)) continue;
                if (!preg_match('/^\d{2}:\d{2}$/', (string)($b['start'] ?? '')) || !preg_match('/^\d{2}:\d{2}$/', (string)($b['end'] ?? ''))) continue;
                $clean['prayer_breaks'][] = ['name' => trim((string)($b['name'] ?? 'নামাজ')) ?: 'নামাজ', 'start' => $b['start'], 'end' => $b['end']];
            }
            $s['service_hours'] = $clean;
        }
        xp_write('settings', $s);
        xp_json_out(['ok' => true, 'settings' => $s]);

    case 'admin_gateways':
        need_admin();
        $list = $input['gateways'] ?? null;
        if (is_array($list)) {
            xp_write('gateways', $list);
        }
        xp_json_out(['ok' => true, 'gateways' => xp_read('gateways')]);

    case 'admin_operators':
        need_admin();
        if (isset($input['operators']) && is_array($input['operators'])) {
            xp_write('operators', $input['operators']);
        }
        xp_json_out(['ok' => true, 'operators' => xp_read('operators')]);

    case 'admin_user_wallet':
        need_admin();
        $uid = $input['user_id'] ?? '';
        $amount = (float)($input['amount'] ?? 0);
        if ($amount <= 0) xp_json_out(['ok' => false, 'error' => 'পরিমাণ ভুল']);
        if (($input['type'] ?? 'credit') === 'debit') {
            xp_json_out(wallet_debit($uid, $amount, 'অ্যাডমিন সমন্বয়'));
        }
        xp_json_out(['ok' => true, 'tx' => wallet_credit($uid, $amount, 'অ্যাডমিন টপআপ')]);

    case 'admin_banners':
        need_admin();
        if (isset($input['banners'])) xp_write('banners', $input['banners']);
        xp_json_out(['ok' => true, 'banners' => xp_read('banners')]);

    case 'gateway_auto':
        xp_json_out(gw_secret_confirm($input['secret'] ?? '', $input['trx'] ?? '', (float)($input['amount'] ?? 0), $input['paycode'] ?? ''));

    default:
        xp_json_out(['ok' => false, 'error' => 'অজানা অ্যাকশন'], 404);
}
