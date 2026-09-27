<?php
/**
 * 🔄 ভিজিটে-আপডেট এন্ডপয়েন্ট
 * ───────────────────────────────────────────────────────────
 * কেউ সাইটে ঢুকলে ব্রাউজার এটা ডাকে, আর সার্ভার প্রক্সি দিয়ে নতুন ছবি আনে।
 * cron লাগে না — যেসব হোস্টে cron নেই বা বাইরের প্রোগ্রাম ঢুকতে দেয় না
 * (InfinityFree ইত্যাদি), সেখানে এটাই একমাত্র উপায়।
 *
 *   GET api/refresh.php          → দরকার হলে একটা ফাইল আনবে
 *   GET api/refresh.php&force=1  → সময়ের হিসাব না করেই চেষ্টা করবে
 *
 * 🔒 গোপন কী লাগে না (ব্রাউজার থেকেই ডাকা হয়), তবে অপব্যবহার ঠেকাতে:
 *    • lazy_min_interval এর মধ্যে আবার ডাকলে কিছুই করে না
 *    • flock — একসাথে দুটো রান কখনো চলবে না
 *    • এক রিকোয়েস্টে সর্বোচ্চ lazy_files টা ফাইল, lazy_budget সেকেন্ড
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/puller.php';

$budget = (int)pcfg('lazy_budget', 40);
@set_time_limit($budget + 25);
@ignore_user_abort(true);   // ব্যবহারকারী ট্যাব বন্ধ করলেও ডাউনলোড শেষ হোক

ensure_dirs();

if (!pcfg('enabled', false)) {
    json_out(['ok' => false, 'error' => 'pull_disabled',
              'msg' => 'config.php → pull.enabled = true করুন'], 409);
}
if (!pcfg('lazy', true)) {
    json_out(['ok' => false, 'error' => 'lazy_disabled',
              'msg' => 'config.php → pull.lazy = true করুন'], 409);
}

$st    = store_read('status.json', []);
$last  = (int)($st['last_sync'] ?? 0);
$age   = $last > 0 ? time() - $last : PHP_INT_MAX;
$minIv = (int)pcfg('lazy_min_interval', 240);
$force = !empty($_GET['force']);

// 🔸 কোনো টার্গেট ছবি এখনো একেবারেই আসেনি? তাহলে ৪ মিনিট অপেক্ষা করানোর
//    মানে হয় না — প্রথম ভিজিটেই যেন সবগুলো একে একে চলে আসে।
$idxNow  = store_read('index.json', ['files' => []]);
$missing = 0;
foreach ((array)pcfg('targets', []) as $t) {
    $rel = sanitize_rel((string)$t);
    if ($rel !== null && !isset($idxNow['files'][$rel])) $missing++;
}
if ($missing > 0) $minIv = 0;   // ছবি না আসা পর্যন্ত অপেক্ষা নয় (lock ও ১-ফাইল সীমা রক্ষাকবচ)

// ── সদ্য আপডেট হয়েছে? তাহলে কিছু করার দরকার নেই ──
if (!$force && $age < $minIv) {
    json_out(['ok' => true, 'action' => 'fresh', 'age' => $age,
              'next_in' => $minIv - $age, 'missing' => $missing,
              'msg' => 'ডেটা এখনো তাজা']);
}

// ── একসাথে একটার বেশি রান নয় ──
$lockFile = data_path('pull.lock');
$fp = @fopen($lockFile, 'c');
if ($fp === false) {
    json_out(['ok' => false, 'error' => 'lock_failed'], 500);
}
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    fclose($fp);
    json_out(['ok' => true, 'action' => 'running',
              'msg' => 'আরেকটি আপডেট এখন চলছে']);
}

$t0 = microtime(true);
// মরা প্রক্সি দ্রুত বাদ যাক, শেষ চেষ্টাটা পুরো সময় পাক
pull_attempt_cap((int)pcfg('lazy_attempt_timeout', 20));
try {
    $res = pull_run(false, (int)pcfg('lazy_files', 1), $budget);
} catch (Throwable $e) {
    flock($fp, LOCK_UN); fclose($fp);
    json_out(['ok' => false, 'error' => 'pull_failed', 'msg' => $e->getMessage()], 500);
}
flock($fp, LOCK_UN);
fclose($fp);

json_out([
    'ok'         => (bool)($res['ok'] ?? false),
    'action'     => 'done',
    'downloaded' => (int)($res['downloaded'] ?? 0),
    'unchanged'  => (int)($res['unchanged'] ?? 0),
    'errors'     => (int)($res['errors'] ?? 0),
    'remaining'  => (int)($res['remaining'] ?? 0),   // >0 হলে ব্রাউজার আবার ডাকবে
    'missing'    => $missing,                        // এখনো যতগুলো ছবি একেবারেই আসেনি
    'bytes'      => (int)($res['bytes'] ?? 0),
    'source_ok'  => (bool)($res['source_ok'] ?? false),
    'proxy'      => (string)(pool_load()['current'] ?? ''),
    'elapsed'    => round(microtime(true) - $t0, 1),
    'notes'      => array_slice((array)($res['notes'] ?? []), 0, 3),
]);
