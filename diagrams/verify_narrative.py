#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Cross-check the narrative against the diagrams it describes.

The .txt states, for every figure, how many actions, decisions and alternative
outcomes the diagram contains, in words.  This script recounts those three
categories straight from activity_diagrams.drawio and compares the two, so a
diagram edit can never silently invalidate the chapter text.
"""

import io
import re
import xml.etree.ElementTree as ET

SRC = "activity_narrative.txt"
DRAWIO = "activity_diagrams.drawio"

# page id -> figure number used in the narrative
PAGES = {"p1": 8, "p2": 9, "p3": 10, "p4": 11}

WORDS = {w: i for i, w in enumerate(
    "zero one two three four five six seven eight nine ten eleven twelve "
    "thirteen fourteen fifteen sixteen seventeen eighteen nineteen twenty"
    .split())}


def num(word):
    """'nineteen' -> 19, 'twenty-five' -> 25."""
    total = 0
    for part in word.lower().split("-"):
        if part not in WORDS:
            raise ValueError("not a number word: %r" % word)
        total += WORDS[part]
    return total


def census(model):
    """Recount actions / decisions / outcomes the way the generator draws them.

    A blue box is an action of the actor's lane and a green box is an action of
    the proposed system's lane, so both are activities of the diagram and the
    narrative adds them together.  The ovals that carry the Start and End
    labels belong to neither colour and are not counted.
    """
    cells = [c for c in model.iter("mxCell") if c.get("vertex") == "1"]
    styles = [c.get("style") or "" for c in cells]
    return {
        "actions": sum(1 for s in styles if "#e0f2fe" in s or "#dcfce7" in s),
        "decisions": sum(1 for s in styles if "rhombus" in s),
        "outcomes": sum(1 for s in styles if "#fee2e2" in s),
        "actor_lane": sum(1 for s in styles if "#e0f2fe" in s),
        "system_lane": sum(1 for s in styles if "#dcfce7" in s),
    }


def main():
    # ---------------------------------------------------------------- diagram
    root = ET.parse(DRAWIO).getroot()
    actual = {}
    for page in root:
        pid = page.get("id")
        if pid in PAGES:
            actual[PAGES[pid]] = census(page.find("mxGraphModel"))

    # -------------------------------------------------------------- narrative
    text = re.sub(r"\s+", " ", io.open(SRC, encoding="utf-8").read())

    # each figure is introduced by its own banner; the count sentence sits in
    # the section that follows that banner ('The dentist figure contains ...',
    # 'Figure 10 contains ...', 'It contains ...')
    claimed = {}
    for chunk in re.split(r"(?=FIGURE \d+\. ACTIVITY DIAGRAM)", text):
        hm = re.match(r"FIGURE (\d+)\. ACTIVITY DIAGRAM", chunk)
        if not hm:
            continue
        m = re.search(r"contains ([a-z-]+) actions?, ([a-z-]+) decisions? "
                      r"and ([a-z-]+) (?:alternative )?outcomes?", chunk, re.I)
        if m:
            claimed[int(hm.group(1))] = {"actions": num(m.group(1)),
                                         "decisions": num(m.group(2)),
                                         "outcomes": num(m.group(3))}

    # ------------------------------------------------------------- reporting
    bad = []
    for fig in sorted(actual):
        a, c = actual[fig], claimed.get(fig)
        if c is None:
            bad.append("Figure %d: no count sentence found in the narrative" % fig)
            print("Figure %-3d DIAGRAM   %s" % (fig, a))
            continue
        same = all(a[k] == c[k] for k in ("actions", "decisions", "outcomes"))
        print("Figure %-3d %s  diagram %s  narrative %s"
              % (fig, "MATCH " if same else "MISMATCH", a, c))
        if not same:
            bad.append("Figure %d: diagram %s vs narrative %s" % (fig, a, c))

    # every figure must also have a heading and a numbered-step list
    for fig in sorted(actual):
        head = re.search(r"FIGURE %d\. ACTIVITY DIAGRAM - [A-Z ()\d]+" % fig, text)
        if not head:
            bad.append("Figure %d: missing section heading" % fig)

    print()
    if bad:
        print("NARRATIVE INCONSISTENCIES (%d):" % len(bad))
        for b in bad:
            print("  -", b)
        raise SystemExit(1)
    print("NARRATIVE MATCHES ALL FOUR DIAGRAMS")


if __name__ == "__main__":
    main()
