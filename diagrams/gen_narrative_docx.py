#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Build 3.6_Activity_Diagram_Narrative.docx from activity_narrative.txt.

The 3.5 narrative .docx is used as a template so that the Heading 1 /
Heading 2 / Normal styles, the Times New Roman 12pt 1.5-spaced body and
the 1.25in margins come out identical to the other chapter 3 narratives.
"""

import io
import re
import shutil

import docx
from docx.enum.text import WD_ALIGN_PARAGRAPH as AL

SRC = "activity_narrative.txt"
TEMPLATE = "3.5_Data_Flow_Diagram_Narrative.docx"
OUT = "3.6_Activity_Diagram_Narrative.docx"


def read_sections(path):
    """Return (banner_lines, [(heading, kind, [paragraph_text, ...]), ...])."""
    lines = io.open(path, encoding="utf-8").read().split("\n")

    # ---- banner: text between the first and second ==== divider ---------
    i = 0
    while i < len(lines) and not lines[i].startswith("===="):
        i += 1
    while i < len(lines) and lines[i].startswith("===="):   # opening rule
        i += 1
    j = i
    while j < len(lines) and not lines[j].startswith("===="):
        j += 1
    banner = [l.rstrip() for l in lines[i:j] if l.strip()]
    body = lines[j + 1:]

    # ---- split on the all-caps headings ---------------------------------
    heads, cur = [], None
    for ln in body:
        m = re.match(r"^(FIGURE \d+\. .*|NOTATION LEGEND|RELATIONSHIP TO THE OTHER DIAGRAMS)\s*$",
                     ln)
        if m:
            cur = [m.group(1), []]
            heads.append(cur)
        elif cur is not None and not ln.startswith("===="):
            cur[1].append(ln)              # keep blanks: they end paragraphs
    out = []
    for head, raw in heads:
        if head == "NOTATION LEGEND":
            paras = group_legend(raw)
        elif head.startswith("FIGURE"):
            paras = group_numbered(raw)
        else:
            paras = group_blank(raw)
        out.append((head, paras))
    return banner, out


def group_blank(raw):
    paras, cur = [], []
    for ln in raw:
        if not ln.strip():
            if cur:
                paras.append(" ".join(x.strip() for x in cur))
                cur = []
        else:
            cur.append(ln)
    if cur:
        paras.append(" ".join(x.strip() for x in cur))
    return paras


def group_legend(raw):
    """A legend entry is 'term' + a run of 2+ spaces + 'description'.
    Anything else (e.g. the closing prose paragraph) continues the entry
    that is currently open, so plain indented prose is not split up."""
    paras, cur = [], None
    for ln in raw:
        if not ln.strip():                    # blank line ends the entry
            if cur is not None:
                paras.append(cur)
                cur = None
        elif re.match(r"^  \S.*?\s{2,}\S", ln):     # term + column gap
            if cur is not None:
                paras.append(cur)
            cur = ln.strip()
        elif cur is not None:
            cur += " " + ln.strip()
        else:
            cur = ln.strip()
    if cur is not None:
        paras.append(cur)
    return paras


def group_numbered(raw):
    """A numbered step starts at 'N.'; deeper indents continue it."""
    paras, cur = [], None
    for ln in raw:
        if not ln.strip():                    # blanks never join text
            continue
        if re.match(r"^\s*\d+\.\s", ln):      # a new numbered step
            if cur is not None:
                paras.append(cur)
            cur = ln.strip()
        elif cur is not None:
            cur += " " + ln.strip()
        elif ln.strip():
            cur = ln.strip()
    if cur is not None:
        paras.append(cur)
    return paras


def split_legend(p):
    """'Term    description' -> ('Term', 'description')"""
    m = re.match(r"^(\S.*?)\s{2,}(.*)$", p)
    return (m.group(1), m.group(2)) if m else (None, p)


def main():
    banner, sections = read_sections(SRC)

    shutil.copyfile(TEMPLATE, OUT)
    doc = docx.Document(OUT)

    # drop every paragraph of the template body; keep the trailing sectPr
    body = doc.element.body
    for p in list(doc.paragraphs):
        body.remove(p._element)

    def add(text, style="Normal", align=AL.JUSTIFY):
        para = doc.add_paragraph(style=style)
        if align is not None:
            para.alignment = align
        run = para.add_run(text)
        run.font.name = "Times New Roman"
        if style.startswith("Heading"):
            run.font.bold = True
        return para

    # ---- title -----------------------------------------------------------
    add("3.6 Activity Diagram", "Heading 1", None)

    # ---- banner as an identity block -------------------------------------
    for ln in banner:
        add(ln, "Normal", AL.LEFT)

    # ---- sections --------------------------------------------------------
    def cap(word):
        """'(PART' -> '(Part'  (str.capitalize would give '(part')."""
        if not word.isupper():
            return word
        for i, ch in enumerate(word):          # find the first letter
            if ch.isalpha():
                return word[:i] + ch.upper() + word[i + 1:].lower()
        return word

    def pretty(head):
        """FIGURE 10. ACTIVITY DIAGRAM - ADMINISTRATOR ROLE (PART 1)
           -> Figure 10. Activity Diagram - Administrator Role (Part 1)"""
        m = re.match(r"^FIGURE (\d+)\. ACTIVITY DIAGRAM - (.+)$", head)
        if m:
            rest = " ".join(cap(w) for w in m.group(2).split())
            return "Figure %s. Activity Diagram - %s" % (m.group(1), rest)
        return {"NOTATION LEGEND": "Notation Legend",
                "RELATIONSHIP TO THE OTHER DIAGRAMS":
                    "Relationship to the Other Diagrams"}.get(head, head)

    for head, paras in sections:
        add(pretty(head), "Heading 2", None)

        for p in paras:
            if head == "NOTATION LEGEND":
                term, desc = split_legend(p)
                para = doc.add_paragraph(style="Normal")
                para.alignment = AL.LEFT
                if term:
                    r1 = para.add_run(term + "  ")
                    r1.font.name = "Times New Roman"
                    r1.font.bold = True
                    r2 = para.add_run(desc)
                    r2.font.name = "Times New Roman"
                else:                       # closing prose, no term column
                    r = para.add_run(desc)
                    r.font.name = "Times New Roman"
            else:
                add(p, "Normal", AL.JUSTIFY)

    doc.save(OUT)
    print("wrote %s  paragraphs=%d" % (OUT, len(doc.paragraphs)))
    for p in doc.paragraphs[:8]:
        print("   [%s] %s" % (p.style.name, p.text[:74]))


if __name__ == "__main__":
    main()
