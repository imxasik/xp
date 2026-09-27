<?php
/**
 * ফ্রি প্রক্সি পুল ম্যানেজার
 *   https://yourdomain.com/wx/proxies.php?key=CRON_KEY
 *
 *   &do=refresh   পাবলিক তালিকা থেকে নতুন BD প্রক্সি আনবে
 *   &do=test      সবগুলো যাচাই করবে (সমান্তরালে)
 *   &do=test&n=40 শুধু সেরা ৪০টা যাচাই করবে (দ্রুত)
 *   &do=reset     পুল মুছে বীজ-তালিকা থেকে নতুন করে শুরু
 *   &json=1       JSON আউটপুট
 */
declare(strict_types=1);

@set_time_limit(300);
require_once __DIR__ . '/lib/puller.php';

ensure_dirs();

$key = (string)pcfg('cron_key', '');
if ($key === '' || str_starts_with($key, 'CHANGE_ME')) json_out(['ok' => false, 'error' => 'cron_key_not_set'], 500);
if (!hash_equals($key, (string)($_GET['key'] ?? ''))) json_out(['ok' => false, 'error' => 'unauthorized'], 401);

$do     = (string)($_GET['do'] ?? '');
$action = null;

if ($do === 'refresh') {
    $action = ['refresh', pool_refresh(true)];
} elseif ($do === 'reset') {
    store_write('proxies.json', []);
    pool_load();
    $action = ['reset', ['ok' => true]];
} elseif ($do === 'test') {
    $n      = (int)($_GET['n'] ?? 0);
    $subset = $n > 0 ? pool_best($n) : null;
    $action = ['test', pool_test($subset)];
}

$d     = pool_load();
$list  = $d['list'] ?? [];
$best  = pool_best(100);
$alive = 0;
foreach ($list as $e) if (($e['last_ok'] ?? 0) > time() - 21600) $alive++;

if (!empty($_GET['json'])) {
    json_out(['ok' => true, 'total' => count($list), 'alive_6h' => $alive,
              'current' => $d['current'] ?? '', 'updated' => $d['updated'] ?? 0,
              'tested' => $d['tested'] ?? 0, 'action' => $action, 'best' => array_slice($best, 0, 20)]);
}

$k = rawurlencode($key);
$ago = function (int $t): string {
    if ($t <= 0) return '—';
    $s = time() - $t;
    if ($s < 60) return 'এইমাত্র';
    if ($s < 3600) return intdiv($s, 60) . ' মিনিট আগে';
    if ($s < 86400) return intdiv($s, 3600) . ' ঘণ্টা আগে';
    return intdiv($s, 86400) . ' দিন আগে';
};
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html><html lang="bn"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>প্রক্সি পুল</title>
<style>
 :root{--bg:#0a0e14;--card:#121a25;--line:#1f2b3a;--tx:#e8eef7;--tx2:#93a4bb;--tx3:#61748d;
       --good:#34d399;--bad:#f87171;--warn:#fbbf24;--acc:#2dd4bf}
 *{box-sizing:border-box}
 body{margin:0;background:var(--bg);color:var(--tx);font:14px/1.55 system-ui,-apple-system,"Noto Sans Bengali",sans-serif;padding:16px}
 .box{max-width:840px;margin:auto}
 h1{font-size:19px;margin:0 0 3px}
 .sub{color:var(--tx3);font-size:12.5px;margin:0 0 16px}
 .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:9px;margin-bottom:14px}
 .stat{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:11px 13px}
 .stat b{display:block;font-size:21px;line-height:1.2}
 .stat span{font-size:11px;color:var(--tx3)}
 .btns{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
 .btn{background:var(--card);border:1px solid var(--line);color:var(--tx);padding:9px 15px;
      border-radius:10px;text-decoration:none;font-size:13px;font-weight:600;display:inline-block}
 .btn.p{background:linear-gradient(140deg,#2dd4bf,#38bdf8);color:#04211f;border:0}
 .btn:active{transform:scale(.97)}
 .note{background:#0c2f26;color:#6ee7b7;padding:11px 13px;border-radius:11px;margin-bottom:14px;font-size:13px}
 .note.w{background:#3a2c10;color:#fcd34d}
 table{width:100%;border-collapse:collapse;background:var(--card);border-radius:12px;overflow:hidden;font-size:12.5px}
 th{text-align:left;padding:9px 10px;color:var(--tx3);font-size:11px;text-transform:uppercase;border-bottom:1px solid var(--line)}
 td{padding:8px 10px;border-bottom:1px solid var(--line);vertical-align:middle}
 tr:last-child td{border:0}
 .u{font-family:ui-monospace,monospace;font-size:11.5px;word-break:break-all}
 .pill{display:inline-block;padding:2px 8px;border-radius:99px;font-size:10.5px;font-weight:700}
 .live{background:rgba(52,211,153,.16);color:var(--good)}
 .dead{background:rgba(248,113,113,.14);color:var(--bad)}
 .unk{background:rgba(148,163,184,.14);color:var(--tx3)}
 .cur{background:rgba(45,212,191,.2);color:var(--acc)}
 code{background:#0e1620;padding:2px 6px;border-radius:5px;font-size:11.5px}
 .foot{color:var(--tx3);font-size:11.5px;margin-top:16px;line-height:1.8}
</style></head><body><div class="box">

<h1>🔀 ফ্রি প্রক্সি পুল</h1>
<p class="sub">লক্ষ্য: <?= htmlspecialchars((string)cfg('source_url')) ?></p>

<?php if ($action): [$what, $res] = $action; ?>
  <div class="note <?= (!empty($res['working']) || !empty($res['added']) || !empty($res['ok'])) ? '' : 'w' ?>">
  <?php if ($what === 'refresh'): ?>
    🔄 রিফ্রেশ: <?= (int)($res['sources_ok'] ?? 0) ?>টি উৎস থেকে <?= (int)($res['seen'] ?? 0) ?>টি দেখা,
    <b><?= (int)($res['added'] ?? 0) ?>টি নতুন বাংলাদেশি প্রক্সি যোগ</b> হয়েছে (<?= (int)($res['non_bd'] ?? 0) ?>টি বিদেশি বাদ)। মোট <?= (int)($res['total'] ?? 0) ?>টি।
  <?php elseif ($what === 'test'): ?>
    🧪 যাচাই: <?= (int)($res['tested'] ?? 0) ?>টির মধ্যে <b><?= (int)($res['working'] ?? 0) ?>টি কাজ করছে</b>।
  <?php else: ?>
    ♻️ পুল রিসেট হয়েছে — বীজ-তালিকা থেকে নতুন করে শুরু।
  <?php endif; ?>
  </div>
<?php endif; ?>

<div class="stats">
  <div class="stat"><b><?= count($list) ?></b><span>মোট প্রক্সি</span></div>
  <div class="stat"><b style="color:var(--good)"><?= $alive ?></b><span>৬ ঘণ্টায় সচল</span></div>
  <div class="stat"><b style="font-size:13px"><?= $ago((int)($d['updated'] ?? 0)) ?></b><span>তালিকা আনা</span></div>
  <div class="stat"><b style="font-size:13px"><?= $ago((int)($d['tested'] ?? 0)) ?></b><span>শেষ যাচাই</span></div>
</div>

<div class="btns">
  <a class="btn p" href="?key=<?= $k ?>&do=test&n=40">🧪 সেরা ৪০টি যাচাই (দ্রুত)</a>
  <a class="btn" href="?key=<?= $k ?>&do=test">🧪 সব যাচাই (ধীর)</a>
  <a class="btn" href="?key=<?= $k ?>&do=refresh">🔄 নতুন তালিকা আনো</a>
  <a class="btn" href="?key=<?= $k ?>&do=reset">♻️ রিসেট</a>
  <a class="btn" href="fetch.php?key=<?= $k ?>&probe=1">🔍 probe</a>
</div>

<table>
<tr><th>#</th><th>প্রক্সি</th><th>অবস্থা</th><th>স্কোর</th><th>গতি</th><th>শেষ সফল</th></tr>
<?php $i = 0; foreach (array_slice($best, 0, 60) as $u):
    $e = $list[$u] ?? []; $i++;
    $lastOk = (int)($e['last_ok'] ?? 0);
    $isCur  = ($d['current'] ?? '') === $u;
    if ($lastOk > time() - 21600)      { $cls = 'live'; $txt = 'সচল'; }
    elseif (($e['fail'] ?? 0) > 0)     { $cls = 'dead'; $txt = 'ব্যর্থ'; }
    else                               { $cls = 'unk';  $txt = 'অজানা'; }
?>
<tr>
  <td><?= $i ?></td>
  <td class="u"><?= htmlspecialchars($u) ?><?= $isCur ? ' <span class="pill cur">এখন ব্যবহৃত</span>' : '' ?></td>
  <td><span class="pill <?= $cls ?>"><?= $txt ?></span></td>
  <td><?= (int)($e['score'] ?? 0) ?></td>
  <td><?= !empty($e['ms']) ? round($e['ms'] / 1000, 1) . 's' : '—' ?></td>
  <td><?= $ago($lastOk) ?></td>
</tr>
<?php endforeach; ?>
</table>

<div class="foot">
  🔒 এই পাতাটা গোপন — URL কাউকে দেবেন না।<br>
  ⚙️ cron চলার সময় পুল নিজে থেকেই ব্যবস্থাপনা করে; এখানে আসার দরকার শুধু সমস্যা হলে।<br>
  💡 "৬ ঘণ্টায় সচল" শূন্য হলে <code>🔄 নতুন তালিকা</code> → <code>🧪 যাচাই</code> চাপুন।
</div>
</div></body></html>
