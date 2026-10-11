#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Build the IT Expert evaluation form .docx from its paste-ready .md.

Usage:

    python gen_it_eval_docx.py [SOURCE.md] [OUTPUT.docx]

With no arguments it builds evaluation/IT_Expert_Evaluation_Form.docx from
evaluation/IT_Expert_Evaluation_Form.md.

The Chapter 3 narrative .docx is copied first, so the Heading 1 / Heading 2 /
Heading 3 / Normal styles, the Times New Roman 12pt 1.5-spaced body, the
1.25in margins and the Table Grid style come out identical to the narratives
of the manuscript who accompanies it.

Only the block between the markers

    <!-- BEGIN SUBMITTABLE -->
    <!-- END SUBMITTABLE -->

is written, so the build notes and the notes for the researchers stay in the
.md and never reach the printed form.

Markdown handled: '# ' -> Heading 1, '## ' -> Heading 2, '### ' -> Heading 3,
blank-line-separated prose -> justified Normal, pipe tables -> a real Word
table, inline **bold** -> bold runs, inline `code` -> Consolas runs, and
numbered items -> indented Normal paragraphs that keep their numbers.

The .md stays the single source of truth, so editing the items and re-running
this script is the whole workflow.
"""

import io
import os
import re
import shutil
import sys

import docx
from docx.enum.text import WD_ALIGN_PARAGRAPH as AL
from docx.shared import Inches, Pt

SRC = "IT_Expert_Evaluation_Form.md"
TEMPLATE = os.path.join("..", "diagrams",
                        "3.5_Data_Flow_Diagram_Narrative.docx")
OUT = "IT_Expert_Evaluation_Form.docx"

BEGIN = "<!-- BEGIN SUBMITTABLE -->"
END = "<!-- END SUBMITTABLE -->"

BODY_FONT = "Times New Roman"
MONO_FONT = "Consolas"
ITEM = re.compile(r"^(\s*)(\d+)\.\s+(.*)$")
HEADER_SHADE = "B4C7E7"   # light blue fill of the table header row


def shade_cell(cell, hex_fill):
    """Fill a table cell with a solid background colour."""
    from docx.oxml import OxmlElement
    from docx.oxml.ns import qn

    tc_pr = cell._tc.get_or_add_tcPr()
    shd = OxmlElement("w:shd")
    shd.set(qn("w:val"), "clear")
    shd.set(qn("w:color"), "auto")
    shd.set(qn("w:fill"), hex_fill)
    tc_pr.append(shd)


def submittable(md_text):
    """Return only the block between the two markers."""
    if BEGIN not in md_text or END not in md_text:
        raise SystemExit(
            "markers %r / %r not found in %s" % (BEGIN, END, SRC))
    block = md_text.split(BEGIN, 1)[1].split(END, 1)[0]
    return block


def blocks(md_text):
    """Yield ('table', rows) or ('para', text) or ('head', level, text)."""
    out, cur, tbl = [], [], []

    def flush_para():
        if cur:
            first = cur[0].rstrip()
            rest = " ".join(x.strip() for x in cur[1:])
            out.append(("para", (first + " " + rest) if rest else first))
            del cur[:]

    def flush_table():
        if tbl:
            out.append(("table", [r for r in tbl]))
            del tbl[:]

    for raw in md_text.split("\n"):
        line = raw.rstrip()
        if line.strip().startswith("<!--"):
            continue
        m = re.match(r"^(#{1,3}) +(.*)$", line)
        if m:
            flush_para()
            flush_table()
            out.append(("head", len(m.group(1)), m.group(2).strip()))
        elif line.startswith("|"):
            flush_para()
            tbl.append([c.strip() for c in line.strip("|").split("|")])
        elif not line.strip() or set(line.strip()) <= set("-"):
            flush_para()
            flush_table()
        elif ITEM.match(line):
            # a new numbered item always starts its own paragraph, even when
            # the previous item was not separated from it by a blank line
            flush_para()
            flush_table()
            cur.append(line)
        else:
            flush_table()
            cur.append(line)
    flush_para()
    flush_table()
    return out


def add_runs(para, text, size=None):
    """Write text into para, honouring inline **bold** and `code`."""
    bold = mono = False
    for piece in re.split(r"(\*\*|`)", text):
        if piece == "**":
            bold = not bold
            continue
        if piece == "`":
            mono = not mono
            continue
        if not piece:
            continue
        run = para.add_run(piece)
        run.font.name = MONO_FONT if mono else BODY_FONT
        run.font.bold = bold
        if size is not None:
            run.font.size = Pt(size)


def is_rule(row):
    return all(set(c) <= set("-: ") and c.strip() for c in row)


def add_table(doc, rows):
    """Render a markdown pipe table as a real Word table."""
    header = rows[0]
    body = [r for r in rows[1:] if not is_rule(r)]
    table = doc.add_table(rows=1 + len(body), cols=len(header))
    table.style = "Table Grid"
    table.autofit = True
    for j, cell_text in enumerate(header):
        para = table.cell(0, j).paragraphs[0]
        para.alignment = AL.LEFT
        add_runs(para, cell_text, size=10)
        for run in para.runs:
            run.font.bold = True
        shade_cell(table.cell(0, j), HEADER_SHADE)
    for i, row in enumerate(body, start=1):
        for j in range(len(header)):
            text = row[j] if j < len(row) else ""
            para = table.cell(i, j).paragraphs[0]
            para.alignment = AL.LEFT
            add_runs(para, text, size=10)
    return table


def add_list_item(doc, indent, number, text):
    """Numbered item as an indented Normal paragraph that keeps its number."""
    para = doc.add_paragraph(style="Normal")
    para.alignment = AL.JUSTIFY
    para.paragraph_format.left_indent = Inches(0.5 * (indent + 1))
    para.paragraph_format.first_line_indent = Inches(-0.25)
    add_runs(para, "%s. %s" % (number, text))


def add_caption(doc, text):
    """A table caption line, bold and left aligned."""
    para = doc.add_paragraph(style="Normal")
    para.alignment = AL.LEFT
    clean = text.replace("**", "")
    run = para.add_run(clean)
    run.font.name = BODY_FONT
    run.font.bold = True
    return para


def main():
    global SRC, OUT
    os.chdir(os.path.dirname(os.path.abspath(__file__)))
    if len(sys.argv) > 1:
        SRC = sys.argv[1]
    if len(sys.argv) > 2:
        OUT = sys.argv[2]

    md_text = submittable(io.open(SRC, encoding="utf-8").read())

    shutil.copyfile(TEMPLATE, OUT)
    doc = docx.Document(OUT)
    body = doc.element.body
    for p in list(doc.paragraphs):
        body.remove(p._element)
    for t in list(doc.tables):
        t._element.getparent().remove(t._element)

    stats = {"head": 0, "para": 0, "table": 0, "item": 0, "caption": 0}
    for item in blocks(md_text):
        if item[0] == "head":
            level, text = item[1], item[2]
            para = doc.add_paragraph(style="Heading %d" % level)
            run = para.add_run(text)
            run.font.name = BODY_FONT
            run.font.bold = True
            stats["head"] += 1
        elif item[0] == "table":
            add_table(doc, item[1])
            doc.add_paragraph(style="Normal")
            stats["table"] += 1
        else:
            text = item[1]
            m = ITEM.match(text)
            if m:
                add_list_item(doc, len(m.group(1)) // 3, m.group(2),
                              m.group(3))
                stats["item"] += 1
            elif text.startswith("**Table "):
                add_caption(doc, text)
                stats["caption"] += 1
            else:
                para = doc.add_paragraph(style="Normal")
                para.alignment = AL.JUSTIFY
                add_runs(para, text.strip())
                stats["para"] += 1

    doc.save(OUT)
    print("wrote %s" % OUT)
    print("   headings=%d paragraphs=%d tables=%d items=%d captions=%d"
          % (stats["head"], stats["para"], stats["table"], stats["item"],
             stats["caption"]))
    for p in doc.paragraphs:
        if p.style.name.startswith("Heading"):
            print("   ", p.text)
    return 0


if __name__ == "__main__":
    sys.exit(main())
