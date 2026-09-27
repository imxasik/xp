<?php
/**
 * পাবলিক API — মিরর করা ফাইলের তালিকা + sync স্ট্যাটাস।
 *   GET api/list.php
 *   GET api/list.php?since=<unix>   (শুধু নতুন এন্ট্রি)
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/common.php';

header('Access-Control-Allow-Origin: *');

$idx    = store_read('index.json', ['files' => [], 'updated' => 0]);
$status = store_read('status.json', []);

$since = isset($_GET['since']) ? (int)$_GET['since'] : 0;

$files = [];
$dirs  = [];
$kinds = [];
$bytes = 0;
$newest = 0;

foreach (($idx['files'] ?? []) as $rel => $f) {
    $mtime = (int)($f['mtime'] ?? 0);
    $ts    = (int)($f['ts'] ?? $mtime);
    $size  = (int)($f['size'] ?? 0);
    $dir   = (string)($f['dir'] ?? '');
    $kind  = (string)($f['kind'] ?? kind_of($rel));

    $bytes += $size;
    $newest = max($newest, $mtime);
    $dirs[$dir === '' ? '/' : $dir] = ($dirs[$dir === '' ? '/' : $dir] ?? 0) + 1;
    $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;

    if ($since > 0 && $ts <= $since) continue;

    $files[] = [
        'p' => $rel,                              // relative path
        'n' => basename($rel),                    // file name
        'd' => $dir,                              // directory
        'k' => $kind,                             // kind
        's' => $size,                             // bytes
        'm' => $mtime,                            // source modified time
        't' => $ts,                               // mirrored at
    ];
}

usort($files, fn($a, $b) => $b['m'] <=> $a['m'] ?: strcmp($b['n'], $a['n']));
arsort($dirs);

$lastSync = (int)($status['last_sync'] ?? 0);
$stale    = $lastSync === 0 || (time() - $lastSync) > ((int)cfg('stale_minutes', 45) * 60);

json_out([
    'ok'     => true,
    'now'    => time(),
    'tz'     => cfg('timezone'),
    'site'   => [
        'title'     => cfg('site_title'),
        'subtitle'  => cfg('site_subtitle'),
        'source'    => cfg('source_url'),
        'lang'      => cfg('default_lang'),
        'theme'     => cfg('default_theme'),
        'pageSize'  => (int)cfg('page_size', 60),
        'retention' => (int)cfg('retention_days', 0),
    ],
    'sync'   => [
        'lastSync'   => $lastSync,
        'lastUpload' => (int)($status['last_upload'] ?? 0),
        'stale'      => $stale,
        'sourceOk'   => (bool)($status['source_ok'] ?? false),
        'uploaded'   => (int)($status['uploaded'] ?? 0),
        'scanned'    => (int)($status['scanned'] ?? 0),
        'errors'     => (int)($status['errors'] ?? 0),
        'agent'      => (string)($status['agent_version'] ?? ''),
        // ভিজিটে-আপডেট চালু কিনা — ফ্রন্টএন্ড এটা দেখে refresh.php ডাকবে
        'lazy'       => (bool)(cfg('pull', [])['enabled'] ?? false)
                        && (bool)(cfg('pull', [])['lazy'] ?? false),
        'lazyMinAge' => (int)(cfg('pull', [])['lazy_min_interval'] ?? 240),
    ],
    'stats'  => [
        'count'  => count($idx['files'] ?? []),
        'bytes'  => $bytes,
        'newest' => $newest,
        'dirs'   => $dirs,
        'kinds'  => $kinds,
    ],
    'files'  => $files,
]);
