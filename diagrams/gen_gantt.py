#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Generate the Chapter 3.10 Gantt chart assets for the dental AI capstone.

Outputs (into <workspace>/diagrams/):
    Gantt_Chart.drawio      one page, plain uncompressed mxfile XML
    Gantt_Chart.svg         the same geometry as SVG, for checking spacing

Notation, kept consistent with the other chapter 3 figures:
    A3 landscape, Arial, black-and-white line art.
    phase band   light grey block spanning the whole duration of the phase
    task bar     white rectangle with a black border, spanning its weeks
    milestone    rhombus sitting on its week column
    dependency   thin dashed line from a milestone to the band it closes
    time axis    W1..W10 with a month header and vertical week gridlines

Every task bar is dated from the actual file history of the project, so the
schedule is evidence-based rather than asserted.  The phase-level dates that
have no file behind them (Planning, Design, Testing, Deployment) are recorded
in 3.10_Gantt_Chart_Narrative.txt as stated assumptions.

Standard library only.
"""
from __future__ import annotations

import datetime as dt
import io
import xml.sax.saxutils as sax

# --------------------------------------------------------------------------
# Canvas and style constants (A3 landscape, draw.io px)
# --------------------------------------------------------------------------
PAGE_W = 1654
PAGE_H = 1169
FONT = "Arial"

LABEL_X = 40            # phase column
LABEL_W = 236
TASK_X = 290            # task column
TASK_W = 258
CHART_X = 560           # week 1 starts here
CHART_R = 1540          # right edge of week 10
WEEKS = 10
WEEK_W = (CHART_R - CHART_X) / WEEKS
DAY_W = WEEK_W / 7.0

AXIS_Y = 150
BAND_TOP = 196
BAND_GAP = 12
LEGEND_Y = 1000

FS_TITLE, FS_SUB = 18, 12
FS_BAND, FS_TASK = 11, 10
FS_AXIS, FS_MONTH, FS_LEG = 10, 10, 10
FS_MILE = 9

ST_PHASE = ("rounded=0;whiteSpace=wrap;html=1;fillColor=#E8E8E8;"
            "strokeColor=#000000;strokeWidth=1;fontFamily=%s;fontSize=%d;"
            "fontColor=#000000;fontStyle=1;" % (FONT, FS_BAND))
ST_TASK = ("rounded=0;whiteSpace=wrap;html=1;fillColor=#FFFFFF;"
           "strokeColor=#000000;strokeWidth=1;fontFamily=%s;fontSize=%d;"
           "fontColor=#000000;align=left;spacingLeft=6;" % (FONT, FS_TASK))
ST_GRID = ("endArrow=none;html=1;strokeColor=#B0B0B0;strokeWidth=1;"
           "dashed=0;edgeStyle=none;")
ST_DEP = ("endArrow=open;endFill=0;html=1;strokeColor=#808080;strokeWidth=1;"
          "dashed=1;edgeStyle=none;")
ST_MILE = ("rhombus;whiteSpace=wrap;html=1;fillColor=#FFFFFF;"
           "strokeColor=#000000;strokeWidth=1.5;fontFamily=%s;fontSize=%d;"
           "fontColor=#000000;verticalLabelPosition=bottom;"
           "verticalAlign=top;align=center;" % (FONT, FS_MILE))
ST_TEXT = ("text;html=1;strokeColor=none;fillColor=none;align=%s;"
           "verticalAlign=middle;whiteSpace=wrap;rounded=0;fontFamily=%s;"
           "fontSize=%d;fontColor=#000000;%s")
ST_LEG = ("text;html=1;strokeColor=none;fillColor=none;align=left;"
          "verticalAlign=middle;whiteSpace=wrap;rounded=0;fontFamily=%s;"
          "fontSize=%d;fontColor=#000000;" % (FONT, FS_LEG))

M_LINE = "#B0B0B0"


# --------------------------------------------------------------------------
# Time axis.  Week 1 is Monday 3 August 2026; week 10 ends Sunday 11 October.
# The five development weeks (W4..W8) are exactly Monday 24 August to Friday
# 25 September, which is the real span of the file history below.
# --------------------------------------------------------------------------
WEEK_START = dt.date(2026, 8, 3)
WEEK_DAYS = 7
AXIS_END = WEEK_START + dt.timedelta(days=WEEKS * WEEK_DAYS)      # 12 Oct


def day_index(d):
    """Week grids are labelled Mon..Sun, so a Monday sits on the left edge."""
    return (d - WEEK_START).days / float(WEEK_DAYS)


def x_of(d):
    return CHART_X + day_index(d) * WEEK_W


# --------------------------------------------------------------------------
# Schedule content.  task dates are evidence-based: each one is the date the
# module's files first and last changed in the project history.
#   phase : (name, start, end, [tasks], [milestones])
#   task  : (name, start, end)
#   mile  : (name, date)
# --------------------------------------------------------------------------
SCHEDULE = [
    ("Planning and Requirements", dt.date(2026, 8, 3), dt.date(2026, 8, 16),
     [],
     [("Requirements approved", dt.date(2026, 8, 16))]),

    ("Design", dt.date(2026, 8, 17), dt.date(2026, 8, 23),
     [("Conceptual framework and system design",
       dt.date(2026, 8, 17), dt.date(2026, 8, 23))],
     [("Design baseline approved", dt.date(2026, 8, 23))]),

    ("Development", dt.date(2026, 8, 24), dt.date(2026, 9, 25),
     [
         ("Project scaffolding and workspace set-up",
          dt.date(2026, 8, 24), dt.date(2026, 8, 27)),
         ("AI demand forecasting module and model training",
          dt.date(2026, 8, 24), dt.date(2026, 9, 15)),
         ("Database schema and core libraries",
          dt.date(2026, 9, 15), dt.date(2026, 9, 21)),
         ("Patient booking module",
          dt.date(2026, 9, 18), dt.date(2026, 9, 25)),
         ("Patient account, calendar and care module",
          dt.date(2026, 9, 22), dt.date(2026, 9, 25)),
         ("Queue management and walk-in QR check-in",
          dt.date(2026, 9, 18), dt.date(2026, 9, 25)),
         ("Dentist module",
          dt.date(2026, 9, 24), dt.date(2026, 9, 25)),
         ("Administrator module",
          dt.date(2026, 9, 15), dt.date(2026, 9, 25)),
         ("Documentation, diagrams and system integration",
          dt.date(2026, 9, 18), dt.date(2026, 9, 25)),
     ],
     [("Database ready", dt.date(2026, 9, 18)),
      ("System feature complete", dt.date(2026, 9, 25))]),

    ("Testing", dt.date(2026, 9, 28), dt.date(2026, 10, 2),
     [],
     [("Testing complete", dt.date(2026, 10, 2))]),

    ("Deployment and Evaluation", dt.date(2026, 10, 5), dt.date(2026, 10, 9),
     [],
     [("Evaluation complete", dt.date(2026, 10, 9))]),
]

MONTHS = [("August 2026", dt.date(2026, 8, 3), dt.date(2026, 8, 31)),
          ("September 2026", dt.date(2026, 9, 1), dt.date(2026, 9, 30)),
          ("October 2026", dt.date(2026, 10, 1), dt.date(2026, 10, 11))]

CAPTION = "Figure 19. Gantt Chart of the Project Schedule"
TITLE = "3.10 GANTT CHART"
SUBTITLE = ("AI-Based Intelligent Dental Appointment Scheduling System "
            "Using Machine Learning for Optimized Clinic Management")

LEGEND = [("Phase band", "the whole duration of one Agile phase"),
          ("Task bar", "one work package of the project, dated from the "
                       "project history"),
          ("Milestone", "a dated deliverable that closes the phase above it"),
          ("Dashed line", "the milestone completes the phase it points to")]


def esc(text):
    return sax.escape(text if text is not None else "", {'"': "&quot;"})


# --------------------------------------------------------------------------
# Geometry: total page height depends on how many task rows exist
# --------------------------------------------------------------------------
MIL_ROW = 34            # reserved lane for the milestone of a phase
NO_TASK_H = 52          # height of a phase that holds only a milestone


def geometry():
    """Return the phase list and the bottom of the chart area.

    A phase that holds task bars reserves a lane BELOW the bars for its
    milestone, so a milestone rhombus can never land on a task bar.  A phase
    that holds only a milestone is just tall enough for the rhombus and its
    label underneath.
    """
    phases = []
    y = 196
    for name, start, end, tasks, miles in SCHEDULE:
        if tasks:
            h = 4 + len(tasks) * 22 + 12 + (MIL_ROW if miles else 0)
        else:
            h = NO_TASK_H
        phases.append({"name": name, "start": start, "end": end,
                       "tasks": tasks, "miles": miles,
                       "top": float(y), "h": float(h)})
        y += h + 12
    return phases, float(y - 12)


PHASES, CHART_BOTTOM = geometry()
LEGEND_TOP = CHART_BOTTOM + 46
PAGE_H = LEGEND_TOP + 190
CAPTION_Y = LEGEND_TOP + 132


def bars_bottom(ph):
    """y of the last task bar's bottom edge inside a phase."""
    return ph["top"] + 4 + len(ph["tasks"]) * 22


def milestone_cy(ph):
    """Centre of the milestone lane: under the bars, or the band centre."""
    if ph["tasks"]:
        return bars_bottom(ph) + 12 + 13          # middle of the reserved lane
    return ph["top"] + 20


def vcell(cid, value, style, x, y, w, h):
    return {"type": "vertex", "id": cid, "value": value, "style": style,
            "x": x, "y": y, "w": w, "h": h}


def ecell(cid, style, source, target, x1, y1, x2, y2):
    return {"type": "edge", "id": cid, "style": style, "source": source,
            "target": target, "x1": x1, "y1": y1, "x2": x2, "y2": y2}


# --------------------------------------------------------------------------
# Layout
# --------------------------------------------------------------------------
def layout():
    cells = []
    bands = {}          # phase name -> (top, height)
    rows = []           # (task name, x, y, w, h) for the verifier

    cells.append(vcell("t0", TITLE, ST_TEXT % ("center", FONT, FS_TITLE,
                                               "fontStyle=1;"),
                       0, 24, PAGE_W, 30))
    cells.append(vcell("t1", SUBTITLE, ST_TEXT % ("center", FONT, FS_SUB, ""),
                       0, 56, PAGE_W, 24))

    # ---- month header -----------------------------------------------------
    for i, (mname, mstart, mend) in enumerate(MONTHS):
        x1 = x_of(mstart)
        x2 = min(x_of(mend + dt.timedelta(days=1)), CHART_R)
        cells.append(vcell("m%d" % i, mname,
                           ST_TEXT % ("center", FONT, FS_MONTH, "fontStyle=1;"),
                           x1, 104, x2 - x1, 22))
    # ---- week axis --------------------------------------------------------
    for w in range(WEEKS):
        cells.append(vcell("w%d" % w, "W%d" % (w + 1),
                           ST_TEXT % ("center", FONT, FS_AXIS, ""),
                           CHART_X + w * WEEK_W, 128, WEEK_W, 20))

    # ---- week gridlines ---------------------------------------------------
    for w in range(WEEKS + 1):
        x = CHART_X + w * WEEK_W
        cells.append(vcell("g%d" % w, "", ST_TEXT % ("left", FONT, 6, ""),
                           x, 96, 2, CHART_BOTTOM + 8 - 96))
        cells[-1]["style"] = ("line;html=1;strokeColor=%s;strokeWidth=1;"
                              % M_LINE)

    # ---- phase bands ------------------------------------------------------
    for pi, ph in enumerate(PHASES):
        top = ph["top"]
        bands[ph["name"]] = (top, ph["h"])
        bx = x_of(ph["start"])
        bw = max(x_of(ph["end"] + dt.timedelta(days=1)) - bx, 6.0)
        cells.append(vcell("p%d" % pi, "", ST_PHASE, bx, top, bw, ph["h"]))
        # the phase name sits in the label column, level with the first row
        cells.append(vcell("pl%d" % pi, ph["name"],
                           ST_TEXT % ("left", FONT, FS_BAND, "fontStyle=1;"),
                           LABEL_X + 4, top + 4, LABEL_W - 8, 18))

        # ---- task bars inside this phase ----------------------------------
        for ti, (tname, tstart, tend) in enumerate(ph["tasks"]):
            ty = top + 4 + ti * 22
            tx = x_of(tstart)
            tw = max(x_of(tend + dt.timedelta(days=1)) - tx, 4.0)
            cells.append(vcell("b%d_%d" % (pi, ti), "", ST_TASK,
                               tx, ty, tw, 18))
            cells.append(vcell("n%d_%d" % (pi, ti), tname, ST_TASK,
                               TASK_X, ty, TASK_W, 18))
            rows.append({"name": tname, "start": tstart, "end": tend,
                         "x": tx, "y": ty, "w": tw, "h": 18.0,
                         "phase": ph["name"]})

    # ---- milestones -------------------------------------------------------
    for pi, ph in enumerate(PHASES):
        for mi, (mname, mdate) in enumerate(ph["miles"]):
            cx = x_of(mdate)                     # rhombus centred on the day
            cy = milestone_cy(ph)
            size = 22.0
            cid = "ms%d_%d" % (pi, mi)
            cells.append({"type": "vertex", "id": cid, "value": mname,
                          "style": ST_MILE, "x": cx - size / 2.0,
                          "y": cy - size / 2.0, "w": size, "h": size,
                          "milestone": True, "date": mdate,
                          "phase": ph["name"]})
            # dashed tie from the phase band to the milestone
            if ph["h"] > 44:
                cells.append(ecell("md%d_%d" % (pi, mi), ST_DEP, "p%d" % pi,
                                   cid, bx + bw, cy, cx - size / 2.0 - 4, cy))

    # ---- legend -----------------------------------------------------------
    def swatch(lx, ly, kind):
        if kind == "phase":
            return vcell("lg%d" % ly, "", ST_PHASE, lx, ly, 46, 16)
        return vcell("lg%d" % ly, "", ST_TASK, lx, ly, 46, 16)

    lx, ly = LABEL_X, LEGEND_TOP
    swatch(lx, ly, "phase")
    cells.append(vcell("lt0", LEGEND[0][0] + "  \u2013  " + LEGEND[0][1],
                       ST_LEG, lx + 56, ly - 2, 520, 18))
    ly += 26
    swatch(lx, ly, "task")
    cells.append(vcell("lt1", LEGEND[1][0] + "  \u2013  " + LEGEND[1][1],
                       ST_LEG, lx + 56, ly - 2, 620, 18))
    ly += 26
    cells.append(vcell("lm0", "", ST_MILE, lx + 12, ly, 22, 22))
    cells.append(vcell("lt2", LEGEND[2][0] + "  \u2013  " + LEGEND[2][1],
                       ST_LEG, lx + 56, ly + 2, 620, 18))
    ly += 30
    cells.append(vcell("ld0", "", "line;html=1;strokeColor=#808080;"
                       "strokeWidth=1;dashed=1;", lx + 10, ly + 9, 30, 2))
    cells.append(vcell("lt3", LEGEND[3][0] + "  \u2013  " + LEGEND[3][1],
                       ST_LEG, lx + 56, ly, 620, 18))

    cells.append(vcell("cap", CAPTION,
                       ST_TEXT % ("center", FONT, 12, "fontStyle=2;"),
                       0, CAPTION_Y, PAGE_W, 24))
    return cells, bands, rows


# --------------------------------------------------------------------------
# Emitters
# --------------------------------------------------------------------------
def cell_xml(c):
    if c["type"] == "edge":
        return ('        <mxCell id="%s" style="%s" edge="1" parent="1" '
                'source="%s" target="%s">\n'
                '          <mxGeometry relative="1" as="geometry">\n'
                '            <mxPoint x="%.1f" y="%.1f" as="sourcePoint" />\n'
                '            <mxPoint x="%.1f" y="%.1f" as="targetPoint" />\n'
                '          </mxGeometry>\n'
                '        </mxCell>\n'
                % (c["id"], esc(c["style"]), c["source"], c["target"],
                   c["x1"], c["y1"], c["x2"], c["y2"]))
    return ('        <mxCell id="%s" value="%s" style="%s" vertex="1" '
            'parent="1">\n'
            '          <mxGeometry x="%.1f" y="%.1f" width="%.1f" '
            'height="%.1f" as="geometry" />\n'
            '        </mxCell>\n'
            % (c["id"], esc(c.get("value", "")), esc(c["style"]), c["x"],
               c["y"], c["w"], c["h"]))


def drawio_xml(cells):
    body = "".join(cell_xml(c) for c in cells)
    return ('<mxfile host="app.diagrams.net" agent="Gantt chart generator" '
            'pages="1">\n'
            '  <diagram id="g1" name="%s">\n'
            '    <mxGraphModel dx="1654" dy="1169" grid="0" gridSize="10" '
            'guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" '
            'pageScale="1" pageWidth="%d" pageHeight="%.0f" math="0" '
            'shadow="0">\n'
            '      <root>\n'
            '        <mxCell id="0" />\n'
            '        <mxCell id="1" parent="0" />\n'
            '%s'
            '      </root>\n'
            '    </mxGraphModel>\n'
            '  </diagram>\n'
            '</mxfile>\n' % (esc(CAPTION), PAGE_W, PAGE_H, body))


def svg_esc(text):
    return sax.escape(text if text is not None else "").replace('"', "&quot;")


def font_size_of(style):
    """Recover the font size a style string asks for, for the SVG mirror."""
    for size in (FS_TITLE, FS_SUB, FS_BAND, FS_MONTH, FS_AXIS, FS_TASK, FS_LEG):
        if "fontSize=%d;" % size in style:
            return size
    return FS_TASK


def svg_doc(cells):
    """Mirror of the same geometry, so the spacing can be checked in a browser."""
    p = ['<?xml version="1.0" encoding="UTF-8"?>\n',
         '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%.0f" '
         'viewBox="0 0 %d %.0f">\n' % (PAGE_W, PAGE_H, PAGE_W, PAGE_H),
         '<rect x="0" y="0" width="%d" height="%.0f" fill="#FFFFFF"/>\n'
         % (PAGE_W, PAGE_H)]
    for c in cells:
        if c["type"] == "edge":
            p.append('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" '
                     'stroke="#808080" stroke-width="1" '
                     'stroke-dasharray="5 4"/>\n'
                     % (c["x1"], c["y1"], c["x2"], c["y2"]))
            continue
        st, x, y, w, h = c["style"], c["x"], c["y"], c["w"], c["h"]
        val = c.get("value", "")
        if st.startswith("line"):
            p.append('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" '
                     'stroke="#B0B0B0" stroke-width="1"%s/>\n'
                     % (x + w / 2, y, x + w / 2, y + h,
                        ' stroke-dasharray="5 4"' if "dashed=1" in st else ""))
        elif st.startswith("rhombus"):
            cx, cy = x + w / 2, y + h / 2
            p.append('<polygon points="%.1f,%.1f %.1f,%.1f %.1f,%.1f %.1f,%.1f"'
                     ' fill="#FFFFFF" stroke="#000000" stroke-width="1.5"/>\n'
                     % (cx, y, x + w, cy, cx, y + h, x, cy))
            if val:
                p.append('<text x="%.1f" y="%.1f" font-family="Arial" '
                         'font-size="%d" text-anchor="middle">%s</text>\n'
                         % (cx, y + h + 13, FS_MILE, svg_esc(val)))
        elif "fillColor=#E8E8E8" in st:
            p.append('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" '
                     'fill="#E8E8E8" stroke="#000000" stroke-width="1"/>\n'
                     % (x, y, w, h))
        elif "fillColor=#FFFFFF" in st:
            p.append('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" '
                     'fill="#FFFFFF" stroke="#000000" stroke-width="1"/>\n'
                     % (x, y, w, h))
        if val and not st.startswith("rhombus"):
            middle = "align=center" in st
            tx = x + w / 2 if middle else x + 6
            bold = ' font-weight="bold"' if "fontStyle=1" in st else ""
            ital = ' font-style="italic"' if "fontStyle=2" in st else ""
            p.append('<text x="%.1f" y="%.1f" font-family="Arial" '
                     'font-size="%d" text-anchor="%s" '
                     'dominant-baseline="middle"%s%s>%s</text>\n'
                     % (tx, y + h / 2, font_size_of(st),
                        "middle" if middle else "start", bold, ital,
                        svg_esc(val)))
    p.append("</svg>\n")
    return "".join(p)


def main():
    cells, bands, rows = layout()
    io.open("Gantt_Chart.drawio", "w", encoding="utf-8",
            newline="").write(drawio_xml(cells))
    io.open("Gantt_Chart.svg", "w", encoding="utf-8",
            newline="").write(svg_doc(cells))

    tasks = [c for c in cells if c["id"].startswith("b")]
    miles = [c for c in cells if c.get("milestone")]
    print("page %.0f x %.0f px   weeks=%d   phases=%d   tasks=%d   "
          "milestones=%d" % (PAGE_W, PAGE_H, WEEKS, len(PHASES), len(tasks),
                             len(miles)))
    print("%-38s %-11s %-11s %s" % ("task", "start", "end", "phase"))
    for r in rows:
        print("%-38s %-11s %-11s %s"
              % (r["name"][:38], r["start"], r["end"], r["phase"]))
    print("\nwrote Gantt_Chart.drawio + Gantt_Chart.svg")


if __name__ == "__main__":
    main()