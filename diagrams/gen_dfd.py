"""Generate Chapter 3.5 data-flow-diagram assets for the dental AI capstone.

Outputs (into <workspace>/diagrams/):
  3.5_DFD_Existing.drawio      (pages: EX_L1, EX_L2)
  3.5_DFD_Proposed.drawio      (pages: PR_L1A, PR_L1B)
  3.5_DFD_<PAGE>.svg           (one Word-ready preview per page)
  3.5_Data_Flow_Diagram_Narrative.md
  3.5_Data_Flow_Diagram_Narrative.docx

Notation: Yourdon/DeMarco-flavoured Gane-Sarson as used by the figures
already in Chapter_12345.docx -- rounded entity/process boxes, open-ended
Dn data-store symbols, thin grey orthogonal connectors. Wires carry short
flow codes (F1..); full names live in the per-page legend and in the
narrative's flow index (this is what lets 30+ flows fit on one page).

draw.io import notes:
  - File -> Open the .drawio (plain uncompressed mxfile XML, A3 landscape).
  - "Extras -> Edit Diagram" shows the same XML printed below.
  - File -> Export As -> PNG/PDF per page for manuscript figures.
  - Arial renders 1:1 everywhere; switch to Times New Roman in draw.io only
    via the Style tab if the adviser requires it.

Standard library only (plus python-docx 1.2.0 for the .docx).
"""
from __future__ import annotations

import os
import re
import sys
import xml.sax.saxutils as sax

# --------------------------------------------------------------------------
# Canvas + shared style (A3 landscape, draw.io px)
# --------------------------------------------------------------------------
PAGE_W = 1654
PAGE_H = 1169
FONT = "Arial"
GREY = "#808080"
BLACK = "#000000"
WHITE = "#ffffff"
ARROW = "classicThin"

TITLE_FS = 18
SUB_FS = 14
ENT_FS = 12
PROC_FS = 12
SUBT_FS = 10
BULL_FS = 10
CODE_FS = 10
LEG_FS = 10
CAP_FS = 14

LEG_LH = 14
LEG_X0 = 56
LEG_X1 = 880
LEG_W0 = 760
LEG_W1 = 700

MARGIN = 56
TITLE_Y = 22
SUB_Y = 52

# --- Level 1 pages: entities on top, processes in the middle, stores below ---
L1_ENT_Y, L1_ENT_H = 140, 78
L1_ENT_BOT = L1_ENT_Y + L1_ENT_H          # 218
L1_PROC_Y, L1_PROC_H = 446, 106
L1_PROC_TOP = L1_PROC_Y                   # 446
L1_PROC_BOT = L1_PROC_Y + L1_PROC_H       # 552
L1_TOPBAND = (228, 436)    # lanes for entity <-> process and process <-> process
L1_SBAND = (604, 750)      # lanes for process <-> data store
L1_STORE_Y, L1_STORE_H = 762, 74

# --- Level 2 pages: inputs left, sub-process chain, stores below, output ---
L2_IN_X, L2_IN_W = 36, 170
L2_IN_H, L2_IN_GAP = 76, 18
L2_OUT_X, L2_OUT_W = 1448, 170
L2_OUT_H = 104
L2_CHAIN_X0, L2_CHAIN_X1 = 268, 1408
L2_CHAIN_Y, L2_CHAIN_H = 330, 176
L2_CHAIN_TOP = L2_CHAIN_Y                 # 330
L2_CHAIN_BOT = L2_CHAIN_Y + L2_CHAIN_H    # 506
L2_CHAIN_CY = L2_CHAIN_Y + L2_CHAIN_H // 2
L2_OUT_Y = L2_CHAIN_CY - L2_OUT_H // 2
L2_TRUNK_X = 232                          # input collector trunk
L2_TOPBAND = (244, 306)    # lanes for skip / back links above the chain
L2_BACKBAND = (520, 548)   # lanes for back links and outputs below the chain
L2_SBAND = (564, 700)      # lanes for process <-> data store
L2_STORE_Y, L2_STORE_H = 726, 74

LEG_TOP = 858
LEG_ROW = 13.5
LEG_COL_W = 512
CAP_Y = 1112

EST10 = 5.5     # px per char, Arial 10
EST12 = 6.6     # px per char, Arial 12 bold


def esc(text):
    return sax.escape(text if text is not None else "",
                       {'"': "&quot;"})


def esc_svg(text):
    return sax.escape(text if text is not None else "").replace('"', "&quot;")


def html_value(lines):
    """Join pre-escaped fragments with <br> for an html=1 label."""
    return "<br>".join(lines)


def text_cell(cid, text, x, y, w, h, size, bold=False, italic=False,
              color=BLACK, align="center"):
    st = ("rounded=0;html=1;whiteSpace=wrap;fillColor=none;strokeColor=none;"
          f"fontFamily={FONT};fontSize={size};fontColor={color};"
          f"align={align};verticalAlign=middle;")
    if bold and italic:
        st += "fontStyle=3;"
    elif bold:
        st += "fontStyle=1;"
    elif italic:
        st += "fontStyle=2;"
    return (f'<mxCell id="{cid}" value="{text}" style="{st}" vertex="1" '
            f'parent="1"><mxGeometry x="{x:.0f}" y="{y:.0f}" width="{w:.0f}" '
            f'height="{h:.0f}" as="geometry"/></mxCell>')


def box_cell(cid, text, x, y, w, h, size, color=BLACK, width=1.0):
    st = (f"rounded=1;arcSize=12;whiteSpace=wrap;html=1;fontFamily={FONT};"
          f"fontSize={size};fontColor={color};fillColor={WHITE};"
          f"strokeColor={GREY};strokeWidth={width};align=center;"
          "verticalAlign=middle;")
    return (f'<mxCell id="{cid}" value="{text}" style="{st}" vertex="1" '
            f'parent="1"><mxGeometry x="{x:.0f}" y="{y:.0f}" width="{w:.0f}" '
            f'height="{h:.0f}" as="geometry"/></mxCell>')


def store_cells(prefix, num, name, x, y, w, h):
    """Open-ended data store: left Dn compartment + open-ended name field.

    Matches the panel-accepted figures: the symbol has a left, top and bottom
    border plus a divider, and NO right border (open-ended)."""
    st = (f"shape=partialRectangle;top=1;left=1;bottom=1;right=0;html=1;"
          f"fontFamily={FONT};fontSize={PROC_FS};fontColor={BLACK};"
          f"fillColor={WHITE};strokeColor={GREY};strokeWidth=1.0;"
          "align=center;verticalAlign=middle;fontStyle=1;")
    left = (f'<mxCell id="{prefix}n" value="{esc(num)}" style="{st}" '
            f'vertex="1" parent="1"><mxGeometry x="{x:.0f}" y="{y:.0f}" '
            f'width="54" height="{h:.0f}" as="geometry"/></mxCell>')
    st2 = (f"shape=partialRectangle;top=1;left=0;bottom=1;right=0;html=1;"
           f"whiteSpace=wrap;fontFamily={FONT};fontSize={PROC_FS};"
           f"fontColor={BLACK};fillColor={WHITE};strokeColor={GREY};"
           "strokeWidth=1.0;align=center;verticalAlign=middle;fontStyle=1;")
    lines = [esc(p) for p in name.split("\n")]
    right = (f'<mxCell id="{prefix}" value="{html_value(lines)}" '
             f'style="{st2}" vertex="1" parent="1">'
             f'<mxGeometry x="{x + 54:.0f}" y="{y:.0f}" '
             f'width="{w - 54:.0f}" height="{h:.0f}" as="geometry"/>'
             "</mxCell>")
    return [left, right]


def proc_label(num, title, bullets=None):
    parts = [f"<b>{esc(num)} {esc(title)}</b>"]
    if bullets:
        for b in bullets:
            parts.append(f'<font color="#595959">{esc(b)}</font>')
    return html_value(parts)


def edge_cell(eid, src, tgt, pts, sx, sy, tx, ty):
    """Grey orthogonal wire with a hollow (open) arrowhead at its target.
    Flow codes are drawn as separate white-backed label cells so that the
    placement is identical in the .drawio and the .svg mirror."""
    st = ("edgeStyle=orthogonalEdgeStyle;rounded=0;orthogonalLoop=1;"
          "jettySize=auto;html=1;whiteSpace=wrap;"
          f"fontFamily={FONT};fontSize={CODE_FS};fontColor={BLACK};"
          f"labelBackgroundColor={WHITE};strokeColor={GREY};"
          "strokeWidth=1.0;endArrow=open;endFill=0;endSize=6;"
          f"exitX={sx:.4f};exitY={sy:.4f};exitDx=0;exitDy=0;"
          f"entryX={tx:.4f};entryY={ty:.4f};entryDx=0;entryDy=0;")
    inner = "".join(f'<mxPoint x="{x:.0f}" y="{y:.0f}"/>' for (x, y) in pts[1:-1])
    return (f'<mxCell id="{eid}" value="" style="{st}" edge="1" '
            f'parent="1" source="{src}" target="{tgt}">'
            f'<mxGeometry relative="1" as="geometry">'
            f'<mxPoint x="{pts[0][0]:.0f}" y="{pts[0][1]:.0f}" as="sourcePoint"/>'
            f'<mxPoint x="{pts[-1][0]:.0f}" y="{pts[-1][1]:.0f}" as="targetPoint"/>'
            f'<Array as="points">{inner}</Array></mxGeometry></mxCell>')


def wire_cell(w, plain=False):
    """One edge cell; tolerates a floating start (the input trunk)."""
    pts = w["pts"]
    sfx, sfy = w["sfrac"]
    tfx, tfy = w["tfrac"]
    cell = edge_cell(w["id"], w["src"] or "", w["tgt"] or "", pts,
                     sfx, sfy, tfx, tfy)
    if not w["src"]:
        cell = cell.replace(' source=""', "")
        cell = re.sub(r"exitX=[-\d.]+;exitY=[-\d.]+;exitDx=0;exitDy=0;", "", cell)
    if not w["tgt"]:
        cell = cell.replace(' target=""', "")
        cell = re.sub(r"entryX=[-\d.]+;entryY=[-\d.]+;entryDx=0;entryDy=0;",
                      "", cell)
    if plain:
        cell = cell.replace("endArrow=open;endFill=0;endSize=6;",
                            "endArrow=none;")
    return cell


def drawio_cells(plan):
    """Full mxCell list for one diagram page."""
    cells = [text_cell("title", esc(plan["title"]), MARGIN, TITLE_Y,
                       PAGE_W - 2 * MARGIN, 30, TITLE_FS, bold=True),
             text_cell("subtitle", esc(plan["sub"]), MARGIN, SUB_Y,
                       PAGE_W - 2 * MARGIN, 26, SUB_FS, bold=True)]
    frames, texts = frame_cells(plan)
    cells += frames
    for w in plan["wires"]:
        cells.append(wire_cell(w))
    for ex in plan["extras"]:
        stub = {"id": ex["id"], "pts": ex["pts"], "src": None, "tgt": None,
                "sfrac": (0.0, 0.0), "tfrac": (0.0, 0.0)}
        cells.append(wire_cell(stub, plain=True))
    for (t, s, b, c, x, y, w, h) in texts:
        cells.append(text_cell(f"tx_{len(cells)}", esc(t), x, y, w, h, s,
                               bold=b, color=c))
    for w in plan["wires"]:
        cells.append(label_cell("lb_" + w["id"], w["code"],
                                w["label"][0], w["label"][1]))
    rows, _r, _c = legend_layout(plan["legend"])
    cells.append(text_cell("leghead", "DATA FLOW LEGEND", MARGIN,
                           LEG_TOP - 24, 420, 18, 11, bold=True, align="left"))
    for i, (txt, x, y) in enumerate(rows):
        cells.append(text_cell(f"leg{i}", esc(txt), x, y, LEG_COL_W - 6,
                               LEG_ROW, LEG_FS, align="left"))
    ny = LEG_TOP + _r * LEG_ROW + 10
    for i, note in enumerate(plan["notes"]):
        cells.append(text_cell(f"note{i}", esc(note), MARGIN, ny, 1400, 13, 9,
                               italic=True, color="#404040", align="left"))
        ny += 13
    cells.append(text_cell("cap", esc(plan["caption"]), MARGIN, CAP_Y,
                           PAGE_W - 2 * MARGIN, 28, CAP_FS, italic=True))
    return cells


def svg_text(text, x, y, size, bold=False, italic=False, color=BLACK,
             anchor="middle"):
    st = f'font-family="{FONT}" font-size="{size}" fill="{color}"'
    if bold:
        st += ' font-weight="bold"'
    if italic:
        st += ' font-style="italic"'
    return (f'<text x="{x:.0f}" y="{y:.0f}" text-anchor="{anchor}" {st}>'
            f'{esc_svg(text)}</text>')


def svg_block(lines):
    out = []
    for (t, s, b, c, x, y, w, h) in lines:
        out.append(svg_text(t, x + w / 2.0, y + h / 2.0 + s * 0.36, s, bold=b,
                            color=c))
    return "".join(out)


def svg_doc(plan):
    """Line-art mirror of the plan (same geometry as the .drawio page)."""
    p = [f'<svg xmlns="http://www.w3.org/2000/svg" width="{PAGE_W}" '
         f'height="{PAGE_H}" viewBox="0 0 {PAGE_W} {PAGE_H}">',
         f'<rect x="0" y="0" width="{PAGE_W}" height="{PAGE_H}" '
         'fill="#ffffff"/>',
         svg_text(plan["title"], PAGE_W / 2.0, TITLE_Y + 20, TITLE_FS, bold=True),
         svg_text(plan["sub"], PAGE_W / 2.0, SUB_Y + 18, SUB_FS, bold=True)]

    frames, texts = frame_cells(plan)
    stroke = f'stroke="{GREY}" stroke-width="1.0" fill="#ffffff"'
    for b in plan["boxes"]:
        x, y, w, h = b["rect"]
        if b["kind"] == "store":
            path = (f"M {x:.0f} {y:.0f} H {x + w:.0f} "
                    f"M {x:.0f} {y + h:.0f} H {x + w:.0f} "
                    f"M {x:.0f} {y:.0f} V {y + h:.0f} "
                    f"M {x + 54:.0f} {y:.0f} V {y + h:.0f}")
            p.append(f'<path d="{path}" stroke="{GREY}" stroke-width="1.0" '
                     'fill="none"/>')
        else:
            p.append(f'<rect x="{x:.0f}" y="{y:.0f}" width="{w:.0f}" '
                     f'height="{h:.0f}" rx="9" ry="9" {stroke}/>')
    for w in plan["wires"]:
        d = " L ".join(f"{x:.0f} {y:.0f}" for (x, y) in w["pts"])
        p.append(f'<path d="M {d}" stroke="{GREY}" stroke-width="1.0" '
                 'fill="none" stroke-linejoin="miter"/>')
    for ex in plan["extras"]:
        d = " L ".join(f"{x:.0f} {y:.0f}" for (x, y) in ex["pts"])
        p.append(f'<path d="M {d}" stroke="{GREY}" stroke-width="1.0" '
                 'fill="none"/>')
    for w in plan["wires"]:
        (ax, ay), (bx, by) = w["pts"][-2], w["pts"][-1]
        if abs(bx - ax) >= abs(by - ay):
            dirn = "E" if bx > ax else "W"
        else:
            dirn = "S" if by > ay else "N"
        p.append(arrow_head(bx, by, dirn))
    p.append(svg_block(texts))
    for w in plan["wires"]:
        lx, ly = w["label"]
        lw = est_w(w["code"], CODE_FS) + 8
        lh = CODE_FS + 2
        p.append(f'<rect x="{lx - lw / 2:.0f}" y="{ly - lh / 2:.0f}" '
                 f'width="{lw:.0f}" height="{lh:.0f}" fill="#ffffff"/>')
        p.append(svg_text(w["code"], lx, ly + CODE_FS * 0.36, CODE_FS))

    rows, nrows, ncols = legend_layout(plan["legend"])
    p.append(svg_text("DATA FLOW LEGEND", MARGIN, LEG_TOP - 10, 11, bold=True,
                      anchor="start"))
    for (txt, x, y) in rows:
        p.append(svg_text(txt, x, y + LEG_ROW * 0.5 + LEG_FS * 0.36, LEG_FS,
                          anchor="start"))
    ny = LEG_TOP + nrows * LEG_ROW + 10
    for note in plan["notes"]:
        p.append(svg_text(note, MARGIN, ny + 9, 9, italic=True,
                          color="#404040", anchor="start"))
        ny += 13
    p.append(svg_text(plan["caption"], PAGE_W / 2.0, CAP_Y + 18, CAP_FS,
                      italic=True))
    p.append("</svg>")
    return "".join(p)


def drawio_xml(pages):
    """One .drawio file; `pages` is a list of (tag, cells)."""
    body = ['<mxfile host="app.diagrams.net">']
    for (tag, cells) in pages:
        body.append(
            f'<diagram id="{tag}" name="{esc(tag)}">'
            f'<mxGraphModel dx="{PAGE_W}" dy="{PAGE_H}" grid="1" gridSize="10" '
            'guides="1" tooltips="1" connect="1" arrows="1" fold="1" '
            f'page="1" pageScale="1" pageWidth="{PAGE_W}" pageHeight="{PAGE_H}" '
            'math="0" shadow="0">'
            '<root><mxCell id="0"/><mxCell id="1" parent="0"/>')
        body.extend(cells)
        body.append('</root></mxGraphModel></diagram>')
    body.append('</mxfile>')
    return "".join(body)



def label_cell(cid, code, cx, cy, size=CODE_FS):
    """White-backed flow code drawn at the middle of the longest wire run."""
    w = est_w(code, size) + 8
    h = size + 2
    st = ("rounded=0;html=1;whiteSpace=wrap;fillColor=white;strokeColor=none;"
          f"fontFamily={FONT};fontSize={size};fontColor={BLACK};"
          "align=center;verticalAlign=middle;")
    return (f'<mxCell id="{cid}" value="{esc(code)}" style="{st}" '
            f'vertex="1" parent="1"><mxGeometry x="{cx - w / 2:.0f}" '
            f'y="{cy - h / 2:.0f}" width="{w:.0f}" height="{h:.0f}" '
            'as="geometry"/></mxCell>')


def arrow_head(x, y, direction):
    """Hollow (open) arrowhead -- two 45-degree strokes, no fill."""
    s = 11
    d = s / 1.414
    st = f'stroke="{GREY}" stroke-width="1.0" fill="none"'
    if direction == "E":
        return (f'<path d="M {x:.0f} {y:.0f} L {x - d:.0f} {y - d:.0f} '
                f'M {x:.0f} {y:.0f} L {x - d:.0f} {y + d:.0f}" {st}/>')
    if direction == "W":
        return (f'<path d="M {x:.0f} {y:.0f} L {x + d:.0f} {y - d:.0f} '
                f'M {x:.0f} {y:.0f} L {x + d:.0f} {y + d:.0f}" {st}/>')
    if direction == "S":
        return (f'<path d="M {x:.0f} {y:.0f} L {x - d:.0f} {y - d:.0f} '
                f'M {x:.0f} {y:.0f} L {x + d:.0f} {y - d:.0f}" {st}/>')
    return (f'<path d="M {x:.0f} {y:.0f} L {x - d:.0f} {y + d:.0f} '
            f'M {x:.0f} {y:.0f} L {x + d:.0f} {y + d:.0f}" {st}/>')


# --------------------------------------------------------------------------
# Page content tables (Option B, locked)
# --------------------------------------------------------------------------
# Entities: (key, label) -- title case, matching the panel-accepted figures
ENTS = {
    "pat": "Patient",
    "den": "Dentist",
    "adm": "Admin",
    "stf": "Clinic Staff /\nFront Desk",
    "mgt": "Clinic Management",
}

# Stores: key -> (code, display name)
STORES = {
    "users": ("D1", "Users"),
    "patients": ("D2", "Patients"),
    "dentists": ("D3", "Dentists"),
    "schedules": ("D4", "Dentist Schedules\n& Day-Offs"),
    "config": ("D5", "Clinic Configuration\n(Services, Hours, Settings)"),
    "queue": ("D6", "Queue"),
    "concerns": ("D7", "Appointment Concerns"),
    "notifs": ("D8", "Notifications"),
    "admin_notifs": ("D9", "Admin Notifications"),
    "appts": ("D10", "Appointments"),
    "ai": ("D11", "AI Predictions"),
    "medrec": ("D12", "Medical Records"),
    "logbook": ("D1", "Appointment\nLogbook"),
    "records": ("D2", "Patient Record\nFolders"),
    "board": ("D3", "Dentist Schedule\nBoard"),
    "qlist": ("D4", "Daily Queue List"),
    "feeref": ("D5", "Service & Fee\nReference"),
}

# Each page: dict with title, subtitle, caption, entities (keys), procs
# (list of (pid, title, subtitle, bullets)), stores (list of store keys),
# flows (list of (code, src, dst)) where src/dst are one of:
#   E:<entkey>, P:<pid>, S:<storekey>
PAGES = {}

PAGES["EX_L1"] = {
    "title": "3.5 DATA FLOW DIAGRAM",
    "sub": "EXISTING SYSTEM - LEVEL 1",
    "caption": "Figure 5: Data Flow Diagram of Existing System",
    "ents": ["pat", "stf", "den", "mgt"],
    "procs": [
        ("1.0", "Patient Registration\n& Appointment Booking",
         "Walk-in / phone intake", None),
        ("2.0", "Appointment Scheduling\n& Logbook Recording",
         "Verbal check, logbook write", None),
        ("3.0", "Dentist Availability\n& Schedule Management",
         "Rest days, day-offs", None),
        ("4.0", "Walk-in Queue Handling",
         "Queue numbers, order", None),
        ("5.0", "Appointment Reminder\n& Follow-up",
         "Call / text reminders", None),
        ("6.0", "Records Filing\n& Report Preparation",
         "Folders, monthly summary", None),
    ],
    "stores": ["logbook", "records", "board", "qlist", "feeref"],
    "flows": [
        # E-codes: External <-> process
        ("E1", "E:pat", "P:1.0"), ("E2", "E:pat", "P:1.0"),
        ("E3", "E:pat", "P:2.0"), ("E4", "E:pat", "P:6.0"),
        ("E5", "P:2.0", "E:pat"), ("E6", "P:4.0", "E:pat"),
        ("E7", "P:5.0", "E:pat"), ("E8", "P:2.0", "E:pat"),
        ("E9", "E:stf", "P:2.0"), ("E10", "E:stf", "P:3.0"),
        ("E11", "E:stf", "P:1.0"), ("E12", "E:stf", "P:4.0"),
        ("E13", "P:2.0", "E:stf"), ("E14", "P:6.0", "E:stf"),
        ("E15", "P:4.0", "E:stf"), ("E16", "P:6.0", "E:stf"),
        ("E17", "E:den", "P:3.0"), ("E18", "E:den", "P:2.0"),
        ("E19", "E:den", "P:6.0"), ("E20", "P:3.0", "E:den"),
        ("E21", "P:2.0", "E:den"), ("E22", "P:6.0", "E:mgt"),
        # S-codes: store <-> process
        ("S1", "P:1.0", "S:records"), ("S2", "S:records", "P:2.0"),
        ("S3", "P:2.0", "S:logbook"), ("S4", "S:logbook", "P:2.0"),
        ("S5", "P:3.0", "S:board"), ("S6", "S:board", "P:2.0"),
        ("S7", "S:feeref", "P:2.0"), ("S8", "P:4.0", "S:qlist"),
        ("S9", "S:qlist", "P:4.0"), ("S10", "S:records", "P:6.0"),
        ("S11", "P:6.0", "S:records"), ("S12", "S:logbook", "P:6.0"),
        # P-codes: process <-> process
        ("P1", "P:1.0", "P:2.0"), ("P2", "P:3.0", "P:2.0"),
        ("P3", "P:2.0", "P:4.0"), ("P4", "P:2.0", "P:5.0"),
    ],
}


PAGES["EX_L2"] = {
    "title": "3.5 DATA FLOW DIAGRAM",
    "sub": "EXISTING SYSTEM - LEVEL 2 OF PROCESS 2.0",
    "caption": "Figure 6. Level 2 Data Flow Diagram of the Existing "
               "Appointment Scheduling Process",
    "chain": [
        ("2.1", "Receive Appointment\nRequest",
         ["Walk-in or phone", "Collect patient info", "Note service needed"]),
        ("2.2", "Verify Dentist\nAvailability",
         ["Check schedule board", "Confirm rest day/day-off", "Ask dentist if needed"]),
        ("2.3", "Check Logbook\nfor Conflicts",
         ["Scan logbook entries", "Compare date and time", "Reject double-booking"]),
        ("2.4", "Record Appointment\nin Logbook",
         ["Write name, date, time", "Write dentist, service", "File patient record"]),
        ("2.5", "Issue Appointment Slip",
         ["Copy date and time", "Hand slip to patient", "Log queue if walk-in"]),
    ],
    "inputs": ["Appointment\nRequest", "Patient\nInformation",
               "Service & Fee\nInquiry"],
    "output": "Appointment Slip\n& Confirmation",
    "stores": ["logbook", "records", "board", "qlist", "feeref"],
    "flows": [
        ("C1", "IN", "C:2.1"), ("C2", "C:2.1", "C:2.2"),
        ("C3", "C:2.2", "C:2.3"), ("C4", "C:2.3", "C:2.4"),
        ("C5", "C:2.4", "C:2.5"), ("C6", "C:2.5", "OUT"),
        ("C7", "S:board", "C:2.2"), ("C8", "S:logbook", "C:2.3"),
        ("C9", "C:2.4", "S:logbook"), ("C10", "S:feeref", "C:2.1"),
        ("C11", "C:2.4", "S:records"), ("C12", "C:2.5", "S:qlist"),
        ("C13", "S:qlist", "C:2.5"),
    ],
}


PAGES["PR_L1A"] = {
    "title": "3.5 DATA FLOW DIAGRAM",
    "sub": "PROPOSED SYSTEM - LEVEL 1 (PART 1 OF 2)",
    "caption": "Figure 6: Level 1 Data Flow Diagram of the Proposed System "
               "(Part 1 of 2)",
    "ents": ["pat", "den", "adm"],
    "procs": [
        ("1.0", "User & Role\nManagement",
         "Register, login, roles", None),
        ("2.0", "Patient Information\nManagement",
         "Profiles, records", None),
        ("3.0", "Dentist Schedule\n& Availability Mgmt",
         "Schedules, rest days, day-offs", None),
        ("4.0", "Appointment\nManagement",
         "Slots, booking, status", None),
    ],
    "stores": ["users", "patients", "dentists", "schedules", "config"],
    "flows": [
        ("F1", "E:pat", "P:1.0"), ("F2", "P:1.0", "E:pat"),
        ("F3", "E:den", "P:1.0"), ("F4", "E:adm", "P:1.0"),
        ("F5", "P:1.0", "S:users"), ("F6", "S:users", "P:1.0"),
        ("F7", "E:pat", "P:2.0"), ("F8", "P:2.0", "E:pat"),
        ("F9", "P:2.0", "S:patients"), ("F10", "S:patients", "P:2.0"),
        ("F11", "E:adm", "P:2.0"), ("F12", "E:adm", "P:3.0"),
        ("F13", "E:den", "P:3.0"), ("F14", "P:3.0", "E:den"),
        ("F15", "P:3.0", "S:dentists"), ("F16", "S:dentists", "P:3.0"),
        ("F17", "P:3.0", "S:schedules"), ("F18", "S:schedules", "P:3.0"),
        ("F19", "S:config", "P:3.0"), ("F20", "E:pat", "P:4.0"),
        ("F21", "P:4.0", "E:pat"), ("F22", "S:schedules", "P:4.0"),
        ("F23", "S:config", "P:4.0"), ("F24", "S:dentists", "P:4.0"),
        ("F25", "P:1.0", "P:2.0"), ("F26", "P:1.0", "P:3.0"),
        ("F27", "P:1.0", "P:4.0"), ("F28", "P:2.0", "P:4.0"),
        ("F29", "P:3.0", "P:4.0"),
    ],
}


PAGES["PR_L1B"] = {
    "title": "3.5 DATA FLOW DIAGRAM",
    "sub": "PROPOSED SYSTEM - LEVEL 1 (PART 2 OF 2)",
    "caption": "Figure 7: Level 1 Data Flow Diagram of the Proposed System "
               "(Part 2 of 2)",
    "ents": ["pat", "den", "adm"],
    "procs": [
        ("5.0", "Queue & Concern\nHandling",
         "QR walk-in, line, issues", None),
        ("6.0", "Notification &\nReminder System",
         "Alerts, e-mail reminders", None),
        ("7.0", "Reports, Analytics &\nDemand Forecasting",
         "KPIs, trends, 7-day forecast", None),
    ],
    "stores": ["queue", "concerns", "notifs", "admin_notifs",
               "appts", "medrec"],
    "flows": [
        ("G1", "E:pat", "P:5.0"), ("G2", "P:5.0", "E:pat"),
        ("G3", "E:adm", "P:5.0"), ("G4", "E:den", "P:5.0"),
        ("G5", "P:5.0", "S:queue"), ("G6", "S:queue", "P:5.0"),
        ("G7", "P:5.0", "S:concerns"), ("G8", "S:concerns", "P:5.0"),
        ("G9", "P:5.0", "S:appts"), ("G10", "S:appts", "P:5.0"),
        ("G11", "P:6.0", "E:pat"), ("G12", "P:6.0", "E:den"),
        ("G13", "P:6.0", "E:adm"), ("G14", "P:6.0", "S:notifs"),
        ("G15", "P:6.0", "S:admin_notifs"), ("G16", "S:appts", "P:6.0"),
        ("G17", "P:5.0", "P:6.0"), ("G18", "S:appts", "P:7.0"),
        ("G19", "S:queue", "P:7.0"), ("G20", "S:concerns", "P:7.0"),
        ("G21", "S:notifs", "P:7.0"), ("G22", "S:medrec", "P:7.0"),
        ("G23", "P:7.0", "E:adm"), ("G24", "P:7.0", "E:adm"),
    ],
}


PAGES["PR_L24"] = {
    "title": "3.5 DATA FLOW DIAGRAM",
    "sub": "PROPOSED SYSTEM - LEVEL 2 OF PROCESS 4.0",
    "caption": "Figure 9. Level 2 Data Flow Diagram of the Proposed "
               "Appointment Management Process",
    "chain": [
        ("4.1", "View Available Slots\n& Validate Schedule",
         ["Business hours check", "Rest day / day-off check",
          "Slot interval, max/day, conflicts"]),
        ("4.2", "Book Appointment",
         ["Dentist, service, date, time", "Create Pending record",
          "Assign reference number"]),
        ("4.3", "Confirm / Complete\n/ Cancel",
         ["Dentist or Admin action", "Update appointment status",
          "Free the slot on cancel"]),
        ("4.4", "Reschedule",
         ["New date and time", "Re-validate schedule",
          "Keep history, notify patient"]),
        ("4.5", "Appointment Concern\nHandling",
         ["Patient submits concern", "Admin notification raised",
          "Resolve then confirm/cancel"]),
        ("4.6", "No-Show Marking\n& Alert",
         ["Auto mark after closing", "Admin manual mark",
          "Notify patient"]),
    ],
    "inputs": ["Appointment\nRequest", "Registration &\nLogin Details",
               "Reschedule /\nCancel Request"],
    "output": "Confirmation,\nStatus & Alerts",
    "stores": ["schedules", "config", "concerns", "notifs",
               "admin_notifs", "appts"],
    "flows": [
        ("H1", "IN", "C:4.1"), ("H2", "C:4.1", "C:4.2"),
        ("H3", "C:4.2", "C:4.3"), ("H4", "C:4.3", "C:4.4"),
        ("H5", "C:4.5", "C:4.3"), ("H6", "C:4.3", "C:4.6"),
        ("H7", "C:4.6", "OUT"), ("H8", "C:4.3", "OUT"),
        ("H9", "S:schedules", "C:4.1"), ("H10", "S:config", "C:4.1"),
        ("H11", "S:appts", "C:4.1"), ("H12", "C:4.2", "S:appts"),
        ("H13", "C:4.3", "S:appts"), ("H14", "C:4.5", "S:concerns"),
        ("H15", "S:concerns", "C:4.5"), ("H16", "C:4.5", "S:admin_notifs"),
        ("H17", "C:4.6", "S:appts"), ("H18", "C:4.6", "S:notifs"),
    ],
}


# Flow-name index: (page, code) -> full name (used by legend + validators)
# --------------------------------------------------------------------------
LEG_NAMES = [
    ("EX_L1", "E1", "Appointment request (walk-in / phone)"),
    ("EX_L1", "E2", "Patient information (name, contact, dental concern)"),
    ("EX_L1", "E3", "Reschedule or cancellation request"),
    ("EX_L1", "E4", "Service and fee inquiry"),
    ("EX_L1", "E5", "Appointment confirmation (appointment slip)"),
    ("EX_L1", "E6", "Queue number (walk-in)"),
    ("EX_L1", "E7", "Appointment reminder (call / text)"),
    ("EX_L1", "E8", "No-slot or conflict notice"),
    ("EX_L1", "E9", "Dentist availability (rest day / day-off)"),
    ("EX_L1", "E10", "Walk-in registration details"),
    ("EX_L1", "E11", "Patient walk-in information"),
    ("EX_L1", "E12", "Queue lineup for today"),
    ("EX_L1", "E13", "Daily appointment list / schedule board"),
    ("EX_L1", "E14", "Monthly appointment summary"),
    ("EX_L1", "E15", "Queue status"),
    ("EX_L1", "E16", "No-show list"),
    ("EX_L1", "E17", "Working hours and availability"),
    ("EX_L1", "E18", "Treatment / service duration"),
    ("EX_L1", "E19", "Appointment status (completed / no-show)"),
    ("EX_L1", "E20", "Daily schedule and patient list"),
    ("EX_L1", "E21", "Patient dental history"),
    ("EX_L1", "E22", "Monthly appointment summary"),
    ("EX_L1", "S1", "New patient record (to folders)"),
    ("EX_L1", "S2", "Patient record lookup"),
    ("EX_L1", "S3", "New appointment entry (to logbook)"),
    ("EX_L1", "S4", "Logbook lookup for conflicts"),
    ("EX_L1", "S5", "Updated schedule board entry"),
    ("EX_L1", "S6", "Schedule board lookup"),
    ("EX_L1", "S7", "Service & fee lookup"),
    ("EX_L1", "S8", "New queue line entry"),
    ("EX_L1", "S9", "Queue list lookup"),
    ("EX_L1", "S10", "Patient record lookup (reminders)"),
    ("EX_L1", "S11", "Reminder log entry"),
    ("EX_L1", "S12", "Past logbook entries for report"),
    ("EX_L1", "P1", "Registered patient details (for booking)"),
    ("EX_L1", "P2", "Availability envelope (for scheduling)"),
    ("EX_L1", "P3", "Booked slot (for queue ordering)"),
    ("EX_L1", "P4", "Booked slot (for reminder run)"),
    ("EX_L2", "C1", "Raw request details"),
    ("EX_L2", "C2", "Collected request package"),
    ("EX_L2", "C3", "Availability verdict"),
    ("EX_L2", "C4", "Conflict verdict"),
    ("EX_L2", "C5", "Confirmed appointment record"),
    ("EX_L2", "C6", "Slip details"),
    ("EX_L2", "C7", "Posted availability"),
    ("EX_L2", "C8", "Existing logbook lines"),
    ("EX_L2", "C9", "New logbook line"),
    ("EX_L2", "C10", "Fee reference lookup"),
    ("EX_L2", "C11", "Filed patient record"),
    ("EX_L2", "C12", "Queue number assigned to walk-in"),
    ("EX_L2", "C13", "Daily queue list lookup"),
    ("PR_L1A", "F1", "Registration details / login credentials"),
    ("PR_L1A", "F2", "Auth result, session, dashboard access"),
    ("PR_L1A", "F3", "Dentist login credentials"),
    ("PR_L1A", "F4", "Admin login credentials"),
    ("PR_L1A", "F5", "New / updated user account"),
    ("PR_L1A", "F6", "Account lookup (verify credentials, role)"),
    ("PR_L1A", "F7", "Profile details (name, contact, history)"),
    ("PR_L1A", "F8", "Updated profile / patient number"),
    ("PR_L1A", "F9", "New / updated patient record"),
    ("PR_L1A", "F10", "Patient record lookup"),
    ("PR_L1A", "F11", "Patient maintenance actions"),
    ("PR_L1A", "F12", "Schedule maintenance actions"),
    ("PR_L1A", "F13", "Availability, rest days, day-offs"),
    ("PR_L1A", "F14", "Posted schedule / day-off status"),
    ("PR_L1A", "F15", "Dentist profile update"),
    ("PR_L1A", "F16", "Dentist profile lookup"),
    ("PR_L1A", "F17", "Schedule / day-off entries"),
    ("PR_L1A", "F18", "Schedule / day-off lookup"),
    ("PR_L1A", "F19", "Business-hours & service lookup"),
    ("PR_L1A", "F20", "Booking request (dentist, service, date, time)"),
    ("PR_L1A", "F21", "Confirmation, reference no., available slots"),
    ("PR_L1A", "F22", "Schedule / day-off lookup (validation)"),
    ("PR_L1A", "F23", "Business-hours / settings lookup (validation)"),
    ("PR_L1A", "F24", "Dentist lookup (specialization)"),
    ("PR_L1A", "F25", "Authenticated role context"),
    ("PR_L1A", "F26", "Authenticated role context"),
    ("PR_L1A", "F27", "Authenticated role context"),
    ("PR_L1A", "F28", "Patient identity for booking"),
    ("PR_L1A", "F29", "Availability envelope for validation"),
    ("PR_L1B", "G1", "QR check-in details / walk-in request"),
    ("PR_L1B", "G2", "Queue number and status"),
    ("PR_L1B", "G3", "Queue review & priority actions"),
    ("PR_L1B", "G4", "Serving actions"),
    ("PR_L1B", "G5", "Queue row write (assign / update status)"),
    ("PR_L1B", "G6", "Queue lookup (next, waiting, serving)"),
    ("PR_L1B", "G7", "Concern record write"),
    ("PR_L1B", "G8", "Concern lookup"),
    ("PR_L1B", "G9", "Concern-linked appointment"),
    ("PR_L1B", "G10", "Appointment lookup (validate concern)"),
    ("PR_L1B", "G11", "Patient alerts (booking, status, queue)"),
    ("PR_L1B", "G12", "Dentist alerts (schedule, concerns)"),
    ("PR_L1B", "G13", "Admin alerts (new concern, status changes)"),
    ("PR_L1B", "G14", "Patient notification write"),
    ("PR_L1B", "G15", "Admin notification write"),
    ("PR_L1B", "G16", "Appointment snapshot for message text"),
    ("PR_L1B", "G17", "Queue / concern event (raises an alert)"),
    ("PR_L1B", "G18", "Historical daily appointment counts (cancelled / no-show excluded)"),
    ("PR_L1B", "G19", "Queue counts for report"),
    ("PR_L1B", "G20", "Concern counts for report"),
    ("PR_L1B", "G21", "Notification log for report"),
    ("PR_L1B", "G22", "Treatment rows for report"),
    ("PR_L1B", "G23", "KPI cards, charts, PDF / CSV export view"),
    ("PR_L1B", "G24", "7-day demand forecast (Low / Medium / High)"),
    ("PR_L24", "H1", "Request + credentials into validation"),
    ("PR_L24", "H2", "Validated slot"),
    ("PR_L24", "H3", "New Pending booking"),
    ("PR_L24", "H4", "Confirmed booking (to reschedule)"),
    ("PR_L24", "H5", "Resolved concern verdict"),
    ("PR_L24", "H6", "Post-visit appointment (for no-show check)"),
    ("PR_L24", "H7", "No-show alert to patient"),
    ("PR_L24", "H8", "Confirmation / status update to patient"),
    ("PR_L24", "H9", "Schedule / day-off lookup"),
    ("PR_L24", "H10", "Business-hours / settings lookup"),
    ("PR_L24", "H11", "Existing bookings lookup"),
    ("PR_L24", "H12", "New booking write"),
    ("PR_L24", "H13", "Status update write"),
    ("PR_L24", "H14", "Concern write"),
    ("PR_L24", "H15", "Concern read"),
    ("PR_L24", "H16", "Admin-notification write"),
    ("PR_L24", "H17", "No-show status write"),
    ("PR_L24", "H18", "Patient alert write"),
]








# ==========================================================================
# Layout engine
# ==========================================================================
CHAR_W = 0.552          # px per pt per character, Arial (deliberately roomy)


def est_w(text, size, bold=False):
    """Rough rendered width of `text` at `size` pt in Arial."""
    return len(str(text)) * CHAR_W * (1.14 if bold else 1.0) * size


def wrap_words(text, size, maxw, bold=False):
    """Greedy word wrap honouring explicit newlines -> list of lines."""
    out = []
    for para in str(text).split("\n"):
        words = para.split()
        if not words:
            out.append("")
            continue
        cur = words[0]
        for word in words[1:]:
            if est_w(cur + " " + word, size, bold) <= maxw:
                cur += " " + word
            else:
                out.append(cur)
                cur = word
        out.append(cur)
    return out


def row_rects(keys, y, h, gap, cap, span=None):
    """Spread n boxes evenly over the printable width -> {key: (x, y, w, h)}.

    `span` pins the row to an explicit (x0, x1) corridor, which the level 2
    sheets use so that the sub-process chain never runs under the input
    column on the left or the output box on the right."""
    n = len(keys)
    x0, x1 = span or (MARGIN, PAGE_W - MARGIN)
    w = min((x1 - x0 - (n - 1) * gap) / n, cap)
    total = n * w + (n - 1) * gap
    left = x0 + (x1 - x0 - total) / 2.0
    return {k: (left + i * (w + gap), y, w, h) for i, k in enumerate(keys)}


def spread(n, pad=0.14):
    """n attachment fractions evenly spread inside [pad, 1 - pad]."""
    if n <= 1:
        return [0.5]
    return [pad + (1 - 2 * pad) * i / (n - 1) for i in range(n)]


def place_lanes(ivs, band, gap=28):
    """First-fit colouring of horizontal runs -> (lane y per item, lanes)."""
    order = sorted(range(len(ivs)), key=lambda i: -(ivs[i][1] - ivs[i][0]))
    lanes, lane_of = [], [0] * len(ivs)
    for i in order:
        lo, hi = ivs[i]
        for k, placed in enumerate(lanes):
            if all(hi + gap <= a or lo - gap >= b for (a, b) in placed):
                placed.append((lo, hi))
                lane_of[i] = k
                break
        else:
            lanes.append([(lo, hi)])
            lane_of[i] = len(lanes) - 1
    y0, y1 = band
    step = (y1 - y0) / max(1, len(lanes))
    ys = [y0 + step * (k + 0.5) for k in range(len(lanes))]
    return [ys[k] for k in lane_of], len(lanes), step


def mid_of_longest(pts):
    """Label anchor: mid point of the longest HORIZONTAL run, so that every
    flow code sits on its own corridor lane (lanes never share a y, and runs
    sharing a lane are kept a fixed gap apart by place_lanes)."""
    horiz = [(a, b) for (a, b) in zip(pts, pts[1:]) if abs(b[0] - a[0]) > 1.0]
    if horiz:
        a, b = max(horiz, key=lambda s: abs(s[1][0] - s[0][0]))
    else:
        a, b = pts[0], pts[1] if len(pts) > 1 else pts[0]
    return ((a[0] + b[0]) / 2, (a[1] + b[1]) / 2)


def frac(rect, pt):
    """Attachment fractions of `pt` inside `rect` (draw.io exit/entry)."""
    x, y, w, h = rect
    fx = min(1.0, max(0.0, (pt[0] - x) / w))
    fy = min(1.0, max(0.0, (pt[1] - y) / h))
    return fx, fy


# --------------------------------------------------------------------------
# Plan builders. A plan is pure geometry shared by the .drawio and .svg
# emitters:  boxes[] (rect + text lines) + wires[] (polyline + code) +
# legend[] (code -> full name), so the two outputs can never drift apart.
# --------------------------------------------------------------------------

def new_plan(pk):
    page = PAGES[pk]
    return {"key": pk, "title": page["title"], "sub": page["sub"],
            "caption": page["caption"], "boxes": [], "wires": [],
            "extras": [], "legend": [], "notes": [], "stats": {}}


def add_box(plan, bid, kind, rect, lines, dnum=None):
    plan["boxes"].append({"id": bid, "kind": kind, "rect": rect,
                          "lines": lines, "dnum": dnum})


def add_wire(plan, wid, code, pts, src, tgt, srect, trect, head=True):
    plan["wires"].append(
        {"id": wid, "code": code, "pts": pts, "src": src, "tgt": tgt,
         "sfrac": frac(srect, pts[0]) if srect else (0.0, 0.0),
         "tfrac": frac(trect, pts[-1]) if trect else (0.0, 0.0),
         "label": mid_of_longest(pts), "head": head})


L1_NOTES = [
    "Rounded box = process or external entity; open-ended box = data store "
    "(Dn).",
    "Wires carry the data-flow code; the legend gives the full flow name.",
    "Where several sources feed one destination, the same code is repeated "
    "on each wire.",
]

L2_NOTES = [
    "Sub-processes are numbered as a decomposition of their parent process; "
    "the input boxes on the left collect into one trunk.",
    "Rounded box = sub-process; open-ended box = data store (Dn); plain box "
    "= external input or output.",
    "Wires carry the data-flow code; the legend gives the full flow name.",
]


def legend_for(pk):
    """(code, full name) rows for one page, in flow order."""
    names = {(p, c): n for (p, c, n) in LEG_NAMES}
    out, seen = [], set()
    for (code, src, dst) in PAGES[pk]["flows"]:
        if code in seen:
            continue
        seen.add(code)
        assert (pk, code) in names, f"{pk}: no legend name for {code}"
        out.append((code, names[(pk, code)]))
    unused = sorted({c for (p, c, n) in LEG_NAMES if p == pk} - seen)
    assert not unused, f"{pk}: unused legend names {unused}"
    return out



def plan_l1(pk):
    """Entities on top, processes across the middle, data stores below.

    Every wire is drawn as  start -> lane -> lane -> end, so entity flows,
    process-to-process flows and store flows share one deterministic rule:
    entity/process links and process-process links use the band above the
    process row, store links use the band below it."""
    page = PAGES[pk]
    plan = new_plan(pk)
    ents, procs, stores, flows = (page["ents"], page["procs"],
                                  page["stores"], page["flows"])

    ent_r = row_rects(ents, L1_ENT_Y, L1_ENT_H, 74, 330)
    proc_r = row_rects([p[0] for p in procs], L1_PROC_Y, L1_PROC_H, 30, 268)
    store_r = row_rects(stores, L1_STORE_Y, L1_STORE_H, 18, 300)
    full = {"E": ent_r, "P": proc_r, "S": store_r}

    def eid(ref):
        k, key = ref.split(":", 1)
        return {"E": "en_", "P": "pr_", "S": "st_"}[k] + key.replace(".", "_")

    def box(ref):
        k, key = ref.split(":", 1)
        return full[k][key]

    def port_box(ref):
        """Rectangle whose edge carries the attachment (store = name field)."""
        r = box(ref)
        if ref.startswith("S:"):
            return (r[0] + 54, r[1], r[2] - 54, r[3])
        return r

    for key in ents:
        r = ent_r[key]
        lines = [(ln, ENT_FS + 1, True, BLACK)
                 for ln in wrap_words(ENTS[key], ENT_FS + 1, r[2] - 24, True)]
        add_box(plan, "en_" + key, "ent", r, lines)
    for (pid, title, subtitle, bullets) in procs:
        r = proc_r[pid]
        lines = [(ln, PROC_FS, True, BLACK)
                 for ln in wrap_words(title, PROC_FS, r[2] - 22, True)]
        for b in (bullets or ([subtitle] if subtitle else [])):
            lines += [(ln, SUBT_FS, False, "#595959")
                      for ln in wrap_words(b, SUBT_FS, r[2] - 22)]
        add_box(plan, "pr_" + pid.replace(".", "_"), "proc", r, lines)
    for key in stores:
        ccode, name = STORES[key]
        r = store_r[key]
        lines = [(ln, PROC_FS, True, BLACK)
                 for ln in wrap_words(name, PROC_FS, r[2] - 64, True)]
        add_box(plan, "st_" + key, "store", r, lines, dnum=ccode)

    # ---- attachment ports ------------------------------------------------
    sides, attach = [], {}
    for (code, s, d) in flows:
        sk, dk = s[0], d[0]
        if sk == "E" and dk == "P":
            sd = ("B", "T")
        elif sk == "P" and dk == "E":
            sd = ("T", "B")
        elif sk == "P" and dk == "P":
            sd = ("T", "T")
        elif sk == "P" and dk == "S":
            sd = ("B", "T")
        elif sk == "S" and dk == "P":
            sd = ("T", "B")
        else:
            raise AssertionError(f"{pk}: unsupported flow {code} {s} -> {d}")
        sides.append(sd)
        for (ref, side, other, end) in ((s, sd[0], d, "s"), (d, sd[1], s, "d")):
            orc = port_box(other)
            attach.setdefault((ref, side), []).append(
                (orc[0] + orc[2] / 2, len(sides) - 1, end))

    pos = {}
    for (ref, side), items in attach.items():
        items.sort()
        r = port_box(ref)
        fr = spread(len(items))
        for i, (_, fi, end) in enumerate(items):
            px = r[0] + r[2] * fr[i]
            py = r[1] if side == "T" else r[1] + r[3]
            pos[(fi, end)] = (px, py)

    # ---- lanes (one pool above the process row, one below) ---------------
    above = [fi for fi, (c, s, d) in enumerate(flows) if "S" not in (s[0], d[0])]
    below = [fi for fi, (c, s, d) in enumerate(flows) if "S" in (s[0], d[0])]
    lane = {}
    for tag, idx, band, gap in (("above", above, L1_TOPBAND, 30),
                                ("below", below, L1_SBAND, 26)):
        ivs = [(min(pos[(fi, "s")][0], pos[(fi, "d")][0]),
                max(pos[(fi, "s")][0], pos[(fi, "d")][0])) for fi in idx]
        ys, n, step = place_lanes(ivs, band, gap)
        for fi, y in zip(idx, ys):
            lane[fi] = y
        plan["stats"][tag] = (n, round(step, 1))

    for fi, (code, s, d) in enumerate(flows):
        p0, p1 = pos[(fi, "s")], pos[(fi, "d")]
        y = lane[fi]
        pts = [(p0[0], p0[1]), (p0[0], y), (p1[0], y), (p1[0], p1[1])]
        add_wire(plan, f"w{fi}", code, pts, eid(s), eid(d),
                 port_box(s), port_box(d))

    plan["legend"] = legend_for(pk)
    plan["notes"] = L1_NOTES
    return plan

def plan_l2(pk):
    """Inputs on the left, the sub-process chain across the middle, data
    stores below and the single output box on the right.

    Chain links between neighbouring sub-processes are drawn straight;
    skip/back links use the band above the chain, store links the band
    below it, and an output that starts from an inner sub-process is
    routed under the chain and up into the output box."""
    page = PAGES[pk]
    plan = new_plan(pk)
    chain, inputs, output = page["chain"], page["inputs"], page["output"]
    stores, flows = page["stores"], page["flows"]
    nums = [c[0] for c in chain]
    idx_of = {n: i for i, n in enumerate(nums)}

    chain_r = row_rects(nums, L2_CHAIN_Y, L2_CHAIN_H, 34, 210,
                        span=(L2_CHAIN_X0, L2_CHAIN_X1))
    out_r = (L2_OUT_X, L2_OUT_Y, L2_OUT_W, L2_OUT_H)
    n_in = len(inputs)
    in_total = n_in * L2_IN_H + (n_in - 1) * L2_IN_GAP
    in_y0 = L2_CHAIN_CY - in_total / 2.0
    in_r = [(L2_IN_X, in_y0 + i * (L2_IN_H + L2_IN_GAP), L2_IN_W, L2_IN_H)
            for i in range(n_in)]
    store_r = row_rects(stores, L2_STORE_Y, L2_STORE_H, 18, 300)

    def pid(num):
        return "pr_" + num.replace(".", "_")

    def sid(key):
        return "st_" + key

    def sbox(key):
        r = store_r[key]
        return (r[0] + 54, r[1], r[2] - 54, r[3])

    for i, txt in enumerate(inputs):
        r = in_r[i]
        add_box(plan, f"in{i}", "ent", r,
                [(ln, ENT_FS, True, BLACK)
                 for ln in wrap_words(txt, ENT_FS, r[2] - 20, True)])
    for (num, title, bullets) in chain:
        r = chain_r[num]
        lines = [(num, PROC_FS + 1, True, BLACK)]
        lines += [(ln, PROC_FS + 1, True, BLACK)
                  for ln in wrap_words(title, PROC_FS + 1, r[2] - 20, True)]
        for b in bullets:
            lines += [(ln, SUBT_FS, False, "#595959")
                      for ln in wrap_words(b, SUBT_FS, r[2] - 20)]
        add_box(plan, pid(num), "proc", r, lines)
    for key in stores:
        ccode, name = STORES[key]
        r = store_r[key]
        add_box(plan, sid(key), "store", r,
                [(ln, PROC_FS, True, BLACK)
                 for ln in wrap_words(name, PROC_FS, r[2] - 64, True)],
                dnum=ccode)
    add_box(plan, "out", "out", out_r,
            [(ln, ENT_FS + 1, True, BLACK)
             for ln in wrap_words(output, ENT_FS + 1, out_r[2] - 20, True)])

    # ---- classify flows --------------------------------------------------
    items = []
    for (code, s, d) in flows:
        if s == "IN":
            num = d.split(":", 1)[1]
            items.append({"code": code, "kind": "in", "num": num,
                          "src": "trunk", "tgt": pid(num)})
        elif d == "OUT":
            num = s.split(":", 1)[1]
            kind = "out_last" if idx_of[num] == len(nums) - 1 else "out_route"
            items.append({"code": code, "kind": kind, "num": num,
                          "src": pid(num), "tgt": "out"})
        elif s[0] == "S" or d[0] == "S":
            if s[0] == "S":
                skey, num = s.split(":", 1)[1], d.split(":", 1)[1]
                items.append({"code": code, "kind": "sto2proc", "src": "s:" + skey,
                              "tgt": pid(num), "num": num})
            else:
                num, skey = s.split(":", 1)[1], d.split(":", 1)[1]
                items.append({"code": code, "kind": "proc2sto", "src": pid(num),
                              "tgt": "s:" + skey, "num": num})
        else:
            n1, n2 = s.split(":", 1)[1], d.split(":", 1)[1]
            kind = "adj" if idx_of[n2] - idx_of[n1] == 1 else "skip"
            items.append({"code": code, "kind": kind, "src": pid(n1),
                          "tgt": pid(n2)})
    # ---- attachment ports ------------------------------------------------
    rects = {}
    for num in nums:
        rects[pid(num)] = chain_r[num]
    for key in stores:
        rects[sid(key)] = sbox(key)
    rects["out"] = out_r

    def tag_id(tag):
        return sid(tag[2:]) if tag.startswith("s:") else tag

    def tag_rect(tag):
        return rects[tag_id(tag)]

    def cx_of(tag):
        r = tag_rect(tag)
        return r[0] + r[2] / 2.0

    need = {}
    for ii, it in enumerate(items):
        k = it["kind"]
        if k == "sto2proc":
            need.setdefault((it["src"], "T"), []).append((cx_of(it["tgt"]), ii, "s"))
            need.setdefault((it["tgt"], "B"), []).append((cx_of(it["src"]), ii, "d"))
        elif k == "proc2sto":
            need.setdefault((it["src"], "B"), []).append((cx_of(it["tgt"]), ii, "s"))
            need.setdefault((it["tgt"], "T"), []).append((cx_of(it["src"]), ii, "d"))
        elif k == "skip":
            need.setdefault((it["src"], "T"), []).append((cx_of(it["tgt"]), ii, "s"))
            need.setdefault((it["tgt"], "T"), []).append((cx_of(it["src"]), ii, "d"))
        elif k == "out_route":
            need.setdefault((it["src"], "B"), []).append(
                (L2_OUT_X + L2_OUT_W / 2.0, ii, "s"))
            need.setdefault(("out", "B"), []).append((cx_of(it["src"]), ii, "d"))

    pos = {}
    for (tag, side), lst in need.items():
        lst.sort()
        r = tag_rect(tag)
        fr = spread(len(lst))
        for i, (_, ii, end) in enumerate(lst):
            px = r[0] + r[2] * fr[i]
            py = r[1] if side == "T" else r[1] + r[3]
            pos[(ii, end)] = (px, py)

    # ---- lanes -----------------------------------------------------------
    lane = {}
    for kind, band, gap in (("skip", L2_TOPBAND, 34),
                            ("out_route", L2_BACKBAND, 28),
                            ("sto2proc", L2_SBAND, 30),
                            ("proc2sto", L2_SBAND, 30)):
        idx = [ii for ii, it in enumerate(items) if it["kind"] == kind]
        if not idx:
            continue
        ivs = [(min(pos[(ii, "s")][0], pos[(ii, "d")][0]),
                max(pos[(ii, "s")][0], pos[(ii, "d")][0])) for ii in idx]
        ys, n, step = place_lanes(ivs, band, gap)
        for ii, y in zip(idx, ys):
            lane[ii] = y
        plan["stats"][kind] = (n, round(step, 1))

    # ---- wires -----------------------------------------------------------
    for ii, it in enumerate(items):
        k, code = it["kind"], it["code"]
        wid = f"w{ii}"
        if k == "in":
            r = chain_r[it["num"]]
            y = L2_CHAIN_CY
            pts = [(L2_TRUNK_X, y), (r[0], y)]
            add_wire(plan, wid, code, pts, None, pid(it["num"]), None, r)
        elif k == "adj":
            (n1, n2) = (it["src"], it["tgt"])
            pts = [(rects[n1][0] + rects[n1][2], L2_CHAIN_CY),
                   (rects[n2][0], L2_CHAIN_CY)]
            add_wire(plan, wid, code, pts, n1, n2, rects[n1], rects[n2])
        elif k == "out_last":
            r = chain_r[it["num"]]
            pts = [(r[0] + r[2], L2_CHAIN_CY), (L2_OUT_X, L2_CHAIN_CY)]
            add_wire(plan, wid, code, pts, pid(it["num"]), "out", r, out_r)
        elif k == "skip":
            y = lane[ii]
            (sx, _), (tx, _) = pos[(ii, "s")], pos[(ii, "d")]
            pts = [(sx, L2_CHAIN_TOP), (sx, y), (tx, y), (tx, L2_CHAIN_TOP)]
            add_wire(plan, wid, code, pts, it["src"], it["tgt"],
                     rects[it["src"]], rects[it["tgt"]])
        elif k == "out_route":
            y = lane[ii]
            (sx, _), (ox, _) = pos[(ii, "s")], pos[(ii, "d")]
            ob = L2_OUT_Y + L2_OUT_H
            pts = [(sx, L2_CHAIN_BOT), (sx, y), (ox, y), (ox, ob)]
            add_wire(plan, wid, code, pts, it["src"], "out",
                     rects[it["src"]], out_r)
        else:
            y = lane[ii]
            (sx, _), (px, _) = pos[(ii, "s")], pos[(ii, "d")]
            if k == "sto2proc":
                pts = [(sx, L2_STORE_Y), (sx, y), (px, y), (px, L2_CHAIN_BOT)]
            else:
                pts = [(sx, L2_CHAIN_BOT), (sx, y), (px, y), (px, L2_STORE_Y)]
            add_wire(plan, wid, code, pts, tag_id(it["src"]), tag_id(it["tgt"]),
                     tag_rect(it["src"]), tag_rect(it["tgt"]))

    # input collector trunk (drawn without arrowheads, as in the AI figure)
    ys = [in_r[i][1] + in_r[i][3] / 2.0 for i in range(n_in)] + [L2_CHAIN_CY]
    for i in range(n_in):
        y = in_r[i][1] + in_r[i][3] / 2.0
        plan["extras"].append({"id": f"stub{i}",
                               "pts": [(in_r[i][0] + in_r[i][2], y),
                                       (L2_TRUNK_X, y)]})
    plan["extras"].append({"id": "trunk",
                           "pts": [(L2_TRUNK_X, min(ys)),
                                   (L2_TRUNK_X, max(ys))]})

    plan["legend"] = legend_for(pk)
    plan["notes"] = L2_NOTES
    return plan





# ==========================================================================
# Emitters
# ==========================================================================
def text_lines(x, y, w, h, lines):
    """Place a vertically centred block of text lines -> [(t,s,b,c,x,y,w,h)]."""
    lh = max(s for (t, s, b, c) in lines) + 4
    top = y + (h - lh * len(lines)) / 2.0
    return [(t, s, b, c, x, top + i * lh, w, lh)
            for i, (t, s, b, c) in enumerate(lines)]


def legend_layout(legend):
    """(text, x, y) grid rows for the on-page flow legend."""
    n = len(legend)
    cols = max(1, min(3, -(-n // 12)))
    rows = -(-n // cols)
    out = []
    for i, (code, name) in enumerate(legend):
        txt = f"{code} = {name}"
        assert est_w(txt, LEG_FS) <= LEG_COL_W - 6, ("legend too wide", txt)
        out.append((txt, MARGIN + (i // rows) * LEG_COL_W,
                    LEG_TOP + (i % rows) * LEG_ROW))
    return out, rows, cols


def store_frame_cells(bid, dnum, rect):
    """Open-ended store frame: Dn compartment + name field, no values."""
    x, y, w, h = rect
    st = (f"shape=partialRectangle;top=1;left=1;bottom=1;right=0;html=1;"
          f"fontFamily={FONT};fontSize={PROC_FS};fontColor={BLACK};"
          f"fillColor={WHITE};strokeColor={GREY};strokeWidth=1.0;")
    num = (f'<mxCell id="{bid}d" value="{esc(dnum)}" style="{st}" vertex="1" '
           f'parent="1"><mxGeometry x="{x:.0f}" y="{y:.0f}" width="54" '
           f'height="{h:.0f}" as="geometry"/></mxCell>')
    nm = (f'<mxCell id="{bid}" value="" style="{st}" vertex="1" parent="1">'
          f'<mxGeometry x="{x + 54:.0f}" y="{y:.0f}" width="{w - 54:.0f}" '
          f'height="{h:.0f}" as="geometry"/></mxCell>')
    return [num, nm]


def frame_cell(bid, rect):
    x, y, w, h = rect
    st = (f"rounded=1;arcSize=10;html=1;whiteSpace=wrap;"
          f"fontFamily={FONT};fillColor={WHITE};strokeColor={GREY};"
          "strokeWidth=1.0;")
    return (f'<mxCell id="{bid}" value="" style="{st}" vertex="1" parent="1">'
            f'<mxGeometry x="{x:.0f}" y="{y:.0f}" width="{w:.0f}" '
            f'height="{h:.0f}" as="geometry"/></mxCell>')


def frame_cells(plan):
    """Vertex cells for every box in the plan (frames only, no text)."""
    cells, texts = [], []
    for b in plan["boxes"]:
        bid, rect, kind = b["id"], b["rect"], b["kind"]
        x, y, w, h = rect
        if kind == "store":
            cells += store_frame_cells(bid, b["dnum"], rect)
            texts += text_lines(x, y, 54, h, [(b["dnum"], PROC_FS, True, BLACK)])
            texts += text_lines(x + 54, y, w - 54, h, b["lines"])
        else:
            cells.append(frame_cell(bid, rect))
            texts += text_lines(x, y, w, h, b["lines"])
    return cells, texts


# ==========================================================================
# Narrative (shared by the .md and the .docx)
# ==========================================================================
NARR_HEAD = """
# 3.5 Data Flow Diagram

A data flow diagram (DFD) shows how data moves through the system: where it
comes from, which process transforms it, where it is stored, and where it
goes. Two figures are presented. Figure 5 shows the existing system, which is
performed manually by the clinic staff with paper records: an appointment
logbook, a schedule board, filed folders, and reminders sent by call or text.
Figures 6 and 7 show the proposed system, an automated web-based application
that stores the same records in database tables and performs the validation,
scheduling, queueing, and notification steps automatically.

Both figures use the Gane-Sarson notation already adopted in Section 3.4.
Rounded rectangles are processes or external entities, open-ended rectangles
are data stores carrying a Dn number, and thin grey lines are data flows whose
hollow arrowhead points at the destination of the data. Every wire carries a
short code, and the full name of each flow is printed in the legend under the
diagram.
"""

NARR_EX_L1 = """

## 3.5.1 Level 1 Data Flow Diagram of the Existing System

Figure 5 presents the data flow diagram of the existing system of Abalos
Dental Clinic. The existing system is composed of six processes, namely 1.0
Patient Registration and Appointment Booking, 2.0 Appointment Scheduling and
Logbook Recording, 3.0 Dentist Availability and Schedule Management, 4.0
Walk-in Queue Handling, 5.0 Appointment Reminder and Follow-up, and 6.0
Records Filing and Report Preparation, and it exchanges data with four
external entities: the Patient, the Clinic Staff or Front Desk, the Dentist,
and the Clinic Management. All data in the existing system are stored
manually in the Appointment Logbook, the Patient Record Folders, the Dentist
Schedule Board, the Daily Queue List, and the Service and Fee Reference.

In the first process, the Patient supplies the front desk with an appointment
request and personal information such as name, contact number, and dental
concern, either as a walk-in or by phone, together with the fee and service
information read from the Service and Fee Reference. The Clinic Staff records
these into the Patient Record Folders, issues a registration confirmation
back to the Patient, and passes the booking details to the second process.

In the second process, the booking details are checked verbally against the
Dentist Schedule Board and the appointment is written into the Appointment
Logbook. Once the confirmed schedule is received from the third process, an
appointment confirmation slip is released to the Patient; when the requested
time conflicts with an existing entry, a no-slot or conflict notice is
returned instead.

In the third process, the Dentist and the Clinic Staff supply working hours,
availability, and rest days, which are reflected on the Dentist Schedule
Board and read back by the staff to verify every booking, and the confirmed
schedule then flows to the scheduling process.

In the fourth process, the walk-in patient is checked in and assigned a
queue number drawn from the Daily Queue List, the queue lineup for the day is
read from the same list, and the recorded appointment list and queue status
are returned to the Dentist and the Clinic Staff.

In the fifth process, the upcoming appointments recorded in the logbook are
used to send appointment reminders to the Patient by call or text, and the
outcome of each visit, completed or no-show, is forwarded to the records
process.

In the sixth process, the Clinic Management requests reports, and the Clinic
Staff compiles the monthly appointment summary and the no-show list from the
Appointment Logbook and the Patient Record Folders; the monthly summary
report is then returned to the Clinic Management.

The diagram also makes the weaknesses of the manual procedure visible: one
appointment is recorded in more than one place, schedule conflicts can only
be detected by reading the appointment logbook line by line, and no process
evaluates the attendance history of the patient, which is why a no-show is
discovered only when the patient fails to arrive.
"""

NARR_EX_L2 = """## 3.5.2 Level 2 Data Flow Diagram of the Existing Appointment Scheduling Process

Figure 6 decomposes process 2.0 (appointment scheduling and logbook
recording) into five sub-processes numbered 2.1 to 2.5. The external inputs
of the sub-processes are collected on the left of the sheet and the single
output, the appointment slip with its verbal confirmation, is shown on the
right.

The decomposition shows that every booking passes through the same sequence:
the request is received and the details are noted (2.1), the availability of
the dentist is verified from the schedule board (2.2), the logbook is scanned
for a conflicting date and time (2.3), the appointment is written into the
logbook (2.4), and the slip is issued to the patient while a queue number is
given when the patient walked in (2.5). All five sub-processes work on the
same paper records, D1 Appointment Logbook, D2 Patient Record Folders and D3
Dentist Schedule Board. The daily queue list (D4) is used only when a walk-in
is served, and the service and fee reference (D5) is read by sub-process 2.1
when the patient asks for the fee of a service.
"""

NARR_PR_L1A = """

## 3.5.2 Level 1 Data Flow Diagram of the Proposed System

Figures 6 and 7 present the data flow diagram of the proposed automated system
of Abalos Dental Clinic. The proposed system is composed of seven processes
numbered 1.0 to 7.0, and it exchanges data with three external entities: the
Patient, the Dentist, and the Administrator. All data that were written by
hand in the existing system are stored in database tables, namely Users,
Patients, Dentists, Dentist Schedules and Day-Offs, Clinic Configuration,
Queue, Appointment Concerns, Notifications, Admin Notifications,
Appointments, and Medical Records, so the paper logbook, schedule board, and
file folders are no longer used. To keep every flow readable the diagram is
printed on two parts: Figure 6 holds processes 1.0 to 4.0, and Figure 7
holds processes 5.0 to 7.0, with process numbers and data store numbers
continuous across the two parts.

In Figure 6, process 1.0 registers the users of the system
and checks the credentials and the role of the Patient, the Dentist, and the
Administrator at login. Process 2.0 maintains the Patient profile, and
process 3.0 maintains the Dentist profile, the schedules with rest days and
day-offs, and the services and clinic settings such as the operating hours,
the slot interval, and the maximum number of patients per day, all of which
the Administrator maintains.

Process 4.0 is the automated appointment management process. It receives the
authenticated role from process 1.0, the patient identity from process 2.0,
and the availability information from process 3.0, and then validates the
requested dentist, service, date, and time against the schedule and clinic
configuration stores before the booking is accepted, which replaces the
verbal checking and line-by-line logbook reading of the existing system. The
Administrator also maintains the appointment records through this process.
"""

NARR_PR_L1B = """

In Figure 7, process 5.0 handles the walk-in queue and the
concerns of the patients: the check-in creates or finds the patient record
and assigns a queue number, the front desk decides the order of service, and
a concern submitted by the patient is written into the Appointment Concerns
store after the appointment it refers to has been read. Process 6.0 turns
those events into alerts automatically: it writes the patient notifications
and the administrator notifications and reads the appointment records to
compose the text of the confirmation, reminder, and status messages, which
replaces the manual call and text reminders of the existing system.

Process 7.0 assembles the dashboard and the reports by reading the
appointment records, the queue entries, the concerns, the notifications, and
the medical records, and returns the key performance indicators, the charts,
and the exported documents to the Administrator. This process also carries
the machine-learning feature of the system. It reads the historical daily
appointment counts from the Appointments store (D10), excluding cancelled and
no-show records and retaining the days on which no appointment was booked so
that the historical series stays complete, and applies Linear Regression to
the previous 90 days of records to estimate the expected appointment demand
for the following seven days. Each forecast day is rounded to a whole number,
prevented from becoming negative, and classified as Low, Medium, or High
against the historical average, and the resulting forecast is returned to the
Administrator so that the clinic can plan its dentists and its slots ahead of
the coming week. Processes 5.0 and 6.0 are connected directly because a queue
or concern event is what raises an alert, and every other exchange of data
happens through the shared database stores, so the automated system keeps one
copy of each record instead of the several paper copies the existing system
requires.
"""

NARR_PR_L24 = """## 3.5.4 Level 2 Data Flow Diagram of the Proposed Appointment Management Process

Figure 9 decomposes process 4.0 into six sub-processes numbered 4.1 to 4.6,
and shows the data stores the appointment records actually touch: D4 Dentist
Schedules and Day-Offs, D5 Clinic Configuration, D7 Appointment Concerns, D8
Notifications, D9 Admin Notifications and D10 Appointments.

The booking path is 4.1 to 4.2 to 4.3. Sub-process 4.1 builds the list of
available slots for the requested date by reading the schedule (D4), the
clinic configuration (D5) and the existing bookings (D10), so that a rest
day, a day-off, a closed day, a booked slot or the daily limit already
removes a slot before the patient can choose it. Sub-process 4.2 creates the
record in D10 with the status Pending and assigns the reference number of the
appointment. Sub-process 4.3 lets the dentist or the administrator confirm,
complete or cancel the booking and writes the new status back to D10, which
frees the slot when a booking is cancelled.

The remaining sub-processes cover the exceptions. Sub-process 4.4 reschedules
a confirmed booking by validating the new date and time through the same
rules as 4.1 and then writing the updated record to D10. Sub-process 4.5
receives the concern of the patient (D7), raises the administrator
notification (D9) and returns the resolved verdict to 4.3 so that the
appointment can be confirmed or cancelled. Sub-process 4.6 marks the
appointments that were not attended, either automatically after the closing
time or manually by the administrator, writes the No-Show status to D10 and
sends the alert to the patient through D8.
"""

NARR_TAIL = """

## 3.5.3 Notation and Deliverables

The two figures of this section are supplied in three forms so that they can
be reviewed, edited, and pasted into the manuscript. The editable source is
3.5_DFD_Existing.drawio, which holds Figure 5, and 3.5_DFD_Proposed.drawio,
which holds Figure 6 and Figure 7; each page can be opened in draw.io or
in the diagrams.net editor and then exported as PNG or PDF. The Word-ready
preview of each figure is the matching SVG file, for example
3.5_DFD_EX_L1.svg, 3.5_DFD_PR_L1A.svg or 3.5_DFD_PR_L1B.svg, which can be
inserted with Insert > Pictures and keeps its line art at any size. The full
name of every flow code is printed in the legend under the figure, so a wire
can be traced without leaving the page.

The lines are drawn in grey with a hollow arrowhead. A flow that points into
a process or a data store is an input to it, and a flow that points out of it
is an output. A data store is written to when the arrowhead points at the
store and read from when the arrowhead points at the process. Figure 5
describes the manual procedure being replaced, Figures 6 and 7 describe the
automated procedure of the proposed system, and Section 3.6 continues with
the activity diagram of the appointment scheduling process.
"""

PAGE_NARR = {
    "EX_L1": NARR_EX_L1, "PR_L1A": NARR_PR_L1A,
    "PR_L1B": NARR_PR_L1B,
}


def page_detail_lines(pk):
    """Auto-generated process / entity / store / flow index for one page."""
    page = PAGES[pk]
    out = []
    if "procs" in page:
        out.append("**Processes on the sheet.**")
        for (pid, title, sub, bullets) in page["procs"]:
            t = re.sub(r"\s+", " ", title.replace("\n", " "))
            note = sub or ", ".join(bullets or [])
            out.append(f"- {pid} {t} -- {note}.")
        out.append("**External entities.**")
        ents = ", ".join(ENTS[k].replace("\n", " ") for k in page["ents"])
        out.append(f"- {ents}.")
    else:
        out.append("**Sub-processes on the sheet.**")
        for (num, title, bullets) in page["chain"]:
            t = re.sub(r"\s+", " ", title.replace("\n", " "))
            out.append(f"- {num} {t} -- {', '.join(bullets)}.")
        out.append("**External inputs and output.**")
        ins = ", ".join(i.replace("\n", " ") for i in page["inputs"])
        out.append(f"- Inputs: {ins}.")
        out.append(f"- Output: {page['output'].replace(chr(10), ' ')}.")
    out.append("**Data stores.**")
    for key in page["stores"]:
        code, name = STORES[key]
        out.append(f"- {code} {name.replace(chr(10), ' ')}.")
    out.append(f"**Data flows of {page['caption'].split('.')[0]} "
               "in the figure legend.**")
    for (code, name) in legend_for(pk):
        out.append(f"- {code} = {name}.")
    return out


def narrative_md():
    blocks = [NARR_HEAD]
    for pk in PAGE_ORDER:
        blocks.append(PAGE_NARR[pk])
    blocks.append(NARR_TAIL)
    md = "\n\n".join(b.rstrip() for b in blocks).lstrip(chr(10))
    return re.sub(r"\n{3,}", "\n\n", md).rstrip() + "\n"


def nar_paragraphs(md):
    """(kind, text) list for the .docx. Consecutive body lines are joined
    into ONE Word paragraph; blank lines, headings and '-' bullets delimit."""
    paras, buf = [], []

    def flush():
        if buf:
            paras.append(("P", " ".join(buf)))
            buf.clear()

    for line in md.splitlines():
        s = line.strip()
        if not s:
            flush()
            continue
        if s.startswith("# "):
            flush()
            paras.append(("H1", s[2:].strip()))
        elif s.startswith("## "):
            flush()
            paras.append(("H2", s[3:].strip()))
        elif s.startswith("### "):
            flush()
            paras.append(("H3", s[4:].strip()))
        elif s.startswith("- "):
            flush()
            paras.append(("P", re.sub(r"\s+", " ", s[2:]).strip()))
        else:
            buf.append(s)
    flush()
    return paras


def write_docx(path, paras):
    from docx import Document
    from docx.shared import Pt
    from docx.enum.text import WD_ALIGN_PARAGRAPH
    doc = Document()
    normal = doc.styles["Normal"]
    normal.font.name = "Times New Roman"
    normal.font.size = Pt(12)
    normal.paragraph_format.space_after = Pt(6)
    normal.paragraph_format.line_spacing = 1.5
    for (kind, text) in paras:
        if kind == "H1":
            p = doc.add_heading(text, level=1)
        elif kind == "H2":
            p = doc.add_heading(text, level=2)
        elif kind == "H3":
            p = doc.add_heading(text, level=3)
        else:
            p = doc.add_paragraph(text)
            p.alignment = WD_ALIGN_PARAGRAPH.JUSTIFY
        for run in p.runs:
            run.font.name = "Times New Roman"
            run.font.size = Pt(12 if kind == "P" else 14)
    doc.save(path)


# ==========================================================================
# Validation + entry point
# ==========================================================================
OUT = os.path.dirname(os.path.abspath(__file__))
PAGE_ORDER = ["EX_L1", "PR_L1A", "PR_L1B"]
PLANNERS = {"EX_L1": plan_l1, "PR_L1A": plan_l1, "PR_L1B": plan_l1}
DRAWIO_FILES = [("3.5_DFD_Existing.drawio", ["EX_L1"]),
                ("3.5_DFD_Proposed.drawio",
                 ["PR_L1A", "PR_L1B"])]


def overlap(a, b, slack=1.0):
    return (a[0] < b[0] + b[2] - slack and b[0] < a[0] + a[2] - slack and
            a[1] < b[1] + b[3] - slack and b[1] < a[1] + a[3] - slack)


def audit_plan(plan):
    """Geometry + readability audit of one page plan."""
    pk = plan["key"]
    boxes = plan["boxes"]
    for b in boxes:
        x, y, w, h = b["rect"]
        assert 0 <= x and x + w <= PAGE_W, (pk, b["id"], "x bounds")
        assert 0 <= y and y + h <= PAGE_H, (pk, b["id"], "y bounds")
        assert b["lines"], (pk, b["id"], "empty label")
        avail = w - (70 if b["kind"] == "store" else 8)
        for (t, s, bb, c) in b["lines"]:
            assert est_w(t, s, bb) <= avail, (pk, b["id"], "text overflow", t)
    for i in range(len(boxes)):
        for j in range(i + 1, len(boxes)):
            assert not overlap(boxes[i]["rect"], boxes[j]["rect"]), \
                f"{pk}: boxes overlap {boxes[i]['id']} / {boxes[j]['id']}"
    labels = []
    for w in plan["wires"]:
        for (x, y) in w["pts"]:
            assert 0 <= x <= PAGE_W and 0 <= y <= PAGE_H, (pk, w["id"], "point")
        lx, ly = w["label"]
        lw = est_w(w["code"], CODE_FS) + 8
        lh = CODE_FS + 2
        labels.append((w["id"], lx - lw / 2, ly - lh / 2, lw, lh))
    for i in range(len(labels)):
        for j in range(i + 1, len(labels)):
            assert not overlap(labels[i][1:], labels[j][1:]), \
                f"{pk}: flow labels overlap {labels[i][0]} / {labels[j][0]}"
    rows, _r, _c = legend_layout(plan["legend"])
    bottom = LEG_TOP + _r * LEG_ROW + 10 + 13 * len(plan["notes"])
    assert rows and bottom <= CAP_Y - 6, (pk, "legend collides with caption")
    return len(boxes), len(plan["wires"])


def validate_drawio(path, expected):
    import xml.dom.minidom as minidom
    with open(path, "r", encoding="utf-8") as fh:
        raw = fh.read()
    dom = minidom.parseString(raw)
    diagrams = dom.getElementsByTagName("diagram")
    assert len(diagrams) == len(expected), (path, len(diagrams))
    edges_total = 0
    for (tag, plan) in expected:
        node = [d for d in diagrams if d.getAttribute("id") == tag][0]
        cells = node.getElementsByTagName("mxCell")
        edges = [c for c in cells if c.getAttribute("edge") == "1"]
        verts = [c for c in cells if c.getAttribute("vertex") == "1"]
        assert len(edges) == len(plan["wires"]) + len(plan["extras"]), \
            (tag, len(edges))
        for c in edges:
            assert "endArrow=" in c.getAttribute("style"), \
                (tag, "no arrowhead", c.getAttribute("id"))
        for c in edges:
            for g in c.getElementsByTagName("mxGeometry"):
                assert g.getElementsByTagName("Array"), (tag, "no waypoints")
        values = [c.getAttribute("value") for c in verts]
        for w in plan["wires"]:
            assert values.count(w["code"]) == 1, (tag, "label cell", w["code"])
        have = set(v.strip() for v in values)
        for b in plan["boxes"]:
            for (t, s, bb, c) in b["lines"]:
                assert t in have, (tag, "missing text cell", t)
        for (code, name) in plan["legend"]:
            assert f"{code} = {name}" in have, (tag, "legend row", code)
        for c in cells:
            for g in c.getElementsByTagName("mxGeometry"):
                for a in ("x", "y", "width", "height"):
                    if g.hasAttribute(a):
                        float(g.getAttribute(a))
        edges_total += len(edges)
    print(f"[drawio] {os.path.basename(path)}: pages={len(expected)} "
          f"edges={edges_total}")
    return edges_total


def validate_svg(path, plan):
    import xml.dom.minidom as minidom
    with open(path, "r", encoding="utf-8") as fh:
        raw = fh.read()
    assert raw.startswith("<svg") and raw.rstrip().endswith("</svg>")
    dom = minidom.parseString(raw)
    texts = ["".join(n.data for n in t.childNodes if n.nodeType == n.TEXT_NODE)
             for t in dom.getElementsByTagName("text")]
    joined = " " + " ".join(texts) + " "
    for b in plan["boxes"]:
        for (t, s, bb, c) in b["lines"]:
            assert t in joined, (path, "missing text", t)
    for w in plan["wires"]:
        assert f" {w['code']} " in joined, (path, "missing code", w["code"])
    for (code, name) in plan["legend"]:
        assert f"{code} = {name}" in joined, (path, "missing legend", code)
    assert not re.search(r"(?:=| )(?:nan|inf)(?![a-z])", raw.lower()), \
        (path, "non-numeric coordinate")
    paths = dom.getElementsByTagName("path")
    arrowheads = [p for p in paths if p.getAttribute("fill") == "none" and
                  p.getAttribute("stroke-width") == "1.0"]
    print(f"[svg] {os.path.basename(path)}: text={len(texts)} "
          f"paths={len(paths)} wires={len(plan['wires'])}")
    assert len(arrowheads) >= len(plan["wires"]) + len(plan["extras"])
    return len(texts)


def main():
    plans, totals = {}, {}
    for pk in PAGE_ORDER:
        plan = PLANNERS[pk](pk)
        nb, nw = audit_plan(plan)
        plans[pk] = plan
        totals[pk] = (nb, nw, len(plan["legend"]))
    for (fname, tags) in DRAWIO_FILES:
        path = os.path.join(OUT, fname)
        pages = [(t, drawio_cells(plans[t])) for t in tags]
        with open(path, "w", encoding="utf-8", newline="\n") as fh:
            fh.write(drawio_xml(pages))
        validate_drawio(path, [(t, plans[t]) for t in tags])
    for pk in PAGE_ORDER:
        path = os.path.join(OUT, f"3.5_DFD_{pk}.svg")
        with open(path, "w", encoding="utf-8", newline="\n") as fh:
            fh.write(svg_doc(plans[pk]))
        validate_svg(path, plans[pk])

    md = narrative_md()
    md_path = os.path.join(OUT, "3.5_Data_Flow_Diagram_Narrative.md")
    with open(md_path, "w", encoding="utf-8", newline="\n") as fh:
        fh.write(md)
    paras = nar_paragraphs(md)
    write_docx(os.path.join(OUT, "3.5_Data_Flow_Diagram_Narrative.docx"), paras)
    print(f"[narrative] md={len(md)} chars, docx paragraphs={len(paras)}")
    for pk in PAGE_ORDER:
        nb, nw, nl = totals[pk]
        print(f"    {pk:6s} boxes={nb:3d} flows={nw:3d} legend={nl:3d} "
              f"lanes={plans[pk]['stats']}")
    total_flows = sum(t[1] for t in totals.values())
    print(f"OK: pages={len(PAGE_ORDER)} flows={total_flows} "
          f"legend-rows={sum(t[2] for t in totals.values())}")


if __name__ == "__main__":
    main()
