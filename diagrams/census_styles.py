#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""List the value of every vertex per page, grouped by its fill colour, so the
wording used in the narrative can be checked against what is actually drawn."""

import re
import xml.etree.ElementTree as ET

root = ET.parse("activity_diagrams.drawio").getroot()


def cat(style):
    if "rhombus" in style:
        return "decision"
    if "#fee2e2" in style:
        return "outcome (red)"
    if "#dcfce7" in style:
        return "green"
    if "#e0f2fe" in style:
        return "action (blue)"
    return "other"


for page in root:
    print("=" * 78)
    print(page.get("id"), "-", page.get("name"))
    buckets = {}
    for c in page.find("mxGraphModel").iter("mxCell"):
        if c.get("vertex") != "1":
            continue
        val = re.sub(r"<[^>]+>", " ", c.get("value") or "")
        val = re.sub(r"\s+", " ", val).strip()
        buckets.setdefault(cat(c.get("style") or ""), []).append(val)
    for k in ("action (blue)", "green", "decision", "outcome (red)", "other"):
        if k in buckets:
            print("  %-14s x%d" % (k, len(buckets[k])))
            if k in ("green", "other"):
                for v in buckets[k]:
                    print("        -", v[:70])
