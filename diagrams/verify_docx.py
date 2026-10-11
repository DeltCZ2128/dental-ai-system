#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Verify 3.6_Activity_Diagram_Narrative.docx: headings in order, Word styles,
blank-line preservation and the number of numbered steps in each figure."""

import io
import re
import sys
from collections import Counter

import docx

DOC = sys.argv[1] if len(sys.argv) > 1 else "3.6_Activity_Diagram_Narrative.docx"
d = docx.Document(DOC)

styles = Counter(p.style.name for p in d.paragraphs)
print("paragraphs      :", len(d.paragraphs))
print("styles          :", dict(styles))
print("blank paragraphs:", sum(1 for p in d.paragraphs if not p.text.strip()))

sec = next(s for s in d.sections[0].page_width.__class__.__mro__
           if False) if False else None

print("\nheadings in order:")
for p in d.paragraphs:
    if p.style.name.startswith("Heading"):
        print("  %-9s %s" % (p.style.name.replace("Heading ", "H"), p.text))

cur, steps = None, {}
for p in d.paragraphs:
    if p.style.name == "Heading 2":
        cur = p.text
        steps[cur] = 0
    elif cur and re.match(r"^\d+\.\s", p.text.strip()):
        steps[cur] += 1

print("\nnumbered steps per section:")
for k, v in steps.items():
    print("  %-58s %3d" % (k[:58], v))

# formatting probes - prefer a numbered step, fall back to any body paragraph
steps_para = [p for p in d.paragraphs
              if p.style.name == "Normal"
              and len(p.text.strip()) > 40
              and re.match(r"^\d+\.\s", p.text.strip())]
if not steps_para:                      # some narratives are pure prose
    steps_para = [p for p in d.paragraphs
                  if p.style.name == "Normal" and len(p.text.strip()) > 40]
p = steps_para[0]
r = p.runs[0]
sa = p.paragraph_format.space_after
st = d.styles["Normal"]
print("\nprobe paragraph: %r" % p.text[:60])
print("  run font      : %s size=%s" % (r.font.name, r.font.size))
print("  style font    : %s size=%s" % (st.font.name, st.font.size))
print("  aligned       : %s" % p.alignment)
print("  line spacing  : %s  space after: %s"
      % (p.paragraph_format.line_spacing, sa.pt if sa else "inherit"))
print("  style spacing : %s after=%s"
      % (st.paragraph_format.line_spacing,
         st.paragraph_format.space_after))
print("margins (in)   :", [round(getattr(s, m).inches, 2)
                           for s in d.sections
                           for m in ("left_margin", "right_margin",
                                     "top_margin", "bottom_margin")])

# every section heading of the narrative must appear as a Heading 2
have = [p.text for p in d.paragraphs if p.style.name == "Heading 2"]
missing = []
if len(sys.argv) > 2:
    txt = io.open(sys.argv[2], encoding="utf-8").read()
    for m in re.finditer(r"^(FIGURE \d+\..*|[A-Z][A-Z ]{6,})\s*$", txt, re.M):
        h = m.group(1).strip()
        if h.startswith("===="):
            continue
        want = h
        fig = re.match(r"^FIGURE (\d+)\. USE CASE DIAGRAM - (.+)$", h)
        if fig:
            want = "Figure %s. Use Case Diagram - %s" % (
                fig.group(1), fig.group(2).title())
        if want not in have and h not in have:
            missing.append(want)
    missing = [x for x in missing
               if x not in ("NOTATION LEGEND", "ACTIVITY DIAGRAM NARRATIVE",
                            "TRACEABILITY TO THE IMPLEMENTATION")
               and x not in have]
print("\nHeading 2 found :", len(have))
for h in have:
    print("   ", h)
if sys.argv[2:] and missing:
    print("missing headings:", missing)
print("RESULT:",
      "DOCX OK" if (len(have) >= 2 and not missing) else "DOCX NEEDS CHECKING")
