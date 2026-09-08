#!/usr/bin/env python3
"""Fallback server when PHP CLI is unavailable. Same JSON API as api/index.php."""
from __future__ import annotations
import json, os, time, hashlib, secrets, re, threading
from http.server import ThreadingHTTPServer, SimpleHTTPRequestHandler
from urllib.parse import urlparse, parse_qs
from pathlib import Path

ROOT = Path(__file__).resolve().parent
DATA = ROOT / "data"
DATA.mkdir(exist_ok=True)
LOCK = threading.Lock()
SESS = {}

def now():
    return time.strftime("%Y-%m-%d %H:%M:%S")

def jpath(name):
    return DATA / f"{name}.json"

def read(name, default=None):
    p = jpath(name)
    if not p.exists():
        return [] if default is None else default
    try:
        return json.loads(p.read_text("utf-8") or "null") or (default if default is not None else [])
    except Exception:
        return default if default is not None else []

def write(name, data):
    p = jpath(name)
    tmp = p.with_suffix(".tmp")
    tmp.write_text(json.dumps(data, ensure_ascii=False, indent=2), "utf-8")
    tmp.replace(p)

def xid(prefix=""):
    return prefix + secrets.token_hex(6) + hex(int(time.time()))[2:]

def phash(pw):
    return hashlib.sha256(("xp|" + pw).encode()).hexdigest()

def find(name, pred):
    for r in read(name):
        if pred(r):
            return r
    return None

def push(name, row):
    with LOCK:
        allr = read(name)
        allr.append(row)
        write(name, allr)
    return row

def update(name, id, mut):
    with LOCK:
        allr = read(name)
        found = None
        for i, r in enumerate(allr):
            if r.get("id") == id:
                allr[i] = mut(r)
                found = allr[i]
                break
        if found:
            write(name, allr)
        return found

def seed():
    if not read("admins"):
        write("admins", [{"id":"a_root","username":"admin","name":"সুপার অ্যাডমিন","password":phash("admin123"),"created_at":now()}])
    if not read("settings", {}):
        write("settings", {
            "site_name":"XP Telecom","tagline":"বাংলাদেশের সকল অপারেটর অফার ও রিচার্জ",
            "phone":"01700000000","whatsapp":"01700000000",
            "notice":"স্বাগতম! অফার কেনার আগে নম্বরটি ভালো করে চেক করুন।",
            "auto_approve_payments": True, "auto_process_orders": True,
            "gateway_secret":"xp-secret-change-me","min_topup":20,"theme":"ocean"
        })
    if not read("operators"):
        write("operators", [
            {"code":"gp","name":"Grameenphone","color":"#00a651","recharge_rate":99.5,"active":True,"prefixes":["013","017"]},
            {"code":"robi","name":"Robi","color":"#ed1c24","recharge_rate":99.6,"active":True,"prefixes":["018"]},
            {"code":"airtel","name":"Airtel","color":"#ed1c24","recharge_rate":99.6,"active":True,"prefixes":["016"]},
            {"code":"bl","name":"Banglalink","color":"#f26522","recharge_rate":99.4,"active":True,"prefixes":["014","019"]},
            {"code":"tt","name":"Teletalk","color":"#0072bc","recharge_rate":100,"active":True,"prefixes":["015"]},
        ])
    if not read("categories"):
        write("categories", [
            {"id":"regular","name":"রেগুলার অফার","icon":"⚡"},
            {"id":"drive","name":"ড্রাইভ অফার","icon":"🚗"},
            {"id":"internet","name":"ইন্টারনেট","icon":"📶"},
            {"id":"minutes","name":"মিনিট","icon":"📞"},
            {"id":"combo","name":"কম্বো","icon":"🎁"},
            {"id":"sms","name":"এসএমএস","icon":"✉️"},
            {"id":"voice","name":"ভয়েস","icon":"🎙️"},
            {"id":"bundle","name":"বান্ডেল","icon":"📦"},
            {"id":"social","name":"সোশ্যাল প্যাক","icon":"💬"},
            {"id":"night","name":"নাইট প্যাক","icon":"🌙"},
            {"id":"recharge","name":"রিচার্জ","icon":"💰"},
        ])
    if not read("gateways"):
        write("gateways", [
            {"code":"wallet","name":"এক্সপি ওয়ালেট","merchant":"","enabled":True,"auto":True,"instructions":"ইনস্ট্যান্ট কাটা হবে আপনার ওয়ালেট থেকে।"},
            {"code":"bkash","name":"বিকাশ","merchant":"01711111111","enabled":True,"auto":True,"instructions":"Send Money করুন। রেফারেন্সে পে-কোড দিন। তারপর TrxID জমা দিন।"},
            {"code":"nagad","name":"নগদ","merchant":"01712222222","enabled":True,"auto":True,"instructions":"Send Money করুন। রেফারেন্সে পে-কোড দিন।"},
            {"code":"rocket","name":"রকেট","merchant":"01713333333","enabled":True,"auto":True,"instructions":"Send Money করুন। রেফারেন্সে পে-কোড দিন।"},
            {"code":"upay","name":"উপায়","merchant":"01714444444","enabled":True,"auto":True,"instructions":"Send Money করে TrxID দিন।"},
            {"code":"ucash","name":"ইউক্যাশ","merchant":"01715555555","enabled":True,"auto":True,"instructions":"Send Money করে TrxID দিন।"},
            {"code":"bank","name":"ব্যাংক","merchant":"XP Telecom Ltd — 1234567890 — City Bank","enabled":True,"auto":False,"instructions":"ব্যাংক ট্রান্সফার করে রেফারেন্স নম্বর দিন।"},
        ])
    if not read("banners"):
        write("banners", [
            {"id":"b1","title":"সব অপারেটর এক ছাদের নিচে","text":"জিপি, রবি, এয়ারটেল, বাংলালিংক, টেলিটক","active":True},
            {"id":"b2","title":"ড্রাইভ অফার স্টক লাইভ","text":"কম দামে হাই স্পিড প্যাক","active":True},
        ])
    if not read("offers"):
        def pack(op, cat, title, desc, face, price, validity, stock=99):
            return {"id":xid("of_"),"operator":op,"category":cat,"title":title,"description":desc,
                    "face_value":face,"price":price,"validity":validity,"stock":stock,"sold":0,
                    "featured":True,"active":True,"created_at":now()}
        write("offers", [
            pack("gp","regular","জিপি ৫ জিবি","৫ জিবি ডাটা, ৭ দিন",69,62,"৭ দিন"),
            pack("gp","drive","জিপি ড্রাইভ ১৫ জিবি","ড্রাইভ অফার, ৩০ দিন",299,249,"৩০ দিন"),
            pack("gp","combo","জিপি কম্বো ১৯৯","১০ জিবি + ১০০ মিনিট",199,175,"৩০ দিন"),
            pack("gp","minutes","জিপি ১০০ মিনিট","যেকোনো নম্বরে",58,52,"৭ দিন"),
            pack("gp","sms","জিপি ৫০০ এসএমএস","যেকোনো অপারেটরে",28,24,"৭ দিন"),
            pack("gp","voice","জিপি ভয়েস ৩০০ মিনিট","জিপি-টু-জিপি",75,65,"৭ দিন"),
            pack("gp","bundle","জিপি বান্ডেল ৩৪৯","২০ জিবি + ২০০ মিনিট + ৫০০ এসএমএস",349,299,"৩০ দিন"),
            pack("gp","social","জিপি সোশ্যাল ৪৯","ফেসবুক + মেসেঞ্জার",49,42,"৭ দিন"),
            pack("gp","night","জিপি নাইট ১০ জিবি","১২টা-৬টা",48,42,"৭ দিন"),
            pack("robi","regular","রবি ৮ জিবি","৮ জিবি, ৩০ দিন",198,175,"৩০ দিন"),
            pack("robi","drive","রবি ড্রাইভ ২৫ জিবি","ড্রাইভ স্পেশাল",399,329,"৩০ দিন"),
            pack("robi","combo","রবি কম্বো ১৪৯","৫ জিবি + ৫০ মিনিট + ২০০ এসএমএস",149,129,"৩০ দিন"),
            pack("robi","social","রবি সোশ্যাল ৩৯","ফেসবুক + ইনস্টা + টিকটক",39,33,"৭ দিন"),
            pack("airtel","internet","এয়ারটেল ১০ জিবি","৪জি ডাটা",179,159,"৩০ দিন"),
            pack("airtel","minutes","এয়ারটেল ২০০ মিনিট","যেকোনো অপারেটরে",89,78,"৭ দিন"),
            pack("airtel","sms","এয়ারটেল ১০০০ এসএমএস","অল নেটওয়ার্ক",49,42,"৩০ দিন"),
            pack("bl","regular","বিএল ৬ জিবি","৬ জিবি বান্ডেল",129,115,"১৫ দিন"),
            pack("bl","drive","বিএল ড্রাইভ ৪০ জিবি","ড্রাইভ মেগা",499,419,"৩০ দিন"),
            pack("bl","voice","বিএল ভয়েস ৫০০ মিনিট","বিএল-টু-বিএল",99,85,"৭ দিন"),
            pack("bl","bundle","বিএল বান্ডেল ১৯৯","১০ জিবি + ১০০ মিনিট + ৩০০ এসএমএস",199,169,"৩০ দিন"),
            pack("tt","internet","টেলিটক ৫ জিবি","সরকারি নেটওয়ার্ক",99,92,"৩০ দিন"),
            pack("tt","combo","টেলিটক কম্বো ৯৯","৩ জিবি + ৩০ মিনিট + ১০০ এসএমএস",99,88,"৩০ দিন"),
            pack("tt","night","টেলিটক নাইট ২০ জিবি","১২টা-৭টা",79,69,"৭ দিন"),
        ])
    for f in ["users","orders","invoices","wallet"]:
        if not jpath(f).exists():
            write(f, [])

seed()

def wallet_credit(uid, amount, note, ref=""):
    user = update("users", uid, lambda u: {**u, "wallet": round(float(u.get("wallet") or 0) + amount, 2)})
    tx = {"id":xid("w_"),"user_id":uid,"type":"credit","amount":amount,"note":note,"ref":ref,"balance":user.get("wallet",0),"created_at":now()}
    push("wallet", tx)
    return tx

def wallet_debit(uid, amount, note, ref=""):
    user = find("users", lambda u: u["id"]==uid)
    if not user or float(user.get("wallet") or 0) < amount:
        return {"ok": False, "error": "ওয়ালেট ব্যালেন্স অপর্যাপ্ত"}
    updated = update("users", uid, lambda u: {**u, "wallet": round(float(u.get("wallet") or 0) - amount, 2)})
    tx = {"id":xid("w_"),"user_id":uid,"type":"debit","amount":amount,"note":note,"ref":ref,"balance":updated.get("wallet",0),"created_at":now(),"ok":True}
    push("wallet", tx)
    return tx

def settings():
    return read("settings", {})

def gw_methods():
    return [g for g in read("gateways") if g.get("enabled")]

def fulfill(inv):
    if inv.get("purpose") == "wallet_topup":
        wallet_credit(inv["user_id"], float(inv["amount"]), "ওয়ালেট রিচার্জ", inv["id"])
    if inv.get("purpose") == "order" and (inv.get("meta") or {}).get("order_id"):
        order_mark_paid(inv["meta"]["order_id"])

def order_auto_process(order):
    s = settings()
    if not s.get("auto_process_orders"):
        return
    if order.get("status") != "processing":
        return
    if order.get("offer_id"):
        def mut(of):
            of = dict(of)
            if int(of.get("stock") or 0) > 0:
                of["stock"] = int(of["stock"]) - 1
            of["sold"] = int(of.get("sold") or 0) + 1
            return of
        update("offers", order["offer_id"], mut)
    update("orders", order["id"], lambda o: {**o, "status":"completed","processed_at":now(),"auto":True})

def order_mark_paid(oid):
    order = update("orders", oid, lambda o: {**o, "status":"processing"} if o.get("status")=="awaiting_payment" else o)
    if order:
        order_auto_process(order)

def gw_invoice(user, amount, method, purpose, meta=None):
    gw = find("gateways", lambda g: g["code"]==method)
    if not gw or not gw.get("enabled"):
        return {"ok": False, "error": "পেমেন্ট মাধ্যম পাওয়া যায়নি"}
    if amount < 1:
        return {"ok": False, "error": "সর্বনিম্ন ১ টাকা"}
    paycode = (method[:2].upper()) + str(secrets.randbelow(900000)+100000)
    inv = {"id":xid("inv_"),"user_id":user["id"],"method":method,"amount":round(amount,2),
           "purpose":purpose,"meta":meta or {},"paycode":paycode,"merchant":gw.get("merchant",""),
           "instructions":gw.get("instructions",""),"status":"paid" if method=="wallet" else "pending",
           "trx_id":"","sender":"","created_at":now(),"paid_at": now() if method=="wallet" else None}
    if method == "wallet":
        deb = wallet_debit(user["id"], amount, purpose, inv["id"])
        if not deb.get("ok"):
            return deb
    push("invoices", inv)
    return {"ok": True, "invoice": inv}

class H(SimpleHTTPRequestHandler):
    def __init__(self, *a, **k):
        super().__init__(*a, directory=str(ROOT), **k)

    def log_message(self, fmt, *args):
        pass

    def sid(self):
        c = self.headers.get("Cookie") or ""
        m = re.search(r"XPTEL=([A-Za-z0-9]+)", c)
        if m and m.group(1) in SESS:
            return m.group(1)
        s = secrets.token_hex(12)
        SESS[s] = {}
        return s

    def json_out(self, data, code=200, sid=None):
        body = json.dumps(data, ensure_ascii=False).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        if sid:
            self.send_header("Set-Cookie", f"XPTEL={sid}; Path=/; HttpOnly")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def body_json(self):
        n = int(self.headers.get("Content-Length") or 0)
        raw = self.rfile.read(n) if n else b"{}"
        try:
            return json.loads(raw.decode() or "{}")
        except Exception:
            return {}

    def do_GET(self):
        u = urlparse(self.path)
        # Make sure a session cookie exists for top-level HTML pages so the
        # JS fetch() right after page load reuses the same SID.
        existing_sid = None
        c = self.headers.get("Cookie") or ""
        m = re.search(r"XPTEL=([A-Za-z0-9]+)", c)
        if m and m.group(1) in SESS:
            existing_sid = m.group(1)
        page_sid = existing_sid
        if u.path in ("/", "/index.php", "/admin.php") and not page_sid:
            page_sid = secrets.token_hex(12)
            SESS[page_sid] = {}
        if u.path in ("/", "/index.php"):
            html = (ROOT / "index.php").read_text("utf-8")
            if "?>" in html:
                html = html.split("?>", 1)[1]
            html = re.sub(r"<\?php[\s\S]*?\?>", "", html)
            html = html.replace("<?= htmlspecialchars($s['site_name'] ?? 'XP Telecom') ?>", "XP Telecom")
            b = html.encode("utf-8")
            self.send_response(200)
            self.send_header("Content-Type", "text/html; charset=utf-8")
            if not existing_sid:
                self.send_header("Set-Cookie", f"XPTEL={page_sid}; Path=/; HttpOnly")
            self.send_header("Content-Length", str(len(b)))
            self.end_headers()
            self.wfile.write(b)
            return
        if u.path == "/admin.php":
            html = (ROOT / "admin.php").read_text("utf-8")
            if "?>" in html:
                html = html.split("?>", 1)[1]
            html = re.sub(r"<\?php[\s\S]*?\?>", "", html)
            b = html.encode("utf-8")
            self.send_response(200)
            self.send_header("Content-Type", "text/html; charset=utf-8")
            if not existing_sid:
                self.send_header("Set-Cookie", f"XPTEL={page_sid}; Path=/; HttpOnly")
            self.send_header("Content-Length", str(len(b)))
            self.end_headers()
            self.wfile.write(b)
            return
        if u.path.startswith("/api/"):
            return self.api()
        return super().do_GET()

    def do_POST(self):
        if urlparse(self.path).path.startswith("/api/"):
            return self.api()
        self.send_error(404)

    def api(self):
        seed()
        sid = self.sid()
        sess = SESS.setdefault(sid, {})
        q = parse_qs(urlparse(self.path).query)
        action = (q.get("action") or [""])[0]
        inp = self.body_json() if self.command == "POST" else {}
        def user():
            uid = sess.get("uid")
            if not uid:
                return None
            u = find("users", lambda x: x["id"]==uid)
            if u:
                u = dict(u); u.pop("password", None)
            return u
        def admin():
            return find("admins", lambda a: a["id"]==sess.get("aid"))

        try:
            out = handle(action, inp, sess, user, admin)
        except Exception as e:
            out = ({"ok": False, "error": str(e)}, 500)
        if isinstance(out, tuple):
            data, code = out
        else:
            data, code = out, 200
        self.json_out(data, code, sid)

def handle(action, inp, sess, user_fn, admin_fn):
    if action == "boot":
        u = user_fn()
        return {"ok": True, "csrf": "ok", "user": u, "admin": bool(admin_fn()),
                "settings": settings(), "operators": read("operators"),
                "categories": read("categories"), "gateways": gw_methods(),
                "banners": [b for b in read("banners") if b.get("active")],
                "offers": [o for o in read("offers") if o.get("active")]}
    if action == "register":
        phone = re.sub(r"\D", "", inp.get("phone") or "")
        if not re.match(r"^01[3-9]\d{8}$", phone):
            return {"ok": False, "error": "সঠিক বাংলাদেশি মোবাইল নম্বর দিন"}
        if len(inp.get("password") or "") < 6:
            return {"ok": False, "error": "পাসওয়ার্ড কমপক্ষে ৬ অক্ষর"}
        if find("users", lambda u: u["phone"]==phone):
            return {"ok": False, "error": "এই নম্বরে অ্যাকাউন্ট আছে"}
        user = {"id":xid("u_"),"name":(inp.get("name") or "গ্রাহক").strip(),"phone":phone,
                "password":phash(inp["password"]),"wallet":0,"role":"user","status":"active","created_at":now()}
        push("users", user)
        sess["uid"] = user["id"]
        u = dict(user); u.pop("password")
        return {"ok": True, "user": u}
    if action == "login":
        phone = re.sub(r"\D", "", inp.get("phone") or "")
        u = find("users", lambda x: x["phone"]==phone)
        if not u or u.get("password") != phash(inp.get("password") or ""):
            return {"ok": False, "error": "নম্বর বা পাসওয়ার্ড ভুল"}
        sess["uid"] = u["id"]
        uu = dict(u); uu.pop("password", None)
        return {"ok": True, "user": uu}
    if action == "logout":
        sess.clear()
        return {"ok": True}
    if action == "me":
        u = user_fn()
        if not u:
            return {"ok": False, "error": "লগইন করুন"}, 401
        orders = [o for o in read("orders") if o["user_id"]==u["id"]]
        orders.sort(key=lambda x: x.get("created_at",""), reverse=True)
        return {"ok": True, "user": u, "orders": orders,
                "wallet": [w for w in read("wallet") if w["user_id"]==u["id"]],
                "invoices": [i for i in read("invoices") if i["user_id"]==u["id"]]}
    if action == "order":
        u = user_fn()
        if not u:
            return {"ok": False, "error": "লগইন করুন"}, 401
        number = re.sub(r"\D", "", inp.get("number") or "")
        if not re.match(r"^01[3-9]\d{8}$", number):
            return {"ok": False, "error": "প্রাপকের নম্বর সঠিক নয়"}
        typ = inp.get("type") or "offer"
        method = inp.get("method") or "wallet"
        if typ == "offer":
            offer = find("offers", lambda o: o["id"]==inp.get("offer_id") and o.get("active"))
            if not offer:
                return {"ok": False, "error": "অফার পাওয়া যায়নি"}
            title, operator, amount, price = offer["title"], offer["operator"], float(offer["face_value"]), float(offer["price"])
            oid = offer["id"]
        else:
            amount = float(inp.get("amount") or 0)
            if amount < 10:
                return {"ok": False, "error": "সর্বনিম্ন রিচার্জ ১০ টাকা"}
            op = find("operators", lambda o: o["code"]==inp.get("operator"))
            if not op:
                return {"ok": False, "error": "অপারেটর বেছে নিন"}
            price = round(amount * float(op.get("recharge_rate") or 100) / 100, 2)
            title = f"{op['name']} রিচার্জ ৳{amount}"
            operator = op["code"]
            oid = None
        order = {"id":xid("ord_"),"user_id":u["id"],"type":typ,"offer_id":oid,"title":title,"operator":operator,
                 "number":number,"face_value":amount,"price":price,"status":"awaiting_payment","note":inp.get("note") or "",
                 "created_at":now(),"processed_at":None}
        push("orders", order)
        inv = gw_invoice(u, price, method, "order", {"order_id": order["id"]})
        if not inv.get("ok"):
            update("orders", order["id"], lambda o: {**o, "status":"cancelled"})
            return inv
        def mut(o):
            o = dict(o); o["invoice_id"] = inv["invoice"]["id"]
            if inv["invoice"]["status"]=="paid":
                o["status"]="processing"
            return o
        order = update("orders", order["id"], mut)
        if order["status"]=="processing":
            order_auto_process(order)
            order = find("orders", lambda x: x["id"]==order["id"]) or order
        return {"ok": True, "order": order, "invoice": inv["invoice"]}
    if action == "pay_trx":
        u = user_fn()
        if not u:
            return {"ok": False, "error": "লগইন করুন"}, 401
        trx = (inp.get("trx_id") or "").upper().strip()
        sender = re.sub(r"\D", "", inp.get("sender") or "")
        if len(trx) < 6:
            return {"ok": False, "error": "ট্রানজেকশন আইডি সঠিক নয়"}
        if find("invoices", lambda i: (i.get("trx_id") or "").upper()==trx and i.get("status")=="paid"):
            return {"ok": False, "error": "এই ট্রানজেকশন আগে ব্যবহার হয়েছে"}
        inv = find("invoices", lambda i: i["id"]==inp.get("invoice_id") and i["user_id"]==u["id"])
        if not inv:
            return {"ok": False, "error": "ইনভয়েস পাওয়া যায়নি"}
        auto = settings().get("auto_approve_payments")
        valid = bool(re.match(r"^[A-Z0-9]{8,20}$", trx))
        status = "paid" if auto and valid else "review"
        def mut(i):
            i=dict(i); i["trx_id"]=trx; i["sender"]=sender; i["status"]=status
            if status=="paid": i["paid_at"]=now()
            return i
        updated = update("invoices", inv["id"], mut)
        if status=="paid":
            fulfill(updated)
        return {"ok": True, "invoice": updated}
    if action == "topup":
        u = user_fn()
        if not u:
            return {"ok": False, "error": "লগইন করুন"}, 401
        amount = float(inp.get("amount") or 0)
        mn = float(settings().get("min_topup") or 20)
        if amount < mn:
            return {"ok": False, "error": f"সর্বনিম্ন টপআপ ৳{mn}"}
        method = inp.get("method") or "bkash"
        if method == "wallet":
            return {"ok": False, "error": "ওয়ালেট দিয়ে টপআপ নয়"}
        return gw_invoice(u, amount, method, "wallet_topup")
    if action == "admin_login":
        a = find("admins", lambda x: x["username"]==inp.get("username"))
        if not a or a.get("password") != phash(inp.get("password") or ""):
            return {"ok": False, "error": "লগইন ব্যর্থ"}
        sess["aid"] = a["id"]
        aa=dict(a); aa.pop("password",None)
        return {"ok": True, "admin": aa}
    if action == "admin_boot":
        if not admin_fn():
            return {"ok": False, "error": "অ্যাডমিন লগইন প্রয়োজন"}, 401
        users = []
        for u in read("users"):
            uu=dict(u); uu.pop("password",None); users.append(uu)
        return {"ok": True, "settings": settings(), "operators": read("operators"), "offers": read("offers"),
                "orders": list(reversed(read("orders"))), "users": users, "invoices": list(reversed(read("invoices"))),
                "gateways": read("gateways"), "categories": read("categories"), "banners": read("banners"),
                "wallet": list(reversed(read("wallet")))}
    if action == "admin_save_offer":
        if not admin_fn():
            return {"ok": False}, 401
        o = dict(inp)
        if not o.get("id"):
            o["id"]=xid("of_"); o["sold"]=0; o["created_at"]=now()
            o["active"]=bool(o.get("active")); o["featured"]=bool(o.get("featured"))
            o["face_value"]=float(o.get("face_value") or 0); o["price"]=float(o.get("price") or 0); o["stock"]=int(o.get("stock") or 0)
            push("offers", o)
        else:
            def mut(old):
                old=dict(old)
                for k in ["title","description","operator","category","validity"]:
                    if k in o: old[k]=o[k]
                old["face_value"]=float(o.get("face_value", old.get("face_value")))
                old["price"]=float(o.get("price", old.get("price")))
                old["stock"]=int(o.get("stock", old.get("stock")))
                old["active"]=bool(o.get("active")); old["featured"]=bool(o.get("featured"))
                return old
            update("offers", o["id"], mut)
        return {"ok": True, "offers": read("offers")}
    if action == "admin_delete_offer":
        if not admin_fn():
            return {"ok": False}, 401
        write("offers", [x for x in read("offers") if x.get("id") != inp.get("id")])
        return {"ok": True, "offers": read("offers")}
    if action == "admin_order":
        if not admin_fn():
            return {"ok": False}, 401
        row = update("orders", inp.get("id"), lambda o: {**o, "status": inp.get("status") or "processing", "admin_note": inp.get("note") or "", "processed_at": now()})
        return {"ok": bool(row), "order": row}
    if action == "admin_invoice":
        if not admin_fn():
            return {"ok": False}, 401
        approve = bool(inp.get("approve"))
        updated = update("invoices", inp.get("id"), lambda i: {**i, "status": "paid" if approve else "rejected", "admin_note": inp.get("note") or "", "paid_at": now() if approve else i.get("paid_at")})
        if approve and updated:
            fulfill(updated)
        return {"ok": True, "invoice": updated}
    if action == "admin_settings":
        if not admin_fn():
            return {"ok": False}, 401
        s = settings()
        for k in ["site_name","tagline","phone","whatsapp","notice","gateway_secret","theme"]:
            if k in inp: s[k]=inp[k]
        s["auto_approve_payments"]=bool(inp.get("auto_approve_payments"))
        s["auto_process_orders"]=bool(inp.get("auto_process_orders"))
        s["min_topup"]=float(inp.get("min_topup") or s.get("min_topup") or 20)
        write("settings", s)
        return {"ok": True, "settings": s}
    if action == "admin_gateways":
        if not admin_fn():
            return {"ok": False}, 401
        if isinstance(inp.get("gateways"), list):
            write("gateways", inp["gateways"])
        return {"ok": True, "gateways": read("gateways")}
    if action == "admin_operators":
        if not admin_fn():
            return {"ok": False}, 401
        if isinstance(inp.get("operators"), list):
            write("operators", inp["operators"])
        return {"ok": True, "operators": read("operators")}
    if action == "admin_user_wallet":
        if not admin_fn():
            return {"ok": False}, 401
        uid = inp.get("user_id"); amount=float(inp.get("amount") or 0)
        if amount<=0:
            return {"ok": False, "error": "পরিমাণ ভুল"}
        if inp.get("type")=="debit":
            return wallet_debit(uid, amount, "অ্যাডমিন সমন্বয়")
        return {"ok": True, "tx": wallet_credit(uid, amount, "অ্যাডমিন টপআপ")}
    if action == "admin_banners":
        if not admin_fn():
            return {"ok": False}, 401
        if "banners" in inp:
            write("banners", inp["banners"])
        return {"ok": True, "banners": read("banners")}
    if action == "gateway_auto":
        s = settings()
        if inp.get("secret") != s.get("gateway_secret"):
            return {"ok": False, "error": "অননুমোদিত"}
        amount=float(inp.get("amount") or 0)
        inv = find("invoices", lambda i: i.get("paycode")==inp.get("paycode") and abs(float(i["amount"])-amount)<0.01 and i.get("status")!="paid")
        if not inv:
            return {"ok": False, "error": "মিল পাওয়া যায়নি"}
        updated = update("invoices", inv["id"], lambda i: {**i, "trx_id": (inp.get("trx") or "").upper(), "status":"paid","paid_at":now(),"auto":True})
        fulfill(updated)
        return {"ok": True, "invoice": updated}
    return {"ok": False, "error": "অজানা অ্যাকশন"}, 404

if __name__ == "__main__":
    port = int(os.environ.get("PORT", "8080"))
    httpd = ThreadingHTTPServer(("0.0.0.0", port), H)
    print(f"XP Telecom on http://0.0.0.0:{port}", flush=True)
    httpd.serve_forever()
