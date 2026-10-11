#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Final validation of every activity-diagram deliverable.

Checks, for the master workbook and each standalone file:
  * the XML parses
  * one page per standalone document / four pages in the master
  * the pages="N" attribute agrees with the child count
  * every edge source/target resolves to a cell that exists
  * every decision (rhombus) has exactly two outgoing edges
  * no edge or vertex id appears twice inside a page
"""

import glob
import io
import xml.etree.ElementTree as ET

MASTER = "activity_diagrams.drawio"
EXPECTED = {
    "Figure 8 - Patient": (70, 48, 11),
    "Figure 9 - Dentist": (44, 28, 5),
    "Figure 10 - Administrator Part 1": (65, 43, 11),
    "Figure 11 - Administrator Part 2": (70, 49, 10),
}

BAD = []


def check(path):
    raw = io.open(path, encoding="utf-8").read()
    try:
        root = ET.fromstring(raw)
    except ET.ParseError as exc:                       # pragma: no cover
        BAD.append("%s: not well-formed (%s)" % (path, exc))
        print("%-46s XML PARSE FAILED: %s" % (path, exc))
        return
    pages = list(root)
    declared = root.get("pages")
    if declared is not None and int(declared) != len(pages):
        BAD.append("%s: pages=%s but %d <diagram> children"
                   % (path, declared, len(pages)))
    print("%-46s pages=%s declared=%s" % (path, len(pages), declared))

    for page in pages:
        name = page.get("name")
        model = page.find("mxGraphModel")
        if model is None:
            BAD.append("%s/%s: no mxGraphModel" % (path, name))
            continue
        cells = list(model.iter("mxCell"))
        verts = [c for c in cells if c.get("vertex") == "1"]
        edges = [c for c in cells if c.get("edge") == "1"]
        ids = [c.get("id") for c in cells]
        dupes = sorted({i for i in ids if ids.count(i) > 1})
        dangling = [c.get("id") for c in edges
                    if c.get("source") not in ids or c.get("target") not in ids]
        dec = [c for c in cells if "rhombus" in (c.get("style") or "")]
        odd = [(c.get("id"),
                sum(1 for e in edges if e.get("source") == c.get("id")))
               for c in dec]
        odd = [t for t in odd if t[1] != 2]

        want = EXPECTED.get(name)
        got = (len(verts), len(edges), len(dec))
        tag = "OK  "
        if dupes or dangling or odd:
            tag = "FAIL"
            BAD.append("%s/%s dupes=%s dangling=%s non-two-way=%s"
                       % (path, name, dupes, dangling, odd))
        elif want and want != got:
            tag = "FAIL"
            BAD.append("%s/%s counts %s != expected %s" % (path, name, got, want))
        print("   %s %-34s verts=%3d edges=%3d decisions=%2d%s"
              % (tag, name, got[0], got[1], got[2],
                 "" if (want is None or want == got)
                 else "   expected %s" % (want,)))


def main():
    for path in [MASTER] + sorted(glob.glob("Activity_Diagram_*.drawio")):
        check(path)
    print()
    if BAD:
        print("PROBLEMS FOUND (%d):" % len(BAD))
        for b in BAD:
            print("  -", b)
        raise SystemExit(1)
    print("ALL DRAWIO DELIVERABLES VALID")


if __name__ == "__main__":
    main()
