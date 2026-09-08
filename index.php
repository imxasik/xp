<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/lib/store.php';
require_once __DIR__ . '/lib/seed.php';
xp_seed_if_empty();
$s = xp_settings();
?>
<!doctype html>
<html lang="bn">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title><?= htmlspecialchars($s['site_name'] ?? 'XP Telecom') ?></title>
  <link rel="stylesheet" href="assets/css/app.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&family=Noto+Sans+Bengali:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body>
  <div class="app">
    <header class="top">
      <div class="brand"><div class="logo">XP</div><div><div id="bname">XP Telecom</div><div class="muted" style="font-size:11px">Telecom Store</div></div></div>
      <a class="muted" href="admin.php">অ্যাডমিন</a>
    </header>
    <aside class="side desk">
      <button class="on" data-v="home">হোম</button>
      <button data-v="offers">অফার</button>
      <button data-v="recharge">রিচার্জ</button>
      <button data-v="wallet">ওয়ালেট</button>
      <button data-v="account">অ্যাকাউন্ট</button>
    </aside>
    <div class="main" id="main"></div>
  </div>
  <nav class="nav">
    <button class="on" data-v="home">হোম</button>
    <button data-v="offers">অফার</button>
    <button data-v="recharge">রিচার্জ</button>
    <button data-v="wallet">ওয়ালেট</button>
    <button data-v="account">আমি</button>
  </nav>
  <div class="modal" id="modal"><div class="sheet"></div></div>
  <div class="toast" id="toast"></div>
  <script src="assets/js/app.js"></script>
</body>
</html>
