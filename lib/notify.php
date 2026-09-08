<?php
declare(strict_types=1);

/**
 * Notifications — stored in data/notifications.json
 *  to: 'admin' | 'user' | 'all'
 *  Read state is tracked with a per-account `notif_seen_at` timestamp
 *  (users.json / admins.json) so broadcasts need no per-row bookkeeping.
 */

function notify(string $to, ?string $userId, string $type, string $title, string $body = '', array $ref = []): array
{
    $n = [
        'id' => xp_id('n_'),
        'to' => $to,
        'user_id' => $userId,
        'type' => $type,
        'title' => $title,
        'body' => $body,
        'ref' => $ref,
        'created_at' => xp_now(),
        'ts' => microtime(true),
    ];
    $all = xp_read('notifications', []);
    $all[] = $n;
    // keep the file bounded
    if (count($all) > 1500) {
        $all = array_slice($all, -1500);
    }
    xp_write('notifications', $all);
    return $n;
}

function notify_admin(string $type, string $title, string $body = '', array $ref = []): array
{
    return notify('admin', null, $type, $title, $body, $ref);
}

function notify_user(string $userId, string $type, string $title, string $body = '', array $ref = []): array
{
    return notify('user', $userId, $type, $title, $body, $ref);
}

function notifs_for_user(?string $uid, int $limit = 50): array
{
    $all = xp_read('notifications', []);
    $list = array_values(array_filter($all, function ($n) use ($uid) {
        if (($n['to'] ?? '') === 'all') return true;
        return $uid && ($n['to'] ?? '') === 'user' && ($n['user_id'] ?? '') === $uid;
    }));
    $list = array_reverse($list);
    return array_slice($list, 0, $limit);
}

function notifs_for_admin(int $limit = 100): array
{
    $all = xp_read('notifications', []);
    $list = array_values(array_filter($all, fn($n) => in_array($n['to'] ?? '', ['admin', 'all'], true)));
    $list = array_reverse($list);
    return array_slice($list, 0, $limit);
}

/** $seen = float microtime (preferred) or legacy 'Y-m-d H:i:s' string */
function notifs_unread(array $list, $seen): int
{
    if (!$seen) return count($list);
    $c = 0;
    foreach ($list as $n) {
        if (is_numeric($seen)) {
            if ((float)($n['ts'] ?? 0) > (float)$seen) $c++;
        } elseif (strcmp((string)$n['created_at'], (string)$seen) > 0) $c++;
    }
    return $c;
}

function notif_seen_mark(): array
{
    return ['notif_seen_at' => xp_now(), 'notif_seen_ts' => microtime(true)];
}

function notif_seen_of(array $acct)
{
    return $acct['notif_seen_ts'] ?? ($acct['notif_seen_at'] ?? null);
}

function bn_digits(string $s): string
{
    return strtr($s, ['0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯']);
}

function bn_clock(string $hhmm): string
{
    [$h, $m] = array_map('intval', explode(':', $hhmm . ':0'));
    if ($h >= 4 && $h < 12) $p = 'সকাল';
    elseif ($h < 15) $p = 'দুপুর';
    elseif ($h < 18) $p = 'বিকাল';
    elseif ($h < 20) $p = 'সন্ধ্যা';
    else $p = 'রাত';
    $h12 = $h % 12;
    if ($h12 === 0) $h12 = 12;
    $t = $m ? sprintf('%d:%02d', $h12, $m) : sprintf('%dটা', $h12);
    return $p . ' ' . bn_digits($t);
}

/** Default service-hour config, merged with settings.service_hours */
function service_config(): array
{
    $d = [
        'enabled' => true,
        'start' => '08:00',
        'end' => '22:00',
        'scope' => 'drive', // drive | all
        'avg_minutes' => '৫–১৫',
        'prayer_breaks' => [
            ['name' => 'যোহর', 'start' => '12:15', 'end' => '12:50'],
            ['name' => 'আসর', 'start' => '16:15', 'end' => '16:45'],
            ['name' => 'মাগরিব', 'start' => '18:05', 'end' => '18:35'],
            ['name' => 'এশা', 'start' => '19:30', 'end' => '20:00'],
        ],
    ];
    $s = xp_settings()['service_hours'] ?? [];
    if (!is_array($s)) $s = [];
    $cfg = array_merge($d, $s);
    if (!is_array($cfg['prayer_breaks'] ?? null)) $cfg['prayer_breaks'] = $d['prayer_breaks'];
    return $cfg;
}

function service_minutes(string $hhmm): int
{
    $p = explode(':', $hhmm);
    return ((int)($p[0] ?? 0)) * 60 + (int)($p[1] ?? 0);
}

/**
 * Current service state.
 * state: open | prayer | closed
 */
function service_status(): array
{
    $c = service_config();
    $now = date('H:i');
    $nm = service_minutes($now);
    $out = [
        'enabled' => (bool)$c['enabled'],
        'state' => 'open',
        'now' => $now,
        'start' => $c['start'],
        'end' => $c['end'],
        'scope' => $c['scope'],
        'avg_minutes' => $c['avg_minutes'],
        'resumes_at' => null,
        'break_name' => null,
        'hours_label' => bn_clock($c['start']) . ' – ' . bn_clock($c['end']),
        'prayer_breaks' => $c['prayer_breaks'],
    ];
    if (!$c['enabled']) {
        $out['message'] = 'সার্ভিস চালু আছে · সাধারণত ' . $c['avg_minutes'] . ' মিনিটে অফার হিট হয়';
        return $out;
    }
    $sm = service_minutes($c['start']);
    $em = service_minutes($c['end']);
    if ($nm < $sm || $nm >= $em) {
        $out['state'] = 'closed';
        $out['resumes_at'] = $c['start'];
        $out['message'] = 'সার্ভিস সময় ' . $out['hours_label'] . ' · এখন অর্ডার দিলে ' . bn_clock($c['start']) . ' থেকে সিরিয়াল অনুযায়ী হিট হবে';
        return $out;
    }
    foreach ($c['prayer_breaks'] as $b) {
        $bs = service_minutes($b['start'] ?? '00:00');
        $be = service_minutes($b['end'] ?? '00:00');
        if ($nm >= $bs && $nm < $be) {
            $out['state'] = 'prayer';
            $out['resumes_at'] = $b['end'];
            $out['break_name'] = $b['name'] ?? 'নামাজ';
            $out['message'] = ($b['name'] ?? 'নামাজ') . ' নামাজের বিরতি চলছে · ' . bn_clock($b['end']) . ' এর পর অর্ডার হিট হবে, একটু ধৈর্য ধরুন';
            return $out;
        }
    }
    $out['message'] = 'সার্ভিস চালু আছে · সাধারণত ' . $c['avg_minutes'] . ' মিনিটে অফার হিট হয়';
    return $out;
}

/** Does the service-hour window apply to this order? */
function service_applies(array $order, ?array $offer = null): bool
{
    $c = service_config();
    if (empty($c['enabled'])) return false;
    if (($c['scope'] ?? 'drive') === 'all') return true;
    $cat = $offer['category'] ?? '';
    return $cat === 'drive';
}
