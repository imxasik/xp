<?php
/**
 * স্ট্যাটিক সাইট বানানোর স্ক্রিপ্ট — GitHub Actions এর জন্য
 * ─────────────────────────────────────────────────────────────
 * ১) প্রক্সি দিয়ে BAF থেকে ছবি নামায় (যতক্ষণ না একটা প্রক্সি কাজ করে)
 * ২) dist/ ফোল্ডারে সম্পূর্ণ স্ট্যাটিক সাইট তৈরি করে:
 *        index.html · list.json · assets/ · files/
 * ৩) GitHub Pages সেটাই পরিবেশন করে — PHP লাগে না, CDN বলে খুব দ্রুত
 *
 * চালানো:  php deploy/build-static.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/server/lib/puller.php';

$DIST  = getenv('DIST_DIR') ?: ($root . '/dist');
$TRIES = max(1, (int)(getenv('BUILD_TRIES') ?: 4));   // কয় দফা চেষ্টা
$log   = fn(string $m) => fwrite(STDERR, '  ' . $m . "\n");

ensure_dirs();

/* ── ১) পুল তাজা করা ───────────────────────────────────────── */
$pd = pool_load();
if ((time() - (int)($pd['updated'] ?? 0)) > 21600) {
    $r = pool_refresh(true);
    $log(sprintf('পুল: %d উৎস, %d বাংলাদেশি প্রক্সি (মোট %d)',
        $r['sources_ok'] ?? 0, $r['added'] ?? 0, $r['total'] ?? 0));
}

/* ── ২) ছবি আনা — GitHub runner-এ সময়ের কড়াকড়ি নেই ───────── */
$got = 0;
for ($i = 1; $i <= $TRIES; $i++) {
    pull_attempt_cap(25);
    $res = pull_run(false, (int)pcfg('max_files', 40), 180);
    $got += (int)($res['downloaded'] ?? 0);

    $log(sprintf('দফা %d/%d → নামল %d · অপরিবর্তিত %d · এরর %d · %s bytes · %ss · %s',
        $i, $TRIES, $res['downloaded'] ?? 0, $res['unchanged'] ?? 0, $res['errors'] ?? 0,
        number_format((int)($res['bytes'] ?? 0)), $res['elapsed'] ?? '?',
        pool_load()['current'] ?: '—'));

    // সব ফাইল হাতে এসে গেছে?
    $idx = index_load();
    $missing = 0;
    foreach ((array)pcfg('targets', []) as $t) {
        $rel = sanitize_rel((string)$t);
        if ($rel !== null && !isset($idx['files'][$rel])) $missing++;
    }
    if ($missing === 0 && ($res['errors'] ?? 0) === 0) break;

    if ($i < $TRIES) { $log('আবার চেষ্টা…'); sleep(5); }
}

/* ── ৩) dist/ তৈরি ─────────────────────────────────────────── */
$idx = index_load();
if (empty($idx['files'])) {
    fwrite(STDERR, "❌ একটাও ছবি নেই — বিল্ড বাতিল (পুরোনো সাইটটাই থাকবে)\n");
    exit(1);
}

@mkdir($DIST . '/assets', 0775, true);
@mkdir($DIST . '/files', 0775, true);

// assets
foreach (glob($root . '/server/assets/*') ?: [] as $f) {
    copy($f, $DIST . '/assets/' . basename($f));
}

// ছবি
$copied = 0;
foreach ($idx['files'] as $rel => $_) {
    $src = data_path('files/' . $rel);
    if (!is_file($src)) continue;
    $dst = $DIST . '/files/' . $rel;
    if (!is_dir(dirname($dst))) @mkdir(dirname($dst), 0775, true);
    if (copy($src, $dst)) $copied++;
}

// list.json — api/list.php এর মতোই গঠন।
// ⚠️ require করা যাবে না: json_out() শেষে exit করে, তাহলে এই স্ক্রিপ্টও থেমে যাবে।
//    তাই আলাদা প্রসেসে চালিয়ে আউটপুট নিই।
$json = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' '
                         . escapeshellarg($root . '/server/api/list.php') . ' 2>/dev/null');
$data = json_decode($json, true) ?: [];
if (empty($data['ok'])) {
    fwrite(STDERR, "❌ list.json বানানো গেল না\n");
    exit(1);
}
$data['static']  = true;
$data['builtAt'] = time();
file_put_contents($DIST . '/list.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

// index.html — PHP টেমপ্লেট রেন্ডার করে স্ট্যাটিক বানাই (এটি exit করে না)
$html = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' '
                         . escapeshellarg($root . '/server/index.php') . ' 2>/dev/null');
$html = str_replace('</head>', "<script>window.__STATIC__=true;</script>\n</head>", $html);
file_put_contents($DIST . '/index.html', $html);

// GitHub Pages যেন Jekyll দিয়ে প্রসেস না করে
file_put_contents($DIST . '/.nojekyll', '');

$bytes = 0;
foreach ($idx['files'] as $rel => $f) $bytes += (int)($f['size'] ?? 0);
$log(sprintf('✅ dist/ তৈরি — %d ছবি, %s, index.html %s bytes',
    $copied, human_size($bytes), number_format(strlen($html))));
