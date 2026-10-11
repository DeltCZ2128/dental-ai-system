#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Check that every artefact of the evaluation package agrees.

Usage:

    python verify_evaluation_package.py

Two instruments are kept in four places each: the markdown that is the source
of truth, the printable .docx built from it, the Google Apps Script that
builds the online copy, and the scoring workbook. This script reads all of
them and reports whether the rated items, the characteristics and the item
counts match, then checks the structure of the workbook itself.

It exits with a non-zero status when anything disagrees.
"""

import io
import os
import re
import sys

import docx
import openpyxl

WORKBOOK = "System_Evaluation_Scoring_Sheet.xlsx"
CODES = ["A", "B", "C", "D", "E", "F", "G", "H"]

ARTEFACTS = [
    {
        "name": "IT Expert",
        "md": "IT_Expert_Evaluation_Form.md",
        "docx": "IT_Expert_Evaluation_Form.docx",
        "js": "google_apps_script.js",
        "resp": "IT Expert Responses",
        "mean": "IT Expert Mean",
        "summary": "IT Expert Summary",
        "row": 3,          # first item row on the mean sheet
        "expected": 38,
    },
    {
        "name": "End User",
        "md": "EndUser_Evaluation_Form.md",
        "docx": "EndUser_Evaluation_Form.docx",
        "js": "google_apps_script_enduser.js",
        "resp": "End User Responses",
        "mean": "End User Mean",
        "summary": "End User Summary",
        "row": 3,
        "expected": 36,
    },
]

SHEETS = ["How to Use", "Scale",
          "IT Expert Responses", "IT Expert Mean", "IT Expert Summary",
          "End User Responses", "End User Mean", "End User Summary",
          "Comparison"]


def from_md(path):
    """Item count per characteristic, read from the rating tables."""
    text = io.open(path, encoding="utf-8").read()
    body = text.split("<!-- BEGIN SUBMITTABLE -->", 1)[1]
    body = body.split("<!-- END SUBMITTABLE -->", 1)[0]
    counts = {}
    current = None
    for line in body.split("\n"):
        m = re.match(r"^### ([A-H])\.", line.strip())
        if m:
            current = m.group(1)
            counts[current] = 0
            continue
        if current and re.match(r"^\|\s*\d+\s*\|", line):
            counts[current] = counts.get(current, 0) + 1
    return counts


def from_docx(path):
    """Rows of each rating table, plus how many rating tables were found.

    A rating table of the IT expert instrument ends with the five scale
    columns. A rating table of the end-user instrument ends with the same
    five columns followed by the Not Applicable column, so a trailing N/A is
    set aside before the last five heads are tested.
    """
    doc = docx.Document(path)
    order = []
    for table in doc.tables:
        head = [c.text.strip() for c in table.rows[0].cells]
        if head and head[-1] == "N/A":
            head = head[:-1]
        if len(head) >= 6 and head[-5:] == ["5", "4", "3", "2", "1"]:
            order.append(len(table.rows) - 1)
    return dict(zip(CODES, order)), len(order)


def from_js(path):
    """Number of strings in the items array of each characteristic."""
    text = io.open(path, encoding="utf-8").read()
    counts = {}
    for chunk in text.split("code: '")[1:]:
        code = chunk[0]
        if code not in CODES:
            continue
        body = chunk.split("items: [", 1)[1].split("\n    ]", 1)[0]
        # Each item ends on a line whose last character is the closing quote
        # of the string, either ' or ", optionally followed by a comma.
        # Continuation lines end with ' +, so they do not count.
        counts[code] = len(re.findall(r"""['"]\s*,?\s*$""", body, re.M))
    return counts


def check(label, left, right):
    ok = left == right
    print("   %-52s %s" % (label, "match" if ok else "MISMATCH"))
    if not ok:
        print("      left : %s" % (left,))
        print("      right: %s" % (right,))
    return ok


def from_workbook(wb, cfg):
    """Item count per characteristic from the mean sheet, and the summary."""
    ws = wb[cfg["mean"]]
    counts = {}
    r = cfg["row"]
    while ws.cell(row=r, column=2).value in CODES:
        code = ws.cell(row=r, column=2).value
        counts[code] = counts.get(code, 0) + 1
        r += 1
    items_total = r - cfg["row"]

    summary = wb[cfg["summary"]]
    declared = {}
    r = 3
    while summary.cell(row=r, column=2).value:
        name = str(summary.cell(row=r, column=1).value or "")
        m = re.match(r"^([A-H])\.", name)
        if m:
            declared[m.group(1)] = summary.cell(row=r, column=2).value
        r += 1
    return counts, items_total, declared, r - 1, \
        summary.cell(row=r - 1, column=2).value


def main():
    os.chdir(os.path.dirname(os.path.abspath(__file__)))

    missing = []
    for cfg in ARTEFACTS:
        for key in ("md", "docx", "js"):
            if not os.path.exists(cfg[key]):
                missing.append(cfg[key])
    if not os.path.exists(WORKBOOK):
        missing.append(WORKBOOK)
    if missing:
        for name in missing:
            print("missing: %s - build it first" % name)
        return 1

    wb = openpyxl.load_workbook(WORKBOOK)
    good = True

    for cfg in ARTEFACTS:
        print("=" * 68)
        print("%s instrument" % cfg["name"])
        print("=" * 68)
        md = from_md(cfg["md"])
        dx, dx_tables = from_docx(cfg["docx"])
        js = from_js(cfg["js"])
        xl, xl_items, xl_declared, overall_row, overall_total = \
            from_workbook(wb, cfg)

        print("  rated items per characteristic (md / docx / xlsx / js)")
        for code in CODES:
            row = [md.get(code, 0), dx.get(code, 0), xl.get(code, 0),
                   js.get(code, 0)]
            same = len(set(row)) == 1
            good = good and same
            print("     %s: %s   %s"
                  % (code, "   ".join(str(v) for v in row),
                     "match" if same else "MISMATCH"))

        totals = [sum(md.values()), sum(dx.values()), xl_items,
                  sum(js.values())]
        same = len(set(totals)) == 1
        good = good and same
        print("  totals                        : %s   %s"
              % ("   ".join(str(t) for t in totals),
                 "match" if same else "MISMATCH"))

        good = good and check("  rating tables in the .docx", dx_tables, 8)
        good = good and check("  item total declared in the summary",
                              overall_total, cfg["expected"])
        good = good and check("  summary counts agree with the mean rows",
                              dict(xl_declared), dict(xl))
        good = good and check("  overall rating row on the summary",
                              overall_row, 3 + len(CODES))
        print()

    print("=" * 68)
    print("Workbook structure")
    print("=" * 68)
    good = good and check("  sheets", wb.sheetnames, SHEETS)

    comp = wb["Comparison"]
    rows = 0
    r = 3
    while comp.cell(row=r, column=1).value:
        rows += 1
        r += 1
    good = good and check("  comparison rows (8 characteristics + overall)",
                          rows, 9)
    good = good and check("  grand mean formula on the first comparison row",
                          comp["D3"].value, '=IFERROR(AVERAGE(B3:C3),"")')
    good = good and check("  comparison reads the IT Expert summary",
                          "IT Expert Summary" in str(comp["B3"].value), True)
    good = good and check("  comparison reads the End User summary",
                          "End User Summary" in str(comp["C3"].value), True)

    for cfg in ARTEFACTS:
        ws = wb[cfg["resp"]]
        heads = [c.value for c in ws[2] if c.value]
        good = good and check("  %s response columns" % cfg["name"],
                              len(heads), 1 + cfg["expected"])

    print()
    print("RESULT: %s"
          % ("all artefacts agree" if good else "DIFFERENCES FOUND"))
    return 0 if good else 1


if __name__ == "__main__":
    sys.exit(main())

