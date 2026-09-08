const $ = (s, r=document) => r.querySelector(s);
const $$ = (s, r=document) => [...r.querySelectorAll(s)];
const state = { boot:null, view:'home', filter:{op:'all', cat:'all'}, user:null };

async function api(action, body={}) {
  const res = await fetch('api/index.php?action=' + encodeURIComponent(action), {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify(body)
  });
  return res.json();
}
function toast(m){ const t=$('#toast'); t.textContent=m; t.style.display='block'; setTimeout(()=>t.style.display='none', 2400); }
function money(n){ return '৳' + Number(n).toFixed(2); }
function opColor(c){ return ({gp:'#00a651',robi:'#ed1c24',airtel:'#c00',bl:'#f26522',tt:'#3db5ff'}[c]||'#2ee6a6'); }
function opName(c){ const o=(state.boot?.operators||[]).find(x=>x.code===c); return o?o.name:c; }

function setView(v){
  state.view=v;
  $$('.nav button, .side button').forEach(b=>b.classList.toggle('on', b.dataset.v===v));
  render();
}

function render(){
  const m=$('#main');
  const map={home:viewHome,offers:viewOffers,recharge:viewRecharge,wallet:viewWallet,account:viewAccount};
  m.innerHTML = (map[state.view]||viewHome)();
  bind();
}

function viewHome(){
  const s=state.boot.settings||{};
  const cats=state.boot.categories||[];
  return `
    <div class="hero">
      <h1>${s.site_name||'XP Telecom'}</h1>
      <p>${s.tagline||''}</p>
    </div>
    <div class="notice">${s.notice||''}</div>
    <div class="grid">${cats.map(c=>`<div class="tile" data-go="${c.id==='recharge'?'recharge':'offers'}" data-cat="${c.id}"><span class="ic">${c.icon}</span>${c.name}</div>`).join('')}</div>
    <div class="muted" style="margin:8px 0">জনপ্রিয় অফার</div>
    <div class="list">${offerCards((state.boot.offers||[]).filter(o=>o.featured).slice(0,8))}</div>`;
}

function offerCards(list){
  if(!list.length) return '<div class="card muted">কোন অফার নেই</div>';
  return list.map(o=>`
    <div class="card offer" data-buy="${o.id}">
      <div class="opdot" style="background:${opColor(o.operator)}22;color:${opColor(o.operator)}">${(o.operator||'').toUpperCase()}</div>
      <div style="flex:1">
        <h3>${o.title}</h3>
        <div class="meta">${opName(o.operator)} · ${o.validity} · স্টক ${o.stock}</div>
        <div class="meta">${o.description||''}</div>
      </div>
      <div class="price"><b>${money(o.price)}</b><s>${money(o.face_value)}</s></div>
    </div>`).join('');
}

function viewOffers(){
  const ops=[{code:'all',name:'সব'},...(state.boot.operators||[])];
  const cats=[{id:'all',name:'সব'},...(state.boot.categories||[]).filter(c=>c.id!=='recharge')];
  let list=state.boot.offers||[];
  if(state.filter.op!=='all') list=list.filter(o=>o.operator===state.filter.op);
  if(state.filter.cat!=='all') list=list.filter(o=>o.category===state.filter.cat);
  return `
    <div class="ops">${ops.map(o=>`<button class="chip ${state.filter.op===o.code?'on':''}" data-op="${o.code}">${o.name}</button>`).join('')}</div>
    <div class="ops">${cats.map(c=>`<button class="chip ${state.filter.cat===c.id?'on':''}" data-cat="${c.id}">${c.name||c.id}</button>`).join('')}</div>
    <div class="list">${offerCards(list)}</div>`;
}

function viewRecharge(){
  const ops=state.boot.operators||[];
  return `<div class="card">
    <h3>মোবাইল রিচার্জ</h3>
    <label>অপারেটর</label>
    <select id="rop">${ops.map(o=>`<option value="${o.code}">${o.name} (${o.recharge_rate}%)</option>`).join('')}</select>
    <label>নম্বর</label><input id="rnum" inputmode="numeric" maxlength="11" placeholder="01XXXXXXXXX">
    <label>পরিমাণ</label><input id="ramt" type="number" min="10" value="50">
    <div class="ops">${[20,50,100,200,500].map(n=>`<button class="chip" data-amt="${n}">৳${n}</button>`).join('')}</div>
    <label>পেমেন্ট</label>
    <select id="rmethod">${(state.boot.gateways||[]).map(g=>`<option value="${g.code}">${g.name}</option>`).join('')}</select>
    <div style="height:10px"></div>
    <button class="btn" id="rgo">রিচার্জ করুন</button>
  </div>`;
}

function viewWallet(){
  const u=state.user;
  if(!u) return authBox();
  const gws=(state.boot.gateways||[]).filter(g=>g.code!=='wallet');
  return `<div class="card"><div class="muted">ওয়ালেট ব্যালেন্স</div><h1>${money(u.wallet||0)}</h1></div>
    <div class="card">
      <h3>টপআপ</h3>
      <label>পরিমাণ</label><input id="tamt" type="number" value="100">
      <label>মাধ্যম</label>
      <select id="tmethod">${gws.map(g=>`<option value="${g.code}">${g.name}</option>`).join('')}</select>
      <div style="height:10px"></div>
      <button class="btn" id="tgo">পেমেন্ট তৈরি</button>
    </div>
    <div id="hist"></div>`;
}

function viewAccount(){
  if(!state.user) return authBox();
  return `<div class="card">
    <h3>${state.user.name}</h3>
    <div class="muted">${state.user.phone}</div>
    <div class="muted">ওয়ালেট ${money(state.user.wallet||0)}</div>
    <div style="height:10px"></div>
    <button class="btn ghost" id="logout">লগআউট</button>
  </div>
  <div class="card"><h3>অর্ডার</h3><div id="olist" class="muted">লোড হচ্ছে...</div></div>`;
}

function authBox(){
  return `<div class="card">
    <h3>লগইন / রেজিস্টার</h3>
    <label>নাম</label><input id="aname" placeholder="আপনার নাম">
    <label>মোবাইল</label><input id="aphone" inputmode="numeric" placeholder="01XXXXXXXXX">
    <label>পাসওয়ার্ড</label><input id="apass" type="password">
    <div class="row" style="margin-top:12px">
      <button class="btn" id="alogin">লগইন</button>
      <button class="btn sec" id="areg">রেজিস্টার</button>
    </div>
  </div>`;
}

function bind(){
  $$('[data-go]').forEach(el=>el.onclick=()=>{ if(el.dataset.cat) state.filter.cat=el.dataset.cat; setView(el.dataset.go); });
  $$('[data-op]').forEach(el=>el.onclick=()=>{ state.filter.op=el.dataset.op; render(); });
  $$('[data-cat]').forEach(el=>{ if(!el.dataset.go) el.onclick=()=>{ state.filter.cat=el.dataset.cat; render(); }; });
  $$('[data-buy]').forEach(el=>el.onclick=()=>openBuy(el.dataset.buy));
  $$('[data-amt]').forEach(el=>el.onclick=()=>$('#ramt').value=el.dataset.amt);
  $('#rgo')&&($('#rgo').onclick=doRecharge);
  $('#tgo')&&($('#tgo').onclick=doTopup);
  $('#alogin')&&($('#alogin').onclick=()=>doAuth('login'));
  $('#areg')&&($('#areg').onclick=()=>doAuth('register'));
  $('#logout')&&($('#logout').onclick=async()=>{ await api('logout'); location.reload(); });
  if(state.view==='account' && state.user) loadMe();
  if(state.view==='wallet' && state.user) loadMeWallet();
}

async function doAuth(kind){
  const body={name:$('#aname').value, phone:$('#aphone').value, password:$('#apass').value};
  const r=await api(kind, body);
  if(!r.ok) return toast(r.error);
  toast('সফল');
  await boot();
}

async function loadMe(){
  const r=await api('me');
  if(!r.ok) return;
  state.user=r.user;
  $('#olist').innerHTML = (r.orders||[]).slice(0,20).map(o=>`<div style="padding:8px 0;border-bottom:1px solid var(--line)">
    <b>${o.title}</b> · ${o.number}<div class="muted">${o.status} · ${money(o.price)} · ${o.created_at}</div></div>`).join('') || 'কোন অর্ডার নেই';
}
async function loadMeWallet(){
  const r=await api('me');
  if(!r.ok) return;
  state.user=r.user;
  const box=$('#hist');
  if(box) box.innerHTML = (r.wallet||[]).slice().reverse().slice(0,15).map(w=>`<div class="card">${w.type==='credit'?'+':'-'}${money(w.amount)} · ${w.note}<div class="muted">${w.created_at}</div></div>`).join('');
}

function openBuy(id){
  const o=(state.boot.offers||[]).find(x=>x.id===id);
  if(!o) return;
  const gws=state.boot.gateways||[];
  showSheet(`<h3>${o.title}</h3><p class="muted">${o.description}</p>
    <label>প্রাপক নম্বর</label><input id="bnum" inputmode="numeric" maxlength="11">
    <label>পেমেন্ট</label><select id="bmethod">${gws.map(g=>`<option value="${g.code}">${g.name}</option>`).join('')}</select>
    <div style="height:12px"></div>
    <button class="btn" id="bgo">কিনুন ${money(o.price)}</button>`);
  $('#bgo').onclick=async()=>{
    const r=await api('order',{type:'offer',offer_id:o.id,number:$('#bnum').value,method:$('#bmethod').value});
    handlePay(r);
  };
}

async function doRecharge(){
  const r=await api('order',{type:'recharge',operator:$('#rop').value,number:$('#rnum').value,amount:$('#ramt').value,method:$('#rmethod').value});
  handlePay(r);
}
async function doTopup(){
  const r=await api('topup',{amount:$('#tamt').value,method:$('#tmethod').value});
  handlePay(r);
}

function handlePay(r){
  if(!r.ok) return toast(r.error);
  const inv=r.invoice;
  if(inv.status==='paid'){
    hideSheet(); toast('সফল! অর্ডার প্রসেসিং'); boot(); return;
  }
  showSheet(`<h3>${inv.method.toUpperCase()} পেমেন্ট</h3>
    <p>পরিমাণ <b>${money(inv.amount)}</b></p>
    <p>মার্চেন্ট</p><div class="copy" id="mer">${inv.merchant}</div>
    <p>পে-কোড (রেফারেন্স)</p><div class="copy">${inv.paycode}</div>
    <p class="muted">${inv.instructions||''}</p>
    <label>আপনার নম্বর</label><input id="psender" inputmode="numeric">
    <label>TrxID</label><input id="ptrx">
    <div style="height:10px"></div>
    <button class="btn" id="psend">জমা দিন</button>`);
  $('#psend').onclick=async()=>{
    const x=await api('pay_trx',{invoice_id:inv.id,trx_id:$('#ptrx').value,sender:$('#psender').value});
    if(!x.ok) return toast(x.error);
    toast(x.invoice.status==='paid'?'অটো কনফার্মড':'রিভিউতে গেছে');
    hideSheet(); boot();
  };
}

function showSheet(html){ const m=$('#modal'); m.classList.add('show'); $('.sheet',m).innerHTML='<button class="btn ghost" onclick="hideSheet()">বন্ধ</button>'+html; }
function hideSheet(){ $('#modal').classList.remove('show'); }

async function boot(){
  const b=await api('boot');
  state.boot=b; state.user=b.user;
  $('#bname').textContent=b.settings.site_name||'XP';
  render();
}

$$('.nav button, .side button').forEach(b=>b.onclick=()=>setView(b.dataset.v));
boot();
window.hideSheet=hideSheet;
