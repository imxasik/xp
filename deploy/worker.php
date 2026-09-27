<?php
/**
 * পটভূমির কর্মী — cron ছাড়াই নিজে থেকে ছবি আনতে থাকে।
 *
 * InfinityFree-এ এটা সম্ভব ছিল না (সেখানে প্রতি রিকোয়েস্টে ৫০ সেকেন্ড সীমা,
 * আর বাইরের কেউ সাইট ডাকতে পারে না)। Koyeb-এ কনটেইনার সবসময় চালু থাকে,
 * তাই এই লুপটাই cron-এর কাজ করে — কিন্তু অনেক বেশি নির্ভরযোগ্যভাবে:
 * একটা কাজ করা প্রক্সি না পাওয়া পর্যন্ত চেষ্টা করে যায়।
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/puller.php';

$every = max(60, (int)(getenv('REFRESH_SECONDS') ?: 300));
$log   = fn(string $m) => fwrite(STDERR, '[worker] ' . date('H:i:s') . ' ' . $m . "\n");

$log("শুরু — প্রতি {$every} সেকেন্ডে আপডেট");
ensure_dirs();

// প্রথম রানে পুল একবার তাজা করে নিই
if ((int)(pool_load()['updated'] ?? 0) === 0) {
    $r = pool_refresh(true);
    $log(sprintf('পুল রিফ্রেশ: %d উৎস, %d বাংলাদেশি প্রক্সি', $r['sources_ok'] ?? 0, $r['added'] ?? 0));
}

$fails = 0;

while (true) {
    $t0 = microtime(true);

    try {
        // ⭐ এখানে সময়ের কড়াকড়ি নেই — তাই অনেকগুলো প্রক্সি চেষ্টা করতে পারি
        $res = pull_run(false, (int)pcfg('max_files', 40), 240);

        $log(sprintf('নামল %d · অপরিবর্তিত %d · এরর %d · %s bytes · %.0fs · %s',
            $res['downloaded'] ?? 0, $res['unchanged'] ?? 0, $res['errors'] ?? 0,
            number_format((int)($res['bytes'] ?? 0)), microtime(true) - $t0,
            pool_load()['current'] ?: '—'));

        $ok = ($res['downloaded'] ?? 0) > 0 || ($res['unchanged'] ?? 0) > 0;
        $fails = $ok ? 0 : $fails + 1;

        // পরপর ৩ বার ব্যর্থ → পুলে নতুন প্রক্সি আনি
        if ($fails >= 3) {
            $r = pool_refresh(true);
            $log(sprintf('পরপর %d বার ব্যর্থ → নতুন তালিকা আনলাম (%d যোগ)', $fails, $r['added'] ?? 0));
            $fails = 0;
        }
    } catch (Throwable $e) {
        $log('ত্রুটি: ' . $e->getMessage());
        $fails++;
    }

    $sleep = max(20, $every - (int)(microtime(true) - $t0));
    sleep($sleep);
}
