#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Build the scoring workbook that covers both respondent groups.

Usage:

    python gen_evaluation_xlsx.py

The workbook carries eight sheets.

    Scale                  the five-point interpretation table of Table 17

    IT Expert Responses    one row per IT expert, one column per rated item
    IT Expert Mean          frequency, N, weighted mean and interpretation
    IT Expert Summary       the eight characteristic means and the overall

    End User Responses      one row per end-user, one column per rated item
    End User Mean           frequency, N, weighted mean and interpretation
    End User Summary        the eight characteristic means and the overall

    Comparison              the two groups side by side, and the grand mean

Every figure is a formula, so nothing is tallied by hand.

The weighted mean of one item follows the manuscript:

    W = SUM(F * X) / N

with F the frequency of a scale value, X the scale value, and N the number of
respondents who rated that item. An item answered "Not Applicable" in the
end-user instrument is not one of the five scale values, so it is left out of
N, which is the treatment of a missing response described in Section 3.13.

The comparison sheet weights each respondent group equally, so the grand mean
is the mean of the two group means and not the mean of every individual
response. This keeps a group with more respondents from dominating the
result, which is what Section 3.12 asks for.
"""

import os

from openpyxl import Workbook
from openpyxl.formatting.rule import FormulaRule
from openpyxl.styles import Alignment, Border, Font, PatternFill, Side
from openpyxl.utils import get_column_letter
from openpyxl.worksheet.datavalidation import DataValidation

OUT = "System_Evaluation_Scoring_Sheet.xlsx"

TITLE_FONT = Font(name="Calibri", size=14, bold=True, color="1F3864")
HEAD_FONT = Font(name="Calibri", size=10, bold=True, color="FFFFFF")
HEAD_FILL = PatternFill("solid", fgColor="1F3864")
SUB_FONT = Font(name="Calibri", size=10, bold=True, color="1F3864")
SUB_FILL = PatternFill("solid", fgColor="D9E2F3")
BOLD = Font(name="Calibri", size=10, bold=True)
BODY = Font(name="Calibri", size=10)
NOTE = Font(name="Calibri", size=9, italic=True)
CENTRE = Alignment(horizontal="center", vertical="center", wrap_text=True)
LEFT = Alignment(horizontal="left", vertical="center", wrap_text=True)
THIN = Side(style="thin", color="9DB2D0")
BOX = Border(left=THIN, right=THIN, top=THIN, bottom=THIN)

ROWS = 50          # respondent rows offered on each response sheet
FIRST_ROW = 4      # first respondent row on a response sheet

CHARACTERISTICS = [
    ("A", "Functional Suitability"),
    ("B", "Performance Efficiency"),
    ("C", "Compatibility"),
    ("D", "Reliability"),
    ("E", "Usability"),
    ("F", "Security"),
    ("G", "Maintainability"),
    ("H", "Portability"),
]

SCALE = [
    (5, "4.21 - 5.00", "Highly Acceptable"),
    (4, "3.41 - 4.20", "Acceptable"),
    (3, "2.61 - 3.40", "Moderately Acceptable"),
    (2, "1.81 - 2.60", "Not Acceptable"),
    (1, "1.00 - 1.80", "Not Acceptable at All"),
]

IT_EXPERT_ITEMS = [
    ("A", "Provides the functions stated in the objectives of the study."),
    ("A", "Implemented functions agree with the Chapter 3 diagrams."),
    ("A", "Validation refuses dentist unavailability, a taken slot, and an "
          "invalid date."),
    ("A", "Access is limited to the functions of the respondent's role."),
    ("A", "Every function produces the output the requirement specifies."),
    ("B", "Pages load within an acceptable time during clinic use."),
    ("B", "Appointment validation returns without a noticeable delay."),
    ("B", "Seven-day demand forecast is generated without a noticeable "
          "delay."),
    ("B", "The system responds acceptably when several users work at once."),
    ("B", "Database work is efficient, without repeated or whole-table "
          "queries."),
    ("C", "Runs on the PHP, MySQL, and web server environment of Section "
          "3.3."),
    ("C", "Displays correctly in current Chrome, Edge, and Firefox."),
    ("C", "Works on both desktop and mobile browsers without breaking."),
    ("C", "Uses standard HTML5, CSS3, JavaScript, and Bootstrap without "
          "conflicts."),
    ("D", "Operates without unexpected errors during continuous use."),
    ("D", "Records are neither lost nor duplicated on repeated submission."),
    ("D", "Concurrent requests for one slot accept only one booking."),
    ("D", "Errors produce an understandable message, not raw detail."),
    ("D", "Data written by one module is the data another module reads."),
    ("E", "Navigation shows the current module and role at all times."),
    ("E", "Forms mark required fields and say what must be corrected."),
    ("E", "Labels, layout, and terminology are consistent across roles."),
    ("E", "A typical task is completed without external documentation."),
    ("F", "Passwords are stored as one-way hashes, not MD5 or SHA1."),
    ("F", "SQL injection is prevented through bound parameters."),
    ("F", "Output is encoded, so cross-site scripting is prevented."),
    ("F", "State-changing forms carry an anti-forgery token that is "
          "verified."),
    ("F", "The session identifier is renewed at sign-in and discarded at "
          "sign-out."),
    ("F", "Error messages disclose no database, path, or credential "
          "detail."),
    ("G", "Source is organised into modules matching the Chapter 3 "
          "processes."),
    ("G", "Code is readable: consistent naming, indentation, and one "
          "responsibility."),
    ("G", "Configuration is separated from the application logic."),
    ("G", "Section headers and comments state the purpose of each "
          "handler."),
    ("G", "The database is normalised, so a record is stored once."),
    ("H", "Deployment to another PHP and MySQL host needs configuration "
          "only."),
    ("H", "No client-side installation is required; a browser is enough."),
    ("H", "The database can be recreated from the included SQL file."),
    ("H", "Works on both XAMPP and a comparable PHP host."),
]

EU_ITEMS = [
    ("A", "I can complete the appointment task I need to do in the "
          "system."),
    ("A", "The system correctly saves the information I enter."),
    ("A", "The system shows me the correct appointment details, such as "
          "the date, the time, the dentist, and the service."),
    ("A", "The system tells me when my chosen schedule is not available "
          "instead of accepting it anyway."),
    ("A", "I can use only the functions that belong to my role, and not "
          "the functions of another role."),
    ("B", "The pages of the system open quickly."),
    ("B", "Completing a task, such as booking or updating an appointment, "
          "takes only a short time."),
    ("B", "The system stays fast even when many appointments are already "
          "stored."),
    ("B", "The information I need appears right away, without a long "
          "wait."),
    ("B", "Booking or rescheduling my appointment takes only a short "
          "time."),
    ("C", "The system works properly on the device I use."),
    ("C", "The system works properly in the web browser I use."),
    ("C", "The text and the images on my screen are clear and easy to "
          "read."),
    ("C", "The system works on a mobile phone, a tablet, and a computer."),
    ("D", "The system works correctly every time I use it."),
    ("D", "The system does not show errors during normal use."),
    ("D", "The information I enter is saved correctly and is not lost."),
    ("D", "The system is available whenever I need to use it."),
    ("D", "When I sign in again, the information I saved earlier is still "
          "there."),
    ("E", "The system is easy to learn, even for a first-time user."),
    ("E", "The labels, the buttons, and the instructions are clear and "
          "easy to understand."),
    ("E", "I can find the page or the information I need without "
          "difficulty."),
    ("E", "The system gives a clear and helpful message when something is "
          "unavailable, or when I enter something wrong."),
    ("F", "My password is never shown to anyone, not even to the clinic "
          "staff."),
    ("F", "I can see my own information, but not the information of other "
          "patients."),
    ("F", "The system does not let me change or remove anything that "
          "belongs to another person."),
    ("F", "When something goes wrong, the system gives a short explanation "
          "instead of technical details."),
    ("F", "Nobody can make me submit a form or change my information "
          "without my knowing."),
    ("G", "The clinic can easily update its own information, such as the "
          "services offered and the opening hours."),
    ("G", "My own information, such as my schedule or my personal details, "
          "is easy to update when it changes."),
    ("G", "When I sign out, the next person cannot continue using the "
          "system as me on the same device."),
    ("G", "The information already in the system does not disappear or "
          "become wrong when the system is updated."),
    ("H", "I can open the system from any device that has a modern "
          "browser, without installing any program."),
    ("H", "I did not have to install anything on my phone or on my "
          "computer to use the system."),
    ("H", "The system works the same way whether I use a phone, a tablet, "
          "or a computer."),
    ("H", "I can scan the QR code and reach the walk-in page from my "
          "phone wherever I am."),
]

CHAR_NAME = dict(CHARACTERISTICS)


def char_counts(items):
    """Item count of each characteristic, in the order the items appear.

    The counts are derived from the item list rather than written out, so a
    count can never drift away from the items it describes.
    """
    counts = {}
    order = []
    for code, _ in items:
        if code not in counts:
            counts[code] = 0
            order.append(code)
        counts[code] += 1
    return [(c, CHAR_NAME.get(c, c), counts[c]) for c in order]


def make_group(name, items):
    slug = name
    return {
        "name": name,
        "items": items,
        "chars": char_counts(items),
        "resp": "%s Responses" % slug,
        "mean": "%s Mean" % slug,
        "summary": "%s Summary" % slug,
    }


IT_EXPERT = make_group("IT Expert", IT_EXPERT_ITEMS)
END_USER = make_group("End User", EU_ITEMS)
GROUPS = [IT_EXPERT, END_USER]


def _grid(ws, first_row, last_row, first_col, last_col):
    """Draw a thin box round every cell of a block."""
    for r in range(first_row, last_row + 1):
        for c in range(first_col, last_col + 1):
            cell = ws.cell(row=r, column=c)
            cell.border = BOX
            cell.font = BODY


def _heads(ws, row, labels):
    for i, h in enumerate(labels, start=1):
        c = ws.cell(row=row, column=i, value=h)
        c.font = HEAD_FONT
        c.fill = HEAD_FILL
        c.alignment = CENTRE


def build_scale(ws):
    """The reference sheet: the five-point interpretation table."""
    ws["A1"] = "Interpretation Scale (Table 17 of the manuscript)"
    ws["A1"].font = TITLE_FONT
    _heads(ws, 2, ["Scale", "Range", "Verbal Interpretation", "",
                   "Lower Bound", "Interpretation (ascending)"])
    for i, (value, rng, label) in enumerate(SCALE):
        r = 3 + i
        ws.cell(row=r, column=1, value=value)
        ws.cell(row=r, column=2, value=rng)
        ws.cell(row=r, column=3, value=label)
    # The right-hand pair holds the lower bound of each band with the band
    # name, written upwards, because LOOKUP needs its lookup vector in
    # ascending order.
    bands = [(1.00, "Not Acceptable at All"),
             (1.81, "Not Acceptable"),
             (2.61, "Moderately Acceptable"),
             (3.41, "Acceptable"),
             (4.21, "Highly Acceptable")]
    for i, (bound, label) in enumerate(bands):
        ws.cell(row=3 + i, column=5, value=bound)
        ws.cell(row=3 + i, column=6, value=label)
    _grid(ws, 3, 7, 1, 6)
    for r in range(3, 8):
        for c in (1, 2, 5):
            ws.cell(row=r, column=c).alignment = CENTRE
        ws.cell(row=r, column=3).alignment = LEFT
        ws.cell(row=r, column=6).alignment = LEFT
    for col, w in ((1, 8), (2, 14), (3, 24), (4, 3), (5, 12), (6, 24)):
        ws.column_dimensions[get_column_letter(col)].width = w
    ws["A9"] = ("The right-hand pair supports the LOOKUP formula used by the "
                "mean and summary sheets.")
    ws["A9"].font = NOTE


def build_responses(ws, group):
    """One row per respondent, one column per rated item."""
    ws["A1"] = "%s Responses" % group["name"]
    ws["A1"].font = TITLE_FONT
    ws["A2"] = "Respondent"
    ws["A2"].font = HEAD_FONT
    ws["A2"].fill = HEAD_FILL
    ws["A2"].alignment = CENTRE

    items = group["items"]
    for i in range(len(items)):
        c = ws.cell(row=2, column=2 + i, value="Q%d" % (i + 1))
        c.font = HEAD_FONT
        c.fill = HEAD_FILL
        c.alignment = CENTRE

    ws["A3"] = "Quality Characteristic"
    ws["A3"].font = SUB_FONT
    ws["A3"].fill = SUB_FILL
    ws["A3"].alignment = CENTRE

    # the group label of each characteristic spans its own items
    col = 2
    for code, name, count in group["chars"]:
        ws.cell(row=3, column=col,
                value="%s. %s (%d items)" % (code, name, count))
        if count > 1:
            ws.merge_cells(start_row=3, start_column=col,
                           end_row=3, end_column=col + count - 1)
        c = ws.cell(row=3, column=col)
        c.font = SUB_FONT
        c.fill = SUB_FILL
        c.alignment = CENTRE
        for k in range(count):
            cc = ws.cell(row=3, column=col + k)
            cc.fill = SUB_FILL
            cc.border = BOX
        col += count

    last_col = 1 + len(items)
    for i in range(ROWS):
        c = ws.cell(row=FIRST_ROW + i, column=1, value="R%d" % (i + 1))
        c.font = BODY
        c.alignment = CENTRE
    _grid(ws, FIRST_ROW, FIRST_ROW + ROWS - 1, 1, last_col)

    # The end-user form also returns the words "Not Applicable", so the
    # validation is a list rather than a whole number. A whole-number rule
    # would refuse the text and the researcher could not paste the export.
    dv = DataValidation(
        type="list",
        formula1='"1,2,3,4,5,Not Applicable"',
        allow_blank=True,
        showErrorMessage=True,
        errorTitle="Value not allowed",
        error="Choose 1, 2, 3, 4, 5, or Not Applicable.",
        promptTitle="Rating",
        prompt="5 Highly Acceptable ... 1 Not Acceptable at All. "
               "Choose or type Not Applicable when the item does not apply.",
        showInputMessage=True,
    )
    ws.add_data_validation(dv)
    dv.add("%s%d:%s%d" % (get_column_letter(2), FIRST_ROW,
                          get_column_letter(last_col), FIRST_ROW + ROWS - 1))

    ws.column_dimensions["A"].width = 12
    for i in range(len(items)):
        ws.column_dimensions[get_column_letter(2 + i)].width = 4.6
    ws.freeze_panes = "B%d" % FIRST_ROW


def build_means(ws, group):
    """Per item: frequencies, N, weighted mean, and interpretation."""
    ws["A1"] = "%s - Weighted Mean per Item" % group["name"]
    ws["A1"].font = TITLE_FONT
    _heads(ws, 2, ["No.", "Char.", "Sub-criterion",
                   "5", "4", "3", "2", "1", "N/A", "N",
                   "Weighted Mean", "Verbal Interpretation"])

    src_sheet = group["resp"]
    first = 3
    ranges = {}
    last_src = FIRST_ROW + ROWS - 1
    for i, (code, text) in enumerate(group["items"]):
        r = first + i
        src = get_column_letter(2 + i)
        ref = "'%s'!%s$%d:%s$%d" % (src_sheet, src, FIRST_ROW, src, last_src)
        ws.cell(row=r, column=1, value=i + 1)
        ws.cell(row=r, column=2, value=code)
        ws.cell(row=r, column=3, value=text)
        for k, value in enumerate((5, 4, 3, 2, 1)):
            ws.cell(row=r, column=4 + k,
                    value="=COUNTIF(%s,%d)" % (ref, value))
        ws.cell(row=r, column=9,
                value='=COUNTIF(%s,"Not Applicable")' % ref)
        # N counts only the five scale values, so a Not Applicable answer is
        # left out of the weighted mean, as Section 3.13 requires.
        ws.cell(row=r, column=10, value="=SUM(D%d:H%d)" % (r, r))
        ws.cell(row=r, column=11,
                value='=IF(J%d=0,"",(D%d*5+E%d*4+F%d*3+G%d*2+H%d)/J%d)'
                      % (r, r, r, r, r, r, r))
        ws.cell(row=r, column=12,
                value='=IF(K%d="","",LOOKUP(K%d,Scale!$E$3:$E$7,'
                      'Scale!$F$3:$F$7))' % (r, r))
        ranges.setdefault(code, []).append(r)

    last = first + len(group["items"]) - 1
    _grid(ws, first, last, 1, 12)
    for r in range(first, last + 1):
        for c in (1, 2, 4, 5, 6, 7, 8, 9, 10, 11):
            ws.cell(row=r, column=c).alignment = CENTRE
        ws.cell(row=r, column=3).alignment = LEFT
        ws.cell(row=r, column=12).alignment = LEFT
        ws.cell(row=r, column=11).number_format = "0.00"

    green = PatternFill("solid", fgColor="C6EFCE")
    mid = PatternFill("solid", fgColor="FFEB9C")
    red = PatternFill("solid", fgColor="FFC7CE")
    for r in range(first, last + 1):
        v = "K%d" % r
        ws.conditional_formatting.add(v, FormulaRule(
            formula=['AND(%s<>"",%s>=3.41)' % (v, v)], fill=green))
        ws.conditional_formatting.add(v, FormulaRule(
            formula=['AND(%s<>"",%s>=2.61,%s<3.41)' % (v, v, v)], fill=mid))
        ws.conditional_formatting.add(v, FormulaRule(
            formula=['AND(%s<>"",%s<2.61)' % (v, v)], fill=red))

    for col, w in ((1, 6), (2, 7), (3, 56), (4, 5), (5, 5), (6, 5), (7, 5),
                   (8, 5), (9, 8), (10, 6), (11, 13), (12, 21)):
        ws.column_dimensions[get_column_letter(col)].width = w
    ws.freeze_panes = "D%d" % first
    return ranges


MEAN_COL = "K"   # the weighted mean column of a mean sheet


def build_summary(ws, group, ranges):
    """The mean of each characteristic and the overall rating."""
    ws["A1"] = "%s - Summary of Ratings by Quality Characteristic" % group["name"]
    ws["A1"].font = TITLE_FONT
    _heads(ws, 2, ["Quality Characteristic", "Number of Items",
                   "Weighted Mean", "Verbal Interpretation"])

    src = group["mean"]
    r = 3
    for code, name, count in group["chars"]:
        rows = ranges[code]
        first, last = rows[0], rows[-1]
        ws.cell(row=r, column=1, value="%s. %s" % (code, name))
        ws.cell(row=r, column=2, value=count)
        ws.cell(row=r, column=3,
                value='=IFERROR(AVERAGE(\'%s\'!%s%d:%s%d),"")'
                      % (src, MEAN_COL, first, MEAN_COL, last))
        ws.cell(row=r, column=4,
                value='=IF(C%d="","",LOOKUP(C%d,Scale!$E$3:$E$7,'
                      'Scale!$F$3:$F$7))' % (r, r))
        r += 1

    total = sum(count for _, _, count in group["chars"])
    ws.cell(row=r, column=1, value="Overall System Rating")
    ws.cell(row=r, column=2, value=total)
    ws.cell(row=r, column=3, value='=IFERROR(AVERAGE(C3:C%d),"")' % (r - 1))
    ws.cell(row=r, column=4,
            value='=IF(C%d="","",LOOKUP(C%d,Scale!$E$3:$E$7,'
                  'Scale!$F$3:$F$7))' % (r, r))
    for c in (1, 2, 3, 4):
        ws.cell(row=r, column=c).font = BOLD
        ws.cell(row=r, column=c).fill = SUB_FILL
    overall_row = r

    _grid(ws, 3, overall_row, 1, 4)
    for rr in range(3, overall_row + 1):
        ws.cell(row=rr, column=1).alignment = LEFT
        for c in (2, 3):
            ws.cell(row=rr, column=c).alignment = CENTRE
        ws.cell(row=rr, column=4).alignment = LEFT
        ws.cell(row=rr, column=3).number_format = "0.00"

    green = PatternFill("solid", fgColor="C6EFCE")
    mid = PatternFill("solid", fgColor="FFEB9C")
    red = PatternFill("solid", fgColor="FFC7CE")
    for rr in range(3, overall_row + 1):
        v = "C%d" % rr
        ws.conditional_formatting.add(v, FormulaRule(
            formula=['AND(%s<>"",%s>=3.41)' % (v, v)], fill=green))
        ws.conditional_formatting.add(v, FormulaRule(
            formula=['AND(%s<>"",%s>=2.61,%s<3.41)' % (v, v, v)], fill=mid))
        ws.conditional_formatting.add(v, FormulaRule(
            formula=['AND(%s<>"",%s<2.61)' % (v, v)], fill=red))

    last_mean_row = 3 + len(group["items"]) - 1
    note = overall_row + 2
    ws.cell(row=note, column=1,
            value="Responses received (largest N of any item)")
    ws.cell(row=note, column=1).font = BOLD
    ws.cell(row=note, column=3,
            value="=MAX('%s'!J3:J%d)" % (src, last_mean_row))
    ws.cell(row=note, column=3).font = BOLD
    ws.cell(row=note + 1, column=1,
            value=("Every figure above is computed by formula from the "
                   "response sheet, so nothing is tallied by hand. An item "
                   "answered Not Applicable is left out of N."))
    ws.cell(row=note + 1, column=1).font = NOTE
    ws.merge_cells(start_row=note + 1, start_column=1,
                   end_row=note + 1, end_column=4)

    for col, w in ((1, 34), (2, 16), (3, 15), (4, 26)):
        ws.column_dimensions[get_column_letter(col)].width = w
    return overall_row


def build_comparison(ws, overall_rows):
    """The two groups side by side, with the grand mean of each row.

    Each group carries the same weight, so the grand mean is the mean of the
    two group means rather than the mean of every individual response. That
    keeps a group with more respondents from dominating the result.
    """
    a, b = GROUPS
    ws["A1"] = "Comparison of the Two Respondent Groups"
    ws["A1"].font = TITLE_FONT
    _heads(ws, 2, ["Quality Characteristic",
                   "%s Mean" % a["name"],
                   "%s Mean" % b["name"],
                   "Grand Mean (equal weight)",
                   "Difference (%s - %s)" % (a["name"], b["name"]),
                   "Verbal Interpretation of the Grand Mean"])

    sa, sb = a["summary"], b["summary"]
    r = 3
    for code, name in CHARACTERISTICS:
        ws.cell(row=r, column=1, value="%s. %s" % (code, name))
        ws.cell(row=r, column=2, value='=IFERROR(\'%s\'!C%d,"")' % (sa, r))
        ws.cell(row=r, column=3, value='=IFERROR(\'%s\'!C%d,"")' % (sb, r))
        ws.cell(row=r, column=4, value='=IFERROR(AVERAGE(B%d:C%d),"")' % (r, r))
        ws.cell(row=r, column=5, value='=IFERROR(B%d-C%d,"")' % (r, r))
        ws.cell(row=r, column=6,
                value='=IF(D%d="","",LOOKUP(D%d,Scale!$E$3:$E$7,'
                      'Scale!$F$3:$F$7))' % (r, r))
        r += 1

    oa = overall_rows[a["name"]]
    ob = overall_rows[b["name"]]
    ws.cell(row=r, column=1, value="Overall System Rating")
    ws.cell(row=r, column=2, value='=IFERROR(\'%s\'!C%d,"")' % (sa, oa))
    ws.cell(row=r, column=3, value='=IFERROR(\'%s\'!C%d,"")' % (sb, ob))
    ws.cell(row=r, column=4, value='=IFERROR(AVERAGE(B%d:C%d),"")' % (r, r))
    ws.cell(row=r, column=5, value='=IFERROR(B%d-C%d,"")' % (r, r))
    ws.cell(row=r, column=6,
            value='=IF(D%d="","",LOOKUP(D%d,Scale!$E$3:$E$7,'
                  'Scale!$F$3:$F$7))' % (r, r))
    for c in (1, 2, 3, 4, 5, 6):
        ws.cell(row=r, column=c).font = BOLD
        ws.cell(row=r, column=c).fill = SUB_FILL
    overall_row = r

    _grid(ws, 3, overall_row, 1, 6)
    for rr in range(3, overall_row + 1):
        ws.cell(row=rr, column=1).alignment = LEFT
        ws.cell(row=rr, column=6).alignment = LEFT
        for c in (2, 3, 4, 5):
            ws.cell(row=rr, column=c).alignment = CENTRE
            ws.cell(row=rr, column=c).number_format = "0.00"

    green = PatternFill("solid", fgColor="C6EFCE")
    mid = PatternFill("solid", fgColor="FFEB9C")
    red = PatternFill("solid", fgColor="FFC7CE")
    for rr in range(3, overall_row + 1):
        v = "D%d" % rr
        ws.conditional_formatting.add(v, FormulaRule(
            formula=['AND(%s<>"",%s>=3.41)' % (v, v)], fill=green))
        ws.conditional_formatting.add(v, FormulaRule(
            formula=['AND(%s<>"",%s>=2.61,%s<3.41)' % (v, v, v)], fill=mid))
        ws.conditional_formatting.add(v, FormulaRule(
            formula=['AND(%s<>"",%s<2.61)' % (v, v)], fill=red))

    note = overall_row + 2
    lines = [
        "The grand mean gives each respondent group the same weight, so the "
        "group with more respondents does not dominate the result, as "
        "Section 3.12 requires.",
        "The difference column is the first group mean less the second. A "
        "positive figure means the first group scored higher.",
        "Read the grand mean against the Scale sheet for the verbal band "
        "(4.21 to 5.00 is Highly Acceptable).",
    ]
    for i, line in enumerate(lines):
        ws.cell(row=note + i, column=1, value=line).font = NOTE
        ws.merge_cells(start_row=note + i, start_column=1,
                       end_row=note + i, end_column=6)

    for col, w in ((1, 30), (2, 14), (3, 14), (4, 20), (5, 20), (6, 26)):
        ws.column_dimensions[get_column_letter(col)].width = w
    return overall_row


def build_guide(ws):
    """A short note on how to fill the workbook in."""
    ws["A1"] = "How to use this workbook"
    ws["A1"].font = TITLE_FONT
    steps = [
        "Open the form for a respondent group and export or copy the "
        "responses, one respondent per row.",
        "Paste the rated columns into the matching response sheet: Q1 to Q38 "
        "for the IT experts, and Q1 to Q36 for the end-users. The column "
        "order is the order of the items in the printed form.",
        "Leave a cell blank, or write Not Applicable, when a respondent did "
        "not answer an item or said the item did not apply. That item is then "
        "left out of N, so it cannot pull the mean down.",
        "The mean and summary sheets fill themselves in, because every figure "
        "there is a formula.",
        "Read the Comparison sheet for the two group means, the grand mean "
        "and the difference, and the Scale sheet for the verbal bands.",
        "Each response sheet offers 50 rows. To add more, copy the last row "
        "down and widen the COUNTIF ranges on the matching mean sheet to the "
        "new last row.",
    ]
    for i, text in enumerate(steps):
        r = 3 + i
        ws.cell(row=r, column=1, value="Step %d" % (i + 1)).font = BOLD
        ws.cell(row=r, column=2, value=text).alignment = LEFT
    ws.column_dimensions["A"].width = 10
    ws.column_dimensions["B"].width = 92


def main():
    os.chdir(os.path.dirname(os.path.abspath(__file__)))

    wb = Workbook()
    guide = wb.active
    guide.title = "How to Use"
    build_guide(guide)

    scale = wb.create_sheet("Scale")
    build_scale(scale)

    overall_rows = {}
    for group in GROUPS:
        resp = wb.create_sheet(group["resp"])
        build_responses(resp, group)

        mean = wb.create_sheet(group["mean"])
        ranges = build_means(mean, group)

        summary = wb.create_sheet(group["summary"])
        overall_rows[group["name"]] = build_summary(summary, group, ranges)

    comp = wb.create_sheet("Comparison")
    build_comparison(comp, overall_rows)

    wb.save(OUT)
    print("wrote %s" % OUT)
    for group in GROUPS:
        counts = ", ".join(str(c) for _, _, c in group["chars"])
        print("   %-9s items=%-3d characteristics=%d  counts=[%s]"
              % (group["name"], len(group["items"]), len(group["chars"]),
                 counts))
        print("   %-9s overall rating sits on row %d of its summary sheet"
              % ("", overall_rows[group["name"]]))
    print("   respondent rows per sheet : %d" % ROWS)
    print("   sheets : %s" % ", ".join(s.title for s in wb.worksheets))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())







