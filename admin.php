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
</head>
<body>
<div class="app" style="max-width:1100px">
  <header class="top"><div class="brand"><div class="logo">AD</div>অ্যাডমিন প্যানেল</div><a href="index.php">স্টোর</a></header>
  <div id="box"></div>
</div>
<script>
const api=(a,b={})=>fetch('api/index.php?action='+a,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(b)}).then(r=>r.json());
let D=null, tab='dash';
const $=s=>document.querySelector(s);
function money(n){return '৳'+Number(n||0).toFixed(2)}
async function start(){
  const r=await api('admin_boot');
  if(!r.ok){ login(); return; }
  D=r; paint();
}
function login(){
  $('#box').innerHTML=`<div class="card" style="max-width:420px;margin:40px auto">
    <h3>অ্যাডমিন লগইন</h3>
    <label>ইউজার</label><input id="u" value="admin">
    <label>পাসওয়ার্ড</label><input id="p" type="password" value="admin123">
    <div style="height:10px"></div><button class="btn" id="go">প্রবেশ</button>
    <p class="muted">ডিফল্ট: admin / admin123 — সেটিংস থেকে বদলান</p></div>`;
  $('#go').onclick=async()=>{
    const x=await api('admin_login',{username:$('#u').value,password:$('#p').value});
    if(!x.ok) return alert(x.error); start();
  };
}
function nav(){
  const items=[['dash','ড্যাশবোর্ড'],['offers','অফার'],['orders','অর্ডার'],['pay','পেমেন্ট'],['users','ইউজার'],['gw','গেটওয়ে'],['ops','অপারেটর'],['set','সেটিংস']];
  return `<div class="ops">${items.map(([k,n])=>`<button class="chip ${tab===k?'on':''}" data-t="${k}">${n}</button>`).join('')}</div>`;
}
function paint(){
  const o=D.orders||[], inv=D.invoices||[], u=D.users||[];
  const sale=o.filter(x=>x.status==='completed').reduce((a,x)=>a+Number(x.price),0);
  let body='';
  if(tab==='dash') body=`<div class="kpis">
    <div class="kpi"><span class="muted">ইউজার</span><b>${u.length}</b></div>
    <div class="kpi"><span class="muted">অর্ডার</span><b>${o.length}</b></div>
    <div class="kpi"><span class="muted">সেলস</span><b>${money(sale)}</b></div>
    <div class="kpi"><span class="muted">পেন্ডিং পে</span><b>${inv.filter(i=>i.status!=='paid').length}</b></div>
  </div><div class="card"><h3>সর্বশেষ অর্ডার</h3>${rows(o.slice(0,8), x=>`${x.title} · ${x.number} · ${x.status} · ${money(x.price)}`)}</div>`;
  if(tab==='offers') body=offersUI();
  if(tab==='orders') body=`<div class="card">${(D.orders||[]).map(x=>`<div style="padding:8px 0;border-bottom:1px solid var(--line)">
    <b>${x.title}</b> ${x.number} ${money(x.price)} <span class="badge">${x.status}</span>
    <div class="row" style="margin-top:6px">
      <button class="btn" data-os="${x.id}" data-st="completed">সম্পন্ন</button>
      <button class="btn ghost" data-os="${x.id}" data-st="failed">ফেল</button>
    </div></div>`).join('')||'নেই'}</div>`;
  if(tab==='pay') body=`<div class="card">${(D.invoices||[]).map(i=>`<div style="padding:8px 0;border-bottom:1px solid var(--line)">
    ${i.method} ${money(i.amount)} · ${i.status} · ${i.trx_id||'-'} · ${i.paycode}
    ${i.status!=='paid'?`<div class="row" style="margin-top:6px"><button class="btn" data-iv="${i.id}" data-ok="1">অ্যাপ্রুভ</button>
    <button class="btn ghost" data-iv="${i.id}" data-ok="0">রিজেক্ট</button></div>`:''}
  </div>`).join('')||'নেই'}</div>`;
  if(tab==='users') body=`<div class="card">${(D.users||[]).map(x=>`<div style="padding:8px 0;border-bottom:1px solid var(--line)">
    ${x.name} · ${x.phone} · ওয়ালেট ${money(x.wallet)}
    <div class="row"><input placeholder="৳" id="w${x.id}" type="number" style="max-width:120px">
    <button class="btn" data-w="${x.id}">ক্রেডিট</button></div></div>`).join('')||'নেই'}</div>`;
  if(tab==='gw') body=gwUI();
  if(tab==='ops') body=opsUI();
  if(tab==='set') body=setUI();
  $('#box').innerHTML=nav()+body;
  $$('[data-t]').forEach(b=>b.onclick=()=>{tab=b.dataset.t;paint()});
  $$('[data-os]').forEach(b=>b.onclick=async()=>{await api('admin_order',{id:b.dataset.os,status:b.dataset.st}); start();});
  $$('[data-iv]').forEach(b=>b.onclick=async()=>{await api('admin_invoice',{id:b.dataset.iv,approve:b.dataset.ok==='1'}); start();});
  $$('[data-w]').forEach(b=>b.onclick=async()=>{await api('admin_user_wallet',{user_id:b.dataset.w,amount:document.getElementById('w'+b.dataset.w).value,type:'credit'}); start();});
  const so=$('#saveOffer'); if(so) so.onclick=saveOffer;
  const sg=$('#saveGw'); if(sg) sg.onclick=saveGw;
  const ss=$('#saveSet'); if(ss) ss.onclick=saveSet;
  const so2=$('#saveOps'); if(so2) so2.onclick=saveOps;
  $$('[data-del]').forEach(b=>b.onclick=async()=>{await api('admin_delete_offer',{id:b.dataset.del}); start();});
  $$('[data-ed]').forEach(b=>b.onclick=()=>fillOffer(b.dataset.ed));
}
function $$(s){return [...document.querySelectorAll(s)]}
function rows(list,fn){return list.map(x=>`<div class="muted" style="padding:6px 0;border-bottom:1px solid var(--line)">${fn(x)}</div>`).join('')}
function offersUI(){
  const ops=D.operators||[], cats=D.categories||[];
  return `<div class="card">
    <h3>অফার যোগ/এডিট</h3>
    <input type="hidden" id="oid">
    <label>টাইটেল</label><input id="otitle">
    <label>বিবরণ</label><input id="odesc">
    <label>অপারেটর</label><select id="oop">${ops.map(o=>`<option value="${o.code}">${o.name}</option>`).join('')}</select>
    <label>ক্যাটাগরি</label><select id="ocat">${cats.map(c=>`<option value="${c.id}">${c.name}</option>`).join('')}</select>
    <div class="row"><div><label>ফেস ভ্যালু</label><input id="oface" type="number"></div>
    <div><label>মূল্য</label><input id="oprice" type="number"></div></div>
    <div class="row"><div><label>ভ্যালিডিটি</label><input id="oval" value="৩০ দিন"></div>
    <div><label>স্টক</label><input id="ostock" type="number" value="50"></div></div>
    <label><input type="checkbox" id="oact" checked> অ্যাকটিভ</label>
    <label><input type="checkbox" id="ofeat" checked> ফিচার্ড</label>
    <button class="btn" id="saveOffer">সেভ অফার</button>
  </div>
  <div class="card">${(D.offers||[]).map(o=>`<div style="padding:8px 0;border-bottom:1px solid var(--line)">
    <b>${o.title}</b> ${o.operator} ${money(o.price)} স্টক ${o.stock}
    <div class="row"><button class="btn sec" data-ed="${o.id}">এডিট</button>
    <button class="btn ghost" data-del="${o.id}">ডিলিট</button></div></div>`).join('')}</div>`;
}
function fillOffer(id){
  const o=D.offers.find(x=>x.id===id); if(!o) return;
  oid.value=o.id; otitle.value=o.title; odesc.value=o.description; oop.value=o.operator; ocat.value=o.category;
  oface.value=o.face_value; oprice.value=o.price; oval.value=o.validity; ostock.value=o.stock; oact.checked=!!o.active; ofeat.checked=!!o.featured;
  window.scrollTo({top:0,behavior:'smooth'});
}
async function saveOffer(){
  await api('admin_save_offer',{
    id:oid.value,title:otitle.value,description:odesc.value,operator:oop.value,category:ocat.value,
    face_value:oface.value,price:oprice.value,validity:oval.value,stock:ostock.value,active:oact.checked,featured:ofeat.checked
  }); start();
}
function gwUI(){
  return `<div class="card"><p class="muted">অটো কনফার্ম ওয়েবহুক: POST api/index.php?action=gateway_auto — secret, trx, amount, paycode</p>
    ${(D.gateways||[]).map((g,i)=>`<div class="card">
      <b>${g.name}</b> (${g.code})
      <label>মার্চেন্ট</label><input class="gm" data-i="${i}" value="${g.merchant||''}">
      <label>নির্দেশনা</label><input class="gi" data-i="${i}" value="${g.instructions||''}">
      <label><input type="checkbox" class="ge" data-i="${i}" ${g.enabled?'checked':''}> চালু</label>
    </div>`).join('')}
    <button class="btn" id="saveGw">গেটওয়ে সেভ</button></div>`;
}
async function saveGw(){
  const g=D.gateways.map((x,i)=>({...x,merchant:document.querySelector(`.gm[data-i="${i}"]`).value,
    instructions:document.querySelector(`.gi[data-i="${i}"]`).value,
    enabled:document.querySelector(`.ge[data-i="${i}"]`).checked}));
  await api('admin_gateways',{gateways:g}); start();
}
function opsUI(){
  return `<div class="card">${(D.operators||[]).map((o,i)=>`<div class="card">
    <b>${o.name}</b>
    <label>রিচার্জ রেট %</label><input class="or" data-i="${i}" value="${o.recharge_rate}">
    <label><input type="checkbox" class="oa" data-i="${i}" ${o.active?'checked':''}> অ্যাকটিভ</label>
  </div>`).join('')}<button class="btn" id="saveOps">সেভ</button></div>`;
}
async function saveOps(){
  const operators=D.operators.map((o,i)=>({...o,recharge_rate:Number(document.querySelector(`.or[data-i="${i}"]`).value),active:document.querySelector(`.oa[data-i="${i}"]`).checked}));
  await api('admin_operators',{operators}); start();
}
function setUI(){
  const s=D.settings||{};
  return `<div class="card">
    <label>সাইট নাম</label><input id="sn" value="${s.site_name||''}">
    <label>ট্যাগলাইন</label><input id="st" value="${s.tagline||''}">
    <label>নোটিশ</label><input id="snote" value="${s.notice||''}">
    <label>হোয়াটসঅ্যাপ</label><input id="sw" value="${s.whatsapp||''}">
    <label>গেটওয়ে সিক্রেট</label><input id="ssc" value="${s.gateway_secret||''}">
    <label>মিন টপআপ</label><input id="smin" type="number" value="${s.min_topup||20}">
    <label><input type="checkbox" id="sap" ${s.auto_approve_payments?'checked':''}> পেমেন্ট অটো অ্যাপ্রুভ (Trx ফরম্যাট ঠিক থাকলে)</label>
    <label><input type="checkbox" id="sao" ${s.auto_process_orders?'checked':''}> অর্ডার অটো প্রসেস</label>
    <button class="btn" id="saveSet">সেটিংস সেভ</button>
  </div>`;
}
async function saveSet(){
  await api('admin_settings',{
    site_name:sn.value,tagline:st.value,notice:snote.value,whatsapp:sw.value,gateway_secret:ssc.value,
    min_topup:smin.value,auto_approve_payments:sap.checked,auto_process_orders:sao.checked
  }); start();
}
start();
</script>
</body>
</html>
