#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
=====================================================================================
 BoB-SatMap v1.0  --  Bay of Bengal professional weather-satellite map
=====================================================================================
Made for  Pydroid3 on Android  (also runs on any desktop Python 3.8+).

Downloads REAL Meteosat (EUMETSAT) and Himawari-8/9 (JMA / NICT) imagery,
reprojects the geostationary disk onto a Bay-of-Bengal lon/lat map and draws
a professional weather chart: coastlines, borders, cities, graticule, wind
barbs & MSLP (Open-Meteo, no key), IR colour enhancement, GIF loops.

Needed packages (all available in Pydroid3's pip):   numpy, matplotlib, pillow
Everything else (urllib, json, math, io, os, time, datetime) is standard.

Verified public data sources (no account / no API key needed):
  * JMA  Himawari real-time quick looks (all bands, 10-min slots)
  * NICT Himawari true-colour full-disk tiles (high resolution)
  * EUMETSAT EUMETView "latestImages" static full-disk images (Meteosat)
  * Open-Meteo weather data API
  * Natural Earth coastlines / borders (GitHub mirrors, cached after 1st run)
=====================================================================================
"""

# =========================== USER CONFIG =====================================
# --- Map area (default: the whole Bay of Bengal basin) ----------------------
LON_MIN, LON_MAX = 78.0, 102.0
LAT_MIN, LAT_MAX = 2.0, 28.0
AREA_TITLE = "BAY OF BENGAL  •  NORTH INDIAN OCEAN"

# --- Which satellite / product ----------------------------------------------
# SATELLITE: "HIMAWARI", "METEOSAT" or "AUTO" (tries Himawari, then Meteosat)
SATELLITE = "AUTO"
# PRODUCT: "AUTO" (colour by day / IR by night), "IR_COLOR" (enhanced IR,
# works day+night), "IR_GRAY", "VIS" (visible), "TRUECOLOR", "WV",
# "DUST", "SANDWICH", "CONVECTION"
PRODUCT = "AUTO"

HI_RES_COLOR = True        # Himawari TRUECOLOR: hi-res NICT tiles (False: JMA disk)
NICT_ZOOM    = 8           # 1,2,4,8,16,20  ->  8 => 4400px disk (only BoB tiles fetched)
METEOSAT_FULLRES = False   # True: bigger Meteosat disk (more RAM) / False: 1280px

# --- Overlays ---------------------------------------------------------------
DRAW_CITIES      = True
DRAW_GRATICULE   = True
DRAW_RIVERS      = False
DRAW_WIND        = True     # 10 m wind barbs (Open-Meteo model data)
DRAW_MSLP        = False    # mean-sea-level-pressure contours (Open-Meteo)
DRAW_STATES      = False    # GADM state borders (bigger first-time download)

# --- Output -----------------------------------------------------------------
DPI            = 170
OUT_DIR_NAME   = "BoB_Maps"     # created next to this script
FRAMES         = 1              # >1 makes an animated GIF (IR frames)
FRAME_STEP_MIN = 30             # minutes between GIF frames
DATA_DIR_NAME  = "geo_cache"    # downloaded boundaries are cached here

# --- Advanced (normally no need to touch) -----------------------------------
SUB_LON = {"HIMAWARI": 140.7, "METEOSAT": 45.5}   # Himawari-8/9, Meteosat-9 IODC
TIMEOUT  = 30                 # seconds per network request
RETRIES  = 3
JMA_DELAY_MIN = 45            # JMA quick looks appear ~40-80 min late
# =============================================================================

import os, io, json, math, time, traceback
from datetime import datetime, timedelta, timezone

import numpy as np
import matplotlib
matplotlib.use("Agg")                      # REQUIRED on Pydroid3 (no display)
import matplotlib.pyplot as plt
from matplotlib import patheffects as pe
from matplotlib.collections import LineCollection
from matplotlib.patches import Polygon as MplPolygon, Rectangle as MplRectangle
from PIL import Image

# --------------------------------------------------------------------------
# constants (geometry core verified by selftest_offline.py)
# --------------------------------------------------------------------------
WGS84_A = 6378.1370                # equatorial radius [km]
WGS84_B = 6356.752314245           # polar radius  [km]
H_GEO   = 42164.0                  # geostationary orbit radius [km]
EARTH_HALF_ANGLE = math.asin(WGS84_A / H_GEO)    # angular radius of the disk

JMA_FD = ("https://www.data.jma.go.jp/mscweb/data/himawari/"
          "img/fd_/fd__{prod}_{hhmm}.jpg")
NICT_BASE = "https://himawari8.nict.go.jp/img/D531106/"
EUMET = ("https://eumetview.eumetsat.int/static-images/"
         "latestImages/EUMETSAT_{name}.jpg")
OPEN_METEO = ("https://api.open-meteo.com/v1/forecast"
              "?latitude={lats}&longitude={lons}"
              "&hourly=wind_speed_10m,wind_direction_10m,pressure_msl,"
              "temperature_2m&forecast_days=1&timezone=UTC")

NE_MIRRORS = [
    "https://raw.githubusercontent.com/nvkelso/natural-earth-vector/master/geojson/",
    "https://cdn.jsdelivr.net/gh/nvkelso/natural-earth-vector@master/geojson/",
    "https://github.com/nvkelso/natural-earth-vector/raw/master/geojson/",
]
GADM_MIRROR = "https://geodata.ucdavis.edu/gadm/gadm4.1/json/"
GADM_STATES = ["IND", "BGD", "MMR", "LKA", "THA", "MYS", "NPL", "BTN"]

# Meteosat static-image name candidates (first available wins).
EUMET_NAMES = {
    "TRUECOLOR_HI": ["MSGIODC_RGBNatColourEnhncd_FullResolution",
                     "MSGIODC_RGBNatColour_FullResolution",
                     "MSGIODC_RGBNatColourEnhncd_LowResolution",
                     "MSGIODC_RGBNatColour_LowResolution"],
    "TRUECOLOR_LO": ["MSGIODC_RGBNatColourEnhncd_LowResolution",
                     "MSGIODC_RGBNatColour_LowResolution",
                     "MSGIODC_IR108_BT_LowResolution"],
    "IR_COLOR": ["MSGIODC_IR108_BT_LowResolution",
                 "MSGIODC_IR108_LowResolution",
                 "MSGIODC_IR908_BT_LowResolution",
                 "MSGIODC_IR108_BT_FullResolution"],
    "DUST": ["MSGIODC_RGBDust_FullResolution", "MSGIODC_RGBDust_LowResolution"],
    "CONVECTION": ["MSGIODC_RGBConvection_FullResolution",
                   "MSGIODC_RGBConvection_LowResolution"],
}
# JMA Himawari quick-look codes (full disk, every 10 minutes)
JMA_CODES = {"IR_COLOR": "b13", "IR_GRAY": "b13", "VIS": "b03", "WV": "b08",
             "TRUECOLOR": "tre", "DUST": "dst", "SANDWICH": "snd",
             "CONVECTION": "cve"}
# JMA quick looks that are already RGB colour images
JMA_COLOR_CODES = {"tre", "trm", "dnc", "ngt", "dst", "dsl", "snd", "cve",
                   "dms", "arm"}

# ---- map annotations --------------------------------------------------------
CITIES = [  # (name, lon, lat, kind)  C=capital B=big M=medium S=small
    ("Kolkata",        88.36, 22.57, "B"),
    ("Chennai",        80.27, 13.08, "B"),
    ("Visakhapatnam",  83.22, 17.69, "M"),
    ("Bhubaneswar",    85.82, 20.30, "M"),
    ("Dhaka",          90.41, 23.81, "C"),
    ("Chattogram",     91.84, 22.33, "M"),
    ("Yangon",         96.17, 16.84, "B"),
    ("Colombo",        79.86,  6.93, "C"),
    ("Port Blair",     92.73, 11.62, "S"),
    ("Phuket",         98.34,  7.95, "S"),
    ("Banda Aceh",     95.32,  5.55, "S"),
    ("Kathmandu",      85.32, 27.71, "C"),
    ("Bangkok",       100.50, 13.76, "C"),
]
COUNTRY_LABELS = [
    ("INDIA",       84.0, 21.6), ("SRI LANKA", 80.9, 7.6),
    ("BANGLADESH",  90.2, 24.1), ("MYANMAR",  96.3, 21.3),
    ("THAILAND",   100.6, 15.6), ("NEPAL",    84.1, 27.8),
    ("BHUTAN",      90.4, 27.5),
]
WATER_LABELS = [
    ("B A Y   O F   B E N G A L", 88.3, 11.9, -32, 14),
    ("A N D A M A N   S E A",     95.6,  9.6, -18, 11),
    ("I N D I A N   O C E A N",   83.5,  3.1,  -4, 11),
]

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
OUT_DIR = os.path.join(SCRIPT_DIR, OUT_DIR_NAME)
DATA_DIR = os.path.join(SCRIPT_DIR, DATA_DIR_NAME)


def log(msg):
    print(time.strftime("[%H:%M:%S] ") + str(msg), flush=True)

# ==========================================================================
#  1) small network helpers (stdlib urllib only)
# ==========================================================================
def fetch_bytes(url, timeout=TIMEOUT, retries=RETRIES, quiet=False, min_size=800):
    """HTTP GET with retries; returns bytes or None on failure."""
    import urllib.request
    headers = {"User-Agent": "BoB-SatMap/1.0 (Android; python-urllib)"}
    last = None
    for attempt in range(1, retries + 1):
        try:
            req = urllib.request.Request(url, headers=headers)
            with urllib.request.urlopen(req, timeout=timeout) as r:
                data = r.read()
            if data and len(data) > min_size:
                return data
            last = "empty/short reply"
        except Exception as exc:  # noqa: BLE001
            last = str(exc)
        if attempt < retries:
            time.sleep(min(2 * attempt, 5))
    if not quiet:
        log("  ! download failed: %s  (%s)" % (url.split("/")[-1], last))
    return None


def fetch_json(url, **kw):
    kw.setdefault("min_size", 20)
    raw = fetch_bytes(url, **kw)
    if raw is None:
        return None
    try:
        return json.loads(raw.decode("utf-8", "replace"))
    except Exception:
        return None


def fetch_image(url, **kw):
    raw = fetch_bytes(url, **kw)
    if raw is None:
        return None
    try:
        img = Image.open(io.BytesIO(raw))
        img.load()
        return img.convert("RGB")
    except Exception:
        return None

# ==========================================================================
#  2) boundary layers (Natural Earth geojson, cached, with mirrors)
# ==========================================================================
def download_with_mirrors(name, mirrors):
    path = os.path.join(DATA_DIR, name)
    os.makedirs(DATA_DIR, exist_ok=True)
    if os.path.exists(path):
        return path
    for base in mirrors:
        data = fetch_bytes(base + name, quiet=True)
        if data:
            with open(path, "wb") as f:
                f.write(data)
            log("  geo layer %-38s <- %s" % (name, base.split("/")[2]))
            return path
    log("  ! could not download %s (continuing without it)" % name)
    return None


_GEO_CACHE = {}


def load_geojson(name):
    if name in _GEO_CACHE:
        return _GEO_CACHE[name]
    path = download_with_mirrors(name, NE_MIRRORS)
    gj = None
    if path is not None:
        try:
            with open(path, "rb") as f:
                gj = json.loads(f.read().decode("utf-8", "replace"))
        except Exception:
            try:
                os.remove(path)
            except OSError:
                pass
            gj = None
    _GEO_CACHE[name] = gj
    return gj


def load_gadm_states():
    if "_states_" in _GEO_CACHE:
        return _GEO_CACHE["_states_"]
    segs = []
    for iso in GADM_STATES:
        path = download_with_mirrors("gadm41_%s_1.json" % iso, [GADM_MIRROR])
        if path is None:
            continue
        try:
            with open(path, "rb") as f:
                gj = json.loads(f.read().decode("utf-8", "replace"))
        except Exception:
            continue
        segs.extend(layer_segments(gj))
    _GEO_CACHE["_states_"] = segs
    return segs


def crop_geom_coords(geom, pad=1.5):
    out = []
    def inside(pl):
        return any(LON_MIN - pad <= x <= LON_MAX + pad and
                   LAT_MIN - pad <= y <= LAT_MAX + pad for x, y in pl)
    def emit(pl):
        if len(pl) > 1 and inside(pl):
            out.append(pl)
    gtype = geom.get("type")
    c = geom.get("coordinates", [])
    if gtype == "LineString":
        emit(c)
    elif gtype == "MultiLineString":
        for pl in c:
            emit(pl)
    elif gtype == "Polygon":
        for pl in c:
            emit(pl)
    elif gtype == "MultiPolygon":
        for poly in c:
            for pl in poly:
                emit(pl)
    return out


def layer_segments(gj):
    segs = []
    if not gj:
        return segs
    for feat in gj.get("features", []):
        for pl in crop_geom_coords(feat.get("geometry") or {}):
            segs.append([(p[0], p[1]) for p in pl])
    return segs


def layer_polygons(gj, pad=1.5):
    """Exterior rings only (enclaves/holes negligible for this region)."""
    polys = []
    if not gj:
        return polys
    for feat in gj.get("features", []):
        geom = feat.get("geometry") or {}
        gtype, pol = geom.get("type"), []
        if gtype == "Polygon":
            pol = geom["coordinates"][:1]
        elif gtype == "MultiPolygon":
            pol = [p[0] for p in geom["coordinates"]]
        for ring in pol:
            if len(ring) > 2 and any(LON_MIN - pad <= x <= LON_MAX + pad and
                                     LAT_MIN - pad <= y <= LAT_MAX + pad
                                     for x, y in ring):
                polys.append([(p[0], p[1]) for p in ring])
    return polys

# ==========================================================================
#  3) geostationary navigation + disk calibration (verified core)
# ==========================================================================
def lonlat_to_scan(lon, lat, sub_lon):
    """Vectorised forward transform.  Returns x, y, valid-mask (numpy)."""
    lam = np.radians(np.asarray(lon, dtype=np.float64))
    phi = np.radians(np.asarray(lat, dtype=np.float64))
    lam_d = lam - math.radians(sub_lon)
    phi_c = np.arctan((WGS84_B ** 2 / WGS84_A ** 2) * np.tan(phi))
    r_e = WGS84_B / np.sqrt(1.0 - ((WGS84_A ** 2 - WGS84_B ** 2) / WGS84_A ** 2)
                            * np.cos(phi_c) ** 2)
    r1 = H_GEO - r_e * np.cos(phi_c) * np.cos(lam_d)
    r2 = -r_e * np.cos(phi_c) * np.sin(lam_d)
    r3 = r_e * np.sin(phi_c)
    rn = np.sqrt(r1 * r1 + r2 * r2 + r3 * r3)
    valid = (H_GEO * (H_GEO - r1)) > (r_e * r_e)
    x = np.arctan2(-r2, r1)
    y = np.arcsin(np.clip(-r3 / rn, -1.0, 1.0))
    return x, y, valid


def detect_disk(gray, thr=8.0):
    """Disk centre + pixels-per-radian from a full-disk image.

    gray: 2-D float array (0..255).  Falls back to nominal framing if the
    edge can not be found (e.g. very dark night ocean).
    """
    h, w = gray.shape
    row, col = h // 2, w // 2
    prof_x = np.median(gray[max(0, row - 2):row + 3, :], axis=0)
    prof_y = np.median(gray[:, max(0, col - 2):col + 3], axis=1)

    def edge_run(prof, rev=False):
        n = len(prof)
        rng = range(n - 1, -1, -1) if rev else range(n)
        for i in rng:
            if prof[i] > thr:
                ok = True
                for k in range(1, 4):
                    j = i - k if rev else i + k
                    if j < 0 or j >= n or prof[j] <= thr:
                        ok = False
                        break
                if ok:
                    return i
        return None

    left, right = edge_run(prof_x), edge_run(prof_x, True)
    top, bot = edge_run(prof_y), edge_run(prof_y, True)
    if None not in (left, right, top, bot) and (right - left) > 0.4 * w:
        cx = (left + right) / 2.0
        cy = (top + bot) / 2.0
        if abs(cx - w / 2.0) > 0.08 * w:
            cx, cy = w / 2.0, h / 2.0
        r_ = ((right - left) + (bot - top)) / 4.0
        return cx, cy, r_ / EARTH_HALF_ANGLE
    log("  ! disk edge uncertain - using nominal (centred) framing")
    return w / 2.0, h / 2.0, (w * 0.44) / EARTH_HALF_ANGLE


def reproject(arr, cx, cy, cfac, sub_lon, lons2d, lats2d, ox=0.0, oy=0.0):
    """Resample a (possibly windowed) disk image onto the lon/lat grid."""
    x, y, valid = lonlat_to_scan(lons2d, lats2d, sub_lon)
    px = cx + x * cfac - ox
    py = cy + y * cfac - oy
    sh = arr.shape
    h, w = sh[:2]
    inside = valid & (px >= 0.5) & (px <= w - 1.51) & (py >= 0.5) & (py <= h - 1.51)
    if arr.ndim == 2:
        arr3 = arr[..., None]
    else:
        arr3 = arr
    xs = np.clip(px, 0, w - 1.001)
    ys = np.clip(py, 0, h - 1.001)
    x0 = xs.astype(np.int32)
    y0 = ys.astype(np.int32)
    dx = (xs - x0)[..., None]
    dy = (ys - y0)[..., None]
    i00 = arr3[y0, x0]
    i10 = arr3[y0, x0 + 1]
    i01 = arr3[y0 + 1, x0]
    i11 = arr3[y0 + 1, x0 + 1]
    out = (i00 * (1 - dx) + i10 * dx) * (1 - dy) + (i01 * (1 - dx) + i11 * dx) * dy
    return out, inside.astype(np.float32)

# ==========================================================================
#  4) satellite data providers
# ==========================================================================
def jma_fd_fetch(prod, dt_utc):
    """Exact-slot download of one JMA full-disk quick look."""
    return fetch_image(JMA_FD.format(prod=prod, hhmm=dt_utc.strftime("%H%M")))


def jma_walkback(prod, first_slot, max_slots=36):
    """Walk back through JMA's 10-min slots until an image exists."""
    for i in range(max_slots):
        dt = first_slot - timedelta(minutes=10 * i)
        img = jma_fd_fetch(prod, dt)
        if img is not None:
            return img, dt
    return None, None


def jma_ir_frames(dt_latest, n_frames, step_min):
    frames, slot, attempts = [], dt_latest, 0
    while len(frames) < n_frames and attempts < n_frames * 8:
        img = jma_fd_fetch("b13", slot)
        if img is not None:
            frames.append((img, slot))
        slot -= timedelta(minutes=step_min)
        slot = slot.replace(minute=(slot.minute // 10) * 10, second=0)
        attempts += 1
    return frames


def nict_latest_time():
    js = fetch_json(NICT_BASE + "latest.json", retries=2)
    if not js or "date" not in js:
        return None
    try:
        return datetime.strptime(js["date"], "%Y-%m-%d %H:%M:%S").replace(
            tzinfo=timezone.utc)
    except ValueError:
        return None


def nict_window(lon_c, lat_c, sub_lon, zoom=NICT_ZOOM, stamp=None):
    """Fetch only the NICT true-colour tiles that cover the map window."""
    if stamp is None:
        stamp = nict_latest_time()
    if stamp is None:
        return None
    datepath = stamp.strftime("%Y/%m/%d")
    hhmmss = stamp.strftime("%H%M") + "00"

    def tile(z, tx, ty, quiet=False):
        url = "%s%dd/550/%s/%s_%d_%d.png" % (NICT_BASE, z, datepath, hhmmss, tx, ty)
        return fetch_image(url, quiet=quiet, retries=2)

    # 1) calibrate once on the cheap 1x1 thumbnail
    thumb = tile(1, 0, 0)
    if thumb is None:
        return None
    cx1, cy1, cfac1 = detect_disk(np.asarray(thumb.convert("L"), dtype=np.float32))
    z = min([1, 2, 4, 8, 16, 20], key=lambda v: abs(v - max(1, int(zoom))))
    cx, cy, cfac = cx1 * z, cy1 * z, cfac1 * z

    # 2) which tiles touch the window (+ margin)
    xs, ys, _ = lonlat_to_scan(lon_c, lat_c, sub_lon)
    px = cx + xs * cfac
    py = cy + ys * cfac
    full = z * 550
    if px.max() < 0 or py.max() < 0 or px.min() > full or py.min() > full:
        log("  NICT: window outside Himawari disk at z%d" % z)
        return None
    x0 = int(max(0, math.floor(px.min() - 20)))
    x1 = int(min(full - 1, math.ceil(px.max() + 20)))
    y0 = int(max(0, math.floor(py.min() - 20)))
    y1 = int(min(full - 1, math.ceil(py.max() + 20)))
    tx0, ty0, tx1, ty1 = x0 // 550, y0 // 550, x1 // 550, y1 // 550
    ntiles = (tx1 - tx0 + 1) * (ty1 - ty0 + 1)
    log("  NICT tiles: x%d-%d, y%d-%d  (%d tiles, z%d)"
        % (tx0, tx1, ty0, ty1, ntiles, z))

    # 3) assemble
    win = Image.new("RGB", ((tx1 - tx0 + 1) * 550, (ty1 - ty0 + 1) * 550), (0, 0, 0))
    got = 0
    for tx in range(tx0, tx1 + 1):
        for ty in range(ty0, ty1 + 1):
            t = tile(z, tx, ty, quiet=True)
            if t is not None:
                win.paste(t, ((tx - tx0) * 550, (ty - ty0) * 550))
                got += 1
    if got == 0:
        return None
    log("  NICT window: %d/%d tiles, %dx%d px" % (got, ntiles, win.width, win.height))
    return win, stamp, (cx, cy, cfac), (float(tx0 * 550), float(ty0 * 550))


def eumet_fetch(product):
    if product == "TRUECOLOR":
        key = "TRUECOLOR_HI" if METEOSAT_FULLRES else "TRUECOLOR_LO"
    elif product in ("IR_COLOR", "IR_GRAY"):
        key = "IR_COLOR"
    else:
        key = product
    for name in EUMET_NAMES.get(key, []):
        img = fetch_image(EUMET.format(name=name), quiet=True, retries=1)
        if img is not None:
            return img, name
    log("  ! no EUMETSAT static image worked for %s" % product)
    return None, None


class Frame(object):
    """One calibrated source image, ready for reprojection."""

    def __init__(self, img, stamp, sub_lon, cx, cy, cfac, ox, oy,
                 provider, native_rgb):
        self.stamp, self.sub_lon = stamp, sub_lon
        self.cx, self.cy, self.cfac = cx, cy, cfac
        self.ox, self.oy = ox, oy
        self.provider = provider
        self.native_rgb = native_rgb
        if native_rgb:
            self.arr = np.asarray(img.convert("RGB"), dtype=np.float32) / 255.0
        else:
            self.arr = np.asarray(img.convert("L"), dtype=np.float32) / 255.0

    def mean_brightness(self, lons2d, lats2d):
        xs, ys, ok = lonlat_to_scan(lons2d, lats2d, self.sub_lon)
        gray = self.arr if self.arr.ndim == 2 else self.arr.mean(axis=2)
        h, w = gray.shape
        px = np.clip(self.cx + xs * self.cfac - self.ox, 0, w - 1).astype(int)
        py = np.clip(self.cy + ys * self.cfac - self.oy, 0, h - 1).astype(int)
        vals = gray[py[ok], px[ok]]
        return float(vals.mean()) if vals.size else 0.0


def to_frame_from_disk(img, stamp, sub_lon, provider, native_rgb):
    gray = np.asarray(img.convert("L"), dtype=np.float32)
    cx, cy, cfac = detect_disk(gray)
    log("  disk: centre(%.0f,%.0f) radius %.0fpx" % (cx, cy, cfac * EARTH_HALF_ANGLE))
    return Frame(img, stamp, sub_lon, cx, cy, cfac, 0.0, 0.0, provider, native_rgb)


def build_frame(product, satellite, gif_slot=None):
    """Deliver one calibrated Frame for a (product, satellite) chain."""
    if satellite == "HIMAWARI":
        if product == "TRUECOLOR" and HI_RES_COLOR:
            lon_c = np.array([LON_MIN - 2, LON_MAX + 2, LON_MIN - 2,
                              LON_MAX + 2, (LON_MIN + LON_MAX) / 2.0])
            lat_c = np.array([LAT_MIN - 2, LAT_MIN - 2, LAT_MAX + 2,
                              LAT_MAX + 2, (LAT_MIN + LAT_MAX) / 2.0])
            res = nict_window(lon_c, lat_c, SUB_LON["HIMAWARI"])
            if res is not None:
                win, stamp, (cx, cy, cfac), (ox, oy) = res
                return Frame(win, stamp, SUB_LON["HIMAWARI"], cx, cy, cfac,
                             ox, oy, "NICT Himawari-9 true-colour tiles", True)
            log("  NICT tiles failed -> JMA full disk fallback")
        code = JMA_CODES.get(product, "b13")
        stamp0 = gif_slot
        if stamp0 is None:
            now = datetime.now(timezone.utc) - timedelta(minutes=JMA_DELAY_MIN)
            stamp0 = now.replace(minute=now.minute // 10 * 10, second=0,
                                 microsecond=0)
        img, dt = jma_walkback(code, stamp0)
        if img is None:
            return None
        return to_frame_from_disk(img, dt, SUB_LON["HIMAWARI"],
                                  "JMA Himawari-9 (%s)" % code,
                                  native_rgb=(code in JMA_COLOR_CODES))
    if satellite == "METEOSAT":
        img, name = eumet_fetch(product)
        if img is None:
            return None
        return to_frame_from_disk(img, datetime.now(timezone.utc),
                                  SUB_LON["METEOSAT"], "EUMETSAT " + name,
                                  native_rgb=("NatColour" in name or "Dust" in name
                                              or "Convection" in name))
    return None

# ==========================================================================
#  5) colour enhancement tables
# ==========================================================================
BT_TOP, BT_BOT = 60.0, -90.0          # assumed gray<->BT stretch of IR images


def bt_to_gray(bt):
    return (BT_TOP - bt) * 255.0 / (BT_TOP - BT_BOT)


def funktop_lut():
    """IR enhancement (approach of the classic 'funktop' CTT palette)."""
    anchors = [
        (60, (6, 6, 6)), (35, (16, 16, 16)), (25, (38, 38, 38)),
        (15, (64, 64, 64)), (5, (96, 96, 96)), (-5, (140, 140, 140)),
        (-15, (190, 190, 190)), (-25, (236, 236, 236)),
        (-30, (0, 229, 255)), (-38, (0, 130, 255)),
        (-43, (255, 255, 0)), (-50, (255, 190, 0)), (-56, (255, 110, 0)),
        (-62, (255, 0, 0)), (-70, (176, 0, 0)), (-78, (255, 0, 220)),
        (-90, (255, 235, 255)),
    ]
    xs = np.array([bt_to_gray(bt) for bt, _ in anchors])
    lut = np.zeros((256, 3), dtype=np.float32)
    g = np.arange(256)
    for ch in range(3):
        lut[:, ch] = np.interp(g, xs, [c[ch] for _, c in anchors]) / 255.0
    return lut


LUTS = {
    "IR_COLOR": funktop_lut(),
    "WV": (np.linspace(0, 1, 256, dtype=np.float32)[:, None]
           * np.array((0.45, 0.70, 1.00))[None, :]),
}


def apply_style(gray01, product):
    if product in LUTS:
        lut = LUTS[product]
        return lut[np.clip((gray01 * 255).astype(np.int32), 0, 255)]
    if product == "VIS":
        gray01 = np.clip(gray01 * 1.15, 0, 1)
    return np.repeat(gray01[..., None], 3, axis=2)

# ==========================================================================
#  6) Open-Meteo weather grid (no API key)
# ==========================================================================
def fetch_weather_grid(step=2.5):
    lats = np.arange(LAT_MIN + 1.0, LAT_MAX, step)
    lons = np.arange(LON_MIN + 1.0, LON_MAX, step)
    pts = [(round(float(la), 2), round(float(lo), 2)) for la in lats for lo in lons]
    hour_now = datetime.now(timezone.utc).replace(minute=0, second=0, microsecond=0)
    out = {}
    for i in range(0, len(pts), 80):
        chunk = pts[i:i + 80]
        url = OPEN_METEO.format(lats=",".join(str(p[0]) for p in chunk),
                                lons=",".join(str(p[1]) for p in chunk))
        js = fetch_json(url, retries=2)
        if js is None:
            continue
        if isinstance(js, dict):
            js = [js]
        for sub, (la, lo) in zip(js, chunk):
            hourly = sub.get("hourly", {}) or {}
            times = hourly.get("time", []) or []
            try:
                idx = min(range(len(times)),
                          key=lambda k: abs(datetime.strptime(
                              times[k], "%Y-%m-%dT%H:%M").replace(
                              tzinfo=timezone.utc) - hour_now)) if times else 0
            except Exception:
                idx = 0

            def val(key):
                v = hourly.get(key) or []
                try:
                    return float(v[idx])
                except (IndexError, TypeError, ValueError):
                    return float("nan")

            out[(la, lo)] = {"ws": val("wind_speed_10m"),
                             "wd": val("wind_direction_10m"),
                             "mslp": val("pressure_msl"),
                             "t2": val("temperature_2m")}
    log("  Open-Meteo grid: %d/%d model points" % (len(out), len(pts)))
    return out, sorted(set(p[0] for p in pts)), sorted(set(p[1] for p in pts))

# ==========================================================================
#  7) the map itself
# ==========================================================================
def draw_map(fig_rgb, fig_alpha, stamp, product, satellite_name, provider,
             is_native_color, out_path, wind_grid=None):
    H, W = fig_rgb.shape[:2]
    lat_mid = 0.5 * (LAT_MIN + LAT_MAX)

    fw = W / DPI + 0.25
    fh = H / DPI + 1.0
    fig = plt.figure(figsize=(fw, fh), dpi=DPI, facecolor="#0d1b2a")
    ax = fig.add_axes([0.03, 0.075, 0.94, 0.83])
    ax.set_facecolor("#102a43")
    ax.set_xlim(LON_MIN, LON_MAX)
    ax.set_ylim(LAT_MIN, LAT_MAX)
    # Plate Carree: 1 deg lon == 1 deg lat (imshow default aspect "equal")
    for side in ("top", "right", "bottom", "left"):
        sp = ax.spines[side]
        sp.set_visible(True)
        sp.set_color("#020617")
        sp.set_linewidth(2.6)

    # ---- base ---------------------------------------------------------------
    countries = load_geojson("ne_50m_admin_0_countries.geojson")
    for poly in layer_polygons(countries):
        ax.add_patch(MplPolygon(poly, closed=True, facecolor="#33332a",
                                edgecolor="none", zorder=1))

    # ---- satellite image ------------------------------------------------------
    ax.imshow(np.clip(fig_rgb, 0, 1),
              extent=[LON_MIN, LON_MAX, LAT_MIN, LAT_MAX],
              origin="upper", interpolation="bilinear",
              alpha=fig_alpha, zorder=3)

    # ---- vector overlays ------------------------------------------------------
    coast = layer_segments(load_geojson("ne_10m_coastline.geojson"))
    bounds = layer_segments(load_geojson("ne_10m_admin_0_boundary_lines_land.geojson"))
    if coast:
        ax.add_collection(LineCollection(coast, colors="#f8fafc", linewidths=1.1,
                                         zorder=5, alpha=0.95))
    if bounds:
        ax.add_collection(LineCollection(bounds, colors="#e2b13c", linewidths=0.7,
                                         zorder=5, alpha=0.9, linestyles="dashed"))
    if DRAW_STATES:
        st = load_gadm_states()
        if st:
            ax.add_collection(LineCollection(st, colors="#9aa5b1", linewidths=0.45,
                                             zorder=4, alpha=0.75, linestyles="dotted"))
    if DRAW_RIVERS:
        riv = layer_segments(load_geojson("ne_50m_rivers_lake_centerlines.geojson"))
        if riv:
            ax.add_collection(LineCollection(riv, colors="#7dd3fc", linewidths=0.5,
                                             zorder=4, alpha=0.8))

    # ---- graticule -------------------------------------------------------------
    if DRAW_GRATICULE:
        for glon in np.arange(math.ceil(LON_MIN), LON_MAX, 2):
            ax.axvline(glon, color="#94a3b8", lw=0.3, alpha=0.45, zorder=2)
        for glat in np.arange(math.ceil(LAT_MIN), LAT_MAX, 2):
            ax.axhline(glat, color="#94a3b8", lw=0.3, alpha=0.45, zorder=2)
        halo = [pe.withStroke(linewidth=1.8, foreground="#0f172a")]
        xt = list(range(int(math.ceil(LON_MIN / 2.0) * 2), int(LON_MAX) + 1, 2))
        yt = list(range(int(math.ceil(LAT_MIN / 2.0) * 2), int(LAT_MAX) + 1, 2))
        ax.set_xticks(xt)
        ax.set_yticks(yt)
        ax.set_xticklabels(["%d°E" % v for v in xt], fontsize=7.5,
                           color="#f1f5f9", fontweight="bold")
        ax.set_yticklabels(["%d°N" % v for v in yt], fontsize=7.5,
                           color="#f1f5f9", fontweight="bold")
        ax.tick_params(colors="#f1f5f9", length=4, width=0.9)
        for lab in ax.get_xticklabels() + ax.get_yticklabels():
            lab.set_path_effects(halo)

    # ---- cities -----------------------------------------------------------------
    if DRAW_CITIES:
        style = {"C": (7.0, "*", 8.5), "B": (5.0, "o", 8.0),
                 "M": (4.0, "o", 7.0), "S": (3.0, "o", 6.0)}
        for name, c_lon, c_lat, kind in CITIES:
            if not (LON_MIN < c_lon < LON_MAX and LAT_MIN < c_lat < LAT_MAX):
                continue
            ms, mk, fs = style[kind]
            ax.plot(c_lon, c_lat, marker=mk, ms=ms, mfc="#f8fafc",
                    mec="#0f172a", mew=0.9, zorder=8)
            dx = {"C": 0.22, "B": 0.20, "M": 0.18, "S": 0.16}[kind]
            t = ax.text(c_lon + dx, c_lat + 0.10, name, fontsize=fs, zorder=9,
                        color="#f8fafc", fontweight="bold")
            t.set_path_effects([pe.withStroke(linewidth=1.6,
                                              foreground="#0f172a")])

    # ---- country / water labels ---------------------------------------------------
    for name, x, y in COUNTRY_LABELS:
        t = ax.text(x, y, name, fontsize=8.5, color="#e2c97f", alpha=0.95,
                    ha="center", fontweight="bold", zorder=6, style="italic")
        t.set_path_effects([pe.withStroke(linewidth=1.4, foreground="#0f172a")])
    for name, x, y, rot, fs in WATER_LABELS:
        t = ax.text(x, y, name, fontsize=fs, color="#9ed0f0", alpha=0.85,
                    ha="center", rotation=rot, zorder=6, style="italic")
        t.set_path_effects([pe.withStroke(linewidth=1.5,
                                          foreground="#0f172a")])

    # ---- weather overlays -----------------------------------------------------------
    if wind_grid:
        out, lats_g, lons_g = wind_grid
        if out:
            P = np.full((len(lats_g), len(lons_g)), np.nan)
            U = np.zeros_like(P)
            V = np.zeros_like(P)
            for i, la in enumerate(lats_g):
                for j, lo in enumerate(lons_g):
                    rec = out.get((la, lo))
                    if not rec:
                        continue
                    P[i, j] = rec["mslp"]
                    spd_kn = rec["ws"] * 0.539957 if not math.isnan(rec["ws"]) else 0.0
                    dd = math.radians(rec["wd"]) if not math.isnan(rec["wd"]) else 0.0
                    U[i, j] = -spd_kn * math.sin(dd)
                    V[i, j] = -spd_kn * math.cos(dd)
            if DRAW_MSLP and not np.all(np.isnan(P)):
                lv = np.arange(940, 1045, 2)
                ax.contour(lons_g, lats_g, P, levels=lv, colors="#0f172a",
                           linewidths=1.5, alpha=0.55, zorder=7)
                cs = ax.contour(lons_g, lats_g, P, levels=lv, colors="#ffd60a",
                                linewidths=0.7, zorder=7)
                ax.clabel(cs, inline=True, fontsize=6.2, fmt="%.0f",
                          colors="#ffd60a")
            if DRAW_WIND:
                bx, by = np.meshgrid(lons_g, lats_g)
                bc = "#0f172a" if is_native_color else "#f8fafc"
                ax.barbs(bx, by, U, V, length=4.6, linewidth=0.55, color=bc,
                         alpha=0.92, zorder=8,
                         barb_increments=dict(half=2.5, full=5, flag=25))

    # ---- north arrow + scale bar ------------------------------------------------------
    ax.text(LON_MAX - 1.1, LAT_MAX - 1.15, "N", fontsize=13, color="#f8fafc",
            fontweight="bold", ha="center", zorder=9)
    ax.annotate("", xy=(LON_MAX - 1.1, LAT_MAX - 0.62),
                xytext=(LON_MAX - 1.1, LAT_MAX - 1.12),
                arrowprops=dict(arrowstyle="-|>", color="#f8fafc", lw=1.4),
                zorder=9)
    lat_sb = LAT_MIN + 0.9
    km_deg = 111.32 * math.cos(math.radians(10.0))
    seg = 100.0 / km_deg
    x_sb = LON_MIN + 0.9
    for k in range(3):
        ax.add_patch(MplRectangle((x_sb + k * seg, lat_sb), seg, 0.22,
                                  facecolor=("#f8fafc" if k % 2 == 0 else "#0f172a"),
                                  edgecolor="#0f172a", lw=0.8, zorder=9))
    for k in range(4):
        t = ax.text(x_sb + k * seg, lat_sb - 0.34, str(k * 100), fontsize=6.5,
                    color="#f8fafc", ha="center", zorder=9, fontweight="bold")
        t.set_path_effects([pe.withStroke(linewidth=1.4, foreground="#0f172a")])
    ax.text(x_sb + 3 * seg + 0.25, lat_sb + 0.02, "km", fontsize=6.5,
            color="#f8fafc", zorder=9, fontweight="bold")

    # ---- title band ----------------------------------------------------------------------
    band = fig.add_axes([0.0, 0.915, 1.0, 0.085])
    band.axis("off")
    band.set_facecolor("#0d1b2a")
    band.plot([0, 1], [0.04, 0.04], color="#4cc9f0", lw=1.6,
              transform=band.transAxes, clip_on=False)
    band.text(0.022, 0.68, "SATELLITE WEATHER WATCH", fontsize=15.5,
              color="#ffffff", fontweight="bold", va="center")
    band.text(0.022, 0.26, AREA_TITLE, fontsize=10.5, color="#4cc9f0",
              va="center", fontweight="bold")
    valid = stamp.strftime("%d %b %Y  %H:%M UTC") if isinstance(stamp, datetime) \
        else str(stamp)
    band.text(0.978, 0.68, "%s  •  %s" % (satellite_name, product.replace("_", " ")),
              fontsize=10.5, color="#ffd60a", fontweight="bold", va="center",
              ha="right")
    band.text(0.978, 0.26, "image nominal time: %s" % valid, fontsize=9,
              color="#cbd5e1", va="center", ha="right")

    # ---- footer ----------------------------------------------------------------------------
    foot = fig.add_axes([0.0, 0.0, 1.0, 0.055])
    foot.axis("off")
    foot.set_facecolor("#0d1b2a")
    foot.plot([0, 1], [1.0, 1.0], color="#4cc9f0", lw=1.2,
              transform=foot.transAxes, clip_on=False)
    src = {"HIMAWARI": "JMA Himawari-8/9 + NICT true-colour tiles (Japan)",
           "METEOSAT": "EUMETSAT Meteosat IODC (EUMETView static images)"
           }.get(satellite_name, satellite_name)
    foot.text(0.022, 0.66, "Data: %s" % src, fontsize=7.6, color="#94a3b8",
              va="center")
    foot.text(0.022, 0.26, "Boundaries: Natural Earth • Weather model: Open-Meteo • "
                           "projection: Plate Carrée (from geostationary)",
              fontsize=7.0, color="#5b6b80", va="center")
    foot.text(0.978, 0.66, "BoB-SatMap v1.0 • rendered %s UTC"
              % datetime.now(timezone.utc).strftime("%d %b %Y %H:%M"),
              fontsize=7.4, color="#94a3b8", va="center", ha="right")
    foot.text(0.978, 0.26, "for personal / educational use",
              fontsize=6.8, color="#5b6b80", va="center", ha="right")

    # ---- IR colour bar (drawn inside the map, bottom-centre) ---------------------------
    if product == "IR_COLOR":
        cax = fig.add_axes([0.235, 0.088, 0.53, 0.016], zorder=30)
        cax.imshow(funktop_lut()[None, :, :], aspect="auto",
                   extent=[BT_TOP, BT_BOT, 0, 1])
        tick_bts = list(range(30, -80, -10))
        cax.set_xticks(tick_bts)
        cax.set_xticklabels([str(t) for t in tick_bts], fontsize=6.4,
                            color="#e2e8f0", fontweight="bold")
        cax.set_yticks([])
        for spine in cax.spines.values():
            spine.set_edgecolor("#020617")
            spine.set_linewidth(1.2)
        cax.tick_params(colors="#e2e8f0", length=3)
        cax.set_xlabel("cloud-top brightness temperature (°C)  •  IR enhancement",
                       fontsize=7.2, color="#f1f5f9", fontweight="bold")

    fig.savefig(out_path, dpi=DPI, facecolor=fig.get_facecolor())
    plt.close(fig)

# ==========================================================================
#  8) pipeline
# ==========================================================================
def auto_daylight():
    """BoB local daylight is roughly 00:30-12:30 UTC."""
    hour = datetime.now(timezone.utc).hour
    return 0 <= hour <= 12


def render_frame(frame, product, lons2d, lats2d, out_png, weather, satellite_name):
    rgb, alpha = reproject(frame.arr, frame.cx, frame.cy, frame.cfac,
                           frame.sub_lon, lons2d, lats2d, frame.ox, frame.oy)
    if frame.native_rgb and rgb.shape[2] >= 3:
        styled = rgb[..., :3]
    else:
        styled = apply_style(rgb[..., 0], product)
    draw_map(styled, alpha, frame.stamp, product, satellite_name,
             frame.provider, frame.native_rgb, out_png, weather)


def build_chain(prod):
    """Provider fallback order for a product."""
    if prod in ("IR_COLOR", "IR_GRAY"):
        order = ["HIMAWARI", "METEOSAT"]
        if SATELLITE == "METEOSAT":
            order = ["METEOSAT", "HIMAWARI"]
    else:
        order = ["HIMAWARI", "METEOSAT"]
        if SATELLITE == "METEOSAT":
            order = ["METEOSAT", "HIMAWARI"]
    return order


def main():
    print("=" * 78)
    print(" BoB-SatMap 1.0   -   Bay of Bengal satellite weather map")
    print(" area %.0f-%.0fE / %.0f-%.0fN   |   satellite: %s   |   product: %s"
          % (LON_MIN, LON_MAX, LAT_MIN, LAT_MAX, SATELLITE, PRODUCT))
    print("=" * 78)
    os.makedirs(OUT_DIR, exist_ok=True)
    os.makedirs(DATA_DIR, exist_ok=True)

    # output grid: 40 px per degree (~2.7 km)
    out_w = int((LON_MAX - LON_MIN) * 40)
    out_h = int((LAT_MAX - LAT_MIN) * 40)
    lons = np.linspace(LON_MIN, LON_MAX, out_w)
    lats = np.linspace(LAT_MAX, LAT_MIN, out_h)   # row 0 = north edge
    lons2d, lats2d = np.meshgrid(lons, lats)

    weather = None
    if DRAW_WIND or DRAW_MSLP:
        log("fetching Open-Meteo weather grid ...")
        weather = fetch_weather_grid()

    # ---- GIF loop mode -----------------------------------------------------
    if FRAMES > 1:
        log("loop mode: %d IR frames, step %d min" % (FRAMES, FRAME_STEP_MIN))
        now = datetime.now(timezone.utc) - timedelta(minutes=JMA_DELAY_MIN)
        first = now.replace(minute=now.minute // 10 * 10, second=0,
                            microsecond=0)
        imgs = jma_ir_frames(first, FRAMES, FRAME_STEP_MIN)
        if not imgs:
            log("!! no frames downloaded - check internet & try again")
            return
        gif_frames = []
        stamp_name = imgs[0][1].strftime("%Y%m%d_%H%M")
        for k, (img, dt) in enumerate(imgs):
            log("rendering frame %d/%d  (%s UTC)"
                % (k + 1, len(imgs), dt.strftime("%H:%M")))
            fr = to_frame_from_disk(img, dt, SUB_LON["HIMAWARI"],
                                    "JMA Himawari-9 (b13)", False)
            png = os.path.join(OUT_DIR, "BoB_loop_%s_f%02d.png"
                               % (stamp_name, k))
            render_frame(fr, "IR_COLOR", lons2d, lats2d, png, weather,
                         "HIMAWARI")
            pal = getattr(getattr(Image, "Palette", Image), "ADAPTIVE", 1)
            gif_frames.append(Image.open(png).convert("P", palette=pal))
        gif_path = os.path.join(OUT_DIR, "BoB_loop_%s.gif" % stamp_name)
        gif_frames[0].save(gif_path, save_all=True,
                           append_images=gif_frames[1:], duration=500, loop=0)
        done_banner(gif_path)
        return

    # ---- single map ---------------------------------------------------------
    prod = PRODUCT
    if prod == "AUTO":
        prod = "TRUECOLOR" if auto_daylight() else "IR_COLOR"
        log("AUTO product -> %s (daylight window 00:30-12:30 UTC)" % prod)

    frame, used_sat = None, None
    tried = []
    for s in build_chain(prod):
        log("trying %s / %s ..." % (s, prod))
        frame = build_frame(prod, s)
        if frame is not None:
            used_sat = s
            break
        tried.append(s)

    # day/night sanity: colour products at night are useless
    if frame is not None and prod in ("TRUECOLOR", "VIS"):
        mb = frame.mean_brightness(lons2d[::4, ::4], lats2d[::4, ::4])
        log("scene brightness over BoB: %.3f" % mb)
        if mb < 0.045:
            log("scene is dark (night-time) -> switching to IR_COLOR")
            prod = "IR_COLOR"
            frame, used_sat = None, None
            for s in build_chain(prod):
                log("trying %s / %s ..." % (s, prod))
                frame = build_frame(prod, s)
                if frame is not None:
                    used_sat = s
                    break
                tried.append(s)

    if frame is None:
        log("!! all sources failed (%s)" % tried)
        log("   check the internet connection, wait a few minutes, run again.")
        return

    stamp_name = (frame.stamp.strftime("%Y%m%d_%H%M")
                  if isinstance(frame.stamp, datetime) else "latest")
    sat_name = used_sat if used_sat else "SAT"
    out_png = os.path.join(OUT_DIR, "BoB_%s_%s_%sZ.png"
                           % (sat_name, prod, stamp_name))
    log("reprojecting + drawing the map ...")
    render_frame(frame, prod, lons2d, lats2d, out_png, weather, sat_name)
    done_banner(out_png)


def done_banner(path):
    print("-" * 78)
    print(" SAVED:  %s" % path)
    print(" OPEN ON YOUR PHONE:  Pydroid3 -> menu -> Folder -> %s -> %s"
          % (OUT_DIR_NAME, os.path.basename(path)))
    print(" (the file also shows up in any file-manager / gallery app)")
    print("-" * 78)


if __name__ == "__main__":
    try:
        main()
    except Exception:
        print("-" * 78)
        print("Unexpected error (please report this traceback):")
        traceback.print_exc()
