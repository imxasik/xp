<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/lib/store.php';
require_once __DIR__ . '/lib/seed.php';
xp_seed_if_empty();
?>
<!doctype html>
<html lang="bn">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>XP Admin</title>
  <link rel="stylesheet" href="assets/css/app.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&family=Noto+Sans+Bengali:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body>
<div class="app admin">
  <header class="top">
    <div class="brand"><div class="logo">AD</div><div><div>অ্যাডমিন প্যানেল</div><div class="muted" id="svcpill" style="font-size:11px">…</div></div></div>
    <div class="acts">
      <button class="bell" id="bell" title="নোটিফিকেশন">🔔<span class="cnt" id="bellcnt">0</span></button>
      <a class="muted" href="index.php">স্টোর</a>
    </div>
  </header>
  <div id="box"></div>
</div>
<div class="modal" id="modal"><div class="sheet"></div></div>
<div class="toast" id="toast"></div>
<script>
const api=(a,b={})=>fetch('api/index.php?action='+a,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(b)}).then(r=>r.json());
let D=null, tab='dash', ofilter='processing', osearch='', ifilter='review', usearch='', pollTimer=null, lastUnread=0;
const $=s=>document.querySelector(s);
function $$(s){return [...document.querySelectorAll(s)]}
function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}
function money(n){return '৳'+Number(n||0).toFixed(2)}
function toast(m){const t=$('#toast');t.textContent=m;t.style.display='block';clearTimeout(t._h);t._h=setTimeout(()=>t.style.display='none',2600)}
function ago(ts){ if(!ts) return ''; const d=(Date.now()-new Date(ts.replace(' ','T')))/1000; if(d<60) return 'এইমাত্র'; if(d<3600) return Math.floor(d/60)+' মিনিট আগে'; if(d<86400) return Math.floor(d/3600)+' ঘন্টা আগে'; return ts.slice(0,16); }
const ST={awaiting_payment:['পেমেন্ট বাকি','wait'],processing:['প্রসেসিং','proc'],completed:['সম্পন্ন','ok'],failed:['ব্যর্থ','bad'],cancelled:['বাতিল','mut'],pending:['পেন্ডিং','wait'],review:['রিভিউ','proc'],paid:['পেইড','ok'],rejected:['রিজেক্ট','bad']};
function badge(s){const [n,c]=ST[s]||[s,'mut'];return `<span class="badge ${c}">${n}</span>`}
function opName(c){const o=(D?.operators||[]).find(x=>x.code===c);return o?o.name:(c||'')}
function userOf(id){return (D?.users||[]).find(u=>u.id===id)||{name:'অজানা',phone:''}}
function copyText(t,msg){ (navigator.clipboard?navigator.clipboard.writeText(t):Promise.reject()).then(()=>toast(msg||'কপি হয়েছে')).catch(()=>{const ta=document.createElement('textarea');ta.value=t;document.body.appendChild(ta);ta.select();document.execCommand('copy');ta.remove();toast(msg||'কপি হয়েছে')}); }
function showSheet(html){const m=$('#modal');m.classList.add('show');$('.sheet',m).innerHTML='<button class="btn ghost" onclick="hideSheet()">বন্ধ</button>'+html;}
function hideSheet(){$('#modal').classList.remove('show')}
window.hideSheet=hideSheet;
$('#modal').onclick=e=>{if(e.target.id==='modal')hideSheet()};

let audioCtx=null;
function beep(){ try{ audioCtx=audioCtx||new (window.AudioContext||window.webkitAudioContext)(); const o=audioCtx.createOscillator(),g=audioCtx.createGain(); o.connect(g);g.connect(audioCtx.destination); o.frequency.value=880; g.gain.setValueAtTime(.001,audioCtx.currentTime); g.gain.exponentialRampToValueAtTime(.2,audioCtx.currentTime+.02); g.gain.exponentialRampToValueAtTime(.001,audioCtx.currentTime+.4); o.start(); o.stop(audioCtx.currentTime+.42);}catch(e){} }

async function start(soft){
  const r=await api('admin_boot');
  if(!r.ok){ login(); return; }
  D=r; setBell(r.notif_unread); paintSvc(); paint();
  if(!pollTimer) pollTimer=setInterval(poll,20000);
}
function setBell(n){ const c=$('#bellcnt'); c.textContent=n>99?'99+':n; c.classList.toggle('on',n>0); if(n>lastUnread){ $('#bell').classList.remove('ring'); void $('#bell').offsetWidth; $('#bell').classList.add('ring'); } lastUnread=n; }
async function poll(){
  const r=await api('admin_poll'); if(!r.ok) return;
  if(r.unread>lastUnread){ beep(); toast('🔔 '+(r.latest?.title||'নতুন নোটিফিকেশন')); await start(true); }
  else setBell(r.unread);
}
function paintSvc(){
  const s=D.service||{}; const el=$('#svcpill');
  el.innerHTML=`<span class="pulse ${s.state}"></span>${s.state==='open'?'সার্ভিস চালু':s.state==='prayer'?(s.break_name+' বিরতি'):'সার্ভিস বন্ধ'} · ${s.hours_label||''}`;
}
function login(){
  $('#box').innerHTML=`<div class="card" style="max-width:420px;margin:40px auto">
    <h3>অ্যাডমিন লগইন</h3>
    <label>ইউজার</label><input id="u" value="admin" autocomplete="username">
    <label>পাসওয়ার্ড</label><input id="p" type="password" autocomplete="current-password">
    <div style="height:10px"></div><button class="btn" id="go">প্রবেশ</button></div>`;
  const go=async()=>{ const x=await api('admin_login',{username:$('#u').value,password:$('#p').value}); if(!x.ok) return toast(x.error); start(); };
  $('#go').onclick=go; $('#p').onkeydown=e=>{if(e.key==='Enter')go()};
}
const TABS=[['dash','📊 ড্যাশবোর্ড'],['orders','🧾 অর্ডার'],['pay','💳 পেমেন্ট'],['notif','🔔 নোটিফিকেশন'],['users','👤 ইউজার'],['offers','🎁 অফার'],['gw','🏦 গেটওয়ে'],['ops','📡 অপারেটর'],['set','⚙️ সেটিংস']];
function counts(){
  const o=D.orders||[], inv=D.invoices||[];
  return {orders:o.filter(x=>x.status==='processing').length, pay:inv.filter(i=>i.status==='review').length, notif:lastUnread};
}
function nav(){
  const c=counts();
  const b=k=>c[k]?`<span class="b">${c[k]}</span>`:'';
  return `<div class="ops">${TABS.map(([k,n])=>`<button class="chip ${tab===k?'on':''}" data-t="${k}">${n}${b(k)}</button>`).join('')}</div>`;
}
function sideNav(){
  const c=counts();
  const b=k=>c[k]?`<span class="badge bad" style="float:right">${c[k]}</span>`:'';
  return `<aside class="side">${TABS.map(([k,n])=>`<button class="${tab===k?'on':''}" data-t="${k}">${n}${b(k)}</button>`).join('')}
    <div style="height:8px"></div><button id="alogout" class="muted">লগআউট</button></aside>`;
}
function paint(){
  let body='';
  if(tab==='dash') body=dashUI();
  if(tab==='offers') body=offersUI();
  if(tab==='orders') body=ordersUI();
  if(tab==='pay') body=payUI();
  if(tab==='notif') body=notifUI();
  if(tab==='users') body=usersUI();
  if(tab==='gw') body=gwUI();
  if(tab==='ops') body=opsUI();
  if(tab==='set') body=setUI();
  $('#box').innerHTML=nav()+`<div class="admin-grid">${sideNav()}<div style="min-width:0">${body}</div></div>`;
  bind();
}
function svcCard(){
  const s=D.service||{}; const ic=s.state==='open'?'🟢':s.state==='prayer'?'🕌':'🌙';
  return `<div class="svc ${s.state}"><div class="ic">${ic}</div><div><b>${esc(s.message||'')}</b><small>সার্ভিস সময়: ${esc(s.hours_label||'')} · নামাজের বিরতি: ${(s.prayer_breaks||[]).map(b=>esc(b.name)).join(', ')||'নেই'} · <a href="#" data-t="set">বদলান</a></small></div></div>`;
}
function dashUI(){
  const o=D.orders||[], inv=D.invoices||[], u=D.users||[];
  const today=new Date().toISOString().slice(0,10);
  const sale=o.filter(x=>x.status==='completed').reduce((a,x)=>a+Number(x.price),0);
  const todaySale=o.filter(x=>x.status==='completed'&&(x.created_at||'').startsWith(today)).reduce((a,x)=>a+Number(x.price),0);
  const pend=o.filter(x=>x.status==='processing');
  return svcCard()+`<div class="kpis">
    <div class="kpi"><span class="muted">প্রসেসিং অর্ডার</span><b>${pend.length}</b></div>
    <div class="kpi"><span class="muted">পেমেন্ট রিভিউ</span><b>${inv.filter(i=>i.status==='review').length}</b></div>
    <div class="kpi"><span class="muted">আজকের সেলস</span><b>${money(todaySale)}</b></div>
    <div class="kpi"><span class="muted">মোট সেলস</span><b>${money(sale)}</b></div>
    <div class="kpi"><span class="muted">ইউজার</span><b>${u.length}</b></div>
    <div class="kpi"><span class="muted">মোট অর্ডার</span><b>${o.length}</b></div>
  </div>
  <div class="two">
  <div class="card"><h3>⏳ হিট করার অপেক্ষায় (${pend.length})</h3>${pend.length?pend.slice(0,10).map(orderItem).join(''):'<div class="empty">সব অর্ডার হিট হয়ে গেছে 🎉</div>'}${pend.length>10?`<div style="margin-top:8px"><button class="btn sm sec" data-t="orders">সব দেখুন</button></div>`:''}</div>
  <div class="card"><h3>🕒 সর্বশেষ অর্ডার</h3>${o.length?o.slice(0,8).map(x=>orderItem(x,true)).join(''):'<div class="empty">কোন অর্ডার নেই</div>'}</div>
  </div>`;
}
function orderSummary(x){
  const u=userOf(x.user_id);
  return [
    `অফার: ${x.title}`,
    `অপারেটর: ${opName(x.operator)}`,
    `নম্বর: ${x.number}`,
    `ফেস ভ্যালু: ৳${x.face_value}`,
    `মূল্য: ৳${x.price}`,
    `গ্রাহক: ${u.name} (${u.phone})`,
    `অর্ডার আইডি: ${x.id}`,
    `সময়: ${x.created_at}`,
    x.note?`নোট: ${x.note}`:''
  ].filter(Boolean).join('\n');
}
function orderItem(x,compact){
  const u=userOf(x.user_id);
  const canAct=['processing','awaiting_payment'].includes(x.status);
  return `<div class="item" id="o_${x.id}">
    <div class="hd"><b>${esc(x.title)}</b>${badge(x.status)}</div>
    <div class="row" style="gap:6px">
      <span class="mono big copybtn" data-copy="${esc(x.number)}" title="নম্বর কপি">${esc(x.number)}</span>
      <button class="btn sm ghost copybtn" data-copy="${esc(x.number)}">📋 নম্বর</button>
      <button class="btn sm ghost" data-copyall="${x.id}">📄 সব তথ্য</button>
    </div>
    <div class="sub">${esc(opName(x.operator))} · ফেস ৳${x.face_value} · <b style="color:var(--acc)">${money(x.price)}</b> · ${esc(u.name)} <span class="mono copybtn" data-copy="${esc(u.phone)}">${esc(u.phone)}</span> · ${ago(x.created_at)}${x.manual?' · <span class="tag">ম্যানুয়াল হিট</span>':''}${x.admin_note?` · নোট: ${esc(x.admin_note)}`:''}</div>
    ${canAct&&!compact?`<div class="acts">
      <button class="btn sm" data-os="${x.id}" data-st="completed">✅ হিট হয়েছে</button>
      <button class="btn sm bad" data-os="${x.id}" data-st="failed">❌ ফেল</button>
      ${x.status==='awaiting_payment'?`<button class="btn sm warn" data-os="${x.id}" data-st="processing">পেইড মার্ক</button>`:''}
    </div>`:canAct&&compact?`<div class="acts"><button class="btn sm" data-os="${x.id}" data-st="completed">✅ হিট হয়েছে</button><button class="btn sm ghost" data-view="${x.id}">বিস্তারিত</button></div>`:''}
  </div>`;
}
function ordersUI(){
  const all=D.orders||[];
  const fl=[['processing','প্রসেসিং'],['awaiting_payment','পেমেন্ট বাকি'],['completed','সম্পন্ন'],['failed','ব্যর্থ'],['all','সব']];
  let list=ofilter==='all'?all:all.filter(x=>x.status===ofilter);
  if(osearch){const q=osearch.toLowerCase();list=list.filter(x=>(x.number+' '+x.title+' '+x.id+' '+userOf(x.user_id).phone+' '+userOf(x.user_id).name).toLowerCase().includes(q));}
  return svcCard()+`<div class="toolbar">${fl.map(([k,n])=>`<button class="chip ${ofilter===k?'on':''}" data-of="${k}">${n}<span class="b">${k==='all'?all.length:all.filter(x=>x.status===k).length}</span></button>`).join('')}
    <input id="osearch" placeholder="🔍 নম্বর / নাম / আইডি" value="${esc(osearch)}"></div>
    <div class="card">${list.length?list.slice(0,100).map(x=>orderItem(x)).join(''):'<div class="empty">কিছু নেই</div>'}</div>`;
}
function payUI(){
  const all=D.invoices||[];
  const fl=[['review','রিভিউ'],['pending','পেন্ডিং'],['paid','পেইড'],['rejected','রিজেক্ট'],['all','সব']];
  const list=ifilter==='all'?all:all.filter(i=>i.status===ifilter);
  return `<div class="toolbar">${fl.map(([k,n])=>`<button class="chip ${ifilter===k?'on':''}" data-if="${k}">${n}<span class="b">${k==='all'?all.length:all.filter(x=>x.status===k).length}</span></button>`).join('')}</div>
  <div class="card">${list.length?list.slice(0,100).map(i=>{const u=userOf(i.user_id);return `<div class="item">
    <div class="hd"><b>${esc(String(i.method||'').toUpperCase())} ${money(i.amount)}</b>${badge(i.status)}</div>
    <div class="kv"><span>TrxID</span><span class="mono copybtn" data-copy="${esc(i.trx_id||'')}">${esc(i.trx_id||'-')}</span>
      <span>পে-কোড</span><span class="mono copybtn" data-copy="${esc(i.paycode)}">${esc(i.paycode)}</span>
      <span>প্রেরক</span><span class="mono">${esc(i.sender||'-')}</span>
      <span>গ্রাহক</span><span>${esc(u.name)} · ${esc(u.phone)}</span>
      <span>উদ্দেশ্য</span><span>${i.purpose==='wallet_topup'?'ওয়ালেট টপআপ':'অর্ডার'} · ${ago(i.created_at)}</span></div>
    ${['review','pending'].includes(i.status)?`<div class="acts"><button class="btn sm" data-iv="${i.id}" data-ok="1">✅ অ্যাপ্রুভ</button>
    <button class="btn sm bad" data-iv="${i.id}" data-ok="0">❌ রিজেক্ট</button></div>`:''}
  </div>`}).join(''):'<div class="empty">কিছু নেই</div>'}</div>`;
}
const NIC={order:'🧾',payment:'💳',wallet:'👛',info:'📢',system:'⚙️'};
function notifUI(){
  const list=D.notifications||[]; const seen=D.admin?.notif_seen_ts||0;
  return `<div class="card"><h3>📢 ঘোষণা পাঠান (সব ইউজারকে)</h3>
    <label>শিরোনাম</label><input id="bt" placeholder="যেমন: আজ রাত ১০টার পর অর্ডার সকালে হিট হবে">
    <label>বিবরণ</label><input id="bb" placeholder="ঐচ্ছিক">
    <div style="height:8px"></div><button class="btn sm" id="bsend">পাঠান</button></div>
  <div class="card"><div class="hd" style="display:flex;justify-content:space-between;align-items:center"><h3 style="margin:0">নোটিফিকেশন</h3><button class="btn sm ghost" id="markread">সব পড়া হয়েছে</button></div>
  ${list.length?list.map(n=>`<div class="notif ${(n.ts||0)>seen?'new':''}"><div class="ic">${NIC[n.type]||'🔔'}</div><div style="min-width:0;flex:1"><b>${esc(n.title)}</b><p>${esc(n.body)}</p><time>${ago(n.created_at)}${n.to==='all'?' · সবাইকে':''}</time></div>${n.ref?.order_id?`<button class="btn sm ghost" data-view="${n.ref.order_id}">দেখুন</button>`:''}</div>`).join(''):'<div class="empty">কোন নোটিফিকেশন নেই</div>'}</div>`;
}
function usersUI(){
  let list=D.users||[];
  if(usearch){const q=usearch.toLowerCase();list=list.filter(x=>(x.name+' '+x.phone).toLowerCase().includes(q));}
  const oc=id=>(D.orders||[]).filter(o=>o.user_id===id).length;
  return `<div class="toolbar"><input id="usearch" placeholder="🔍 নাম / নম্বর" value="${esc(usearch)}"><span class="muted">${list.length} জন</span></div>
  <div class="card">${list.length?list.map(x=>`<div class="item">
    <div class="hd"><b>${esc(x.name)} <span class="mono muted copybtn" data-copy="${esc(x.phone)}">${esc(x.phone)}</span></b>${x.status==='blocked'?'<span class="badge bad">ব্লকড</span>':'<span class="badge ok">অ্যাকটিভ</span>'}</div>
    <div class="sub">ওয়ালেট <b style="color:var(--acc)">${money(x.wallet)}</b> · অর্ডার ${oc(x.id)} · যোগ ${(x.created_at||'').slice(0,10)}</div>
    <div class="acts"><input placeholder="৳" id="w${x.id}" type="number" style="max-width:100px;padding:8px 10px;font-size:13px">
    <button class="btn sm" data-w="${x.id}">ক্রেডিট</button>
    <button class="btn sm ghost" data-msg="${x.id}">✉️ মেসেজ</button>
    <button class="btn sm ${x.status==='blocked'?'sec':'warn'}" data-blk="${x.id}" data-to="${x.status==='blocked'?'active':'blocked'}">${x.status==='blocked'?'আনব্লক':'ব্লক'}</button>
    <button class="btn sm bad" data-udel="${x.id}">🗑 ডিলিট</button></div></div>`).join(''):'<div class="empty">কোন ইউজার নেই</div>'}</div>`;
}
function bind(){
  $$('[data-t]').forEach(b=>b.onclick=e=>{e.preventDefault();tab=b.dataset.t;paint();window.scrollTo({top:0})});
  $$('[data-of]').forEach(b=>b.onclick=()=>{ofilter=b.dataset.of;paint()});
  $$('[data-if]').forEach(b=>b.onclick=()=>{ifilter=b.dataset.if;paint()});
  const os=$('#osearch'); if(os){os.oninput=()=>{osearch=os.value;const p=os.selectionStart;paint();const n=$('#osearch');n.focus();n.setSelectionRange(p,p)}}
  const us=$('#usearch'); if(us){us.oninput=()=>{usearch=us.value;const p=us.selectionStart;paint();const n=$('#usearch');n.focus();n.setSelectionRange(p,p)}}
  $$('[data-copy]').forEach(b=>b.onclick=()=>copyText(b.dataset.copy));
  $$('[data-copyall]').forEach(b=>b.onclick=()=>{const x=D.orders.find(o=>o.id===b.dataset.copyall);if(x)copyText(orderSummary(x),'অর্ডারের সব তথ্য কপি হয়েছে')});
  $$('[data-view]').forEach(b=>b.onclick=()=>viewOrder(b.dataset.view));
  $$('[data-os]').forEach(b=>b.onclick=()=>setOrder(b.dataset.os,b.dataset.st));
  $$('[data-iv]').forEach(b=>b.onclick=async()=>{
    const ok=b.dataset.ok==='1'; let note='';
    if(!ok){note=prompt('রিজেক্টের কারণ (ইউজার দেখবে):','TrxID মেলেনি'); if(note===null) return;}
    b.disabled=true; const r=await api('admin_invoice',{id:b.dataset.iv,approve:ok,note}); if(!r.ok) toast(r.error||'ব্যর্থ'); else toast(ok?'অ্যাপ্রুভ হয়েছে':'রিজেক্ট হয়েছে'); start(true);
  });
  $$('[data-w]').forEach(b=>b.onclick=async()=>{const v=document.getElementById('w'+b.dataset.w).value; if(!(Number(v)>0)) return toast('পরিমাণ দিন'); const r=await api('admin_user_wallet',{user_id:b.dataset.w,amount:v,type:'credit'}); if(!r.ok) return toast(r.error||'ব্যর্থ'); toast('ক্রেডিট হয়েছে'); start(true);});
  $$('[data-blk]').forEach(b=>b.onclick=async()=>{await api('admin_user_status',{user_id:b.dataset.blk,status:b.dataset.to}); start(true);});
  $$('[data-udel]').forEach(b=>b.onclick=async()=>{
    const u=userOf(b.dataset.udel);
    if(!confirm(`${u.name} (${u.phone}) ডিলিট করবেন?\nএর অর্ডার/পেমেন্ট/ওয়ালেট হিস্টোরিও মুছে যাবে।`)) return;
    const r=await api('admin_delete_user',{user_id:b.dataset.udel,purge:true}); if(!r.ok) return toast(r.error||'ব্যর্থ'); toast('ইউজার ডিলিট হয়েছে'); start(true);
  });
  $$('[data-msg]').forEach(b=>b.onclick=()=>{
    const u=userOf(b.dataset.msg);
    showSheet(`<h3>✉️ ${esc(u.name)} কে মেসেজ</h3><label>শিরোনাম</label><input id="mt"><label>বিবরণ</label><input id="mb"><div style="height:8px"></div><button class="btn" id="msend">পাঠান</button>`);
    $('#msend').onclick=async()=>{const r=await api('admin_broadcast',{user_id:u.id,title:$('#mt').value,body:$('#mb').value}); if(!r.ok) return toast(r.error); toast('পাঠানো হয়েছে'); hideSheet();};
  });
  const bs=$('#bsend'); if(bs) bs.onclick=async()=>{const r=await api('admin_broadcast',{title:$('#bt').value,body:$('#bb').value}); if(!r.ok) return toast(r.error); toast('ঘোষণা পাঠানো হয়েছে'); start(true);};
  const mr=$('#markread'); if(mr) mr.onclick=async()=>{await api('admin_notifications',{mark_read:true}); start(true);};
  const lo=$('#alogout'); if(lo) lo.onclick=async()=>{await api('logout'); clearInterval(pollTimer); pollTimer=null; login();};
  const so=$('#saveOffer'); if(so) so.onclick=saveOffer;
  const sg=$('#saveGw'); if(sg) sg.onclick=saveGw;
  const ss=$('#saveSet'); if(ss) ss.onclick=saveSet;
  const so2=$('#saveOps'); if(so2) so2.onclick=saveOps;
  const ab=$('#addBreak'); if(ab) ab.onclick=()=>{$('#breaks').insertAdjacentHTML('beforeend',breakRow({name:'',start:'',end:''}));bind();};
  $$('[data-rmb]').forEach(b=>b.onclick=()=>b.closest('.brow').remove());
  $$('[data-del]').forEach(b=>b.onclick=async()=>{if(!confirm('অফার ডিলিট?'))return;await api('admin_delete_offer',{id:b.dataset.del}); start(true);});
  $$('[data-ed]').forEach(b=>b.onclick=()=>fillOffer(b.dataset.ed));
}
async function setOrder(id,status){
  let note='';
  if(status==='failed'){note=prompt('ব্যর্থতার কারণ (ইউজার দেখবে):','নম্বরে অফার প্রযোজ্য নয়'); if(note===null) return;}
  const r=await api('admin_order',{id,status,note});
  if(!r.ok) return toast(r.error||'ব্যর্থ');
  toast(status==='completed'?'✅ হিট মার্ক হয়েছে, ইউজার নোটিফিকেশন পেয়েছে':'আপডেট হয়েছে');
  hideSheet(); start(true);
}
function viewOrder(id){
  const x=(D.orders||[]).find(o=>o.id===id); if(!x) return toast('অর্ডার পাওয়া যায়নি');
  const u=userOf(x.user_id); const inv=(D.invoices||[]).find(i=>i.id===x.invoice_id);
  showSheet(`<h3>${esc(x.title)} ${badge(x.status)}</h3>
    <div class="kv">
      <span>নম্বর</span><span class="mono big copybtn" data-copy="${esc(x.number)}">${esc(x.number)} 📋</span>
      <span>অপারেটর</span><span>${esc(opName(x.operator))}</span>
      <span>ফেস / মূল্য</span><span>৳${x.face_value} / <b>${money(x.price)}</b></span>
      <span>গ্রাহক</span><span>${esc(u.name)} · <span class="mono copybtn" data-copy="${esc(u.phone)}">${esc(u.phone)}</span></span>
      <span>পেমেন্ট</span><span>${inv?`${esc(String(inv.method).toUpperCase())} ${badge(inv.status)} ${inv.trx_id?'· <span class="mono">'+esc(inv.trx_id)+'</span>':''}`:'-'}</span>
      <span>সময়</span><span>${esc(x.created_at)}</span>
      <span>আইডি</span><span class="mono" style="font-size:11px">${esc(x.id)}</span>
      ${x.note?`<span>নোট</span><span>${esc(x.note)}</span>`:''}
      ${x.admin_note?`<span>অ্যাডমিন নোট</span><span>${esc(x.admin_note)}</span>`:''}
    </div>
    <div style="height:12px"></div>
    <div class="row"><button class="btn sec" data-copyall="${x.id}">📄 সব তথ্য কপি</button></div>
    ${['processing','awaiting_payment'].includes(x.status)?`<div class="row" style="margin-top:8px"><button class="btn" data-os="${x.id}" data-st="completed">✅ হিট হয়েছে</button><button class="btn bad" data-os="${x.id}" data-st="failed">❌ ফেল</button></div>`:''}`);
  $$('.sheet [data-copy]').forEach(b=>b.onclick=()=>copyText(b.dataset.copy));
  $$('.sheet [data-copyall]').forEach(b=>b.onclick=()=>copyText(orderSummary(x),'অর্ডারের সব তথ্য কপি হয়েছে'));
  $$('.sheet [data-os]').forEach(b=>b.onclick=()=>setOrder(b.dataset.os,b.dataset.st));
}
function offersUI(){
  const ops=D.operators||[], cats=D.categories||[];
  return `<div class="two"><div class="card">
    <h3>অফার যোগ/এডিট</h3>
    <input type="hidden" id="oid">
    <label>টাইটেল</label><input id="otitle">
    <label>বিবরণ</label><input id="odesc">
    <label>অপারেটর</label><select id="oop">${ops.map(o=>`<option value="${o.code}">${esc(o.name)}</option>`).join('')}</select>
    <label>ক্যাটাগরি</label><select id="ocat">${cats.map(c=>`<option value="${c.id}">${esc(c.name)}</option>`).join('')}</select>
    <div class="row"><div><label>ফেস ভ্যালু</label><input id="oface" type="number"></div>
    <div><label>মূল্য</label><input id="oprice" type="number"></div></div>
    <div class="row"><div><label>ভ্যালিডিটি</label><input id="oval" value="৩০ দিন"></div>
    <div><label>স্টক</label><input id="ostock" type="number" value="50"></div></div>
    <label><input type="checkbox" id="oact" checked> অ্যাকটিভ</label>
    <label><input type="checkbox" id="ofeat" checked> ফিচার্ড</label>
    <div style="height:8px"></div><button class="btn" id="saveOffer">সেভ অফার</button>
  </div>
  <div class="card"><h3>অফার তালিকা (${(D.offers||[]).length})</h3>${(D.offers||[]).map(o=>`<div class="item">
    <div class="hd"><b>${esc(o.title)}</b>${o.active?'<span class="badge ok">অ্যাকটিভ</span>':'<span class="badge mut">অফ</span>'}</div>
    <div class="sub">${esc(opName(o.operator))} · ${esc(o.category)} · ${money(o.price)} <s>৳${o.face_value}</s> · স্টক ${o.stock} · বিক্রি ${o.sold||0}</div>
    <div class="acts"><button class="btn sm sec" data-ed="${o.id}">এডিট</button>
    <button class="btn sm ghost" data-del="${o.id}">ডিলিট</button></div></div>`).join('')}</div></div>`;
}
function fillOffer(id){
  const o=D.offers.find(x=>x.id===id); if(!o) return;
  oid.value=o.id; otitle.value=o.title; odesc.value=o.description||''; oop.value=o.operator; ocat.value=o.category;
  oface.value=o.face_value; oprice.value=o.price; oval.value=o.validity; ostock.value=o.stock; oact.checked=!!o.active; ofeat.checked=!!o.featured;
  window.scrollTo({top:0,behavior:'smooth'});
}
async function saveOffer(){
  if(!otitle.value.trim()) return toast('টাইটেল দিন');
  const r=await api('admin_save_offer',{
    id:oid.value,title:otitle.value,description:odesc.value,operator:oop.value,category:ocat.value,
    face_value:oface.value,price:oprice.value,validity:oval.value,stock:ostock.value,active:oact.checked,featured:ofeat.checked
  }); if(!r.ok) return toast(r.error||'ব্যর্থ'); toast('অফার সেভ হয়েছে'); start(true);
}
function gwUI(){
  return `<div class="card"><p class="muted">অটো কনফার্ম ওয়েবহুক: POST api/index.php?action=gateway_auto — secret, trx, amount, paycode</p>
    <div class="two">${(D.gateways||[]).map((g,i)=>`<div class="card">
      <b>${esc(g.name)}</b> <span class="tag">${esc(g.code)}</span>
      <label>মার্চেন্ট</label><input class="gm" data-i="${i}" value="${esc(g.merchant||'')}">
      <label>নির্দেশনা</label><input class="gi" data-i="${i}" value="${esc(g.instructions||'')}">
      <label><input type="checkbox" class="ge" data-i="${i}" ${g.enabled?'checked':''}> চালু</label>
    </div>`).join('')}</div>
    <button class="btn" id="saveGw">গেটওয়ে সেভ</button></div>`;
}
async function saveGw(){
  const g=D.gateways.map((x,i)=>({...x,merchant:document.querySelector(`.gm[data-i="${i}"]`).value,
    instructions:document.querySelector(`.gi[data-i="${i}"]`).value,
    enabled:document.querySelector(`.ge[data-i="${i}"]`).checked}));
  await api('admin_gateways',{gateways:g}); toast('সেভ হয়েছে'); start(true);
}
function opsUI(){
  return `<div class="card"><div class="two">${(D.operators||[]).map((o,i)=>`<div class="card">
    <b>${esc(o.name)}</b>
    <label>রিচার্জ রেট %</label><input class="or" data-i="${i}" value="${o.recharge_rate}">
    <label><input type="checkbox" class="oa" data-i="${i}" ${o.active?'checked':''}> অ্যাকটিভ</label>
  </div>`).join('')}</div><button class="btn" id="saveOps">সেভ</button></div>`;
}
async function saveOps(){
  const operators=D.operators.map((o,i)=>({...o,recharge_rate:Number(document.querySelector(`.or[data-i="${i}"]`).value),active:document.querySelector(`.oa[data-i="${i}"]`).checked}));
  await api('admin_operators',{operators}); toast('সেভ হয়েছে'); start(true);
}
function breakRow(b){return `<div class="row brow" style="margin:6px 0"><input class="bn" placeholder="নাম (যোহর)" value="${esc(b.name||'')}" style="flex:2"><input class="bs" type="time" value="${esc(b.start||'')}"><input class="be" type="time" value="${esc(b.end||'')}"><button class="btn sm ghost" data-rmb style="flex:0">✕</button></div>`}
function setUI(){
  const s=D.settings||{}; const sh=Object.assign({enabled:true,start:'08:00',end:'22:00',scope:'drive',avg_minutes:'৫–১৫',prayer_breaks:(D.service||{}).prayer_breaks||[]},s.service_hours||{});
  return `<div class="two"><div class="card"><h3>সাধারণ</h3>
    <label>সাইট নাম</label><input id="sn" value="${esc(s.site_name||'')}">
    <label>ট্যাগলাইন</label><input id="st" value="${esc(s.tagline||'')}">
    <label>নোটিশ (স্টোরে দেখাবে)</label><input id="snote" value="${esc(s.notice||'')}">
    <label>হোয়াটসঅ্যাপ</label><input id="sw" value="${esc(s.whatsapp||'')}">
    <label>গেটওয়ে সিক্রেট</label><input id="ssc" value="${esc(s.gateway_secret||'')}">
    <label>মিন টপআপ</label><input id="smin" type="number" value="${s.min_topup||20}">
    <label><input type="checkbox" id="sap" ${s.auto_approve_payments?'checked':''}> পেমেন্ট অটো অ্যাপ্রুভ (Trx ফরম্যাট ঠিক থাকলে)</label>
    <label><input type="checkbox" id="sao" ${s.auto_process_orders?'checked':''}> সাধারণ অর্ডার অটো সম্পন্ন (ম্যানুয়াল-হিট অর্ডার বাদে)</label>
  </div>
  <div class="card"><h3>🕒 সার্ভিস সময় ও নামাজের বিরতি</h3>
    <p class="muted">ইউজার স্টোরে ও অর্ডারের পর এই তথ্য দেখবে, যাতে ধৈর্য ধরে অপেক্ষা করে।</p>
    <label><input type="checkbox" id="she" ${sh.enabled?'checked':''}> সার্ভিস সময় চালু</label>
    <div class="row"><div><label>শুরু</label><input id="shs" type="time" value="${esc(sh.start)}"></div><div><label>শেষ</label><input id="shx" type="time" value="${esc(sh.end)}"></div></div>
    <label>কোন অর্ডারে প্রযোজ্য (ম্যানুয়াল হিট)</label><select id="shsc"><option value="drive" ${sh.scope==='drive'?'selected':''}>শুধু ড্রাইভ অফার</option><option value="all" ${sh.scope==='all'?'selected':''}>সব অর্ডার</option></select>
    <label>গড় হিট সময় (মিনিট, টেক্সট)</label><input id="shavg" value="${esc(sh.avg_minutes)}">
    <label>নামাজের বিরতি</label>
    <div id="breaks">${(sh.prayer_breaks||[]).map(breakRow).join('')}</div>
    <button class="btn sm ghost" id="addBreak">+ বিরতি যোগ</button>
  </div></div>
  <button class="btn" id="saveSet">সেটিংস সেভ</button>`;
}
async function saveSet(){
  const prayer_breaks=$$('.brow').map(r=>({name:r.querySelector('.bn').value,start:r.querySelector('.bs').value,end:r.querySelector('.be').value})).filter(b=>b.start&&b.end);
  const r=await api('admin_settings',{
    site_name:sn.value,tagline:st.value,notice:snote.value,whatsapp:sw.value,gateway_secret:ssc.value,
    min_topup:smin.value,auto_approve_payments:sap.checked,auto_process_orders:sao.checked,
    service_hours:{enabled:she.checked,start:shs.value,end:shx.value,scope:shsc.value,avg_minutes:shavg.value,prayer_breaks}
  }); if(!r.ok) return toast(r.error||'ব্যর্থ'); toast('সেটিংস সেভ হয়েছে'); start(true);
}
$('#bell').onclick=()=>{tab='notif';paint();api('admin_notifications',{mark_read:true}).then(()=>{setBell(0);});};
document.addEventListener('visibilitychange',()=>{if(!document.hidden&&D)poll()});
start();
</script>
</body>
</html>
