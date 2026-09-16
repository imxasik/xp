#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
selftest_offline.py  --  BoB-SatMap geometry validation harness
================================================================
Pure standard-library Python. Runs *anywhere* (this PC, your Android
phone with Pydroid3, Termux, ...) with NO third-party packages.

It verifies the exact geostationary formulas and pipeline logic that
bob_sat_map.py uses:

  Test 1: geostationary forward->inverse round trip (lon/lat -> scan
          angles x,y -> lon/lat) for random points over the Earth disk.
  Test 2: Earth-disk auto-calibration on a synthetic satellite image
          (works even when the ocean is almost as dark as space, the
          tricky case for IR night images).
  Test 3: full end-to-end reprojection of a synthetic full-disk image
          onto a Bay-of-Bengal lon/lat grid (bilinear sampling, sign
          conventions, row/column order).

If this prints  ALL TESTS PASSED  the geometry core is sound.
"""

import math
import random

# ---- shared constants (identical in bob_sat_map.py) ----------------
WGS84_A = 6378.1370                # equatorial radius [km]
WGS84_B = 6356.752314245           # polar radius [km]
H_GEO   = 42164.0                  # geostationary orbit radius [km]
SUB_HIM = 140.7                    # Himawari-8/9 sub-satellite longitude
SUB_MSG = 45.5                     # Meteosat-9 (IODC) sub-satellite longitude


# =====================================================================
# 1) geostationary navigation (CGMS LRIT/HRIT global specification)
# =====================================================================
def lonlat_to_scan(lon_deg, lat_deg, sub_lon_deg):
    """Forward transform: geographic -> scan angles (x east+, y per CGMS)."""
    lam = math.radians(lon_deg)
    phi = math.radians(lat_deg)
    lam_d = lam - math.radians(sub_lon_deg)
    phi_c = math.atan((WGS84_B * WGS84_B) / (WGS84_A * WGS84_A) * math.tan(phi))
    r_e = WGS84_B / math.sqrt(1.0 - ((WGS84_A ** 2 - WGS84_B ** 2) / WGS84_A ** 2)
                              * math.cos(phi_c) ** 2)
    r1 = H_GEO - r_e * math.cos(phi_c) * math.cos(lam_d)
    r2 = -r_e * math.cos(phi_c) * math.sin(lam_d)
    r3 = r_e * math.sin(phi_c)
    rn = math.sqrt(r1 * r1 + r2 * r2 + r3 * r3)
    # point visible only if ABOVE the geometric horizon:
    # cos(phi_c)*cos(lam_d) > r_e / H_GEO   <=>   H*(H - r1) > r_e**2
    if H_GEO * (H_GEO - r1) <= r_e * r_e:
        return None
    x = math.atan(-r2 / r1)
    y = math.asin(-r3 / rn)
    return x, y


def scan_to_lonlat(x, y, sub_lon_deg):
    """Closed-form inverse transform per CGMS LRIT/HRIT specification."""
    q = (WGS84_A ** 2) / (WGS84_B ** 2)          # 1.006739501 ...
    scof = H_GEO ** 2 - WGS84_A ** 2             # h^2 - R_eq^2
    cx_, sx_ = math.cos(x), math.sin(x)
    cy_, sy_ = math.cos(y), math.sin(y)
    sa = (H_GEO * cx_ * cy_) ** 2
    sd = math.sqrt(sa - (cy_ ** 2 + q * sy_ ** 2) * scof)
    sn = (H_GEO * cx_ * cy_ - sd) / (cy_ ** 2 + q * sy_ ** 2)
    s1 = H_GEO - sn * cx_ * cy_
    s2 = sn * sx_ * cy_
    s3 = -sn * sy_
    s_xy = math.sqrt(s1 * s1 + s2 * s2)
    lon = sub_lon_deg + math.degrees(math.atan2(s2, s1))
    lat = math.degrees(math.atan(q * s3 / s_xy))
    lon = (lon + 180.0) % 360.0 - 180.0
    return lon, lat


def test_roundtrip():
    random.seed(42)
    worst = 0.0
    n = 0
    for _ in range(4000):
        lon = random.uniform(-179, 179)
        lat = random.uniform(-70, 70)
        sub = random.choice([SUB_HIM, SUB_MSG, 0.0])
        got = lonlat_to_scan(lon, lat, sub)
        if got is None:
            continue
        x, y = got
        lon2, lat2 = scan_to_lonlat(x, y, sub)
        dx = (abs(lon2 - lon) + 540) % 360 - 180
        err = math.hypot(dx, lat2 - lat)
        worst = max(worst, err)
        n += 1
    print("Test 1  forward/inverse round-trip:  %d pts, worst error %.2e deg" % (n, worst))
    assert n > 1200 and worst < 0.01, "round-trip failed"
    # sanity: sub-satellite point maps to (0,0)
    x0, y0 = lonlat_to_scan(SUB_HIM, 0.0, SUB_HIM)
    assert abs(x0) < 1e-9 and abs(y0) < 1e-9
    # Kolkata must appear WEST (x<0) of Himawari and EAST (x>0) of Meteosat
    xk_h, _ = lonlat_to_scan(88.36, 22.57, SUB_HIM)
    xk_m, _ = lonlat_to_scan(88.36, 22.57, SUB_MSG)
    assert xk_h < 0.0 and xk_m > 0.0
    # a point on the far side must be invisible
    assert lonlat_to_scan(10.0, 0.0, SUB_HIM) is None
    print("        sign/limb sanity checks OK")


# =====================================================================
# 2) Earth disk auto-calibration (center + radius from image edges)
# =====================================================================
def detect_disk(width, height, pix, thr=8.0):
    """Same logic as bob_sat_map.detect_disk().

    pix(x, y) -> 0..255 brightness. Walks in from the frame edges on the
    middle row/column; first sustained run above `thr` is the disk edge.
    Robust when the daytime ocean is only slightly brighter than space.
    """
    ray = height // 2
    # build 5-median profile of middle row
    prof = []
    for xx in range(width):
        vals = sorted(pix(xx, ray + dy) for dy in (-2, -1, 0, 1, 2))
        prof.append(vals[2])
    left = right = None
    for xx in range(width):
        if prof[xx] > thr and all(prof[min(width - 1, xx + k)] > thr for k in range(1, 4)):
            left = xx
            break
    for xx in range(width - 1, -1, -1):
        if prof[xx] > thr and all(prof[max(0, xx - k)] > thr for k in range(1, 4)):
            right = xx
            break
    rax = width // 2
    profc = []
    for yy in range(height):
        vals = sorted(pix(rax + dx, yy) for dx in (-2, -1, 0, 1, 2))
        profc.append(vals[2])
    top = bot = None
    for yy in range(height):
        if profc[yy] > thr and all(profc[min(height - 1, yy + k)] > thr for k in range(1, 4)):
            top = yy
            break
    for yy in range(height - 1, -1, -1):
        if profc[yy] > thr and all(profc[max(0, yy - k)] > thr for k in range(1, 4)):
            bot = yy
            break
    ok = None not in (left, right, top, bot) and (right - left) > 0.4 * width
    if not ok:
        return None
    return ((left + right) / 2.0, (top + bot) / 2.0,
            (right - left) / 2.0, (bot - top) / 2.0)


def test_calibration():
    W = H = 618
    CX, CY, R = 307.4, 311.8, 171.2   # deliberately off-center disk
    random.seed(7)
    bright = [[0.0] * W for _ in range(H)]

    def base_val(xx, yy):
        d = math.hypot(xx - CX, yy - CY)
        if d > R:
            return random.uniform(0, 3)          # "space" with JPEG-ish noise
        # inside disk: dark "warm ocean" in one half, bright "clouds" other
        if xx < CX:
            return random.uniform(14, 22)        # the hard IR-night case
        return random.uniform(80, 200)

    for yy in range(H):
        for xx in range(W):
            bright[yy][xx] = base_val(xx, yy)
    got = detect_disk(W, H, lambda x, y: bright[y][x])
    assert got is not None, "disk not found at all"
    cx, cy, rx, ry = got
    print("Test 2  disk detection:  cx=%.2f cy=%.2f rx=%.2f ry=%.2f (truth %.2f %.2f %.2f)"
          % (cx, cy, rx, ry, CX, CY, R))
    assert abs(cx - CX) < 1.0 and abs(cy - CY) < 1.0
    assert abs(rx - R) < 1.5 and abs(ry - R) < 1.5
    print("        calibration error sub-pixel  OK")


# =====================================================================
# 3) end-to-end reprojection of a synthetic full disk
# =====================================================================
def texture(lon, lat):
    """Smooth fake 'cloud' field in lon/lat space."""
    return 0.55 + 0.30 * math.sin(math.radians(lon) * 3.0 - math.radians(lat) * 2.0) \
               + 0.12 * math.cos(math.radians(lon) + 2.0 * math.radians(lat))


def bilinear(bright, W, H, fx, fy):
    if fx < 0 or fy < 0 or fx > W - 1 or fy > H - 1:
        return None
    x0 = int(math.floor(fx))
    y0 = int(math.floor(fy))
    x1 = min(x0 + 1, W - 1)
    y1 = min(y0 + 1, H - 1)
    dx = fx - x0
    dy = fy - y0
    v00 = bright[y0][x0]; v10 = bright[y0][x1]
    v01 = bright[y1][x0]; v11 = bright[y1][x1]
    return (v00 * (1 - dx) + v10 * dx) * (1 - dy) + (v01 * (1 - dx) + v11 * dx) * dy


def test_end_to_end():
    sub = SUB_HIM
    W = H = 300                      # small so pure-python Newton is fast
    CX = CY = 149.5                  # centered disk
    R = 127.0
    beta = math.asin(WGS84_A / H_GEO)      # Earth disk angular half-width
    cfac = R / beta                         # px per radian

    # build synthetic full-disk image: pixel -> (x,y) -> lon/lat -> texture
    bright = [[0.0] * W for _ in range(H)]
    for yy in range(H):
        xs = (0 - CX) / cfac * 0  # silence unused
        for xx in range(W):
            dx = xx - CX
            dy = yy - CY
            if dx * dx + dy * dy > (R * 0.995) ** 2:
                bright[yy][xx] = 0.0
                continue
            x = dx / cfac            # east-positive
            y = dy / cfac            # CGMS y as used by lonlat_to_scan
                                     # (negative = north)
            lon, lat = scan_to_lonlat(x, y, sub)
            bright[yy][xx] = texture(lon, lat)

    got = detect_disk(W, H, lambda a, b: bright[b][a] * 255.0, thr=1.0)
    assert got is not None
    cx, cy, rx, ry = got
    cfac_det = (rx + ry) / 2.0 / beta

    # reproject onto the BoB map grid exactly like the app does
    err = 0.0
    n = 0
    worst = (0.0, None)
    for i in range(121):
        for j in range(121):
            lat = 2.0 + i * (26.0 / 120.0)          # 2..28 N
            lon = 78.0 + j * (24.0 / 120.0)         # 78..102 E
            sc = lonlat_to_scan(lon, lat, sub)
            if sc is None:
                continue
            x, y = sc
            col = cx + x * cfac_det
            row = cy + y * cfac_det                  # CGMS y<0 is north-up
            val = bilinear(bright, W, H, col, row)
            if val is None:
                continue
            e = abs(val - texture(lon, lat))
            err += e * e
            n += 1
            if e > worst[0]:
                worst = (e, (lon, lat))
    rms = math.sqrt(err / max(1, n))
    print("Test 3  end-to-end reprojection:  %d samples, RMS=%.4f, worst=%.4f @%s"
          % (n, rms, worst[0], worst[1]))
    assert n > 9000, "too few visible samples (mapping broken?)"
    assert rms < 0.06 and worst[0] < 0.25, "reprojection error too large"
    print("        pixel/angle sign conventions OK")


if __name__ == "__main__":
    print("=" * 64)
    print("BoB-SatMap offline geometry self-test")
    print("=" * 64)
    test_roundtrip()
    test_calibration()
    test_end_to_end()
    print("=" * 64)
    print("ALL TESTS PASSED - geometry core verified without numpy/matplotlib.")
