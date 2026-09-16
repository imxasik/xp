# 🛰️ BoB-SatMap — Your Own Bay of Bengal Weather-Satellite Studio (on Pydroid3)

Build **professional weather maps of the Bay of Bengal** from **real Meteosat
and Himawari-8/9 satellite imagery**, right on your **Android phone** with the
free **Pydroid3** app. No PC, no server, no API key, no account anywhere.
Sources refresh **every 10 minutes** (JMA/NICT Himawari) and **every 15
minutes** (EUMETSAT Meteosat-IODC), so you can build smooth loops.

What the app produces (saved as a PNG on your phone):

* 🛰️ Real satellite image of the Bay of Bengal (Meteosat IODC or Himawari-9),
  geometrically reprojected from the round Earth disk onto a flat map
* 🌈 Professional **IR colour enhancement** (cloud-top-temperature palette like
  the met departments use), true-colour day images, water-vapour, dust, …
* 🗺️ Coastlines, country borders, (optional state borders), graticule, major
  cities (Kolkata, Chennai, Dhaka, Yangon, Colombo, …)
* 🌬️ Wind barbs (and optional pressure contours) from the Open-Meteo weather
  model — **no key needed**
* 🎬 Optional animated **GIF loop** of the last hours
* 🖼️ Professional chart furniture: title band, timestamp (UTC), scale bar,
  north arrow, colour bar, data credits

Everything uses only **free, public, no-login data sources** (verified
September 2026, see the bottom of this file).

---

## 1) Install & set up Pydroid3 (5 minutes)

1. Install **Pydroid3 – IDE for Python 3** from the Play Store.
2. Open Pydroid3 → menu **⋮ → Pip** → **Quick install** and install these 3
   libraries (they are all pre-built for Android in Pydroid3's repository):

   ```
   numpy
   matplotlib
   pillow
   ```

   *Or* open menu → **Terminal** and type: `pip install numpy matplotlib pillow`
3. (Recommended) Allow storage access: Android **Settings → Apps → Pydroid 3 →
   Permissions → Files and media → Allow**. This makes it easier to open the
   finished maps from your gallery/file manager.

> That's all. **Do NOT try to install `cartopy`, `pyproj`, `netCDF4`,
> `xarray`, `shapely` — they are NOT needed here** (they don't work on
> Pydroid3; this app deliberately avoids them and implements the geometry
> itself).

## 2) Get the code onto the phone

You need two files from this folder:

| file | what it is |
|---|---|
| `bob_sat_map.py` | **the app** (single file) |
| `selftest_offline.py` | optional geometry check (no internet needed) |

Any of these ways works — pick the easiest for you:

* **Download / share**: get the two `.py` files to your phone (e.g. via GitHub
  in the browser, WhatsApp/Drive/Telegram, USB copy). Save them anywhere you
  can find (e.g. `Download/`).
* **Copy-paste**: in Pydroid3 press **+ (new file)** and paste the code,
  save as `bob_sat_map.py`.
* In Pydroid3 press the **folder icon (Open)** and select `bob_sat_map.py`.

> Tip: it's nicest to keep the app in its own folder, e.g. create
> `BoB_SatMap/` in the Pydroid3 file dialog and put the `.py` there — outputs
> will land next to it in `BoB_SatMap/BoB_Maps/`.

## 3) RUN 🚀

1. Open `bob_sat_map.py` in Pydroid3.
2. Press the big **yellow ▶ Run** button.
3. Watch the console — you'll see the app downloading boundaries (first run
   only, ~5 MB, then cached), the satellite image, drawing, and finally:

> **Default v1.1:** `FRAMES = 8`, `FRAME_STEP_MIN = 15` — each run makes the
> last ~2 hours as individual PNG frames **plus an animated GIF**
> (`BoB_loop_....gif`). Want one still image instead? Set `FRAMES = 1`.

   ```
   SAVED:  .../BoB_SatMap/BoB_Maps/BoB_HIMAWARI_TRUECOLOR_20260916_0610Z.png
   OPEN ON YOUR PHONE:  Pydroid3 -> menu -> Folder -> BoB_Maps -> ...
   ```

4. Open the map: Pydroid3 menu **☰ → Folder**, navigate into **BoB_Maps/** and
   tap the PNG — or use any file manager / gallery app on the folder
  `BoB_SatMap/BoB_Maps/`.

> ⏱️ A full run takes ~30–90 s on a normal phone+Wi-Fi (first run a bit more).
> Note JMA quick-look images are published ~45–80 min behind real time; the
> app automatically walks back to the newest available image. Meteosat static
> images and NICT tiles are fresher (~15–30 min).

---

## 4) Make it YOURS — customization

All settings are in the clearly marked **`USER CONFIG` block at the top of
`bob_sat_map.py`** — edit and run again.

### 4.1 Choose satellite & product

```python
SATELLITE = "AUTO"        # "HIMAWARI", "METEOSAT" or "AUTO"
PRODUCT   = "AUTO"        # "AUTO" or one of the products below
```

| PRODUCT | shows | works at night? |
|---|---|---|
| `AUTO` | colour by day, enhanced IR by night (recommended default) | ✅ |
| `TRUECOLOR` | true-colour daylight imagery (Himawari: **hi-res NICT tiles** / Meteosat: EUMETSAT Natural Colour) | ❌ (auto-falls back to IR) |
| `IR_COLOR` | enhanced infrared with met-style cloud-top colours + colour bar | ✅ |
| `IR_GRAY` | classic black-and-white IR | ✅ |
| `VIS` | visible channel (AHI B03, grayscale) | ❌ |
| `WV` | water vapour (upper-level moisture, tinted) | ✅ |
| `DUST` | dust RGB (N Indian Ocean haze/dust plumes) | ✅ |
| `SANDWICH` | vis+IR “sandwich” cloud texture | part-day |
| `CONVECTION` | severe-storm convection RGB | ✅ |

> 💡 **AUTO is best**: around the Bay of Bengal (UTC+5:30 … +6:30) daylight
> imagery is useful roughly **00:30–12:30 UTC**; outside that the app uses the.IR.

### 4.2 Choose what to draw

```python
DRAW_CITIES    = True
DRAW_GRATICULE = True
DRAW_WIND      = True     # wind barbs (Open-Meteo)
DRAW_MSLP      = False    # add pressure contours (isobars)
DRAW_RIVERS    = False
DRAW_STATES    = False    # India/Bangladesh/Myanmar/… state borders (GADM)
```

### 4.3 Zoom anywhere (e.g. track a cyclone to landfall)

```python
LON_MIN, LON_MAX = 85.0, 95.0     # tighter box around the system
LAT_MIN, LAT_MAX = 15.0, 24.0
AREA_TITLE = "CYCLONE WATCH — NORTH BAY OF BENGAL"
```

### 4.4 Animated loop (updates every 10–15 min)

```python
FRAMES = 8                # frames to fetch (JMA publishes every 10 minutes)
FRAME_STEP_MIN = 15       # minutes between frames (10 = maximum smoothness)
FRAME_MS = 550            # GIF playback speed (ms per frame)
```

The app walks JMA's exact 10-minute image slots for you, so the loop always
uses real 10-minute-cadence data. Set `FRAMES = 1` for a single still map.

### 4.5 Resolution / memory

```python
MAP_W        = 1500       # map width in pixels (layout scales itself)
HI_RES_COLOR = True       # hi-res Himawari true colour via NICT tiles
NICT_ZOOM    = 8          # 8 -> 4400 px disk (only BoB tiles downloaded);
                          # use 16 for ultra detail (more tiles), 4 on old phones
METEOSAT_FULLRES = False  # True = sharpest Meteosat (uses more RAM)
DPI          = 150
```

### 4.6 Add / move cities

Edit the `CITIES` list (name, longitude, latitude, size `C/B/M/S`):

```python
CITIES = [
    ("Kolkata", 88.36, 22.57, "B"),
    ...
    ("Gopalpur", 84.91, 19.30, "S"),
]
```

---

## 5) How it works (short version)

1. **Download** a full Earth-disk image (JMA quick-look JPEG, NICT true-colour
   tiles, or EUMETSAT EUMETView static image).
2. **Auto-calibrate** the disk: find the Earth's edge in the image, get centre
   and pixels-per-radian (works even at night when the ocean is almost as dark
   as space).
3. **Reproject**: for every pixel of the BoB lon/lat grid, compute where it is
   seen on the satellite disk using the official CGMS geostationary navigation
   equations (with correct limb handling), then bilinear-sample the image.
4. **Style + draw**: enhancement LUTs, Natural Earth boundaries, graticule,
   cities, wind/pressure overlays, chart furniture → PNG.

The geometry core is independently verified by `selftest_offline.py` (runs in
pure Python, no packages): forward/inverse nav round-trip is exact to ~1e-12°
over the Bay of Bengal; disk calibration is sub-pixel; end-to-end reprojection
matches ground truth.

Run the self-test any time:

```
python3 selftest_offline.py
```

---

## 6) Troubleshooting (Pydroid3)

| problem | fix |
|---|---|
| `pip install` fails | Check internet; in Pydroid3 use menu **Pip → Quick install** (uses their repo). Enable "use prebuilt libraries repository" if asked. |
| `all sources failed` | Wait 5–10 min and run again (source hiccup), or switch `SATELLITE`/`PRODUCT`. Also check the phone is really online. |
| image is black / “scene is dark” | It's night over the Bay: normal — the app auto-switches to IR. |
| `BoB_Maps` not visible in gallery | Use Pydroid3 **Folder** menu or a file manager app; some galleries need a media rescan time. |
| title/texts look cut off when viewing | That is your **gallery app zooming/cropping** the preview — open the PNG full-screen (pinch to fit) or in the **Files** app. The rendered PNG always contains the full title band, labels and footer. |
| breeze looks like check-marks | v1.1 draws classic met-office barbs (short feather = 5 kn, long = 10 kn, triangle = 50 kn). | 
| slow runs | Set `DRAW_STATES=False`, `DRAW_RIVERS=False`, `NICT_ZOOM=4`, `METEOSAT_FULLRES=False`. |
| matplotlib warnings about fonts | Harmless. |
| app killed by Android | Other apps eating RAM. Close apps; set `NICT_ZOOM=4`, `DPI=130`. |
| want fresher data | JMA feeds are ~1 h delayed by design — nothing is broken. NICT/Meteosat are fresher. |
| Meteosat image looks big/stretched near BoB | Normal — BoB is seen at an angle from 45.5°E. Himawari (140.7°E) sees it from the other side. Best coverage: INSAT-3D/3DR (74°E) — but those images require a MOSDAC login, so this app uses the free sources instead. |

---

## 7) Verified data sources (no login required)

| content | source | URL pattern (verified 2026-09) |
|---|---|---|
| Himawari-9 full-disk quick looks (all bands, 10-min) | JMA MSC | `https://www.data.jma.go.jp/mscweb/data/himawari/img/fd_/fd__b13_1210.jpg` |
| Himawari-9 true-colour tiles (hi-res) | NICT | `https://himawari8.nict.go.jp/img/D531106/{z}d/550/…` (+`latest.json`) |
| Meteosat (IODC) full-disk latest images | EUMETSAT EUMETView | `https://eumetview.eumetsat.int/static-images/latestImages/EUMETSAT_MSGIODC_…jpg` |
| Coastlines / country borders | Natural Earth | GitHub `nvkelso/natural-earth-vector` (+ jsDelivr mirror) |
| State borders (optional) | GADM 4.1 | `geodata.ucdavis.edu/gadm/gadm4.1/json/` |
| Wind / pressure / temperature | Open-Meteo | `api.open-meteo.com` (free non-commercial) |

**Update cadence:** JMA quick-looks **10 min** (~45–80 min behind real time) ·
NICT true-colour tiles **10 min** (~30 min behind) · EUMETSAT static images
**15 min** · Open-Meteo model data hourly.

**Attribution & use:** Imagery © JMA, NICT and EUMETSAT — free for
personal/educational/non-commercial use with attribution. Natural Earth is
public domain. Open-Meteo data under CC-BY 4.0. The rendered maps carry these
credits automatically in the footer.

### Backups if a source ever breaks
* CIRA RAMSDIS-Online: `https://rammb2.cira.colostate.edu/ramsdis/online/`
  (latest Himawari & Meteosat sector JPGs)
* JMA Himawari SE-Asia sector (80–115°E): files `img/se1/se1_b13_HHMM.jpg`
  on the same server (see the code comments — handy future upgrade)
* `selftest_offline.py` always works offline to confirm the math core.

---

## 8) Files

```
bob_satellite_map/
├── bob_sat_map.py        ← THE APP v1.1 (edit USER CONFIG at top, then ▶ Run)
├── selftest_offline.py   ← optional: verifies the geometry (pure Python)
├── README.md             ← this guide
├── BoB_Maps/             ← created on first run: your PNG maps / GIF loops
└── geo_cache/            ← created on first run: cached boundary files
```

Enjoy your own satellite weather station! 🌧️🌀
