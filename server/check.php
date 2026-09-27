<?php
/**
 * ইনস্টলেশন হেলথ-চেক। ডিপ্লয় করার পর একবার ব্রাউজারে খুলুন:
 *     https://yourdomain.com/wx/check.php
 * সব সবুজ হলে ফাইলটি ডিলিট/রিনেম করে দিন।
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/common.php';
ensure_dirs();

$rows = [];
/** @param bool|null $ok  null = তথ্যমূলক (লাল/সবুজ কিছুই নয়) */
$add = function (string $name, ?bool $ok, string $note = '') use (&$rows) {
    $rows[] = [$name, $ok, $note];
};

$pullOn = (bool)(cfg('pull', [])['enabled'] ?? false);

$add('PHP >= 8.0', version_compare(PHP_VERSION, '8.0.0', '>='), PHP_VERSION);
$add('JSON extension', function_exists('json_encode'));

// ⭐ PULL মোডের প্রাণভোমরা — অনেক ফ্রি হোস্ট এটা ইচ্ছাকৃতভাবে বন্ধ রাখে
$hasCurl = function_exists('curl_init');
$cv      = $hasCurl ? (curl_version()['version'] ?? '?') : '';
$add('cURL extension' . ($pullOn ? ' ⭐' : ''), $hasCurl,
     $hasCurl ? ('v' . $cv . ' — SOCKS5 প্রক্সি ব্যবহার করা যাবে')
              : 'নেই! PULL মোড চলবে না। hostcheck.php চালিয়ে দেখুন');
$add('allow_url_fopen', (bool)ini_get('allow_url_fopen'),
     ini_get('allow_url_fopen') ? 'চালু' : 'বন্ধ (cURL থাকলে সমস্যা নেই)');
$add('GD (থাম্বনেইল)', function_exists('imagecreatetruecolor'),
     function_exists('imagecreatetruecolor') ? 'ok' : 'না থাকলেও চলবে, শুধু থাম্ব বন্ধ থাকবে');
$add('data/ লেখা যায়', is_writable(data_path()), data_path());
$add('data/files/ লেখা যায়', is_writable(data_path('files')));
$add('shared_secret সেট করা', !str_starts_with((string)cfg('shared_secret'), 'CHANGE_ME'),
     'config.php-তে বদলান');
$add('secret যথেষ্ট লম্বা', strlen((string)cfg('shared_secret')) >= 32);

$pmax = (int)ini_get('post_max_size');
$umax = (int)ini_get('upload_max_filesize');
$need = (int)cfg('max_upload_mb');
// PULL মোডে ফাইল আপলোড হয় না, তাই এগুলো তখন শুধু তথ্য — লাল দেখানোর মানে নেই
$add('upload_max_filesize', $pullOn ? null : ($umax >= $need),
     $umax . 'M' . ($pullOn ? ' — PULL মোডে দরকার নেই' : ' (দরকার ' . $need . 'M)'));
$add('post_max_size', $pullOn ? null : ($pmax >= $need),
     $pmax . 'M' . ($pullOn ? ' — PULL মোডে দরকার নেই' : ''));
$add('PULL মোড', $pullOn ? true : null,
     $pullOn ? ('চালু · প্রক্সি: ' . (cfg('pull', [])['proxy'] ?: 'সরাসরি')) : 'বন্ধ (PUSH মোড চলছে)');

$idx = store_read('index.json', ['files' => []]);
$st  = store_read('status.json', []);

// data/ ডাইরেক্ট অ্যাক্সেস হয় কিনা
$selfBase = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');

$okAll = !in_array(false, array_map(fn($r) => $r[1], $rows), true);
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mirror health check</title>
<style>
 body{font:15px/1.6 system-ui,sans-serif;background:#0b1017;color:#e6edf6;margin:0;padding:22px}
 .box{max-width:680px;margin:auto}
 h1{font-size:19px;margin:0 0 4px} p.m{color:#7f92a8;margin:0 0 18px;font-size:13px}
 table{width:100%;border-collapse:collapse;background:#121a25;border-radius:12px;overflow:hidden}
 td{padding:10px 12px;border-bottom:1px solid #1f2b3a;font-size:13.5px;vertical-align:top}
 tr:last-child td{border:0}
 .ok{color:#34d399;font-weight:700} .inf{color:#93a4bb}
    .no{color:#f87171;font-weight:700}
 .n{color:#7f92a8;font-size:12px}
 .sum{margin:16px 0;padding:12px 14px;border-radius:12px;font-weight:600}
 .sum.g{background:#0c2f26;color:#6ee7b7} .sum.b{background:#3a1416;color:#fca5a5}
 code{background:#0e1620;padding:2px 6px;border-radius:5px;font-size:12px}
</style></head><body><div class="box">
<h1>🛰️ Mirror health check</h1>
<p class="m"><?= htmlspecialchars($selfBase) ?></p>

<div class="sum <?= $okAll ? 'g' : 'b' ?>">
  <?= $okAll ? '✅ সব ঠিক আছে — এখন agent চালু করুন।' : '⚠️ নিচের লাল আইটেমগুলো ঠিক করুন।' ?>
</div>

<table>
<?php foreach ($rows as [$n, $ok, $note]): ?>
  <tr><td><?= htmlspecialchars($n) ?></td>
      <td style="width:60px"><span class="<?= $ok === null ? 'inf' : ($ok ? 'ok' : 'no') ?>"><?= $ok === null ? 'ℹ' : ($ok ? '✔' : '✘') ?></span></td>
      <td class="n"><?= htmlspecialchars($note) ?></td></tr>
<?php endforeach; ?>
  <tr><td>মিরর করা ফাইল</td><td colspan="2"><?= count($idx['files'] ?? []) ?></td></tr>
  <tr><td>শেষ sync</td><td colspan="2"><?= !empty($st['last_sync']) ? date('d M Y, h:i A', (int)$st['last_sync']) : 'কখনো হয়নি' ?></td></tr>
  <tr><td>Agent IP</td><td colspan="2"><?= htmlspecialchars((string)($st['agent_ip'] ?? '—')) ?></td></tr>
</table>

<p class="m" style="margin-top:16px">
  Agent config-এ বসান →<br>
  <code>ingest_url = <?= htmlspecialchars($selfBase) ?>/ingest.php</code>
</p>
<p class="m">সব ঠিক থাকলে নিরাপত্তার জন্য এই <code>check.php</code> ফাইলটি মুছে দিন।</p>
</div></body></html>
