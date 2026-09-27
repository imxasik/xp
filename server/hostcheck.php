<?php
/**
 * 🔬 হোস্ট ডায়াগনস্টিক — একদম আলাদা, কোনো config লাগে না
 * ───────────────────────────────────────────────────────────
 * শুধু এই একটা ফাইল আপলোড করে ব্রাউজারে খুললেই বলে দেবে
 * আপনার হোস্টিং-এ pull mode চলবে কি চলবে না।
 *
 *   https://yourdomain.com/wx/hostcheck.php
 *
 * ⚠️ পরীক্ষা শেষে ফাইলটা মুছে ফেলবেন।
 */
declare(strict_types=1);

@set_time_limit(180);
@ini_set('max_execution_time', '180');
@ini_set('default_socket_timeout', '8');

// ফলাফল সাথে সাথে দেখানোর জন্য (টাইমআউট হলেও যতটুকু হয়েছে দেখা যাবে)
@ini_set('output_buffering', '0');
@ini_set('zlib.output_compression', '0');
while (ob_get_level()) @ob_end_flush();
@ob_implicit_flush(true);

// নিজেকে বাইরে থেকে ডাকার পরীক্ষা (cron/agent পারবে কিনা)
if (isset($_GET['selftest'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'HOSTCHECK_MARKER_OK';
    exit;
}

$T0 = microtime(true);

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function row(string $name, string $state, string $detail = ''): void
{
    $icon = ['ok' => '✅', 'no' => '❌', 'warn' => '⚠️', 'info' => 'ℹ️'][$state] ?? 'ℹ️';
    $cls  = ['ok' => 'ok', 'no' => 'no', 'warn' => 'warn', 'info' => 'info'][$state] ?? 'info';
    echo '<tr class="' . $cls . '"><td class="i">' . $icon . '</td><td class="n">' . h($name)
       . '</td><td class="d">' . h($detail) . '</td></tr>' . "\n";
    flush();
}

function sect(string $t): void { echo '<tr class="sec"><td colspan="3">' . h($t) . '</td></tr>' . "\n"; flush(); }

/** প্রক্সি ছাড়া / প্রক্সি দিয়ে GET */
function get(string $url, ?string $proxy = null, int $timeout = 9,
             string $ua = 'Mozilla/5.0 (Linux; Android 13) HostCheck/1.0'): array
{
    $t = microtime(true);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min(7, $timeout),
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => $ua,
        ]);
        if ($proxy !== null) {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
            if (stripos($url, 'https://') === 0) {
                curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, true);
            }
        }
        $b = curl_exec($ch);
        $c = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $e = $b === false ? (curl_error($ch) ?: 'failed') : '';
        curl_close($ch);
        return ['code' => $c, 'body' => (string)$b, 'err' => $e, 'ms' => (int)((microtime(true) - $t) * 1000)];
    }
    if (!ini_get('allow_url_fopen')) {
        return ['code' => 0, 'body' => '', 'err' => 'cURL নেই, allow_url_fopen-ও বন্ধ', 'ms' => 0];
    }
    $o = ['http' => ['timeout' => $timeout, 'ignore_errors' => true,
                     'user_agent' => $ua],
          'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false]];
    if ($proxy !== null) {
        if (str_starts_with($proxy, 'socks')) return ['code' => 0, 'body' => '', 'err' => 'SOCKS-এর জন্য cURL লাগে', 'ms' => 0];
        $o['http']['proxy'] = preg_replace('#^https?://#', 'tcp://', $proxy);
        $o['http']['request_fulluri'] = true;
    }
    $b = @file_get_contents($url, false, stream_context_create($o));
    $c = 0;
    foreach (($http_response_header ?? []) as $hh) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $hh, $m)) { $c = (int)$m[1]; break; }
    }
    return ['code' => $c, 'body' => (string)$b, 'err' => $b === false ? 'stream failed' : '',
            'ms' => (int)((microtime(true) - $t) * 1000)];
}

/** নির্দিষ্ট পোর্ট খোলা আছে কিনা (TCP) */
function port_open(string $host, int $port, float $timeout = 6.0): array
{
    $t = microtime(true);
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    $ms = (int)((microtime(true) - $t) * 1000);
    if ($fp) { fclose($fp); return [true, $ms, '']; }
    return [false, $ms, trim((string)$errstr) ?: ('errno ' . $errno)];
}

// ⚠️ সাধারণ HTTP ব্যবহার করছি ইচ্ছে করেই — HTTPS-এর জন্য প্রক্সিকে CONNECT
//    টানেল বানাতে হয়, যা সস্তা প্রক্সিগুলো প্রায়ই পারে না। HTTP-তে ৩× দ্রুত।
$SOURCE = 'http://wx.baf.mil.bd/FTP_Folder/';

// পোর্ট ৮০-র প্রক্সি আগে — যেসব হোস্ট শুধু ৮০/৪৪৩ খোলা রাখে সেখানে
// একমাত্র এগুলোই কাজে লাগবে।
$PROXIES_80 = [
    'http://103.148.178.10:80', 'http://103.170.185.226:80',
    'http://103.163.51.254:80', 'http://103.210.57.243:80',
];
$PROXIES_HI = [
    'socks5h://119.148.7.10:22122',
    'socks5h://103.162.57.42:1080',
    'http://103.239.253.66:8080',
];
$PROXIES = array_merge($PROXIES_80, $PROXIES_HI);

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html><html lang="bn"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>হোস্ট ডায়াগনস্টিক</title>
<style>
 :root{--bg:#0a0e14;--card:#121a25;--line:#1f2b3a;--tx:#e8eef7;--tx2:#93a4bb;--tx3:#61748d;
       --ok:#34d399;--no:#f87171;--warn:#fbbf24}
 *{box-sizing:border-box}
 body{margin:0;background:var(--bg);color:var(--tx);padding:14px;
      font:14px/1.6 system-ui,-apple-system,"Noto Sans Bengali",sans-serif}
 .b{max-width:760px;margin:auto}
 h1{font-size:19px;margin:0 0 3px}
 .s{color:var(--tx3);font-size:12.5px;margin:0 0 14px}
 table{width:100%;border-collapse:collapse;background:var(--card);border-radius:12px;overflow:hidden}
 td{padding:9px 10px;border-bottom:1px solid var(--line);vertical-align:top;font-size:13px}
 tr:last-child td{border:0}
 td.i{width:26px;text-align:center}
 td.n{font-weight:600;width:42%}
 td.d{color:var(--tx2);font-size:12px;word-break:break-word}
 tr.ok td.n{color:var(--ok)} tr.no td.n{color:var(--no)} tr.warn td.n{color:var(--warn)}
 tr.sec td{background:#0d141d;color:var(--tx3);font-size:11px;font-weight:700;
           text-transform:uppercase;letter-spacing:.5px;padding:8px 10px}
 .v{margin-top:14px;padding:14px;border-radius:12px;font-size:14px;line-height:1.7}
 .v.g{background:#0c2f26;color:#6ee7b7} .v.r{background:#341417;color:#fca5a5}
 .v.y{background:#3a2c10;color:#fcd34d}
 .v b{display:block;font-size:16px;margin-bottom:6px}
 .f{color:var(--tx3);font-size:11.5px;margin-top:14px}
 code{background:#0e1620;padding:2px 6px;border-radius:5px;font-size:11.5px}
</style></head><body><div class="b">
<h1>🔬 হোস্ট ডায়াগনস্টিক</h1>
<p class="s">pull mode চলবে কিনা যাচাই করছি… (৩০–৬০ সেকেন্ড লাগতে পারে)</p>
<table>
<?php

/* ── ১) মৌলিক ── */
sect('১ · মৌলিক');
$phpOk = PHP_VERSION_ID >= 80000;
row('PHP সংস্করণ', $phpOk ? 'ok' : 'no', PHP_VERSION);

$hasCurl = function_exists('curl_init');
if ($hasCurl) {
    $cv = curl_version();
    row('cURL extension', 'ok', 'v' . ($cv['version'] ?? '?') . ' · ' . ($cv['ssl_version'] ?? ''));
} else {
    row('cURL extension', 'no', 'নেই বা বন্ধ করা — SOCKS5 প্রক্সি চলবে না');
}
$fopen = (bool)ini_get('allow_url_fopen');
row('allow_url_fopen', $fopen ? 'ok' : 'warn', $fopen ? 'চালু (cURL না থাকলে বিকল্প)' : 'বন্ধ');
row('max_execution_time', 'info', (string)ini_get('max_execution_time') . 's');

/* ── ২) বাইরে যাওয়া যায়? ── */
sect('২ · বাইরের ইন্টারনেটে সংযোগ');
$ipr = get('http://ip-api.com/json/?fields=query,countryCode,isp', null, 9);
$myip = null; $mycc = null;
if ($ipr['code'] === 200) {
    $j = json_decode($ipr['body'], true);
    $myip = $j['query'] ?? null; $mycc = $j['countryCode'] ?? null;
    row('সাধারণ HTTP বাইরে যায়', 'ok', $myip . ' (' . $mycc . ' · ' . ($j['isp'] ?? '') . ') · ' . $ipr['ms'] . 'ms');
} else {
    row('সাধারণ HTTP বাইরে যায়', 'no', $ipr['err'] ?: ('HTTP ' . $ipr['code']));
}
$https = get('https://api.ipify.org?format=json', null, 9);
row('HTTPS বাইরে যায়', $https['code'] === 200 ? 'ok' : 'no',
    $https['code'] === 200 ? ('ঠিক আছে · ' . $https['ms'] . 'ms') : ($https['err'] ?: 'HTTP ' . $https['code']));
$outbound = ($ipr['code'] === 200 || $https['code'] === 200);

/* ── ৩) অস্বাভাবিক পোর্ট খোলা? ── */
sect('৩ · প্রক্সির পোর্ট খোলা আছে?');
$portsOpen = 0; $port80Open = false;
foreach ([['103.148.178.10', 80], ['119.148.7.10', 22122],
          ['103.162.57.42', 1080], ['103.239.253.66', 8080]] as [$ph, $pp]) {
    [$o, $ms, $er] = port_open($ph, $pp, 6.0);
    if ($o) { $portsOpen++; if ($pp === 80) $port80Open = true; }
    row('পোর্ট ' . $pp . ' (' . $ph . ')', $o ? 'ok' : 'no', $o ? ($ms . 'ms') : $er);
}
if ($port80Open && $portsOpen === 1) {
    row('উপসংহার', 'warn', 'শুধু ৮০/৪৪৩ খোলা → config.php এ pool_ports = [80, 443] দিন');
}

/* ── ৪) আসল সোর্স ── */
sect('৪ · BAF সাইটে পৌঁছানো');
$direct = get($SOURCE, null, 10);
row('সরাসরি (প্রক্সি ছাড়া)', $direct['code'] === 200 ? 'ok' : 'info',
    $direct['code'] === 200 ? '২০০ — হোস্ট বাংলাদেশে!' : 'পৌঁছায় না (স্বাভাবিক — geo-block)');

$proxyOk = null; $tried = 0;
foreach ($PROXIES as $px) {
    if (microtime(true) - $T0 > 110) { row('সময় শেষ', 'warn', 'বাকি প্রক্সি পরীক্ষা করা হয়নি'); break; }
    if (!$hasCurl && str_starts_with($px, 'socks')) {
        row('প্রক্সি ' . parse_url($px, PHP_URL_HOST), 'info', 'বাদ — SOCKS-এর জন্য cURL লাগে');
        continue;
    }
    $tried++;
    $r = get($SOURCE, $px, 16);
    $good = $r['code'] === 200 && str_contains(strtolower($r['body']), 'ftp_folder');
    row('প্রক্সি ' . h(parse_url($px, PHP_URL_SCHEME) . '://' . parse_url($px, PHP_URL_HOST)),
        $good ? 'ok' : 'no',
        $good ? ('✓ ২০০ · ' . strlen($r['body']) . ' bytes · ' . $r['ms'] . 'ms')
              : ($r['err'] ?: 'HTTP ' . $r['code']));
    if ($good) { $proxyOk = $px; break; }
}
/* ── ৫) cron বাইরে থেকে ডাকতে পারবে? ── */
sect('৫ · বাইরে থেকে cron ডাকা যাবে?');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$self   = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
        . ($_SERVER['SCRIPT_NAME'] ?? '/hostcheck.php') . '?selftest=1';
$botOk = null;
if ($outbound) {
    $sr = get($self, null, 8, 'Wget/1.21');
    $blocked = str_contains($sr['body'], '__test') || str_contains($sr['body'], 'aes.js')
            || str_contains($sr['body'], 'slowAES') || in_array($sr['code'], [403, 406, 503], true);

    if (str_contains($sr['body'], 'HOSTCHECK_MARKER_OK')) {
        $botOk = true;
        row('বট/cron অ্যাক্সেস', 'ok', 'wget দিয়ে নিজের সাইটে ঢোকা গেছে · ' . $sr['ms'] . 'ms');
    } elseif ($blocked) {
        $botOk = false;
        row('বট/cron অ্যাক্সেস', 'no',
            str_contains($sr['body'], 'aes.js') || str_contains($sr['body'], '__test')
              ? 'হোস্ট JavaScript চ্যালেঞ্জ দিচ্ছে (iFastNet/InfinityFree-এর বট-ব্লক)'
              : ('HTTP ' . $sr['code'] . ' — বট হিসেবে ব্লক করেছে'));
    } else {
        // সিদ্ধান্তহীন — যেমন সার্ভার একসাথে দুটো রিকোয়েস্ট নিতে পারে না (php -S)
        $botOk = null;
        row('বট/cron অ্যাক্সেস', 'warn',
            'নিশ্চিত হওয়া গেল না (' . ($sr['err'] ?: 'HTTP ' . $sr['code']) . ')। '
            . 'cron বসানোর পর লগ দেখে যাচাই করুন।');
    }
} else {
    row('বট/cron অ্যাক্সেস', 'info', 'বাইরে যাওয়া যায় না বলে পরীক্ষা করা গেল না');
}
?>
</table>

<?php
/* ── রায় ── */
if ($botOk === false) {
    echo '<div class="v r"><b>❌ এই হোস্ট বাইরের প্রোগ্রামকে ঢুকতে দেয় না।</b>'
       . 'শুধু ব্রাউজার থেকে ঢোকা যায় — <code>wget</code>/<code>curl</code>/cron-job.org ব্লক করা।<br><br>'
       . '<b style="font-size:14px">এর মানে:</b> PULL মোডে cron <code>fetch.php</code> ডাকতে পারবে না, '
       . 'আর PUSH মোডে ফোনের agent <code>ingest.php</code> এ আপলোড করতে পারবে না। '
       . 'অর্থাৎ <u>কোনো মোডই চলবে না</u> — প্রক্সি ঠিক থাকলেও।<br><br>'
       . '<b style="font-size:14px">কী করবেন:</b> এই হোস্টিং ছেড়ে দিন। '
       . 'সবচেয়ে সহজ সমাধান — একটা <b>বাংলাদেশি হোস্টিং</b> (৳৯৯–২৯৯/মাস)। '
       . 'তাতে প্রক্সিরও দরকার হবে না, সরাসরি ছবি নামবে।</div>';
} elseif ($proxyOk !== null) {
    echo '<div class="v g"><b>✅ দারুণ! আপনার হোস্টিং-এ pull mode চলবে।</b>'
       . 'ফ্রি প্রক্সি দিয়ে BAF সাইটে পৌঁছানো গেছে (<code>' . h($proxyOk) . '</code>)।<br><br>'
       . 'পরের ধাপ: <code>config.php</code> এ ৩টা লাইন বদলে <code>fetch.php?key=...&amp;probe=1</code> চালান।</div>';
} elseif (!$outbound) {
    echo '<div class="v r"><b>❌ হোস্টিং বাইরের সব সংযোগ ব্লক করেছে।</b>'
       . 'pull mode এখানে কোনোভাবেই চলবে না।<br><br>'
       . '<b style="font-size:14px">কী করবেন:</b> সস্তা পেইড হোস্টিং নিন (~২৫০৳/মাস), '
       . 'অথবা PUSH মোডে যান (ফোনে Termux — README দেখুন)।</div>';
} elseif (!$hasCurl) {
    echo '<div class="v r"><b>❌ cURL নেই — SOCKS5 প্রক্সি চলবে না।</b>'
       . 'সাধারণ ইন্টারনেট কাজ করছে, কিন্তু cURL ছাড়া বেশিরভাগ ফ্রি প্রক্সি ব্যবহার করা যাবে না।<br><br>'
       . '<b style="font-size:14px">কী করবেন:</b> এমন হোস্টিং নিন যেখানে cURL চালু আছে, '
       . 'অথবা PUSH মোডে যান।</div>';
} elseif ($proxyOk === null && $port80Open) {
    echo '<div class="v y"><b>⚠️ শুধু পোর্ট ৮০/৪৪৩ খোলা — চলতে পারে, কিন্তু এখন প্রক্সি পাওয়া গেল না।</b>'
       . 'আপনার হোস্ট বেশিরভাগ পোর্ট ব্লক করেছে, তাই শুধু পোর্ট-৮০ প্রক্সি কাজে লাগবে। '
       . 'এগুলো কম এবং অস্থির, তাই কয়েকবার চেষ্টা করতে হয়।<br><br>'
       . '<b style="font-size:14px">config.php এ এই দুটো দিন:</b><br>'
       . '<code>\'pool_ports\' => [80, 443]</code><br>'
       . '<code>\'source_url\' => \'http://wx.baf.mil.bd/FTP_Folder/\'</code> (https নয় — ৩× দ্রুত)<br><br>'
       . 'তারপর সাইটে কয়েকবার রিফ্রেশ দিন — ভিজিটে-আপডেট প্রতিবার নতুন প্রক্সি চেষ্টা করবে।</div>';
} elseif ($portsOpen === 0) {
    echo '<div class="v r"><b>❌ হোস্ট অস্বাভাবিক পোর্ট (1080/8080/22122) ব্লক করেছে।</b>'
       . 'সাধারণ ওয়েবসাইট খোলা যায়, কিন্তু প্রক্সিতে সংযোগ দেওয়া যায় না।<br><br>'
       . '<b style="font-size:14px">কী করবেন:</b> হোস্টিং বদলান, অথবা এমন প্রক্সি নিন যেটা '
       . '৪৪৩ পোর্টে চলে, অথবা PUSH মোডে যান।</div>';
} else {
    echo '<div class="v y"><b>⚠️ এই মুহূর্তের প্রক্সিগুলো কাজ করল না।</b>'
       . 'হোস্টিং ঠিক আছে (বাইরে যাওয়া যায়, পোর্টও খোলা) — সম্ভবত ভেতরে লেখা প্রক্সিগুলো মরে গেছে।<br><br>'
       . '<b style="font-size:14px">কী করবেন:</b> পুরো সিস্টেম আপলোড করে '
       . '<code>proxies.php?key=...</code> এ গিয়ে 🔄 নতুন তালিকা → 🧪 যাচাই চালান। '
       . 'ওখানে ১৮৯টা প্রক্সি পরীক্ষা করা হবে, এখানে মাত্র ৪টা।</div>';
}
?>
<div class="f">
  ⏱️ মোট সময়: <?= round(microtime(true) - $T0, 1) ?> সেকেন্ড ·
  হোস্ট: <?= h($_SERVER['HTTP_HOST'] ?? '?') ?><br>
  🗑️ পরীক্ষা শেষে <code>hostcheck.php</code> ফাইলটা মুছে ফেলবেন।
</div>
</div></body></html>
