<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/common.php';
ensure_dirs();
$v = max(@filemtime(__DIR__ . '/assets/style.css') ?: 1, @filemtime(__DIR__ . '/assets/app.js') ?: 1);  // ফাইল বদলালেই নম্বর বদলায় → ব্রাউজার পুরোনো ক্যাশ ছেড়ে দেয়
$title = htmlspecialchars((string)cfg('site_title'), ENT_QUOTES);
$sub   = htmlspecialchars((string)cfg('site_subtitle'), ENT_QUOTES);
$theme = cfg('default_theme', 'dark') === 'light' ? 'light' : 'dark';
?><!DOCTYPE html>
<html lang="bn" data-theme="<?= $theme ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0b1017">
<meta name="description" content="<?= $sub ?>">
<meta name="robots" content="noindex, nofollow">
<title><?= $title ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><text y='26' font-size='26'>🛰️</text></svg>">
<link rel="stylesheet" href="assets/style.css?v=<?= $v ?>">
</head>
<body>

<div id="toast" class="toast" role="status" aria-live="polite"></div>

<header class="topbar">
  <div class="wrap topbar-in">
    <div class="brand">
      <div class="brand-mark" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <path d="M17.5 19a4.5 4.5 0 0 0 .5-8.97A6.5 6.5 0 0 0 5.2 11.2 3.9 3.9 0 0 0 6 19z"/>
          <path d="M8 22.5l1-2M12 22.5l1-2M16 22.5l1-2"/>
        </svg>
      </div>
      <div class="brand-txt">
        <h1><?= $title ?></h1>
        <p><?= $sub ?></p>
      </div>
    </div>

    <div class="topbar-actions">
      <button class="icon-btn" id="btnLang" title="Language" aria-label="Language"><span id="langLabel">বাং</span></button>
      <button class="icon-btn" id="btnTheme" title="Theme" aria-label="Theme">
        <svg class="i-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
        <svg class="i-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
      </button>
      <button class="icon-btn" id="btnRefresh" title="Refresh" aria-label="Refresh">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-2.6-6.4"/><path d="M21 4v5h-5"/></svg>
      </button>
    </div>
  </div>

  <div class="wrap statusline">
    <span class="dot" id="syncDot"></span>
    <span id="syncText" data-i18n="loading">লোড হচ্ছে…</span>
    <span class="sep">•</span>
    <span id="countText">—</span>
  </div>
</header>

<main class="wrap">

  <section class="controls">
    <div class="searchbox">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.2-3.2"/></svg>
      <input type="search" id="q" placeholder="ফাইল খুঁজুন…" autocomplete="off" spellcheck="false" enterkeyhint="search">
      <button class="clear" id="qClear" aria-label="Clear" hidden>&times;</button>
    </div>

    <div class="chiprow" id="kindChips" role="tablist"></div>
    <div class="chiprow" id="dirChips"></div>

    <div class="toolrow">
      <div class="seg" id="sortSeg">
        <button data-sort="new" class="on" data-i18n="newest">নতুন আগে</button>
        <button data-sort="old" data-i18n="oldest">পুরোনো আগে</button>
        <button data-sort="name" data-i18n="name">নাম</button>
      </div>
      <div class="seg" id="viewSeg">
        <button data-view="grid" class="on" aria-label="Grid">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.5"/></svg>
        </button>
        <button data-view="list" aria-label="List">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
      </div>
    </div>
  </section>

  <section id="results" class="grid" aria-live="polite"></section>

  <div id="sentinel" class="sentinel"></div>
  <div id="empty" class="empty" hidden>
    <div class="empty-ico">🛰️</div>
    <h3 data-i18n="emptyTitle">কোনো ফাইল পাওয়া যায়নি</h3>
    <p data-i18n="emptyBody">এজেন্ট এখনো কিছু sync করেনি, অথবা ফিল্টারে কিছু মেলেনি।</p>
  </div>

  <footer class="foot">
    <p><span data-i18n="footMirror">এটি একটি রিড-অনলি মিরর।</span>
       <a href="<?= htmlspecialchars((string)cfg('source_url'), ENT_QUOTES) ?>" target="_blank" rel="noopener">মূল উৎস</a></p>
    <p class="muted" id="footStats"></p>
  </footer>
</main>

<!-- Lightbox -->
<div class="lb" id="lb" hidden>
  <div class="lb-top">
    <div class="lb-meta">
      <strong id="lbName"></strong>
      <span id="lbInfo"></span>
    </div>
    <div class="lb-btns">
      <a class="icon-btn ghost" id="lbDl" href="#" download title="Download">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="M7 11l5 5 5-5"/><path d="M4 21h16"/></svg>
      </a>
      <button class="icon-btn ghost" id="lbClose" title="Close" aria-label="Close">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
      </button>
    </div>
  </div>
  <div class="lb-stage" id="lbStage">
    <button class="lb-nav prev" id="lbPrev" aria-label="Previous">&#10094;</button>
    <img id="lbImg" alt="">
    <button class="lb-nav next" id="lbNext" aria-label="Next">&#10095;</button>
  </div>
  <div class="lb-count" id="lbCount"></div>
</div>

<script src="assets/app.js?v=<?= $v ?>"></script>
</body>
</html>
