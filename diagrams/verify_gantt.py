#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Verify Gantt_Chart.drawio independently of the generator that wrote it.

Structural
  * the file parses, is plain uncompressed mxfile XML, declares one page
  * no duplicate cell ids, and every dependency edge resolves
  * all shapes sit inside the page

Schedule correctness (the important part)
  * every task bar's left edge is exactly the pixel of its start date
  * every task bar's right edge is exactly the pixel of its end date + 1 day
  * every bar sits inside its own phase band, horizontally and vertically
  * no two task bars overlap
  * no milestone rhombus (plus its label) overlaps a task bar or another
    milestone
  * every milestone's x position is exactly the pixel of its date
  * phase bands are in chronological order and never overlap
  * the narrative states the same task and milestone counts
"""

import io
import xml.etree.ElementTree as ET

import gen_gantt as G

DRAWIO = "Gantt_Chart.drawio"
NARR = "3.10_Gantt_Chart_Narrative.txt"

PROBLEMS = []
TOUCHED = 0.5           # px tolerance


def fail(msg):
    PROBLEMS.append(msg)


def overlaps(b1, b2, tol=0.0):
    x1, y1, w1, h1 = b1
    x2, y2, w2, h2 = b2
    return not (x1 + w1 <= x2 + tol or x2 + w2 <= x1 + tol or
                y1 + h1 <= y2 + tol or y2 + h2 <= y1 + tol)


def read_cells():
    raw = io.open(DRAWIO, encoding="utf-8").read()
    if "compressed" in raw[:400] or "base64" in raw[:400]:
        fail("%s: document is compressed; draw.io needs plain XML" % DRAWIO)
    root = ET.fromstring(raw)
    pages = [p for p in root if p.tag == "diagram"]
    if len(pages) != 1:
        fail("%s: expected 1 page, found %d" % (DRAWIO, len(pages)))
    model = pages[0].find("mxGraphModel")

    verts, edges = {}, []
    for c in model.iter("mxCell"):
        g = c.find("mxGeometry")
        if c.get("vertex") == "1" and g is not None:
            verts[c.get("id")] = {
                "id": c.get("id"), "value": c.get("value") or "",
                "style": c.get("style") or "",
                "box": (float(g.get("x") or 0), float(g.get("y") or 0),
                        float(g.get("width") or 0),
                        float(g.get("height") or 0))}
        elif c.get("edge") == "1":
            edges.append({"id": c.get("id"), "source": c.get("source"),
                          "target": c.get("target")})

    ids = list(verts) + [e["id"] for e in edges]
    dupes = sorted({i for i in ids if ids.count(i) > 1})
    if dupes:
        fail("duplicate cell ids: %s" % dupes)
    for e in edges:
        if e["source"] not in verts or e["target"] not in verts:
            fail("edge %s does not resolve" % e["id"])

    pw = float(model.get("pageWidth") or 0)
    ph = float(model.get("pageHeight") or 0)
    for v in verts.values():
        x, y, w, h = v["box"]
        if w <= 0 or h <= 0:
            fail("%s has a non-positive size" % v["id"])
        if x < -TOUCHED or y < -TOUCHED or x + w > pw + TOUCHED or \
                y + h > ph + TOUCHED:
            fail("%s ('%s') falls outside the %gx%g page"
                 % (v["id"], v["value"][:28], pw, ph))
    return verts, edges, pw, ph


def check_bars(verts):
    bars = {}
    for i, r in enumerate(G.PHASES):
        for ti, (tname, tstart, tend) in enumerate(r["tasks"]):
            cid = "b%d_%d" % (i, ti)
            if cid not in verts:
                fail("missing bar cell for '%s'" % tname)
                continue
            bars[cid] = (r, tname, tstart, tend, verts[cid]["box"])

    print("task bars: %d" % len(bars))
    for cid, (ph_, tname, tstart, tend, b) in sorted(bars.items()):
        x, y, w, h = b
        want_x = G.x_of(tstart)
        want_r = G.x_of(tend + G.dt.timedelta(days=1))
        if abs(x - want_x) > TOUCHED:
            fail("%s '%s' starts at x=%.1f but %s is x=%.1f"
                 % (cid, tname, x, tstart, want_x))
        if abs((x + w) - want_r) > TOUCHED:
            fail("%s '%s' ends at x=%.1f but %s+1d is x=%.1f"
                 % (cid, tname, x + w, tend, want_r))
        bx = G.x_of(ph_["start"])
        br = G.x_of(ph_["end"] + G.dt.timedelta(days=1))
        if x < bx - TOUCHED or x + w > br + TOUCHED:
            fail("%s '%s' is not inside the band of '%s'"
                 % (cid, tname, ph_["name"]))
        if y < ph_["top"] - TOUCHED or y + h > ph_["top"] + ph_["h"] + TOUCHED:
            fail("%s '%s' is outside the height of its band" % (cid, tname))
        span = (tend - tstart).days + 1
        print("   %-40s %s..%s  %2dd  x=%7.1f..%7.1f  w=%6.1f"
              % (tname[:40], tstart, tend, span, x, x + w, w))

    keys = list(bars)
    for i in range(len(keys)):
        for j in range(i + 1, len(keys)):
            if overlaps(bars[keys[i]][4], bars[keys[j]][4]):
                fail("task bars overlap: '%s' and '%s'"
                     % (bars[keys[i]][1], bars[keys[j]][1]))
    return bars


def check_milestones(verts, bars):
    # only the chart milestones count; lm0/lg* are legend swatches
    miles = [v for v in verts.values()
             if "rhombus" in v["style"] and v["id"].startswith("ms")]
    print("\nmilestones: %d" % len(miles))
    for v in miles:
        cid = v["id"]
        want = None
        for pi, ph_ in enumerate(G.PHASES):
            for mi, (mname, mdate) in enumerate(ph_["miles"]):
                if cid == "ms%d_%d" % (pi, mi):
                    want = (mname, mdate, ph_)
        if want is None:
            fail("milestone %s has no schedule entry" % cid)
            continue
        mname, mdate, ph_ = want
        cx = v["box"][0] + v["box"][2] / 2.0
        if abs(cx - G.x_of(mdate)) > TOUCHED:
            fail("milestone '%s' is at x=%.1f but %s is x=%.1f"
                 % (mname, cx, mdate, G.x_of(mdate)))
        # the rhombus plus the 13px label underneath must clear every bar
        label_zone = (v["box"][0] - 4, v["box"][1],
                      v["box"][2] + 8, v["box"][3] + 18)
        for cid2, (_, tname, _, _, b2) in bars.items():
            if overlaps(v["box"], b2, tol=-1.0) or \
                    overlaps(label_zone, b2, tol=-1.0):
                fail("milestone '%s' (%s) collides with task bar '%s'"
                     % (mname, mdate, tname))
        print("   %-30s %s  x=%7.1f  y=%6.1f" % (mname, mdate, cx,
                                                 v["box"][1]))

    for i in range(len(miles)):
        for j in range(i + 1, len(miles)):
            if overlaps(miles[i]["box"], miles[j]["box"], tol=-1.0):
                fail("milestones '%s' and '%s' overlap"
                     % (miles[i]["value"], miles[j]["value"]))
    return miles


def main():
    verts, edges, pw, ph = read_cells()
    bars = check_bars(verts)
    miles = check_milestones(verts, bars)

    order = [(r["name"], r["top"], r["h"], r["start"]) for r in G.PHASES]
    for i in range(len(order) - 1):
        if order[i][3] > order[i + 1][3]:
            fail("phase '%s' starts after '%s'"
                 % (order[i][0], order[i + 1][0]))
        if order[i][1] + order[i][2] > order[i + 1][1] + TOUCHED:
            fail("bands of '%s' and '%s' overlap"
                 % (order[i][0], order[i + 1][0]))
    print("\nphases (top, height, first day):")
    for name, top, h, start in order:
        print("   %-28s top=%6.1f h=%6.1f  %s" % (name, top, h, start))

    grid = [v for v in verts.values()
            if v["id"].startswith("g") and v["style"].startswith("line")]
    if len(grid) != G.WEEKS + 1:
        fail("expected %d week gridlines, found %d"
             % (G.WEEKS + 1, len(grid)))
    print("\npage %.0fx%.0f   gridlines=%d (expected %d)"
          % (pw, ph, len(grid), G.WEEKS + 1))

    try:
        txt = io.open(NARR, encoding="utf-8").read()
        if "%d task bars" % len(bars) not in txt:
            fail("%s does not state that there are %d task bars"
                 % (NARR, len(bars)))
        if "%d milestones" % len(miles) not in txt:
            fail("%s does not state that there are %d milestones"
                 % (NARR, len(miles)))
        for cid, (_, tname, _, _, _) in bars.items():
            key = tname.split(" and ")[0].split(" module")[0][:16]
            if key.lower() not in txt.lower():
                fail("narrative never mentions '%s'" % tname)
        print("narrative: states %d task bars and %d milestones -> ok"
              % (len(bars), len(miles)))
    except IOError:
        fail("%s not found" % NARR)

    print()
    if PROBLEMS:
        print("PROBLEMS (%d):" % len(PROBLEMS))
        for p in PROBLEMS:
            print("  -", p)
        raise SystemExit(1)
    print("GANTT CHART VALID")


if __name__ == "__main__":
    main()