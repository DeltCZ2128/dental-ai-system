#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Build 3.10_Gantt_Chart_Narrative.docx from 3.10_Gantt_Chart_Narrative.txt.

The 3.5 narrative .docx is copied first, so the Heading 1 / Heading 2 / Normal
styles, the Times New Roman 12pt 1.5-spaced body and the 1.25in margins come
out identical to the other chapter 3 narratives.
"""

import io
import re
import shutil

import docx
from docx.enum.text import WD_ALIGN_PARAGRAPH as AL

SRC = "3.10_Gantt_Chart_Narrative.txt"
TEMPLATE = "3.5_Data_Flow_Diagram_Narrative.docx"
OUT = "3.10_Gantt_Chart_Narrative.docx"

HEADS = (r"NOTATION LEGEND|FIGURE 19\. GANTT CHART - PROJECT SCHEDULE|"
         r"THE FIVE PHASES|THE TEN TASK BARS \(DEVELOPMENT PHASE\)|"
         r"THE SIX MILESTONES|DATE EVIDENCE AND STATED ASSUMPTIONS|"
         r"RELATIONSHIP TO THE OTHER SECTIONS")

SMALL = {"to", "and", "of", "the", "in", "for", "a", "an", "on", "with"}


def read_sections(path):
    """Return (banner_lines, [(heading, [paragraph_text, ...]), ...])."""
    lines = io.open(path, encoding="utf-8").read().split("\n")

    # the banner is the block between the first and the second ==== rule
    i = 0
    while i < len(lines) and not lines[i].startswith("===="):
        i += 1
    while i < len(lines) and lines[i].startswith("===="):
        i += 1
    j = i
    while j < len(lines) and not lines[j].startswith("===="):
        j += 1
    banner = [l.rstrip() for l in lines[i:j] if l.strip()]

    heads, cur = [], None
    for ln in lines[j + 1:]:
        m = re.match(r"^(%s)\s*$" % HEADS, ln)
        if m:
            cur = [m.group(1), []]
            heads.append(cur)
        elif cur is not None and not ln.startswith("===="):
            cur[1].append(ln)

    out = []
    for head, raw in heads:
        paras = (group_legend(raw) if head == "NOTATION LEGEND"
                 else group_numbered(raw))
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
    """'Term' + 2 or more spaces + 'description'; other lines continue one."""
    paras, cur = [], None
    for ln in raw:
        if not ln.strip():
            if cur is not None:
                paras.append(cur)
                cur = None
        elif re.match(r"^  \S.*?\s{2,}\S", ln):
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
    """A numbered step starts at 'N.'; continuation lines join it."""
    paras, cur = [], None
    for ln in raw:
        if not ln.strip():
            if cur is not None:
                paras.append(cur)
                cur = None
            continue
        if re.match(r"^\s*\d+\.\s", ln):
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


def split_legend(p):
    m = re.match(r"^(\S.*?)\s{2,}(.*)$", p)
    return (m.group(1), m.group(2)) if m else (None, p)


def title_case(text):
    """'(DEVELOPMENT' -> '(Development'; str.capitalize() gives '(development'."""
    def cap(word):
        if not word.isupper():
            return word
        for i, ch in enumerate(word):
            if ch.isalpha():
                return word[:i] + ch.upper() + word[i + 1:].lower()
        return word
    return " ".join(w.lower() if (i and w.lower() in SMALL) else cap(w)
                    for i, w in enumerate(text.split()))


def pretty(head):
    """FIGURE 19. GANTT CHART - PROJECT SCHEDULE
       -> Figure 19. Gantt Chart - Project Schedule"""
    m = re.match(r"^FIGURE (\d+)\. (GANTT CHART) - (.+)$", head)
    if m:
        return "Figure %s. %s - %s" % (m.group(1), title_case(m.group(2)),
                                       title_case(m.group(3)))
    return title_case(head)


def main():
    banner, sections = read_sections(SRC)

    shutil.copyfile(TEMPLATE, OUT)
    doc = docx.Document(OUT)

    # drop the template body, keeping the trailing sectPr (the margins)
    body = doc.element.body
    for p in list(doc.paragraphs):
        body.remove(p._element)
    for t in list(doc.tables):
        t._element.getparent().remove(t._element)

    def add(text, style="Normal", align=AL.JUSTIFY):
        para = doc.add_paragraph(style=style)
        if align is not None:
            para.alignment = align
        run = para.add_run(text)
        run.font.name = "Times New Roman"
        if style.startswith("Heading"):
            run.font.bold = True
        return para

    add("3.10 Gantt Chart", "Heading 1", None)
    for ln in banner:
        add(ln, "Normal", AL.LEFT)

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
                else:
                    r = para.add_run(desc)
                    r.font.name = "Times New Roman"
            else:
                add(p, "Normal", AL.JUSTIFY)

    doc.save(OUT)
    print("wrote %s  paragraphs=%d" % (OUT, len(doc.paragraphs)))
    for p in doc.paragraphs:
        if p.style.name.startswith("Heading"):
            print("   [%s] %s" % (p.style.name, p.text))


if __name__ == "__main__":
    main()