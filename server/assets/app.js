/* ==========================================================================
   BAF WX Mirror — front-end
   ========================================================================== */
(() => {
'use strict';

const $  = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
const LS = {
  get: (k, d) => { try { return localStorage.getItem('bafwx.' + k) ?? d; } catch { return d; } },
  set: (k, v) => { try { localStorage.setItem('bafwx.' + k, v); } catch {} },
};

/* ── i18n ──────────────────────────────────────────────────── */
const I18N = {
  bn: {
    loading:'লোড হচ্ছে…', newest:'নতুন আগে', oldest:'পুরোনো আগে', name:'নাম',
    search:'ফাইল খুঁজুন…', all:'সব', images:'ছবি', pdf:'পিডিএফ', text:'টেক্সট',
    archive:'আর্কাইভ', doc:'ডকুমেন্ট', other:'অন্যান্য', root:'মূল ফোল্ডার',
    emptyTitle:'কোনো ফাইল পাওয়া যায়নি',
    emptyBody:'এজেন্ট এখনো কিছু sync করেনি, অথবা ফিল্টারে কিছু মেলেনি।',
    footMirror:'এটি একটি রিড-অনলি মিরর।',
    live:'সিংক সচল', stale:'সিংক পুরোনো', down:'সিংক বন্ধ', never:'কখনো সিংক হয়নি',
    files:'ফাইল', updated:'হালনাগাদ', now:'এইমাত্র',
    m:'মিনিট আগে', h:'ঘণ্টা আগে', d:'দিন আগে',
    refreshed:'হালনাগাদ হয়েছে', failed:'লোড করা যায়নি', updating:'আপডেট হচ্ছে…', firstFetch:'প্রথমবার ছবি আনা হচ্ছে, একটু সময় লাগবে…', newFiles:'টি নতুন ফাইল',
    download:'ডাউনলোড', retention:'দিন পর্যন্ত রাখা হয়',
  },
  en: {
    loading:'Loading…', newest:'Newest', oldest:'Oldest', name:'Name',
    search:'Search files…', all:'All', images:'Images', pdf:'PDF', text:'Text',
    archive:'Archive', doc:'Docs', other:'Other', root:'Root',
    emptyTitle:'No files found',
    emptyBody:'The agent has not synced anything yet, or no file matches your filter.',
    footMirror:'Read-only mirror.',
    live:'Sync live', stale:'Sync stale', down:'Sync down', never:'Never synced',
    files:'files', updated:'updated', now:'just now',
    m:'min ago', h:'h ago', d:'d ago',
    refreshed:'Refreshed', failed:'Could not load', updating:'Updating…', firstFetch:'Fetching images for the first time…', newFiles:' new file(s)',
    download:'Download', retention:'days retained',
  },
};
let lang = LS.get('lang', document.documentElement.lang === 'en' ? 'en' : 'bn');
const t = k => (I18N[lang] && I18N[lang][k]) || I18N.en[k] || k;

/* ── state ─────────────────────────────────────────────────── */
const S = {
  files: [], stats: null, sync: null, site: null,
  q: '', kind: 'all', dir: 'all', sort: 'new', view: LS.get('view', 'grid'),
  shown: 0, pageSize: 60, visible: [], seen: new Set(), first: true,
};

const KIND_ICON = { image:'🖼️', pdf:'📕', text:'📄', archive:'🗜️', doc:'📘', other:'📁' };

/* ── helpers ───────────────────────────────────────────────── */
function fmtSize(b){
  if (!b) return '0 B';
  const u = ['B','KB','MB','GB']; let i = 0, v = b;
  while (v >= 1024 && i < 3) { v /= 1024; i++; }
  return (i === 0 ? v | 0 : v.toFixed(v < 10 ? 1 : 0)) + ' ' + u[i];
}
function ago(ts, now){
  if (!ts) return '—';
  const s = Math.max(0, (now || Date.now()/1000) - ts);
  if (s < 60)    return t('now');
  if (s < 3600)  return Math.floor(s/60) + ' ' + t('m');
  if (s < 86400) return Math.floor(s/3600) + ' ' + t('h');
  return Math.floor(s/86400) + ' ' + t('d');
}
function clock(ts){
  if (!ts) return '—';
  try {
    return new Date(ts*1000).toLocaleString(lang === 'bn' ? 'bn-BD' : 'en-GB', {
      timeZone: (S.site && S.site.tzName) || 'Asia/Dhaka',
      day:'2-digit', month:'short', hour:'2-digit', minute:'2-digit', hour12:true,
    });
  } catch { return new Date(ts*1000).toLocaleString(); }
}
let toastT;
function toast(msg){
  const el = $('#toast');
  el.textContent = msg; el.classList.add('show');
  clearTimeout(toastT); toastT = setTimeout(() => el.classList.remove('show'), 2400);
}
const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

/* ── data ──────────────────────────────────────────────────── */
async function load(showToast){
  const btn = $('#btnRefresh'); btn.classList.add('spin');
  try {
    // PHP সার্ভার থাকলে api/list.php, না থাকলে (GitHub Pages) list.json
    let d = null;
    if (!S.staticMode) {
      try {
        const r = await fetch('api/list.php?_=' + Date.now(), { cache:'no-store' });
        if (r.ok) d = await r.json();
      } catch (e) { /* নিচে list.json চেষ্টা করব */ }
    }
    if (!d || !d.ok) {
      const r2 = await fetch('list.json?_=' + Date.now(), { cache:'no-store' });
      d = await r2.json();
      S.staticMode = true;
    }
    if (!d || !d.ok) throw new Error('bad payload');

    const prevCount = S.files.length;
    S.files = d.files || [];
    S.stats = d.stats; S.sync = d.sync; S.site = d.site || {};
    S.site.tzName = d.tz || 'Asia/Dhaka';
    S.pageSize = (d.site && d.site.pageSize) || 60;
    S.now = d.now;

    if (S.first) {
      lang = LS.get('lang', (d.site && d.site.lang) || 'bn');
      const savedTheme = LS.get('theme', (d.site && d.site.theme) || 'dark');
      document.documentElement.dataset.theme = savedTheme;
      S.first = false;
    }

    renderStatus(); renderChips(); applyFilters(true); renderFoot();

    if (showToast) {
      const diff = S.files.length - prevCount;
      toast(diff > 0 ? diff + t('newFiles') : t('refreshed'));
    }
  } catch (e) {
    console.error(e);
    $('#syncText').textContent = t('failed');
    $('#syncDot').className = 'dot down';
    if (showToast) toast(t('failed'));
  } finally {
    setTimeout(() => btn.classList.remove('spin'), 400);
  }
}

/* ── ভিজিটে-আপডেট ────────────────────────────────────────────
   cron ছাড়াই কাজ চালানোর ব্যবস্থা: পেজ দেখানোর পর ব্রাউজার নিজেই
   api/refresh.php ডাকে। সার্ভার এক রিকোয়েস্টে একটা ফাইল আনে (হোস্টের
   time-limit এর নিচে থাকতে), তাই remaining > 0 হলে আবার ডাকি।        */
let lazyBusy = false;

function lazyBanner(show, text){
  let el = $('#lazyBar');
  if (!el) {
    el = document.createElement('div');
    el.id = 'lazyBar';
    el.className = 'lazybar';
    const host = $('.statusbar') || $('header') || document.body;
    host.appendChild(el);
  }
  el.innerHTML = show
    ? '<span class="lzdot"></span>' + (text || t('updating'))
    : '';
  el.classList.toggle('on', !!show);
}

async function lazyRefresh(){
  if (lazyBusy) return;
  if (S.staticMode) return;                  // GitHub Pages — সার্ভার নেই
  const s = S.sync || {};
  if (!s.lazy) return;                       // সার্ভারে বন্ধ
  const age = Math.max(0, (Date.now()/1000) - (s.lastSync || 0));
  if (s.lastSync && age < (s.lazyMinAge || 240)) return;   // এখনো তাজা

  lazyBusy = true;
  lazyBanner(true, S.files.length ? t('updating') : t('firstFetch'));
  let got = 0;

  try {
    for (let i = 0; i < 6; i++) {            // সর্বোচ্চ ৬ ধাপ
      const r = await fetch('api/refresh.php?_=' + Date.now(), { cache:'no-store' });
      const d = await r.json().catch(() => null);
      if (!d) break;

      if (d.action === 'running') break;
      if (d.action === 'fresh') {
        if (!d.missing) break;                 // সব ছবি আছে, কিছু করার নেই
        await new Promise(r => setTimeout(r, 1500));   // একটু দম নিয়ে আবার
        continue;
      }
      got += (d.downloaded || 0);

      if (d.downloaded) {                    // নতুন ছবি এসেছে — সাথে সাথে দেখাই
        await load(false);
        lazyBanner(true, t('updating'));
      }
      if (!d.remaining && !d.missing) break;
    }
  } catch (e) {
    console.warn('lazy refresh failed', e);
  } finally {
    lazyBusy = false;
    lazyBanner(false);
    if (got) toast(got + t('newFiles'));
    else if (!S.files.length) toast(t('failed'));
  }
}

/* ── status line ───────────────────────────────────────────── */
function renderStatus(){
  const dot = $('#syncDot'), txt = $('#syncText');
  const s = S.sync || {};
  let cls = 'down', label = t('never');
  if (s.lastSync) {
    cls = s.stale ? 'stale' : 'live';
    label = (s.stale ? t('stale') : t('live')) + ' · ' + ago(s.lastSync, S.now);
  }
  dot.className = 'dot ' + cls;
  txt.textContent = label;
  const st = S.stats || {};
  $('#countText').textContent =
    (st.count || 0) + ' ' + t('files') + ' · ' + fmtSize(st.bytes || 0) +
    (st.newest ? ' · ' + t('updated') + ' ' + ago(st.newest, S.now) : '');
}

function renderFoot(){
  const st = S.stats || {}, s = S.sync || {};
  const bits = [];
  if (S.site && S.site.retention) bits.push(S.site.retention + ' ' + t('retention'));
  if (s.agent) bits.push('agent ' + s.agent);
  bits.push(fmtSize(st.bytes || 0));
  $('#footStats').textContent = bits.join(' · ');
}

/* ── chips ─────────────────────────────────────────────────── */
function renderChips(){
  const kinds = (S.stats && S.stats.kinds) || {};
  const order = ['image','pdf','text','doc','archive','other'];
  const labelOf = k => ({image:'images',pdf:'pdf',text:'text',doc:'doc',archive:'archive',other:'other'}[k]);

  let html = `<button class="chip ${S.kind==='all'?'on':''}" data-kind="all">${t('all')}
      <span class="n">${(S.stats&&S.stats.count)||0}</span></button>`;
  order.forEach(k => {
    if (!kinds[k]) return;
    html += `<button class="chip ${S.kind===k?'on':''}" data-kind="${k}">${KIND_ICON[k]} ${t(labelOf(k))}
      <span class="n">${kinds[k]}</span></button>`;
  });
  $('#kindChips').innerHTML = html;

  const dirs = (S.stats && S.stats.dirs) || {};
  const keys = Object.keys(dirs);
  if (keys.length > 1) {
    let dh = `<button class="chip ${S.dir==='all'?'on':''}" data-dir="all">${t('all')}</button>`;
    keys.forEach(d => {
      const nm = d === '/' ? t('root') : d;
      dh += `<button class="chip ${S.dir===d?'on':''}" data-dir="${esc(d)}">${esc(nm)}
        <span class="n">${dirs[d]}</span></button>`;
    });
    $('#dirChips').innerHTML = dh;
  } else {
    $('#dirChips').innerHTML = '';
  }
}

/* ── filter + render ───────────────────────────────────────── */
function applyFilters(reset){
  const q = S.q.trim().toLowerCase();
  let out = S.files;

  if (S.kind !== 'all') out = out.filter(f => f.k === S.kind);
  if (S.dir  !== 'all') out = out.filter(f => (f.d === '' ? '/' : f.d) === S.dir);
  if (q) {
    const terms = q.split(/\s+/);
    out = out.filter(f => {
      const hay = (f.p + ' ' + f.n).toLowerCase();
      return terms.every(x => hay.includes(x));
    });
  }
  out = out.slice();
  if (S.sort === 'new')      out.sort((a,b) => b.m - a.m);
  else if (S.sort === 'old') out.sort((a,b) => a.m - b.m);
  else                       out.sort((a,b) => a.n.localeCompare(b.n, undefined, {numeric:true}));

  S.visible = out;
  // মাত্র কয়েকটা ছবি থাকলে ছোট গ্রিড বোকা দেখায় — বড় করে দেখাই
  S.big = S.view === 'grid' && out.length > 0 && out.length <= 6;
  $('#results').classList.toggle('big', !!S.big);
  if (reset) { S.shown = 0; $('#results').innerHTML = ''; }
  $('#empty').hidden = out.length > 0;
  renderMore();
}

function fileUrl(f, dl){
  // ⚠️ একই নামের ছবি আপডেট হয় (mosradar.jpg)। তাই ভার্সন প্যারাম না দিলে
  //    ব্রাউজার পুরোনো ক্যাশ দেখাবে।
  if (S.staticMode) return 'files/' + f.p.split('/').map(encodeURIComponent).join('/') + '?v=' + f.m;
  return 'file.php?f=' + encodeURIComponent(f.p) + '&v=' + f.m + (dl ? '&dl=1' : '');
}
function thumbUrl(f, w){
  if (S.staticMode) return fileUrl(f, false);   // স্ট্যাটিক মোডে থাম্বনেইল নেই
  return 'thumb.php?f=' + encodeURIComponent(f.p) + '&w=' + w + '&v=' + f.m;
}

function cardHtml(f){
  const isImg = f.k === 'image';
  const fresh = (S.now - f.m) < 3600;
  const dirLabel = f.d ? f.d.split('/').pop() : '';
  return `<button class="card" data-p="${esc(f.p)}">
    <div class="thumb">
      ${fresh ? '<span class="badge-new">NEW</span>' : ''}
      <span class="badge-kind">${esc(f.n.split('.').pop().slice(0,5))}</span>
      ${isImg
        ? `<img loading="lazy" decoding="async" src="${thumbUrl(f, S.big ? 900 : 520)}" alt="${esc(f.n)}">`
        : `<span class="ph">${KIND_ICON[f.k] || '📁'}</span>`}
    </div>
    <div class="card-body">
      <span class="card-name">${esc(f.n)}</span>
      <span class="card-sub">
        ${dirLabel ? `<span class="d">${esc(dirLabel)}</span>` : ''}
        <span>${ago(f.m, S.now)}</span><span>·</span><span>${fmtSize(f.s)}</span>
      </span>
    </div>
    ${S.view === 'list' ? `<span class="lt">${clock(f.m)}</span>` : ''}
  </button>`;
}

function renderMore(){
  const box = $('#results');
  const next = S.visible.slice(S.shown, S.shown + S.pageSize);
  if (!next.length) return;
  box.insertAdjacentHTML('beforeend', next.map(cardHtml).join(''));
  S.shown += next.length;
  // fade-in images
  $$('#results .thumb img:not([data-b])').forEach(img => {
    img.dataset.b = 1;
    if (img.complete) img.classList.add('ready');
    else {
      img.addEventListener('load',  () => img.classList.add('ready'), { once:true });
      img.addEventListener('error', () => {
        img.remove();
      }, { once:true });
    }
  });
}

/* ── lightbox ──────────────────────────────────────────────── */
let lbList = [], lbIdx = 0;
function openFile(p){
  const f = S.visible.find(x => x.p === p);
  if (!f) return;
  if (f.k !== 'image') { window.open(fileUrl(f, false), '_blank', 'noopener'); return; }
  lbList = S.visible.filter(x => x.k === 'image');
  lbIdx  = lbList.findIndex(x => x.p === p);
  document.body.classList.add('lb-open');
  $('#lb').hidden = false;
  showLb();
}
function showLb(){
  const f = lbList[lbIdx]; if (!f) return;
  const img = $('#lbImg');
  img.style.opacity = '0';
  img.src = fileUrl(f, false);
  img.onload = () => { img.style.opacity = '1'; };
  $('#lbName').textContent = f.n;
  $('#lbInfo').textContent = clock(f.m) + ' · ' + fmtSize(f.s) + (f.d ? ' · ' + f.d : '');
  $('#lbDl').href = fileUrl(f, true);
  $('#lbCount').textContent = (lbIdx + 1) + ' / ' + lbList.length;
  const multi = lbList.length > 1;
  $('#lbPrev').style.display = multi ? '' : 'none';
  $('#lbNext').style.display = multi ? '' : 'none';
  // preload neighbours
  [lbIdx-1, lbIdx+1].forEach(i => {
    const n = lbList[i]; if (n && n.k === 'image') { const p = new Image(); p.src = fileUrl(n, false); }
  });
}
function step(d){ if (!lbList.length) return; lbIdx = (lbIdx + d + lbList.length) % lbList.length; showLb(); }
function closeLb(){ $('#lb').hidden = true; document.body.classList.remove('lb-open'); $('#lbImg').src = ''; }

/* ── UI wiring ─────────────────────────────────────────────── */
function applyLang(){
  document.documentElement.lang = lang;
  $('#langLabel').textContent = lang === 'bn' ? 'EN' : 'বাং';
  $('#q').placeholder = t('search');
  $$('[data-i18n]').forEach(el => { el.textContent = t(el.dataset.i18n); });
  renderStatus(); renderChips(); applyFilters(true); renderFoot();
}

function init(){
  document.documentElement.dataset.theme = LS.get('theme', document.documentElement.dataset.theme || 'dark');
  if (S.view === 'list') {
    $('#results').classList.add('list');
    $$('#viewSeg button').forEach(b => b.classList.toggle('on', b.dataset.view === 'list'));
  }
  $('#results').innerHTML = Array.from({length:8}, () => '<div class="skel"></div>').join('');

  $('#btnTheme').onclick = () => {
    const nt = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = nt; LS.set('theme', nt);
  };
  $('#btnLang').onclick = () => { lang = lang === 'bn' ? 'en' : 'bn'; LS.set('lang', lang); applyLang(); };
  $('#btnRefresh').onclick = async () => { await load(true); lazyRefresh(); };

  let qT;
  $('#q').addEventListener('input', e => {
    S.q = e.target.value;
    $('#qClear').hidden = !S.q;
    clearTimeout(qT); qT = setTimeout(() => applyFilters(true), 180);
  });
  $('#qClear').onclick = () => { $('#q').value = ''; S.q = ''; $('#qClear').hidden = true; applyFilters(true); };

  $('#kindChips').addEventListener('click', e => {
    const c = e.target.closest('[data-kind]'); if (!c) return;
    S.kind = c.dataset.kind; renderChips(); applyFilters(true);
    window.scrollTo({ top: 0, behavior:'smooth' });
  });
  $('#dirChips').addEventListener('click', e => {
    const c = e.target.closest('[data-dir]'); if (!c) return;
    S.dir = c.dataset.dir; renderChips(); applyFilters(true);
  });
  $('#sortSeg').addEventListener('click', e => {
    const b = e.target.closest('[data-sort]'); if (!b) return;
    S.sort = b.dataset.sort;
    $$('#sortSeg button').forEach(x => x.classList.toggle('on', x === b));
    applyFilters(true);
  });
  $('#viewSeg').addEventListener('click', e => {
    const b = e.target.closest('[data-view]'); if (!b) return;
    S.view = b.dataset.view; LS.set('view', S.view);
    $$('#viewSeg button').forEach(x => x.classList.toggle('on', x === b));
    $('#results').classList.toggle('list', S.view === 'list');
    applyFilters(true);
  });
  $('#results').addEventListener('click', e => {
    const c = e.target.closest('.card'); if (c) openFile(c.dataset.p);
  });

  $('#lbClose').onclick = closeLb;
  $('#lbPrev').onclick = () => step(-1);
  $('#lbNext').onclick = () => step(1);
  $('#lb').addEventListener('click', e => { if (e.target.id === 'lbStage' || e.target.id === 'lb') closeLb(); });
  document.addEventListener('keydown', e => {
    if ($('#lb').hidden) { if (e.key === '/' && document.activeElement !== $('#q')) { e.preventDefault(); $('#q').focus(); } return; }
    if (e.key === 'Escape') closeLb();
    if (e.key === 'ArrowLeft') step(-1);
    if (e.key === 'ArrowRight') step(1);
  });
  // swipe
  let sx = 0, sy = 0;
  $('#lbStage').addEventListener('touchstart', e => { sx = e.touches[0].clientX; sy = e.touches[0].clientY; }, { passive:true });
  $('#lbStage').addEventListener('touchend', e => {
    const dx = e.changedTouches[0].clientX - sx, dy = e.changedTouches[0].clientY - sy;
    if (Math.abs(dx) > 55 && Math.abs(dx) > Math.abs(dy) * 1.6) step(dx < 0 ? 1 : -1);
    else if (dy > 90 && Math.abs(dy) > Math.abs(dx)) closeLb();
  }, { passive:true });

  // infinite scroll
  new IntersectionObserver(es => { if (es[0].isIntersecting) renderMore(); }, { rootMargin:'700px' })
    .observe($('#sentinel'));

  applyLang();
  load(false).then(lazyRefresh);

  // auto refresh
  setInterval(() => { if (!document.hidden) load(false); }, 90000);
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && S.sync && (Date.now()/1000 - S.sync.lastSync) > 120) { load(false); lazyRefresh(); }
  });
  setInterval(renderStatus, 30000);
}

document.addEventListener('DOMContentLoaded', init);
})();
