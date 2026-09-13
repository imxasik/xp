# ECMWF AIFS (AI forecast) version of the VP-anomaly map

`vp_aifs.py` is a drop-in replacement for your NCEP-reanalysis script, but the
"observation" side now comes from **ECMWF's operational AI forecast model (AIFS-Single)**
taken from the official open-data server — exactly like the official website serves it,
**no API key, no MARS/CDS account**:

```
https://data.ecmwf.int/forecasts/YYYYMMDD/HHz/aifs-single/0p25/oper/YYYYMMDDHH0000-<step>h-oper-fc.grib2
```

## How it reads the data (the tricky part, solved)

* The `.index` file next to each GRIB2 file is plain JSON-Lines with byte offsets.
  The script downloads **only the ~0.6 MB byte-range** that holds the 200 hPa `u` or `v`
  message instead of the whole ~85 MB file (10 fields ≈ 6 MB total).
* ECMWF open-data GRIB2 uses **CCSDS/AEC compression (GRIB2 template 5.42)**.
  On desktop you would decode that with `eccodes`/`cfgrib`, but those need a C library
  that cannot be installed in Pydroid 3. So the script contains a small **pure-python +
  numpy AEC decoder** (port of libaec, CCSDS 121.0-B-3). It was verified
  **byte-for-byte against libaec 1.1.3** for 8-, 12- and 16-bit fields
  (`validation/aec_ref.py` + `validation/aec_pure.py`).
* Grid sanity: the decoded 0 h analysis was compared with NCEP reanalysis for the same
  day — correlation 0.98 (u200), 0.93 (v200), 1.00 (z500 pattern) with zero longitude
  shift and correct north-south orientation (`validation/val_geo.py`).
* Dynamics (divergence → Poisson FFT for χ) verified against your original script:
  same χ range and r ≈ 0.98 on identical input (`validation/vp_orig.py`).

## What the product is

* Forecast = mean of daily samples at lead **+24 h … +24·N_DAYS h** of the newest
  complete AIFS run (the script auto-detects the newest run whose steps are all
  published; it also checks that the run is complete before using it).
* Anomaly = forecast − NCEP/NCAR **1991-2020 daily climatology** (same baseline your
  reanalysis script used, fetched from PSL/NOAA with pydap, bilinearly interpolated
  2.5° → the AIFS grid).
* χ200′ from the anomaly wind divergence via the same FFT Poisson solver you had,
  plus the same style of plot (shading, contours, arrows, coastline, watermark).

## Pydroid 3 notes

* Packages needed (all already used by your old script): `numpy scipy matplotlib
  requests pyshp pydap`. Nothing else — the GRIB/AEC part is pure python.
* First run downloads the Natural Earth coastline once into `map/`.
* Decoded GRIB messages are cached in `aifs_cache/` (~0.6 MB per field). Every run
  auto-prunes `.bin` slices whose base date is older than `CACHE_KEEP_DAYS`
  (default 3), so the folder stays bounded at a few days' worth instead of growing
  forever; the tiny (~40 kB) climatology `.npy` slices are kept. Delete the folder
  to force a full re-download; `CACHE_DIR = ""` disables caching.
* Speed: on this machine the whole map takes ~20 s (download 6 MB + 10 decodes).
  On a phone expect ~2-4 min. If that is too slow set `WORK_STRIDE = 4` (1.0° grid)
  or reduce `N_DAYS`. `WORK_STRIDE = 1` gives the full 0.25° resolution.

## Main knobs (top of `vp_aifs.py`)

| setting | meaning |
|---|---|
| `AUTO` / `MANUAL_BASE` | auto-detect newest complete AIFS run, or force one (UTC datetime) |
| `N_DAYS` | number of forecast days averaged. With `LEAD_HOURS = L` the window **ends at L** (e.g. `N_DAYS=3, LEAD_HOURS=240` → +192/+216/+240 h → title `20–22 Sep 2026`). `N_DAYS=1` → single sample at L. `N_DAYS=0`/`None` → instantaneous **snapshot** at L (so 6/12/18 h work); title shows date and hour, e.g. `12 Sep 2026, 18Z` |
| `LEAD_HOURS` | `None` = forward window +24…+24·N_DAYS h (old default). Or an end hour `240`, or an explicit list `[96,102,108,114]`. Multiples of 6, 0–360. A 1-element list `[216]` behaves exactly like `216`. Titles never show the raw lead any more — always the valid date(s): `22 Sep 2026`, `21–23 Sep 2026`, `30 Sep–2 Oct 2026` |
| `BASE_HOURS` | base times to accept — default `(0, 6, 12, 18)`; unpublished runs are skipped quietly, no retry spam |
| `LEVEL_HPA` | pressure level (default 200) |
| `WORK_STRIDE` | 1 = 0.25°, 2 = 0.5° (default), 4 = 1.0° compute/plot grid |
| `SMOOTH_ANOM_DEG`, `SMOOTH_CHI_DEG` | gaussian smoothing in degrees (physically equal to your old sigma 1.5 / 2.0 at 2.5°) |
| `ARROW_SPACING_DEG` | arrow spacing |
| `VLIM` | χ colour-scale limit (×10⁶ m²/s) |

`validation/vp_orig.py` is your original script, kept to reproduce the reanalysis
version (`vp_orig.png`) for side-by-side comparison.

## The other four anomaly products: `Z.py`, `U.py`, `V.py`, `vws.py`

Same engine, same style, same NCEP 1991-2020 baseline — each is a **separate file**
as requested, and each is an **anomaly** map (forecast − climatology):

| script | product | output | default level |
|---|---|---|---|
| `Z.py` | geopotential height anomaly (AIFS `z` in m²/s² ÷ 9.80665 → m vs NCEP `hgt` m) | `Z.png` | `LEVEL_HPA = 500` |
| `U.py` | zonal wind anomaly | `U.png` | `LEVEL_HPA = 200` |
| `V.py` | meridional wind anomaly | `V.png` | `LEVEL_HPA = 200` |
| `vws.py` | vertical wind shear magnitude anomaly, `|V_top − V_bot|` = hypot(Δu, Δv); shading = shear anomaly, arrows = **forecast** shear vector with a 10 m/s reference key | `VWS.png` | `SHEAR_TOP = 200`, `SHEAR_BOT = 850` |

They are thin config wrappers around the shared engine **`aifs_core.py`** (HTTP +
byte ranges, pure-python AEC decoder, GRIB2 reader, coastline, climatology cache,
plot styling). Keep `aifs_core.py` in the **same folder** as the wrappers — on
Pydroid 3 the script's own directory is on the import path, so `import aifs_core`
just works. `vp_aifs.py` remains fully standalone and untouched.

Per-script knobs (top of each wrapper): `LEVEL_HPA` / `SHEAR_TOP`+`SHEAR_BOT`,
`N_DAYS`, `LEAD_HOURS`, `BASE_HOURS`, `WORK_STRIDE`, `SMOOTH_DEG`, `VLIM`
(`None` = automatic), `OUT_FILE`; `vws.py` also has `ARROWS = True/False`.

Colour scale: `VLIM = None` sets a symmetric limit from the ~90th percentile of
|anomaly|, rounded so the contour interval (vlim/4) is a clean number
(e.g. ±160 m / ±16 m/s). Strong Southern-Hemisphere wave trains then saturate at
the palette edges, which is normal for anomaly maps; set `VLIM` to a fixed number
for day-to-day comparable maps.

Cache sharing: the GRIB byte-range cache is keyed per (file, param, level), so
`U.py`/`V.py` reuse the 200 hPa `u`/`v` messages already downloaded by `vp_aifs.py`,
and `vws.py` reuses them plus adds 850 hPa. Climatology slices are cached per
(variable, level, day-of-year) as `aifs_cache/ltm_<var>_<level>_doy<NNN>.npy`
(note: `vp_aifs.py` uses its own older name `ltm_<var>_doy<NNN>.npy` for 200 hPa —
both schemes coexist happily; delete `aifs_cache/` to reset everything).
