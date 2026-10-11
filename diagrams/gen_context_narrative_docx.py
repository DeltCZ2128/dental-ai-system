#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Build 3.4_Context_Diagram_Narrative.docx from
3.4_Context_Diagram_Narrative.md.

The 3.5 narrative .docx is copied first, so the Heading 1 / Heading 2 /
Heading 3 / Normal styles, the Times New Roman 12pt 1.5-spaced body, the
1.25in margins and the Table Grid style come out identical to the rest of the
chapter 3 narratives.

Markdown handled: '# ' -> Heading 1, '## ' -> Heading 2, '### ' -> Heading 3,
blank-line-separated prose -> justified Normal, and pipe tables -> a real
Word table.  The .md stays the single source of truth, so editing the prose
and re-running this script is the whole workflow.
"""

import io
import re
import shutil

import docx
from docx.enum.text import WD_ALIGN_PARAGRAPH as AL

SRC = "3.4_Context_Diagram_Narrative.md"
TEMPLATE = "3.5_Data_Flow_Diagram_Narrative.docx"
OUT = "3.4_Context_Diagram_Narrative.docx"


def blocks(md_text):
    """Yield ('table', rows) or ('para', text) or ('head', level, text)."""
    out, cur, tbl = [], [], []

    def flush_para():
        if cur:
            out.append(("para", " ".join(x.strip() for x in cur)))
            del cur[:]

    def flush_table():
        if tbl:
            out.append(("table", [r for r in tbl]))
            del tbl[:]

    for raw in md_text.split("\n"):
        line = raw.rstrip()
        m = re.match(r"^(#{1,3}) +(.*)$", line)
        if m:
            flush_para()
            flush_table()
            out.append(("head", len(m.group(1)), m.group(2).strip()))
        elif line.startswith("|"):
            flush_para()
            tbl.append([c.strip() for c in line.strip("|").split("|")])
        elif not line.strip():
            flush_para()
            flush_table()
        else:
            flush_table()
            cur.append(line)
    flush_para()
    flush_table()
    return out


def add_table(doc, rows):
    """Render a markdown pipe table as a real Word table."""
    header, body = rows[0], [r for r in rows[1:] if not is_rule(r)]
    table = doc.add_table(rows=1 + len(body), cols=len(header))
    table.style = "Table Grid"
    table.autofit = True
    for j, cell_text in enumerate(header):
        cell = table.cell(0, j)
        para = cell.paragraphs[0]
        para.alignment = AL.LEFT
        run = para.add_run(cell_text)
        run.font.name = "Times New Roman"
        run.font.size = docx.shared.Pt(10)
        run.font.bold = True
    for i, row in enumerate(body, start=1):
        for j in range(len(header)):
            text = row[j] if j < len(row) else ""
            cell = table.cell(i, j)
            para = cell.paragraphs[0]
            para.alignment = AL.LEFT
            run = para.add_run(text)
            run.font.name = "Times New Roman"
            run.font.size = docx.shared.Pt(10)
    return table


def is_rule(row):
    return all(set(c) <= set("-: ") and c.strip() for c in row)


def main():
    md_text = io.open(SRC, encoding="utf-8").read()

    shutil.copyfile(TEMPLATE, OUT)
    doc = docx.Document(OUT)
    body = doc.element.body
    for p in list(doc.paragraphs):
        body.remove(p._element)
    for t in list(doc.tables):
        t._element.getparent().remove(t._element)

    for item in blocks(md_text):
        if item[0] == "head":
            level, text = item[1], item[2]
            para = doc.add_paragraph(style="Heading %d" % level)
            run = para.add_run(text)
            run.font.name = "Times New Roman"
            run.font.bold = True
        elif item[0] == "table":
            add_table(doc, item[1])
            doc.add_paragraph(style="Normal")
        else:
            para = doc.add_paragraph(style="Normal")
            para.alignment = AL.JUSTIFY
            run = para.add_run(item[1])
            run.font.name = "Times New Roman"

    doc.save(OUT)
    heads = [p.text for p in doc.paragraphs if p.style.name.startswith("Heading")]
    print("wrote %s  paragraphs=%d  tables=%d"
          % (OUT, len(doc.paragraphs), len(doc.tables)))
    for h in heads:
        print("   ", h)


if __name__ == "__main__":
    main()
