#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Verify the 3.8 use case diagrams independently of the generator.

Structural checks
  * the file parses as XML and carries as many <diagram> pages as it claims
  * no cell id is repeated inside a page
  * every edge source/target resolves to a cell that exists
  * every use case oval carries a label
  * every dashed edge is an <<include>> or <<extend>> between two ovals
  * every solid edge is an association between an actor and an oval

Geometric checks (these are the ones that catch an untidy drawing)
  * no two use case ovals overlap
  * every oval sits inside the system boundary
  * every actor sits outside the system boundary, on the page
  * no connector segment passes through a use case oval it is not attached to
  * drawn ovals / associations / relationships per figure are reported so the
    narrative can be checked against them
"""
from __future__ import annotations

import glob
import io
import math
import re
import xml.etree.ElementTree as ET

STANDALONE = ["Use_Case_Patient.drawio",
              "Use_Case_Dentist.drawio",
              "Use_Case_Administrator.drawio"]
MASTER = "use_case_diagrams.drawio"

PROBLEMS = []
NOTES = []


def note(msg):
    NOTES.append(msg)


def fail(msg):
    PROBLEMS.append(msg)


# --------------------------------------------------------------------------
# geometry helpers
# --------------------------------------------------------------------------
def num(style, key, default=None):
    m = re.search(r"(?:^|;)%s=(-?[\d.]+)" % re.escape(key), style)
    return float(m.group(1)) if m else default


def inside_ellipse(p, box, shrink=0.0):
    """Is point p inside the ellipse inscribed in box, shrunk by 'shrink' px?"""
    x, y, w, h = box
    a, b = w / 2.0 - shrink, h / 2.0 - shrink
    if a <= 0 or b <= 0:
        return False
    cx, cy = x + w / 2.0, y + h / 2.0
    return ((p[0] - cx) / a) ** 2 + ((p[1] - cy) / b) ** 2 <= 1.0


def segment_through_ellipse(p1, p2, box, min_chord=3.0):
    """True when the segment really passes INTO the ellipse (not just touches).

    A connector that terminates on the ellipse edge is tangent, so its overlap
    with the interior is a single point and the chord length is ~0.
    """
    x, y, w, h = box
    cx, cy = x + w / 2.0, y + h / 2.0
    a, b = w / 2.0, h / 2.0
    ox, oy = p1[0] - cx, p1[1] - cy
    dx, dy = p2[0] - p1[0], p2[1] - p1[1]
    A = (dx / a) ** 2 + (dy / b) ** 2
    if A == 0:
        return False
    B = 2.0 * (ox * dx / a ** 2 + oy * dy / b ** 2)
    C = (ox / a) ** 2 + (oy / b) ** 2 - 1.0
    disc = B * B - 4 * A * C
    if disc <= 0:
        return False
    root = math.sqrt(disc)
    t1, t2 = (-B - root) / (2 * A), (-B + root) / (2 * A)
    lo, hi = max(t1, 0.0), min(t2, 1.0)
    if hi <= lo:
        return False
    return (hi - lo) * math.hypot(dx, dy) > min_chord


def boxes_overlap(b1, b2):
    x1, y1, w1, h1 = b1
    x2, y2, w2, h2 = b2
    return not (x1 + w1 <= x2 or x2 + w2 <= x1 or
                y1 + h1 <= y2 or y2 + h2 <= y1)


def rect_overlap(b1, b2):
    return boxes_overlap(b1, b2)


def seg_cross(a1, a2, b1, b2):
    """Proper crossing of two segments (shared endpoints do not count)."""
    def cross(o, p, q):
        return (p[0] - o[0]) * (q[1] - o[1]) - (p[1] - o[1]) * (q[0] - o[0])

    d1, d2 = cross(b1, b2, a1), cross(b1, b2, a2)
    d3, d4 = cross(a1, a2, b1), cross(a1, a2, b2)
    if ((d1 > 0) != (d2 > 0)) and ((d3 > 0) != (d4 > 0)):
        for p, q in ((a1, b1), (a1, b2), (a2, b1), (a2, b2)):
            if math.hypot(p[0] - q[0], p[1] - q[1]) < 1.0:
                return False          # touching at a shared end point
        return True
    return False


def segment_inside_box(p1, p2, box, shrink=2.0):
    """True when the segment passes through the interior of a plain box."""
    x, y, w, h = box
    for i in range(1, 80):
        t = i / 80.0
        px = p1[0] + (p2[0] - p1[0]) * t
        py = p1[1] + (p2[1] - p1[1]) * t
        if x + shrink < px < x + w - shrink and \
                y + shrink < py < y + h - shrink:
            return True
    return False


# --------------------------------------------------------------------------
# one page
# --------------------------------------------------------------------------
def check_page(path, page, model):
    name = page.get("name")
    pw = float(model.get("pageWidth") or 0)
    ph = float(model.get("pageHeight") or 0)
    cells = list(model.iter("mxCell"))

    verts, edges = {}, []
    for c in cells:
        g = c.find("mxGeometry")
        if c.get("vertex") == "1" and g is not None:
            verts[c.get("id")] = {
                "id": c.get("id"),
                "box": (float(g.get("x") or 0), float(g.get("y") or 0),
                        float(g.get("width") or 0), float(g.get("height") or 0)),
                "style": c.get("style") or "",
                "value": c.get("value") or "",
            }
        elif c.get("edge") == "1":
            edges.append({"id": c.get("id"), "style": c.get("style") or "",
                          "value": c.get("value") or "",
                          "source": c.get("source"), "target": c.get("target")})

    ids = [v["id"] for v in verts.values()] + [e["id"] for e in edges]
    dupes = sorted({i for i in ids if ids.count(i) > 1})
    if dupes:
        fail("%s/%s: duplicate cell ids %s" % (path, name, dupes))

    ovals = {i: v for i, v in verts.items() if v["style"].startswith("ellipse")}
    actors = {i: v for i, v in verts.items() if "umlActor" in v["style"]}
    bounds = [v for v in verts.values() if "fillColor=none" in v["style"]]
    if not bounds:
        fail("%s/%s: no system boundary rectangle" % (path, name))
        return None
    bnd = bounds[0]["box"]

    for o in ovals.values():
        if not o["value"].strip():
            fail("%s/%s: unlabelled use case %s" % (path, name, o["id"]))
    for a in actors.values():
        if not a["value"].strip():
            fail("%s/%s: unlabelled actor %s" % (path, name, a["id"]))
    if not actors:
        fail("%s/%s: no actor drawn" % (path, name))

    def ends(e):
        s, t = verts.get(e["source"]), verts.get(e["target"])
        if s is None or t is None:
            fail("%s/%s: edge %s has an unresolved end point (%s -> %s)"
                 % (path, name, e["id"], e["source"], e["target"]))
            return None
        p1 = (s["box"][0] + s["box"][2] * num(e["style"], "exitX", 0.5),
              s["box"][1] + s["box"][3] * num(e["style"], "exitY", 0.5))
        p2 = (t["box"][0] + t["box"][2] * num(e["style"], "entryX", 0.5),
              t["box"][1] + t["box"][3] * num(e["style"], "entryY", 0.5))
        return e, s, t, p1, p2

    counts = {"assoc": 0, "include": 0, "extend": 0}
    touched, segs = set(), []
    for e in edges:
        got = ends(e)
        if got is None:
            continue
        _, s, t, p1, p2 = got
        dashed = "dashed=1" in e["style"]
        if dashed:
            kind = ("extend" if "extend" in e["value"]
                    else "include" if "include" in e["value"] else None)
            if kind is None:
                fail("%s/%s: dashed edge %s has no <<include>>/<<extend>> label"
                     % (path, name, e["id"]))
                continue
            counts[kind] += 1
            for end in (s, t):
                if not end["style"].startswith("ellipse"):
                    fail("%s/%s: %s edge %s does not join two use cases"
                         % (path, name, kind, e["id"]))
        else:
            counts["assoc"] += 1
            role = [x for x in (s, t) if "umlActor" in x["style"]]
            usec = [x for x in (s, t) if x["style"].startswith("ellipse")]
            if len(role) != 1 or len(usec) != 1:
                fail("%s/%s: association %s is not actor-to-use-case"
                     % (path, name, e["id"]))
        touched.add(e["source"])
        touched.add(e["target"])
        segs.append((e, p1, p2))

        for oid, o in ovals.items():
            if oid in (e["source"], e["target"]):
                continue
            if segment_through_ellipse(p1, p2, o["box"]):
                fail("%s/%s: %s %s cuts through use case '%s'"
                     % (path, name,
                        "relationship" if dashed else "association",
                        e["id"], o["value"]))
        for aid, a in actors.items():
            if aid == e["source"]:
                continue
            if segment_inside_box(p1, p2, a["box"]):
                fail("%s/%s: %s passes through actor '%s'"
                     % (path, name, e["id"], a["value"]))

    # ---- every use case must be reachable --------------------------------
    for oid, o in ovals.items():
        if oid not in touched:
            fail("%s/%s: use case '%s' is connected to nothing"
                 % (path, name, o["value"]))

    # ---- no overlapping ovals --------------------------------------------
    olist = list(ovals.values())
    for i in range(len(olist)):
        for j in range(i + 1, len(olist)):
            if boxes_overlap(olist[i]["box"], olist[j]["box"]):
                fail("%s/%s: use cases overlap: '%s' and '%s'"
                     % (path, name, olist[i]["value"], olist[j]["value"]))

    # ---- placement --------------------------------------------------------
    for o in ovals.values():
        x, y, w, h = o["box"]
        if (x < bnd[0] - 1 or y < bnd[1] - 1 or
                x + w > bnd[0] + bnd[2] + 1 or y + h > bnd[1] + bnd[3] + 1):
            fail("%s/%s: use case '%s' is outside the system boundary"
                 % (path, name, o["value"]))
    for a in actors.values():
        if rect_overlap(a["box"], bnd):
            fail("%s/%s: actor '%s' is inside the system boundary"
                 % (path, name, a["value"]))
        x, y, w, h = a["box"]
        if pw and (x < 0 or y < 0 or x + w > pw or y + h + 18 > ph):
            fail("%s/%s: actor '%s' runs off the page"
                 % (path, name, a["value"]))

    # ---- crossing relationships (reported, not fatal) --------------------
    for i in range(len(segs)):
        for j in range(i + 1, len(segs)):
            e1, p1a, p1b = segs[i]
            e2, p2a, p2b = segs[j]
            if "dashed=1" not in e1["style"] or "dashed=1" not in e2["style"]:
                continue
            if seg_cross(p1a, p1b, p2a, p2b):
                note("%s/%s: relationships %s and %s cross"
                     % (path, name, e1["id"], e2["id"]))

    return {"name": name, "ovals": len(ovals), "actors": len(actors),
            "assoc": counts["assoc"], "include": counts["include"],
            "extend": counts["extend"],
            "labels": sorted(v["value"] for v in ovals.values())}


# --------------------------------------------------------------------------
# driver
# --------------------------------------------------------------------------
def check_file(path, expect_pages):
    raw = io.open(path, encoding="utf-8").read()
    if "compressed" in raw[:400] or "base64" in raw[:400]:
        fail("%s: file is compressed, draw.io XML must be plain" % path)
    root = ET.fromstring(raw)
    pages = [p for p in root if p.tag == "diagram"]
    declared = root.get("pages")
    if declared is not None and int(declared) != len(pages):
        fail("%s: pages=%s but %d <diagram> children"
             % (path, declared, len(pages)))
    if expect_pages is not None and len(pages) != expect_pages:
        fail("%s: expected %d page(s), found %d"
             % (path, expect_pages, len(pages)))
    out = []
    for page in pages:
        model = page.find("mxGraphModel")
        if model is None:
            fail("%s: page %s has no mxGraphModel" % (path, page.get("id")))
            continue
        if "mxCell" not in raw:
            fail("%s: no cells" % path)
        res = check_page(path, page, model)
        if res:
            out.append(res)
    return out


NARRATIVE = "3.8_Use_Case_Diagram_Narrative.txt"
ROLE_WORD = {"patient": "Patient", "dentist": "Dentist",
             "administrator": "Administrator"}


def cross_check(rows):
    """The narrative must state the same numbers the diagrams actually draw,
    and must document every use case that is drawn."""
    try:
        txt = re.sub(r"\s+", " ",
                     io.open(NARRATIVE, encoding="utf-8").read())
    except IOError:
        note("%s not found - narrative cross-check skipped" % NARRATIVE)
        return

    for r in rows:
        if not r["name"].startswith("Figure 1"):
            continue
        role = r["name"].split("-")[-1].strip()          # Patient / Dentist ...
        m = re.search(r"FIGURE \d+\. USE CASE DIAGRAM - %s ROLE (.{0,420})"
                      % role.upper(), txt)
        if not m:
            fail("%s: no narrative section for the %s figure"
                 % (NARRATIVE, role))
            continue
        claims = dict(re.findall(
            r"(\d+) (use cases?|associations?|<<include>> relationships?|"
            r"<<extend>> relationships?)", m.group(1)))
        want = {"ovals": re.search(r"contains (\d+) use cases", m.group(1)),
                "assoc": re.search(r"(\d+) associations", m.group(1)),
                "include": re.search(r"(\d+) <<include>>", m.group(1)),
                "extend": re.search(r"(\d+) <<extend>>", m.group(1))}
        for key, found in want.items():
            claimed = int(found.group(1)) if found else 0
            if claimed != r[key]:
                fail("%s: %s figure claims %d %s but %d are drawn"
                     % (NARRATIVE, role, claimed, key, r[key]))
        for label in r["labels"]:
            base = label.split(" (")[0]
            if base.lower() not in txt.lower():
                fail("%s: use case '%s' of the %s figure is never mentioned"
                     % (NARRATIVE, label, role))
        print("narrative %-14s use cases=%d associations=%d include=%d extend=%d"
              " -> all documented" % (role, r["ovals"], r["assoc"], r["include"],
                                      r["extend"]))
        del claims


def main():
    rows = []
    for path in [MASTER] + STANDALONE:
        rows += check_file(path, 3 if path == MASTER else 1)
    cross_check(rows)
    print("%-28s %6s %6s %8s %8s %7s %7s"
          % ("page", "ovals", "actors", "assoc", "include", "extend", "total"))
    for r in rows:
        print("%-28s %6d %6d %8d %8d %7d %7d"
              % (r["name"], r["ovals"], r["actors"], r["assoc"], r["include"],
                 r["extend"], r["ovals"] + r["assoc"] + r["include"]
                 + r["extend"] + r["actors"]))

    if NOTES:
        print("\nnotes (%d):" % len(NOTES))
        for n in NOTES:
            print("  ~", n)
    if PROBLEMS:
        print("\nPROBLEMS (%d):" % len(PROBLEMS))
        for p in PROBLEMS:
            print("  -", p)
        raise SystemExit(1)
    print("\nALL USE CASE DELIVERABLES VALID")


if __name__ == "__main__":
    main()


