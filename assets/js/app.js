const $ = (s, r=document) => r.querySelector(s);
const $$ = (s, r=document) => [...r.querySelectorAll(s)];
const state = { boot:null, view:'home', filter:{op:'all', cat:'all'}, user:null, me:null, meAt:0, notifs:null, unread:0, svcTimer:null, pollTimer:null };

async function api(action, body={}) {
  const res = await fetch('api/index.php?action=' + encodeURIComponent(action), {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify(body)
  });
  return res.json();
}
function esc(s){ return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function toast(m){ const t=$('#toast'); t.textContent=m; t.style.display='block'; clearTimeout(t._h); t._h=setTimeout(()=>t.style.display='none', 2600); }
function money(n){ return '৳' + Number(n||0).toFixed(2); }
function opColor(c){ return ({gp:'#00a651',robi:'#ed1c24',airtel:'#c00',bl:'#f26522',tt:'#3db5ff'}[c]||'#2ee6a6'); }
function opName(c){ const o=(state.boot?.operators||[]).find(x=>x.code===c); return o?o.name:c; }
function catName(id){ const c=(state.boot?.categories||[]).find(x=>x.id===id); return c?c.name:id; }
function ago(ts){ if(!ts) return ''; const d=(Date.now()-new Date(ts.replace(' ','T')))/1000; if(d<60) return 'এইমাত্র'; if(d<3600) return Math.floor(d/60)+' মিনিট আগে'; if(d<86400) return Math.floor(d/3600)+' ঘন্টা আগে'; return ts.slice(0,16); }
const ST={awaiting_payment:['পেমেন্ট বাকি','wait'],processing:['প্রসেসিং চলছে','proc'],completed:['সম্পন্ন ✅','ok'],failed:['ব্যর্থ','bad'],cancelled:['বাতিল','mut'],pending:['পেন্ডিং','wait'],review:['যাচাই চলছে','proc'],paid:['পেইড','ok'],rejected:['রিজেক্ট','bad']};
function badge(s){ const [n,c]=ST[s]||[s,'mut']; return `<span class="badge ${c}">${n}</span>`; }
function copyText(t){ (navigator.clipboard?navigator.clipboard.writeText(t):Promise.reject()).then(()=>toast('কপি হয়েছে')).catch(()=>{const ta=document.createElement('textarea');ta.value=t;document.body.appendChild(ta);ta.select();document.execCommand('copy');ta.remove();toast('কপি হয়েছে');}); }

function setView(v){
  state.view=v;
  $$('.nav button, .side button').forEach(b=>b.classList.toggle('on', b.dataset.v===v));
  render();
  window.scrollTo({top:0});
}

function render(){
  const m=$('#main');
  const map={home:viewHome,offers:viewOffers,recharge:viewRecharge,wallet:viewWallet,account:viewAccount,notifs:viewNotifs};
  m.innerHTML = (map[state.view]||viewHome)();
  bind();
}

/* ---------- service hours banner ---------- */
function svcBanner(compact){
  const s=state.boot?.service; if(!s) return '';
  const ic=s.state==='open'?'🟢':s.state==='prayer'?'🕌':'🌙';
  const title=s.state==='open'?`সার্ভিস চালু আছে`:s.state==='prayer'?`${esc(s.break_name)} নামাজের বিরতি`:`এখন সার্ভিস বন্ধ`;
  const sub=s.state==='open'
    ? `সাধারণত ${esc(s.avg_minutes)} মিনিটের মধ্যে অফার হিট হয় · সার্ভিস সময় ${esc(s.hours_label)}`
    : s.state==='prayer'
      ? `${esc(bnClock(s.resumes_at))} এর পর সিরিয়াল অনুযায়ী অর্ডার হিট হবে · এখন অর্ডার দিলে লাইনে থাকবে`
      : `সার্ভিস সময় ${esc(s.hours_label)} · এখন অর্ডার দিলে ${esc(bnClock(s.start))} থেকে সিরিয়াল অনুযায়ী হিট হবে`;
  return `<div class="svc ${s.state}"><div class="ic">${ic}</div><div><b>${title}</b><small>${sub}</small>${compact?'':`<small style="margin-top:4px">নামাজের সময় (${(s.prayer_breaks||[]).map(b=>esc(b.name)).join(', ')}) ২০–৩০ মিনিট বিরতি থাকে। ধৈর্য ধরার জন্য ধন্যবাদ 🙏</small>`}</div></div>`;
}
function bnClock(hhmm){
  if(!hhmm) return '';
  const [h,m]=hhmm.split(':').map(Number);
  const p=h>=4&&h<12?'সকাল':h<15?'দুপুর':h<18?'বিকাল':h<20?'সন্ধ্যা':'রাত';
  let h12=h%12; if(h12===0) h12=12;
  const bn=n=>String(n).replace(/\d/g,d=>'০১২৩৪৫৬৭৮৯'[d]);
  return `${p} ${bn(h12)}${m?':'+bn(String(m).padStart(2,'0')):'টা'}`;
}

function viewHome(){
  const s=state.boot.settings||{};
  const cats=state.boot.categories||[];
  const svc=state.boot.service||{};
  return `
    <div class="hero">
      <h1>${esc(s.site_name||'XP Telecom')}</h1>
      <p>${esc(s.tagline||'')}</p>
      <div class="svcline"><span class="pulse ${svc.state}"></span>${svc.state==='open'?'এখন চালু':svc.state==='prayer'?'নামাজের বিরতি':'বন্ধ'} · ${esc(svc.hours_label||'')}</div>
    </div>
    ${s.notice?`<div class="notice">📢 ${esc(s.notice)}</div>`:''}
    ${svcBanner(true)}
    <div class="grid">${cats.map(c=>`<div class="tile" data-go="${c.id==='recharge'?'recharge':'offers'}" data-cat="${c.id}"><span class="ic">${c.icon}</span>${esc(c.name)}</div>`).join('')}</div>
    <div class="muted" style="margin:8px 0">জনপ্রিয় অফার</div>
    <div class="list">${offerCards((state.boot.offers||[]).filter(o=>o.featured).slice(0,8))}</div>`;
}

function offerCards(list){
  if(!list.length) return '<div class="card muted">কোন অফার নেই</div>';
  return list.map(o=>`
    <div class="card offer" data-buy="${o.id}">
      <div class="opdot" style="background:${opColor(o.operator)}22;color:${opColor(o.operator)}">${esc((o.operator||'').toUpperCase())}</div>
      <div style="flex:1;min-width:0">
        <h3>${esc(o.title)}</h3>
        <div class="meta">${esc(opName(o.operator))} · ${esc(o.validity)} · ${o.stock>0||o.stock===-1?'স্টক আছে':'<span style="color:var(--bad)">স্টক শেষ</span>'}${o.category==='drive'?' · <span class="tag">ম্যানুয়াল হিট</span>':''}</div>
        <div class="meta">${esc(o.description||'')}</div>
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
    ${state.filter.cat==='drive'?svcBanner(false):''}
    <div class="ops">${ops.map(o=>`<button class="chip ${state.filter.op===o.code?'on':''}" data-op="${o.code}">${esc(o.name)}</button>`).join('')}</div>
    <div class="ops">${cats.map(c=>`<button class="chip ${state.filter.cat===c.id?'on':''}" data-cat="${c.id}">${esc(c.name||c.id)}</button>`).join('')}</div>
    <div class="list">${offerCards(list)}</div>`;
}

function viewRecharge(){
  const ops=state.boot.operators||[];
  return `<div class="card">
    <h3>মোবাইল রিচার্জ</h3>
    <label>অপারেটর</label>
    <select id="rop">${ops.map(o=>`<option value="${o.code}">${esc(o.name)} (${o.recharge_rate}%)</option>`).join('')}</select>
    <label>নম্বর</label><input id="rnum" inputmode="numeric" maxlength="11" placeholder="01XXXXXXXXX">
    <label>পরিমাণ</label><input id="ramt" type="number" min="10" value="50">
    <div class="ops">${[20,50,100,200,500].map(n=>`<button class="chip" data-amt="${n}">৳${n}</button>`).join('')}</div>
    <label>পেমেন্ট</label>
    <select id="rmethod">${(state.boot.gateways||[]).map(g=>`<option value="${g.code}">${esc(g.name)}</option>`).join('')}</select>
    <div style="height:10px"></div>
    <button class="btn" id="rgo">রিচার্জ করুন</button>
  </div>`;
}

function viewWallet(){
  const u=state.user;
  if(!u) return authBox();
  const gws=(state.boot.gateways||[]).filter(g=>g.code!=='wallet');
  return `<div class="card"><div class="muted">ওয়ালেট ব্যালেন্স</div><h1 style="margin:4px 0">${money(u.wallet||0)}</h1></div>
    <div class="card">
      <h3>টপআপ</h3>
      <label>পরিমাণ</label><input id="tamt" type="number" value="100">
      <label>মাধ্যম</label>
      <select id="tmethod">${gws.map(g=>`<option value="${g.code}">${esc(g.name)}</option>`).join('')}</select>
      <div style="height:10px"></div>
      <button class="btn" id="tgo">পেমেন্ট তৈরি</button>
    </div>
    <div class="card"><h3>লেনদেন</h3><div id="hist"><div class="sk"></div></div></div>`;
}

function viewAccount(){
  if(!state.user) return authBox();
  return `<div class="card">
    <h3 style="margin-bottom:2px">${esc(state.user.name)}</h3>
    <div class="muted">${esc(state.user.phone)} · ওয়ালেট ${money(state.user.wallet||0)}</div>
    <div style="height:10px"></div>
    <div class="row"><button class="btn sec" data-v="notifs">🔔 নোটিফিকেশন</button><button class="btn ghost" id="logout">লগআউট</button></div>
  </div>
  ${svcBanner(true)}
  <div class="card"><h3>আমার অর্ডার</h3><div id="olist"><div class="sk"></div><div class="sk" style="margin-top:8px;width:70%"></div></div></div>`;
}

const NIC={order:'🧾',payment:'💳',wallet:'👛',info:'📢',system:'⚙️'};
function viewNotifs(){
  if(!state.user) return authBox();
  return `<div class="card"><h3>🔔 নোটিফিকেশন</h3><div id="nlist"><div class="sk"></div><div class="sk" style="margin-top:8px;width:60%"></div></div></div>`;
}
function paintNotifs(){
  const box=$('#nlist'); if(!box) return;
  const list=state.notifs||[]; const seen=state.seenTs||0;
  box.innerHTML=list.length?list.map(n=>`<div class="notif ${(n.ts||0)>seen?'new':''}"><div class="ic">${NIC[n.type]||'🔔'}</div><div style="min-width:0"><b>${esc(n.title)}</b><p>${esc(n.body)}</p><time>${ago(n.created_at)}</time></div></div>`).join(''):'<div class="empty">এখনো কোন নোটিফিকেশন নেই</div>';
}

function authBox(){
  return `<div class="card">
    <h3>লগইন / রেজিস্টার</h3>
    <label>নাম (রেজিস্টারের জন্য)</label><input id="aname" placeholder="আপনার নাম">
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
  $$('#main [data-v]').forEach(el=>el.onclick=()=>setView(el.dataset.v));
  $$('[data-op]').forEach(el=>el.onclick=()=>{ state.filter.op=el.dataset.op; render(); });
  $$('[data-cat]').forEach(el=>{ if(!el.dataset.go) el.onclick=()=>{ state.filter.cat=el.dataset.cat; render(); }; });
  $$('[data-buy]').forEach(el=>el.onclick=()=>openBuy(el.dataset.buy));
  $$('[data-amt]').forEach(el=>el.onclick=()=>$('#ramt').value=el.dataset.amt);
  $('#rgo')&&($('#rgo').onclick=doRecharge);
  $('#tgo')&&($('#tgo').onclick=doTopup);
  $('#alogin')&&($('#alogin').onclick=()=>doAuth('login'));
  $('#areg')&&($('#areg').onclick=()=>doAuth('register'));
  $('#apass')&&($('#apass').onkeydown=e=>{ if(e.key==='Enter') doAuth('login'); });
  $('#logout')&&($('#logout').onclick=async()=>{ await api('logout'); location.reload(); });
  if(state.view==='account' && state.user) loadMe();
  if(state.view==='wallet' && state.user) loadMeWallet();
  if(state.view==='notifs' && state.user) loadNotifs(true);
}

async function doAuth(kind){
  const body={name:$('#aname').value, phone:$('#aphone').value, password:$('#apass').value};
  const r=await api(kind, body);
  if(!r.ok) return toast(r.error);
  toast(kind==='login'?'স্বাগতম!':'অ্যাকাউন্ট তৈরি হয়েছে');
  await boot();
}

/* cached /me so switching tabs doesn't refetch every time */
async function getMe(force){
  if(!force && state.me && Date.now()-state.meAt<15000) return state.me;
  const r=await api('me'); if(!r.ok) return null;
  state.me=r; state.meAt=Date.now(); state.user=r.user; if(r.service) state.boot.service=r.service;
  return r;
}
function orderSteps(o){
  const paid=['processing','completed'].includes(o.status);
  const done=o.status==='completed';
  const failed=['failed','cancelled'].includes(o.status);
  const s=state.boot.service||{};
  const waitMsg=o.status==='processing'?(s.state==='open'?`সাধারণত ${esc(s.avg_minutes)} মিনিটে হিট হয়`:s.state==='prayer'?`${esc(s.break_name)} নামাজের বিরতি, ${esc(bnClock(s.resumes_at))} এর পর হিট হবে`:`${esc(bnClock(s.start))} থেকে সিরিয়াল অনুযায়ী হিট হবে`):'';
  return `<div class="steps">
    <div class="step done"><span class="n">✓</span><span>অর্ডার গৃহীত · ${esc(o.created_at)}</span></div>
    <div class="step ${paid||done?'done':failed?'':'now'}"><span class="n">${paid||done?'✓':'2'}</span><span>${paid||done?'পেমেন্ট কনফার্মড':'পেমেন্ট / TrxID যাচাই বাকি'}</span></div>
    <div class="step ${done?'done':failed?'':paid?'now':''}"><span class="n">${done?'✓':failed?'✕':'3'}</span><span>${done?'অফার হিট হয়েছে ✅ '+esc(o.processed_at||''):failed?'ব্যর্থ'+(o.admin_note?' · '+esc(o.admin_note):'')+(o.refunded?' · টাকা ওয়ালেটে ফেরত':''):paid?'হিট করার লাইনে আছে ⏳ '+waitMsg:'অপেক্ষমাণ'}</span></div>
  </div>`;
}
async function loadMe(){
  const r=await getMe(false); if(!r) return;
  const box=$('#olist'); if(!box) return;
  box.innerHTML = (r.orders||[]).slice(0,20).map(o=>`<div class="item">
    <div class="hd"><b>${esc(o.title)}</b>${badge(o.status)}</div>
    <div class="sub"><span class="mono">${esc(o.number)}</span> · ${money(o.price)} · ${ago(o.created_at)}</div>
    ${['processing','awaiting_payment'].includes(o.status)?orderSteps(o):''}
    ${o.status==='awaiting_payment'&&o.invoice_id?`<div class="acts"><button class="btn sm" data-payinv="${o.invoice_id}">পেমেন্ট করুন / TrxID দিন</button></div>`:''}
    </div>`).join('') || '<div class="empty">কোন অর্ডার নেই</div>';
  $$('[data-payinv]').forEach(b=>b.onclick=()=>{ const inv=(r.invoices||[]).find(i=>i.id===b.dataset.payinv); if(inv) handlePay({ok:true,invoice:inv}); });
}
async function loadMeWallet(){
  const r=await getMe(false); if(!r) return;
  const box=$('#hist');
  if(box) box.innerHTML = (r.wallet||[]).slice().reverse().slice(0,15).map(w=>`<div class="item"><div class="hd"><b style="color:${w.type==='credit'?'var(--acc)':'var(--bad)'}">${w.type==='credit'?'+':'-'}${money(w.amount)}</b><span class="muted">ব্যালেন্স ${money(w.balance)}</span></div><div class="sub">${esc(w.note)} · ${ago(w.created_at)}</div></div>`).join('') || '<div class="empty">কোন লেনদেন নেই</div>';
}

/* ---------- notifications ---------- */
function setBell(n){ const c=$('#bellcnt'); if(!c) return; c.textContent=n>99?'99+':n; c.classList.toggle('on',n>0); if(n>state.unread){ const b=$('#bell'); b.classList.remove('ring'); void b.offsetWidth; b.classList.add('ring'); } state.unread=n; }
async function loadNotifs(markRead){
  const r=await api('notifications',{mark_read:!!markRead}); if(!r.ok) return;
  state.notifs=r.list; state.seenTs=r.seen_ts||0; if(r.service) state.boot.service=r.service;
  paintNotifs();
  if(markRead) setBell(0);
}
async function pollNotifs(){
  if(!state.user || document.hidden) return;
  const r=await api('notifications',{}); if(!r.ok) return;
  if(r.service) state.boot.service=r.service;
  if(r.unread>state.unread){ toast('🔔 '+(r.list[0]?.title||'নতুন নোটিফিকেশন')); state.me=null; if(state.view==='account') loadMe(); }
  setBell(r.unread);
}

function openBuy(id){
  const o=(state.boot.offers||[]).find(x=>x.id===id);
  if(!o) return;
  if(!state.user){ toast('আগে লগইন করুন'); setView('account'); return; }
  const gws=state.boot.gateways||[];
  const svc=state.boot.service||{};
  const manual=o.category==='drive'||svc.scope==='all';
  showSheet(`<h3>${esc(o.title)}</h3><p class="muted" style="margin:0 0 8px">${esc(o.description||'')} · ${esc(o.validity)}</p>
    ${manual?svcBanner(false):''}
    <label>প্রাপক নম্বর (${esc(opName(o.operator))})</label><input id="bnum" inputmode="numeric" maxlength="11" placeholder="01XXXXXXXXX">
    <label>পেমেন্ট</label><select id="bmethod">${gws.map(g=>`<option value="${g.code}">${esc(g.name)}${g.code==='wallet'?' ('+money(state.user.wallet||0)+')':''}</option>`).join('')}</select>
    <div style="height:12px"></div>
    <button class="btn" id="bgo">কিনুন ${money(o.price)}</button>
    <p class="muted" style="margin:10px 0 0">নম্বরটি আবার মিলিয়ে নিন। ভুল নম্বরে অফার গেলে ফেরত দেওয়া সম্ভব নয়।</p>`);
  $('#bgo').onclick=async()=>{
    const num=$('#bnum').value.replace(/\D/g,'');
    if(!/^01[3-9]\d{8}$/.test(num)) return toast('সঠিক ১১ ডিজিটের নম্বর দিন');
    $('#bgo').disabled=true; $('#bgo').textContent='অর্ডার হচ্ছে…';
    const r=await api('order',{type:'offer',offer_id:o.id,number:num,method:$('#bmethod').value});
    if(!r.ok){ $('#bgo').disabled=false; $('#bgo').textContent='কিনুন '+money(o.price); }
    handlePay(r);
  };
}

async function doRecharge(){
  if(!state.user){ toast('আগে লগইন করুন'); setView('account'); return; }
  const num=$('#rnum').value.replace(/\D/g,'');
  if(!/^01[3-9]\d{8}$/.test(num)) return toast('সঠিক ১১ ডিজিটের নম্বর দিন');
  const r=await api('order',{type:'recharge',operator:$('#rop').value,number:num,amount:$('#ramt').value,method:$('#rmethod').value});
  handlePay(r);
}
async function doTopup(){
  const r=await api('topup',{amount:$('#tamt').value,method:$('#tmethod').value});
  handlePay(r);
}

function handlePay(r){
  if(!r.ok) return toast(r.error);
  const inv=r.invoice;
  if(r.service) state.boot.service=r.service;
  if(inv.status==='paid'){
    state.me=null;
    if(r.order) return showOrderDone(r.order);
    hideSheet(); toast('সফল!'); boot(); return;
  }
  showSheet(`<h3>${esc(String(inv.method).toUpperCase())} পেমেন্ট</h3>
    <div class="kv"><span>পরিমাণ</span><b class="big">${money(inv.amount)}</b></div>
    <p style="margin:10px 0 4px">মার্চেন্ট নম্বর <span class="muted">(ট্যাপ করে কপি)</span></p><div class="copy" data-copy="${esc(inv.merchant)}">${esc(inv.merchant)}</div>
    <p style="margin:10px 0 4px">পে-কোড / রেফারেন্স</p><div class="copy" data-copy="${esc(inv.paycode)}">${esc(inv.paycode)}</div>
    <p class="muted">${esc(inv.instructions||'')}</p>
    <label>আপনার নম্বর (যেটা থেকে পাঠালেন)</label><input id="psender" inputmode="numeric" maxlength="11">
    <label>TrxID</label><input id="ptrx" placeholder="যেমন: 9AB12CD3EF">
    <div style="height:10px"></div>
    <button class="btn" id="psend">জমা দিন</button>
    <p class="muted" style="margin-top:10px">TrxID জমা দিলে অটো/ম্যানুয়াল যাচাই হবে। কনফার্ম হলে নোটিফিকেশন পাবেন 🔔</p>`);
  $$('.sheet [data-copy]').forEach(el=>el.onclick=()=>copyText(el.dataset.copy));
  $('#psend').onclick=async()=>{
    const trx=$('#ptrx').value.trim(); if(trx.length<6) return toast('সঠিক TrxID দিন');
    $('#psend').disabled=true;
    const x=await api('pay_trx',{invoice_id:inv.id,trx_id:trx,sender:$('#psender').value});
    if(!x.ok){ $('#psend').disabled=false; return toast(x.error); }
    state.me=null;
    if(x.invoice.status==='paid'){
      if(r.order){ const me=await getMe(true); const o=(me?.orders||[]).find(z=>z.id===r.order.id)||r.order; return showOrderDone(o); }
      hideSheet(); toast('পেমেন্ট কনফার্মড ✅'); boot(); return;
    }
    showSheet(`<h3>TrxID জমা হয়েছে ✅</h3>
      <div class="steps">
        <div class="step done"><span class="n">✓</span><span>অর্ডার/অনুরোধ গৃহীত</span></div>
        <div class="step now"><span class="n">2</span><span>পেমেন্ট যাচাই চলছে — সাধারণত কিছু মিনিট লাগে</span></div>
        <div class="step"><span class="n">3</span><span>কনফার্ম হলে ${r.order?'অফার হিট হবে':'ওয়ালেটে টাকা যোগ হবে'}</span></div>
      </div>
      ${svcBanner(true)}
      <p class="muted">আপডেট পেতে 🔔 নোটিফিকেশন দেখুন। ধৈর্য ধরার জন্য ধন্যবাদ।</p>
      <button class="btn" onclick="hideSheet();setView('account')">আমার অর্ডার দেখুন</button>`);
    boot(false);
  };
}
function showOrderDone(o){
  const s=state.boot.service||{};
  const done=o.status==='completed';
  showSheet(`<h3>${done?'অর্ডার সম্পন্ন ✅':'অর্ডার গৃহীত ⏳'}</h3>
    <div class="kv"><span>অফার</span><span>${esc(o.title)}</span><span>নম্বর</span><span class="mono">${esc(o.number)}</span><span>মূল্য</span><span>${money(o.price)}</span></div>
    ${orderSteps(o)}
    ${done?'':svcBanner(false)}
    ${done?'':`<p class="muted">হিট হলে সাথে সাথে নোটিফিকেশন পাবেন 🔔। ${s.state==='open'?'সাধারণত '+esc(s.avg_minutes)+' মিনিট লাগে।':''} ধৈর্য ধরার জন্য ধন্যবাদ।</p>`}
    <button class="btn" onclick="hideSheet();setView('account')">আমার অর্ডার দেখুন</button>`);
  boot(false);
}

function showSheet(html){ const m=$('#modal'); m.classList.add('show'); $('.sheet',m).innerHTML='<button class="btn ghost" onclick="hideSheet()">বন্ধ</button>'+html; }
function hideSheet(){ $('#modal').classList.remove('show'); }
$('#modal').onclick=e=>{ if(e.target.id==='modal') hideSheet(); };

async function boot(rerender=true){
  const b=await api('boot');
  state.boot=b; state.user=b.user;
  $('#bname').textContent=b.settings.site_name||'XP';
  setBell(b.notif_unread||0);
  if(rerender) render();
  if(!state.pollTimer){ state.pollTimer=setInterval(pollNotifs,30000); state.svcTimer=setInterval(async()=>{ const r=await api('service'); if(r.ok&&state.boot){ const ch=r.service.state!==state.boot.service?.state; state.boot.service=r.service; if(ch&&state.view==='home') render(); } },60000); }
}

$$('.nav button, .side button').forEach(b=>b.onclick=()=>setView(b.dataset.v));
$('#bell').onclick=()=>{ if(!state.user){ toast('নোটিফিকেশন দেখতে লগইন করুন'); setView('account'); return; } setView('notifs'); };
document.addEventListener('visibilitychange',()=>{ if(!document.hidden && state.user) pollNotifs(); });
boot();
window.hideSheet=hideSheet; window.setView=setView;
