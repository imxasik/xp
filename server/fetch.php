<?php
/**
 * PULL MODE — cron এই ফাইলটি ডাকবে।
 *
 *   https://yourdomain.com/wx/fetch.php?key=CRON_KEY           → এক রাউন্ড sync
 *   https://yourdomain.com/wx/fetch.php?key=CRON_KEY&probe=1   → প্রক্সি কাজ করছে কিনা পরীক্ষা
 *   https://yourdomain.com/wx/fetch.php?key=CRON_KEY&dry=1     → কী কী নামানো হতো, শুধু দেখাবে
 *
 * cPanel cron উদাহরণ (প্রতি ১০ মিনিট):
 *   0,10,20,30,40,50 * * * * wget -q -O /dev/null "https://yourdomain.com/wx/fetch.php?key=CRON_KEY"
 *
 * cron না থাকলে (ফ্রি হোস্টিং) → cron-job.org এ এই URL টা বসিয়ে দিন।
 */
declare(strict_types=1);

@set_time_limit(180);
@ignore_user_abort(true);

require_once __DIR__ . '/lib/puller.php';

ensure_dirs();

/* ── auth ── */
$key = (string)pcfg('cron_key', '');
if ($key === '' || str_starts_with($key, 'CHANGE_ME')) {
    json_out(['ok' => false, 'error' => 'cron_key_not_set'], 500);
}
if (!hash_equals($key, (string)($_GET['key'] ?? ''))) {
    json_out(['ok' => false, 'error' => 'unauthorized'], 401);
}
if (!pcfg('enabled', false)) {
    json_out(['ok' => false, 'error' => 'pull_mode_disabled',
              'hint' => "config.php → 'pull' => ['enabled' => true]"], 409);
}

/* ── প্রক্সি প্রোব ── */
if (!empty($_GET['probe'])) {
    $res = ['ok' => true, 'curl' => function_exists('curl_init'),
            'proxy_set' => trim((string)pcfg('proxy', '')) !== '', 'checks' => []];

    // ১) আমার বহির্গামী IP কোন দেশের?
    foreach ([
        'http://ip-api.com/json/?fields=query,countryCode,isp',
        'https://ipinfo.io/json',
        'https://ifconfig.co/json',
        'https://api.ipify.org?format=json',
    ] as $svc) {
        $r = pull_http($svc);
        if ($r['code'] !== 200 || $r['body'] === '') continue;
        $j = json_decode($r['body'], true);
        if (!is_array($j)) continue;
        $ip = $j['query'] ?? $j['ip'] ?? null;
        $cc = $j['countryCode'] ?? $j['country'] ?? $j['country_iso'] ?? null;
        if ($ip === null) continue;
        $res['egress_ip']      = $ip;
        $res['egress_country'] = $cc;
        $res['egress_isp']     = $j['isp'] ?? $j['org'] ?? null;
        $res['checks'][]       = ['ip_service' => parse_url($svc, PHP_URL_HOST), 'ms' => $r['ms']];
        if ($cc !== null) break;   // দেশ পেয়ে গেলে থামি
    }
    if (!$res['curl']) {
        $res['curl_warning'] = '⚠️ cURL নেই — SOCKS5 প্রক্সি চলবে না, শুধু HTTP প্রক্সি চেষ্টা হবে। '
                             . 'অনেক ফ্রি হোস্ট (InfinityFree/000webhost) ইচ্ছাকৃতভাবে cURL বন্ধ রাখে।';
    }
    if (strtolower((string)pcfg('proxy', '')) === 'auto') {
        $pd = pool_load();
        $res['pool'] = ['total' => count($pd['list'] ?? []), 'current' => $pd['current'] ?? '',
                        'updated' => (int)($pd['updated'] ?? 0), 'tested' => (int)($pd['tested'] ?? 0)];
    }
    // ২) সোর্সে পৌঁছানো যায়?
    $base = rtrim((string)cfg('source_url'), '/') . '/';
    $r = pull_http($base);
    $res['source'] = ['http' => $r['code'], 'ms' => $r['ms'], 'err' => $r['err'],
                      'bytes' => strlen($r['body'])];

    if ($r['code'] === 200) {
        $p = parse_listing($base, $r['body']);
        $names = array_map(fn($f) => rawurldecode(basename(parse_url($f['url'], PHP_URL_PATH) ?: '')),
                           $p['files']);
        $res['source']['dirs']      = count($p['dirs']);
        $res['source']['files']     = count($names);
        $res['source']['all_files'] = $names;   // 👈 এখান থেকে হুবহু নাম কপি করে targets-এ বসান
        $res['source']['subfolders'] = array_map(
            fn($d) => rawurldecode(trim(substr($d, strlen($base)), '/')), $p['dirs']);

        // include প্যাটার্নে কোনগুলো মিলছে?
        $res['include_matches'] = array_values(array_filter($names, 'pull_included'));
        $res['verdict'] = '✅ প্রক্সি কাজ করছে — pull mode ব্যবহার করতে পারেন';
    } else {
        $res['verdict'] = ($res['egress_country'] ?? '?') !== 'BD'
            ? '❌ বহির্গামী IP বাংলাদেশি নয় — প্রক্সি সেটিংস দেখুন।'
            : '❌ IP বাংলাদেশি হলেও সোর্সে পৌঁছানো যাচ্ছে না — অন্য প্রক্সি চেষ্টা করুন।';
    }

    // ৩) targets গুলো আসলেই আছে কিনা — একে একে যাচাই
    $targets = array_values(array_filter(array_map('strval', (array)pcfg('targets', []))));
    if ($targets) {
        $res['targets'] = [];
        $good = 0;
        foreach ($targets as $t) {
            $rel = sanitize_rel($t);
            if ($rel === null) { $res['targets'][] = ['path' => $t, 'ok' => false, 'why' => 'অবৈধ পাথ']; continue; }
            $u  = $base . implode('/', array_map('rawurlencode', explode('/', $rel)));
            $rr = pull_http($u, [], true);   // আগে HEAD (হালকা)
            // কিছু সার্ভার HEAD সাপোর্ট করে না (405/501) — তখন GET দিয়ে যাচাই
            if (in_array($rr['code'], [400, 403, 405, 501], true) || $rr['code'] === 0) {
                $rr = pull_http($u);
                $rr['headers']['content-length'] = $rr['headers']['content-length']
                    ?? (string)strlen($rr['body']);
            }
            $ok = $rr['code'] === 200;
            if ($ok) $good++;
            $res['targets'][] = [
                'path'  => $rel,
                'ok'    => $ok,
                'http'  => $rr['code'],
                'size'  => isset($rr['headers']['content-length']) ? (int)$rr['headers']['content-length'] : null,
                'type'  => $rr['headers']['content-type'] ?? null,
                'mtime' => $rr['headers']['last-modified'] ?? null,
                'etag'  => isset($rr['headers']['etag']) ? '✔ আছে (304 কাজ করবে)' : '— নেই',
                'ms'    => $rr['ms'],
            ];
        }
        $res['verdict_targets'] = $good === count($targets)
            ? "✅ সবগুলো target ({$good}টি) পাওয়া গেছে — cron বসিয়ে দিন"
            : "⚠️ {$good}/" . count($targets) . " টি target পাওয়া গেছে। উপরের all_files থেকে হুবহু নাম কপি করুন";
    }

    json_out($res);
}

/* ── ওভারল্যাপ রোধ (একসাথে দুটো রান নয়) ── */
$lockFile = data_path('pull.lock');
$lock = @fopen($lockFile, 'c+');
if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
    json_out(['ok' => false, 'error' => 'already_running'], 429);
}

$res = pull_run(!empty($_GET['dry']));

if ($lock) { @flock($lock, LOCK_UN); @fclose($lock); }

json_out($res);
