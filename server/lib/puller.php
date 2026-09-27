<?php
/**
 * PULL MODE ইঞ্জিন
 * ---------------------------------------------------------------
 * বাংলাদেশি প্রক্সির মধ্য দিয়ে সোর্স সাইট থেকে ফাইল নামায়।
 * Termux agent ছাড়াই কাজ চালানোর জন্য।
 *
 * দুটি কৌশল:
 *   ১) TARGET মোড  — নির্দিষ্ট কয়েকটা ফাইল, crawl ছাড়াই। দ্রুততম।
 *   ২) CRAWL মোড   — পুরো ফোল্ডার ঘুরে include-প্যাটার্নে মেলা ফাইল।
 *
 * দুটোতেই conditional GET (If-None-Match / If-Modified-Since) ব্যবহার হয়,
 * তাই ছবি না বদলালে সার্ভার 304 দেয় আর এক বাইটও ডাউনলোড হয় না।
 */
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/maintain.php';
require_once __DIR__ . '/proxypool.php';

function pcfg(string $k, $d = null)
{
    $p = cfg('pull', []);
    return is_array($p) && array_key_exists($k, $p) ? $p[$k] : $d;
}

/* ══════════════════════════════════════════════════════════════
   সময়ের ডেডলাইন
   ─────────────────────────────────────────────────────────────
   ফ্রি প্রক্সি ধীর/মৃত হতে পারে। ডেডলাইন না থাকলে ৬টা প্রক্সি × ৪৫ সেকেন্ড
   = ২৭০ সেকেন্ড ঝুলে থাকবে আর হোস্ট রিকোয়েস্ট কেটে দেবে। তাই প্রতিটা
   রিকোয়েস্ট বাকি সময়ের মধ্যেই শেষ হতে বাধ্য।
   ══════════════════════════════════════════════════════════════ */

function pull_deadline(?float $set = null): float
{
    static $d = 0.0;
    if ($set !== null) $d = $set;
    return $d;
}

function pull_time_left(): float
{
    $d = pull_deadline();
    return $d > 0.0 ? ($d - microtime(true)) : 99999.0;
}

/**
 * প্রতি প্রক্সি-চেষ্টায় সর্বোচ্চ মোট সেকেন্ড।
 *
 * ⚠️ এখানে CONNECTTIMEOUT কাজে আসে না: প্রক্সিতে TCP সংযোগ তাৎক্ষণিক হয়
 *    (০.০০০৫s), কিন্তু প্রক্সি উৎস থেকে আনতে গিয়ে ঝুলে থাকে। তাই মরা চেষ্টা
 *    ধরতে *মোট* সময়ই সীমিত করতে হয়।
 *    শেষ চেষ্টাটা বাকি পুরো সময় পায়, যাতে বড় ফাইলও নামতে পারে।
 */
function pull_attempt_cap(?int $set = null): int
{
    static $c = 0;
    if ($set !== null) $c = max(0, $set);
    return $c;
}

/* ══════════════════════════════════════════════════════════════
   HTTP (প্রক্সিসহ)
   ══════════════════════════════════════════════════════════════ */

/**
 * প্রক্সি রাউটার।
 *   proxy = 'auto'  → ফ্রি প্রক্সি পুল, একটা ফেল করলে পরেরটা (স্বয়ংক্রিয় failover)
 *   proxy = '...'   → নির্দিষ্ট প্রক্সি
 *   proxy = ''      → সরাসরি
 *
 * @param string[] $reqHeaders অতিরিক্ত রিকোয়েস্ট হেডার
 * @return array{code:int,body:string,err:string,ms:int,headers:array<string,string>}
 */
function pull_http(string $url, array $reqHeaders = [], bool $headOnly = false): array
{
    $mode = trim((string)pcfg('proxy', ''));
    if (strtolower($mode) !== 'auto') {
        return pull_http_via($mode, $url, $reqHeaders, $headOnly);
    }

    // ── অটো মোড ──
    static $stick = null;                     // এই রানে যেটা কাজ করছে
    $tries = max(1, (int)pcfg('pool_tries', 6));
    $cands = pool_best($tries + 4);
    if ($stick !== null) {
        $cands = array_values(array_diff($cands, [$stick]));
        array_unshift($cands, $stick);
    }
    if (empty($cands)) {
        pool_refresh(true);
        $cands = pool_best($tries);
    }

    $last = ['code' => 0, 'body' => '', 'err' => 'কোনো কার্যকর প্রক্সি নেই',
             'ms' => 0, 'headers' => [], 'proxy' => ''];
    $n = 0;
    foreach ($cands as $px) {
        if ($n++ >= $tries) break;
        $left = pull_time_left();
        if ($left < 5) { $last['err'] = 'সময় শেষ'; break; }

        // শেষ চেষ্টা হলে বাকি পুরো সময় দাও (বড় ফাইলের জন্য জরুরি),
        // নাহলে অল্প সময় দিয়ে দ্রুত পরের প্রক্সিতে যাও।
        $cap  = pull_attempt_cap();
        $isLast = ($n >= $tries) || ($cap > 0 && $left <= $cap * 1.7);
        $tmo  = ($cap > 0 && !$isLast) ? min($cap, (int)$left) : (int)$left;

        $r = pull_http_via(pool_curl_proxy($px), $url, $reqHeaders, $headOnly, $tmo);
        $r['proxy'] = $px;

        // ⚠️ শুধু 2xx/304 কেই "প্রক্সি কাজ করছে" ধরব।
        //
        // আগে 404-কেও সফল ধরা হতো ("প্রক্সি ঠিক, ফাইল নেই" ভেবে)। কিন্তু
        // অনেক নকল প্রক্সি (যেমন 103.170.185.226) আসলে সাধারণ ওয়েব সার্ভার —
        // সব রিকোয়েস্টেই 404 দেয়। তখন সেটাই সর্বোচ্চ স্কোর পেয়ে আটকে বসত
        // আর ভালো প্রক্সিগুলো কখনো চেষ্টাই হতো না।
        if (in_array($r['code'], [200, 206, 304], true)) {
            if ($stick !== $px) { pool_mark($px, true, $r['ms']); $stick = $px; }
            return $r;
        }

        // উত্তর দিয়েছে কিন্তু ভুল উত্তর → এই প্রক্সি বাদ, পরেরটা দেখি
        pool_mark($px, false, $r['ms']);
        if ($stick === $px) $stick = null;
        if ($r['code'] > 0 && $last['code'] === 0) $last = $r;   // আসল এররটা রেখে দিই
        elseif ($last['code'] === 0) $last = $r;
    }
    return $last;
}

/** নির্দিষ্ট একটি প্রক্সি (বা প্রক্সি ছাড়া) দিয়ে আসল রিকোয়েস্ট */
function pull_http_via(string $proxy, string $url, array $reqHeaders = [], bool $headOnly = false,
                       ?int $timeoutOv = null): array
{
    $t0      = microtime(true);
    $proxy   = trim($proxy);
    $timeout = (int)pcfg('timeout', 45);
    $verify  = (bool)pcfg('verify_ssl', false);
    // বাকি সময়ের চেয়ে বেশি সময় নেওয়া চলবে না
    if ($timeoutOv !== null && $timeoutOv > 0) $timeout = $timeoutOv;
    $left = (int)floor(pull_time_left());
    if ($left > 0 && $left < $timeout) $timeout = max(5, $left);
    $conn = min(10, $timeout);
    $ua      = 'Mozilla/5.0 (Linux; Android 13) BAFWX-Mirror/1.2';
    $base    = ['Accept: */*', 'Accept-Language: en-US,en;q=0.9,bn;q=0.8'];
    $hdrs    = [];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
            CURLOPT_CONNECTTIMEOUT => $conn,
            CURLOPT_TIMEOUT        => $timeout,
            // ⚠️ LOW_SPEED_* ব্যবহার করবেন না — অনেক ফ্রি প্রক্সি প্রথমে পুরো ফাইল
            //    নিজে বাফার করে, তারপর একসাথে পাঠায়। তখন শুরুর ১০–১৫ সেকেন্ড
            //    কোনো ডেটা আসে না আর কাজ করা ডাউনলোডও বাতিল হয়ে যায়।
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_ENCODING       => '',
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_HTTPHEADER     => array_merge($base, $reqHeaders),
            CURLOPT_NOBODY         => $headOnly,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$hdrs) {
                $p = explode(':', $line, 2);
                if (count($p) === 2) $hdrs[strtolower(trim($p[0]))] = trim($p[1]);
                return strlen($line);
            },
        ]);
        if ($proxy !== '') {
            // "socks5h://user:pass@host:port" ফরম্যাট cURL সরাসরি বোঝে
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
            // ⚠️ টানেল শুধু HTTPS-এর জন্য। সাধারণ HTTP-তে জোর করলে অনেক সস্তা
            //    প্রক্সি "CONNECT tunnel failed 400" দেয় আর অকারণে বাদ পড়ে।
            if (stripos($url, 'https://') === 0) {
                curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, true);
            }
        }
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = $body === false ? (curl_error($ch) ?: 'curl_failed') : '';
        curl_close($ch);
        return ['code' => $code, 'body' => is_string($body) ? $body : '', 'err' => $err,
                'ms' => (int)((microtime(true) - $t0) * 1000), 'headers' => $hdrs];
    }

    // cURL না থাকলে fallback (শুধু HTTP প্রক্সি, SOCKS চলবে না)
    $opts = ['http' => [
        'method'          => $headOnly ? 'HEAD' : 'GET',
        'timeout'         => $timeout,
        'user_agent'      => $ua,
        'header'          => implode("\r\n", array_merge($base, $reqHeaders)),
        'follow_location' => 1,
        'max_redirects'   => 4,
        'ignore_errors'   => true,
    ], 'ssl' => ['verify_peer' => $verify, 'verify_peer_name' => $verify]];

    if ($proxy !== '' && !str_starts_with($proxy, 'socks')) {
        $opts['http']['proxy'] = preg_replace('#^https?://#', 'tcp://', $proxy);
        $opts['http']['request_fulluri'] = true;
    } elseif ($proxy !== '') {
        return ['code' => 0, 'body' => '', 'err' => 'SOCKS প্রক্সির জন্য cURL extension লাগবে',
                'ms' => (int)((microtime(true) - $t0) * 1000), 'headers' => []];
    }

    $body = @file_get_contents($url, false, stream_context_create($opts));
    $code = 0;
    foreach (($http_response_header ?? []) as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $code = (int)$m[1]; continue; }
        $p = explode(':', $h, 2);
        if (count($p) === 2) $hdrs[strtolower(trim($p[0]))] = trim($p[1]);
    }
    return ['code' => $code, 'body' => is_string($body) ? $body : '',
            'err' => $body === false ? 'stream_failed' : '',
            'ms' => (int)((microtime(true) - $t0) * 1000), 'headers' => $hdrs];
}

/* ══════════════════════════════════════════════════════════════
   ডিরেক্টরি লিস্টিং পার্সার (Apache autoindex + IIS)
   ══════════════════════════════════════════════════════════════ */

function url_join(string $base, string $rel): string
{
    if (preg_match('#^https?://#i', $rel)) return $rel;
    $p = parse_url($base);
    if (!$p || empty($p['host'])) return $rel;
    $origin = ($p['scheme'] ?? 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    if (str_starts_with($rel, '/')) return $origin . $rel;
    $dir  = rtrim(preg_replace('#[^/]*$#', '', $p['path'] ?? '/'), '') ?: '/';
    $path = $dir . $rel;
    $out  = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') { array_pop($out); continue; }
        $out[] = $seg;
    }
    return $origin . '/' . implode('/', $out) . (str_ends_with($rel, '/') ? '/' : '');
}

/**
 * টাইমজোন-নিরপেক্ষ ফিঙ্গারপ্রিন্ট: listing যে কাঁচা তারিখ+সাইজ দেখিয়েছে সেটাই।
 * ডিরেক্টরি লিস্টিং-এর তারিখে টাইমজোন থাকে না, তাই parsed timestamp তুলনা করা
 * বিপজ্জনক (agent আর server-এর TZ আলাদা হলে প্রতিবার সব ফাইল আবার নামবে)।
 */
function listing_fp(string $date, string $size): string
{
    return strtolower(preg_replace('/\s+/', ' ', trim($date)) . '|' . trim($size));
}

/** @return array{dirs:string[],files:array<int,array{url:string,size:?int,mtime:?int,fp:string}>} */
function parse_listing(string $baseUrl, string $html): array
{
    $dirs = []; $files = []; $seen = [];
    $host = parse_url($baseUrl, PHP_URL_HOST);

    if (!preg_match_all('#<a\s[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)</a>#is',
                        $html, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        return ['dirs' => [], 'files' => []];
    }

    foreach ($m as $set) {
        $href  = html_entity_decode(trim($set[1][0]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $label = strtolower(trim(strip_tags($set[2][0])));
        $end   = $set[0][1] + strlen($set[0][0]);

        if ($href === '' || preg_match('#^(\?|\#|mailto:|javascript:)#i', $href)) continue;
        if (in_array($href, ['../', '..', './', '/'], true)) continue;
        if (in_array($label, ['parent directory', '[to parent directory]', '..', '[parent]',
                              'name', 'size', 'last modified'], true)) continue;

        $full = url_join($baseUrl, $href);
        if (parse_url($full, PHP_URL_HOST) !== $host) continue;
        if (isset($seen[$full])) continue;
        $seen[$full] = true;

        $size = null; $mtime = null; $fp = '';

        // লিংকের পরের অংশ থেকে HTML ট্যাগ সরিয়ে নিই — তাহলে <pre> ও <table>
        // দুই ধরনের autoindex-ই একই নিয়মে পড়া যায়।
        $tailRaw  = substr($html, $end, 260);
        $tailText = preg_replace('#<[^>]+>#', ' ', $tailRaw) ?? $tailRaw;
        $tailText = preg_replace('/\s+/', ' ', html_entity_decode($tailText, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '';

        // ফরম্যাট ক — wx.baf.mil.bd যেটা ব্যবহার করে:  "2026-09-26 21:45   318K"
        if (preg_match('#(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}(?::\d{2})?)\s+([\d.]+[KMGT]?|-)\b#i', $tailText, $a)) {
            $mtime = strtotime($a[1]) ?: null;
            $size  = parse_size($a[2]);
            $fp    = listing_fp($a[1], $a[2]);
        }
        // ফরম্যাট খ — ক্লাসিক Apache autoindex:  "12-Mar-2024 06:31   142K"
        elseif (preg_match('#(\d{2}-[A-Za-z]{3}-\d{4}\s+\d{2}:\d{2})\s+([\d.]+[KMGT]?|-)\b#i', $tailText, $a)) {
            $mtime = strtotime(str_replace('-', ' ', $a[1])) ?: null;
            $size  = parse_size($a[2]);
            $fp    = listing_fp($a[1], $a[2]);
        }
        // ফরম্যাট গ — IIS: মেটাডেটা লিংকের *আগে* থাকে
        else {
            $preRaw  = substr($html, max(0, $set[0][1] - 240), min(240, $set[0][1]));
            $preText = preg_replace('#<[^>]+>#', ' ', $preRaw) ?? $preRaw;
            $preText = preg_replace('/\s+/', ' ', html_entity_decode($preText, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '';
            // উইন্ডোতে আগের সারির ডেটাও পড়তে পারে, তাই সবসময় শেষ ম্যাচটা নিতে হবে।
            if (preg_match_all('#(\d{1,2}/\d{1,2}/\d{4}\s+\d{1,2}:\d{2}\s*[AP]M)\s+(<dir>|[\d,]+)#i',
                               $preText, $bm, PREG_SET_ORDER)) {
                $b = end($bm);
                $mtime = strtotime($b[1]) ?: null;
                if (stripos($b[2], 'dir') === false) {
                    $size = (int)str_replace(',', '', $b[2]);
                    $fp   = listing_fp($b[1], $b[2]);
                }
            }
        }

        if (str_ends_with($href, '/')) $dirs[] = rtrim($full, '/') . '/';
        else $files[] = ['url' => $full, 'size' => $size, 'mtime' => $mtime, 'fp' => $fp];
    }
    return ['dirs' => $dirs, 'files' => $files];
}

function parse_size(string $s): ?int
{
    $s = trim($s);
    if ($s === '' || $s === '-') return null;
    $mul  = 1;
    $last = strtoupper(substr($s, -1));
    if (in_array($last, ['K', 'M', 'G'], true)) {
        $mul = ['K' => 1024, 'M' => 1048576, 'G' => 1073741824][$last];
        $s   = substr($s, 0, -1);
    }
    return is_numeric($s) ? (int)((float)$s * $mul) : null;
}

/* ══════════════════════════════════════════════════════════════
   ফিল্টার
   ══════════════════════════════════════════════════════════════ */

function pull_allowed(string $rel): bool
{
    $ext  = strtolower((string)pathinfo($rel, PATHINFO_EXTENSION));
    $deny = (array)pcfg('deny_ext', []);
    $ok   = (array)pcfg('allow_ext', []);
    if ($ext !== '' && in_array($ext, $deny, true)) return false;
    if (!empty($ok) && !in_array($ext, $ok, true)) return false;
    return true;
}

/** include প্যাটার্ন — '*' থাকলে glob, নাহলে সাধারণ substring (case-insensitive) */
function pull_included(string $rel): bool
{
    $pats = array_filter(array_map('strval', (array)pcfg('include', [])));
    if (empty($pats)) return true;
    $low  = strtolower($rel);
    $bn   = strtolower(basename($rel));
    foreach ($pats as $p) {
        $p = strtolower(trim($p));
        if ($p === '') continue;
        if (strpos($p, '*') !== false || strpos($p, '?') !== false) {
            if (fnmatch($p, $bn) || fnmatch($p, $low)) return true;
        } elseif (str_contains($low, $p)) {
            return true;
        }
    }
    return false;
}

function pull_rel(string $base, string $url): ?string
{
    $rel = str_starts_with($url, $base)
        ? substr($url, strlen($base))
        : basename(parse_url($url, PHP_URL_PATH) ?: '');
    return sanitize_rel(rawurldecode(trim($rel, '/')));
}

/* ══════════════════════════════════════════════════════════════
   ডাউনলোড + সংরক্ষণ
   ══════════════════════════════════════════════════════════════ */

/**
 * conditional GET — না বদলালে 304, কোনো ডেটা খরচ নেই।
 * @return array{status:string,body:string,etag:string,lm:string,code:int,ms:int,err:string}
 */
function pull_fetch_file(string $url, ?array $cur): array
{
    $h = [];
    if (pcfg('conditional', true) && $cur) {
        if (!empty($cur['etag'])) $h[] = 'If-None-Match: ' . $cur['etag'];
        if (!empty($cur['lm']))   $h[] = 'If-Modified-Since: ' . $cur['lm'];
    }
    $r = pull_http($url, $h);
    $status = match (true) {
        $r['code'] === 304                       => 'unchanged',
        $r['code'] === 200 && $r['body'] !== ''  => 'ok',
        default                                  => 'fail',
    };

    // কিছু নকল প্রক্সি 200 দিয়ে নিজের এরর-পাতা (HTML) পাঠায়। ছবি চেয়ে
    // HTML পেলে সেটা ব্যর্থতা — নাহলে নষ্ট ফাইল সেভ হয়ে যাবে।
    if ($status === 'ok') {
        $ext  = strtolower((string)pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        $head = strtolower(substr(ltrim($r['body']), 0, 120));
        $isHtml = str_starts_with($head, '<!doctype') || str_starts_with($head, '<html')
               || str_contains($head, '<head') || str_contains($head, '<title');
        if ($isHtml && in_array($ext, ['jpg','jpeg','png','gif','webp','pdf'], true)) {
            $status   = 'fail';
            $r['err'] = 'প্রক্সি ছবির বদলে HTML পাঠিয়েছে';
        }
    }
    return [
        'status' => $status,
        'body'   => $r['body'],
        'etag'   => (string)($r['headers']['etag'] ?? ''),
        'lm'     => (string)($r['headers']['last-modified'] ?? ''),
        'code'   => $r['code'],
        'ms'     => $r['ms'],
        'err'    => $r['err'],
    ];
}

/** পুরোনো কপি history/ ফোল্ডারে সরিয়ে রাখা */
function pull_archive(array &$idx, string $rel): void
{
    $keep = (int)pcfg('keep_versions', 0);
    if ($keep <= 0) return;

    $src = data_path('files/' . $rel);
    if (!is_file($src)) return;

    $base  = pathinfo($rel, PATHINFO_FILENAME);
    $ext   = pathinfo($rel, PATHINFO_EXTENSION);
    $stamp = date('Y-m-d_H-i-s', @filemtime($src) ?: time());
    $hrel  = sanitize_rel('history/' . $base . '/' . $stamp . ($ext ? '.' . $ext : ''));
    if ($hrel === null || isset($idx['files'][$hrel])) return;

    $dest = data_path('files/' . $hrel);
    if (!is_dir(dirname($dest))) @mkdir(dirname($dest), 0775, true);
    if (!@copy($src, $dest)) return;

    $old = $idx['files'][$rel] ?? [];
    $idx['files'][$hrel] = [
        'size'  => (int)($old['size'] ?? filesize($dest) ?: 0),
        'psize' => (int)($old['psize'] ?? 0),
        'mtime' => (int)($old['mtime'] ?? time()),
        'ts'    => time(),
        'sha'   => (string)($old['sha'] ?? ''),
        'kind'  => kind_of($hrel),
        'dir'   => dirname($hrel),
        'hist'  => 1,
    ];

    // এই বেসের অতিরিক্ত পুরোনো কপি ছাঁটাই
    $mine = [];
    foreach ($idx['files'] as $k => $v) {
        if (!empty($v['hist']) && str_starts_with($k, 'history/' . $base . '/')) $mine[$k] = (int)($v['mtime'] ?? 0);
    }
    if (count($mine) > $keep) {
        asort($mine);
        foreach (array_slice(array_keys($mine), 0, count($mine) - $keep) as $k) {
            remove_entry($idx, $k);
        }
    }
}

/* ══════════════════════════════════════════════════════════════
   মূল রুটিন
   ══════════════════════════════════════════════════════════════ */

/**
 * @param int|null $maxFilesOv  এক রানে সর্বোচ্চ কয়টা ফাইল (ভিজিটে-আপডেটের জন্য ১)
 * @param int|null $budgetOv    সেকেন্ড সীমা (হোস্টের max_execution_time এর নিচে)
 */
function pull_run(bool $dryRun = false, ?int $maxFilesOv = null, ?int $budgetOv = null): array
{
    $t0     = microtime(true);
    $base   = rtrim((string)cfg('source_url'), '/') . '/';
    $budget = (float)($budgetOv ?? pcfg('budget_seconds', 110));
    $maxF   = (int)($maxFilesOv ?? pcfg('max_files', 40));
    $maxB   = (int)((float)pcfg('max_file_mb', 25) * 1048576);
    $ageD   = (int)pcfg('max_age_days', 14);
    $ageCut = $ageD > 0 ? time() - $ageD * 86400 : 0;
    $targets = array_values(array_filter(array_map('strval', (array)pcfg('targets', []))));

    pull_deadline($t0 + $budget);   // এই রানের হার্ড ডেডলাইন

    ensure_dirs();
    $idx = index_load();

    $out = ['ok' => true, 'mode' => $targets ? 'pull/targets' : 'pull/crawl',
            'scanned' => 0, 'downloaded' => 0, 'unchanged' => 0, 'skipped' => 0,
            'errors' => 0, 'dirs' => 0, 'source_ok' => false, 'bytes' => 0, 'notes' => []];

    /* ── ধাপ ১: কোন ফাইলগুলো দেখব তা ঠিক করা ── */
    $found = [];   // rel => ['url'=>, 'size'=>, 'mtime'=>, 'fp'=>]

    if ($targets) {
        // ⚡ TARGET মোড — listing পড়ার দরকারই নেই
        foreach ($targets as $t) {
            $rel = sanitize_rel($t);
            if ($rel === null) { $out['notes'][] = 'bad_target:' . $t; continue; }
            $found[$rel] = ['url' => $base . implode('/', array_map('rawurlencode', explode('/', $rel))),
                            'size' => null, 'mtime' => null, 'fp' => ''];
        }
        $out['source_ok'] = true; // আসল যাচাই ডাউনলোডের সময় হবে
    } else {
        // 🕸 CRAWL মোড
        $queue = [[$base, 0]]; $visited = [];
        $maxD  = (int)pcfg('max_depth', 3);
        while ($queue) {
            if (microtime(true) - $t0 > $budget * 0.55) { $out['notes'][] = 'crawl_budget_reached'; break; }
            [$url, $depth] = array_shift($queue);
            if (isset($visited[$url]) || $depth > $maxD) continue;
            $visited[$url] = true;

            $r = pull_http($url);
            if ($r['code'] !== 200 || $r['body'] === '') {
                $out['errors']++;
                $out['notes'][] = 'listing_fail:' . ($r['err'] ?: 'HTTP ' . $r['code']);
                continue;
            }
            $out['source_ok'] = true;
            $out['dirs']++;

            $p = parse_listing($url, $r['body']);
            foreach ($p['dirs'] as $d) {
                if (!isset($visited[$d]) && str_starts_with($d, $base)) $queue[] = [$d, $depth + 1];
            }
            foreach ($p['files'] as $f) {
                $rel = pull_rel($base, $f['url']);
                if ($rel === null || !pull_allowed($rel) || !pull_included($rel)) continue;
                $found[$rel] = $f;
            }
        }
        if (!$out['source_ok']) {
            $out['ok'] = false;
            $out['notes'][] = 'সোর্সে পৌঁছানো যায়নি — প্রক্সি কাজ করছে না বা IP বাংলাদেশি নয়';
            pull_status($out, $t0);
            return $out;
        }
    }

    $out['scanned'] = count($found);

    /* ── ধাপ ২: listing দেখেই যেগুলো বাদ দেওয়া যায় ── */
    $todo = [];
    foreach ($found as $rel => $f) {
        if ($ageCut && $f['mtime'] && $f['mtime'] < $ageCut) { $out['skipped']++; continue; }
        $cur = $idx['files'][$rel] ?? null;
        if ($cur && !empty($f['fp']) && $f['fp'] === (string)($cur['fp'] ?? '')) {
            $out['skipped']++; continue;   // listing বলছে একদম অপরিবর্তিত
        }
        $todo[$rel] = $f;
    }
    if ($maxFilesOv !== null && $maxFilesOv <= 2) {
        // ভিজিটে-আপডেট: (১) যে ছবি এখনো নেই সেটা আগে — প্রথম ভিজিটেই যেন
        // সবগুলো ছবি একে একে চলে আসে; (২) তারপর ছোট ফাইল আগে।
        $have = $idx['files'] ?? [];
        uasort($todo, function ($a, $b) use ($have, $todo) {
            return 0;   // নিচে আলাদা করে সাজানো হচ্ছে
        });
        $keys = array_keys($todo);
        usort($keys, function ($ra, $rb) use ($have, $todo) {
            $ha = isset($have[$ra]) ? 1 : 0;
            $hb = isset($have[$rb]) ? 1 : 0;
            if ($ha !== $hb) return $ha <=> $hb;               // নেই → আগে
            $sa = (int)($todo[$ra]['size'] ?? PHP_INT_MAX);
            $sb = (int)($todo[$rb]['size'] ?? PHP_INT_MAX);
            return $sa <=> $sb;                                 // ছোট → আগে
        });
        $sorted = [];
        foreach ($keys as $k) $sorted[$k] = $todo[$k];
        $todo = $sorted;
    } else {
        uasort($todo, fn($a, $b) => (int)($b['mtime'] ?? 0) <=> (int)($a['mtime'] ?? 0));
    }

    if ($dryRun) {
        $out['would_check'] = array_slice(array_keys($todo), 0, 25);
        $out['todo_total']  = count($todo);
        $out['elapsed']     = round(microtime(true) - $t0, 1);
        return $out;
    }

    /* ── ধাপ ৩: conditional GET + সংরক্ষণ ── */
    $n = 0; $seen = 0;
    foreach ($todo as $rel => $f) {
        if ($n >= $maxF) { $out['notes'][] = 'file_limit_reached'; break; }
        if (microtime(true) - $t0 > $budget) { $out['notes'][] = 'time_budget_reached'; break; }
        $seen++;   // এটাকে সত্যিই হাতে নিলাম

        $cur = $idx['files'][$rel] ?? null;
        $r   = pull_fetch_file($f['url'], $cur);

        if ($r['status'] === 'unchanged') {
            $out['unchanged']++;
            $out['source_ok'] = true;
            // fp হালনাগাদ করে রাখি যাতে পরেরবার রিকোয়েস্টই না লাগে
            if ($cur !== null && !empty($f['fp'])) { $idx['files'][$rel]['fp'] = $f['fp']; }
            continue;
        }
        if ($r['status'] === 'fail') {
            $out['errors']++;
            $out['notes'][] = $rel . ':' . ($r['err'] ?: 'HTTP ' . $r['code']);
            continue;
        }

        $out['source_ok'] = true;
        $blob = $r['body'];
        if (strlen($blob) > $maxB) { $out['skipped']++; continue; }

        // একই বাইট হলে ডিস্কে লেখার দরকার নেই
        $sha = hash('sha256', $blob);
        if ($cur && ($cur['sha'] ?? '') === substr($sha, 0, 16)
            && (int)($cur['size'] ?? -1) === strlen($blob)) {
            $idx['files'][$rel]['etag'] = $r['etag'];
            $idx['files'][$rel]['lm']   = $r['lm'];
            if (!empty($f['fp'])) $idx['files'][$rel]['fp'] = $f['fp'];
            $out['unchanged']++;
            continue;
        }

        pull_archive($idx, $rel);

        $dest = data_path('files/' . $rel);
        $dir  = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) { $out['errors']++; continue; }
        if (@file_put_contents($dest, $blob, LOCK_EX) === false) { $out['errors']++; continue; }
        @chmod($dest, 0664);

        $mtime = (int)($f['mtime'] ?? 0);
        if ($mtime <= 0 && $r['lm'] !== '') $mtime = (int)(strtotime($r['lm']) ?: 0);
        if ($mtime <= 0 || $mtime > time() + 86400) $mtime = time();
        @touch($dest, $mtime);

        foreach (glob(data_path('thumbs/' . sha1($rel) . '_*')) ?: [] as $t) @unlink($t);

        $idx['files'][$rel] = [
            'size'  => strlen($blob),
            'psize' => $f['size'] === null ? strlen($blob) : (int)$f['size'],
            'fp'    => (string)($f['fp'] ?? ''),
            'etag'  => $r['etag'],
            'lm'    => $r['lm'],
            'mtime' => $mtime,
            'ts'    => time(),
            'sha'   => substr($sha, 0, 16),
            'kind'  => kind_of($rel),
            'dir'   => (strpos($rel, '/') === false) ? '' : dirname($rel),
        ];
        $out['downloaded']++; $out['bytes'] += strlen($blob); $n++;
    }

    if (!$out['source_ok']) {
        $out['ok'] = false;
        $out['notes'][] = 'একটি ফাইলও আনা গেল না — প্রক্সি/টার্গেট পাথ যাচাই করুন';
    }

    // এখনো কয়টা বাকি (ভিজিটে-আপডেট এটা দেখে আবার ডাকবে)
    $out['remaining'] = max(0, count($todo) - $seen);

    $m = run_retention($idx);
    index_save($idx);
    trim_thumb_cache();
    $out['pruned']      = $m['removed'];
    $out['total_files'] = count($idx['files'] ?? []);
    $out['elapsed']     = round(microtime(true) - $t0, 2);

    pull_status($out, $t0);
    return $out;
}

function pull_status(array $out, float $t0): void
{
    $st = store_read('status.json', []);
    $st['last_sync']     = time();
    $st['source_ok']     = (bool)$out['source_ok'];
    $st['scanned']       = (int)$out['scanned'];
    $st['uploaded']      = (int)($out['downloaded'] ?? 0);
    $st['skipped']       = (int)$out['skipped'] + (int)($out['unchanged'] ?? 0);
    $st['errors']        = (int)$out['errors'];
    $st['duration']      = round(microtime(true) - $t0, 1);
    $st['agent_version'] = $out['mode'] ?? 'pull';
    $st['agent_host']    = 'server-cron';
    if (!empty($out['downloaded'])) $st['last_upload'] = time();
    store_write('status.json', $st);

    agent_log(sprintf('%s scanned=%d dl=%d same=%d skip=%d err=%d src_ok=%d %.2fs',
        $out['mode'] ?? 'pull', $out['scanned'], $out['downloaded'] ?? 0,
        $out['unchanged'] ?? 0, $out['skipped'], $out['errors'],
        $out['source_ok'] ? 1 : 0, microtime(true) - $t0));
}
