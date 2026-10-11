"""Generate Chapter 3.4 context-diagram assets for the dental AI capstone.

Outputs (into <workspace>/diagrams/):
  3.4_Context_Diagram_Existing.drawio
  3.4_Context_Diagram_Proposed.drawio
  3.4_Context_Diagram_Existing.svg
  3.4_Context_Diagram_Proposed.svg
  3.4_Context_Diagram_Narrative.md
  3.4_Context_Diagram_Narrative.docx

Design targets: A4 landscape draw.io pages, black-and-white line art in the
style of the "Triple J Fitness Center" reference figure (entities as plain
rectangles, one central rounded process bubble with a small "0" tab on top,
orthogonal labelled flows whose labels sit on horizontal segments).

draw.io import notes:
  - File -> Open Library? No. Use Extras -> Edit Diagram to verify the XML.
  - File -> Export As -> PNG / PDF per figure for the manuscript.
  - Arial renders 1:1 everywhere; switch to Times New Roman in draw.io only
    via the Style tab if the adviser requires it.

Standard library only (plus python-docx for the .docx, installed as 1.2.0).
"""
from __future__ import annotations

import html
import os
import re
import sys
import xml.sax.saxutils as sax

# --------------------------------------------------------------------------
# Canvas + shared style constants (A4 landscape in draw.io px)
# --------------------------------------------------------------------------
PAGE_W = 1169
PAGE_H = 827
FONT = "Arial"
LABEL_FONT_SIZE = 11
BOX_FONT_SIZE = 12
STROKE = "black"
FILL = "white"
TEXT_COLOR = "black"
ARROW = "classic"
LINE_W = 1.0

ENTITY_W = 170
ENTITY_H = 86
BAND_COLOR = "white"
CAPTION_SIZE = 14
TITLE_SIZE = 16
SUBTITLE_SIZE = 14

PROC_W = 430
PROC_H = 108

# Label + layout policy: short codes on the wires + full legend table.
# This is the ONLY arrangement that fits 31 full thesis-grade flow names
# on one A4-landscape context diagram without overlap.
LABEL_FS = 10
LABEL_LH = 13            # SVG line height for 10pt labels
LEFT_TOP = 118
SHORT_FONT = 11
LEGEND_FS = 10
LEGEND_LH = 13
LEGEND_COL_W = 545
LANE_L0, LANE_L1 = 238, 372
LANE_R0, LANE_R1 = 797, 931


# --------------------------------------------------------------------------
# Diagram contents (locked 32-flow plan)
# --------------------------------------------------------------------------

EXISTING_TITLE = "3.4 CONTEXT DIAGRAM"
EXISTING_SUB = "EXISTING"
EXISTING_PROC = (
    "Existing Manual Appointment\n"
    "Scheduling Process of\n"
    "Abalos Dental Clinic"
)
EXISTING_CAPTION = "Figure 3. Context Diagram of the Existing System"
EXISTING_ENTITIES = ("PATIENT", "CLINIC STAFF / FRONT DESK", "DENTIST")

# Short figure codes per flow: (label, direction, entity, code).
EXISTING_FLOWS = [
    # PATIENT group (entity idx 0)
    ("E1", "Appointment Request (Walk-in / Phone)", ">", 0),
    ("E2", "Patient Information (Name, Contact, Dental Concern)", ">", 0),
    ("E3", "Reschedule or Cancellation Request", ">", 0),
    ("E4", "Service and Fee Inquiry", ">", 0),
    ("E5", "Appointment Confirmation (Appointment Slip)", "<", 0),
    ("E6", "Queue Number (Walk-in)", "<", 0),
    ("E7", "Appointment Reminder (Call / Text)", "<", 0),
    ("E8", "No-Slot or Conflict Notice", "<", 0),
    # CLINIC STAFF group (entity idx 1)
    ("E9", "Walk-in Registration Details", ">", 1),
    ("E10", "Appointment Logbook Records", ">", 1),
    ("E11", "Dentist Availability (Rest Day / Day-Off)", ">", 1),
    ("E12", "Service and Fee Information", ">", 1),
    ("E13", "Daily Appointment List / Schedule Board", "<", 1),
    ("E14", "Patient Record Files", "<", 1),
    ("E15", "Monthly Appointment Summary", "<", 1),
    ("E16", "No-Show List", "<", 1),
    ("E17", "Queue Status", "<", 1),
    # DENTIST group (entity idx 2)
    ("E18", "Working Hours and Availability", ">", 2),
    ("E19", "Treatment / Service Duration", ">", 2),
    ("E20", "Appointment Status (Completed / No-Show)", ">", 2),
    ("E21", "Treatment Notes", ">", 2),
    ("E22", "Daily Schedule and Patient List", "<", 2),
    ("E23", "Patient Dental History", "<", 2),
    ("E24", "Appointment Confirmation Details", "<", 2),
]

PROPOSED_TITLE = "3.4 CONTEXT DIAGRAM"
PROPOSED_SUB = "PROPOSED"
PROPOSED_PROC = (
    "AI-Based Intelligent Dental\n"
    "Appointment Scheduling System\n"
    "Using Machine Learning for\n"
    "Optimized Clinic Management"
)
PROPOSED_CAPTION = "Figure 4. Context Diagram of the Proposed System"
PROPOSED_ENTITIES = ("PATIENT", "DENTIST", "ADMINISTRATOR")
PROPOSED_FLOWS = [
    ("P1", "Registration and Login Credentials", ">", 0),
    ("P2", "Patient Profile Information", ">", 0),
    ("P3", "Appointment Request (Dentist, Service, Date, Time)", ">", 0),
    ("P4", "Walk-in QR Check-in", ">", 0),
    ("P5", "Appointment Concern (Reschedule / Cancel Request)", ">", 0),
    ("P6", "Appointment Confirmation and Reference Number", "<", 0),
    ("P7", "Available Schedule Slots", "<", 0),
    ("P8", "Appointment Notification and Reminder", "<", 0),
    ("P9", "Appointment Status (Confirmed, Rescheduled, Cancelled, Completed, No-Show)", "<", 0),
    ("P10", "Queue Number and Status", "<", 0),
    ("P11", "Updated Patient Record", "<", 0),
    ("P12", "Login Credentials", ">", 1),
    ("P13", "Availability and Working Schedule", ">", 1),
    ("P14", "Rest Days and Day-Offs", ">", 1),
    ("P15", "Appointment Status Update", ">", 1),
    ("P16", "Treatment Notes / Medical Record Entry", ">", 1),
    ("P17", "Daily Appointment Schedule and Patient List", "<", 1),
    ("P18", "Patient Dental History and Medical Records", "<", 1),
    ("P19", "Appointment Notifications", "<", 1),
    ("P20", "Appointment Reschedule Concerns", "<", 1),
    ("P21", "User Accounts and Role Management", ">", 2),
    ("P22", "Patient and Dentist Records", ">", 2),
    ("P23", "Dentist Schedules, Rest Days, Day-Offs", ">", 2),
    ("P24", "Services and Clinic Settings (Operating Hours, Slot Interval, Max Patients per Day)", ">", 2),
    ("P25", "Appointment Monitoring and Confirmation", ">", 2),
    ("P26", "Concern Resolution", ">", 2),
    ("P27", "Dashboard Summary and KPIs", "<", 2),
    ("P28", "Appointment Reports (Volume, Attendance, No-Show Patterns)", "<", 2),
    ("P29", "Seven-Day Demand Forecast (Low / Medium / High)", "<", 2),
    ("P30", "Queue Monitoring Information", "<", 2),
    ("P31", "Notification and System Logs", "<", 2),
]

assert len(EXISTING_FLOWS) == 24, len(EXISTING_FLOWS)
assert len(PROPOSED_FLOWS) == 31, len(PROPOSED_FLOWS)
assert len(EXISTING_FLOWS) + len(PROPOSED_FLOWS) == 55
assert all(len(t) == 4 for t in EXISTING_FLOWS + PROPOSED_FLOWS)
assert [t[0] for t in EXISTING_FLOWS] == [f"E{i}" for i in range(1, 25)]
assert [t[0] for t in PROPOSED_FLOWS] == [f"P{i}" for i in range(1, 32)]


# --------------------------------------------------------------------------
# Layout engine: fixed A4-landscape coordinates, two-column flow plan.
# --------------------------------------------------------------------------

def label_lines(label, width=24):
    """Greedy wrap to <=width chars per line."""
    words, lines, cur = str(label).split(), [], ""
    for w in words:
        t = (cur + " " + w).strip()
        if len(t) <= width or not cur:
            cur = t
        else:
            lines.append(cur)
            cur = w
    if cur:
        lines.append(cur)
    # Re-split any overlong remainder on word boundaries.
    out = []
    for ln in lines:
        while len(ln) > width and " " in ln.strip():
            cut = ln[:width].rsplit(" ", 1)[0]
            out.append(cut)
            ln = ln[len(cut):].strip()
        out.append(ln)
    return out


def label_block_h(label):
    return len(label_lines(label)) * LABEL_LH


def entity_rows(entities):
    """(x, y, w, h) per entity. Left column stacked; last entity on right.

    Left boxes are tall on purpose: each entity-side port needs a unique
    slot so the entity stub never shares a y with another stub.
    """
    n_left = len(entities) - 1
    gap = 34
    eh = (PAGE_H - LEFT_TOP - 88 - gap * (n_left - 1)) // max(n_left, 1)
    rows, top = [], LEFT_TOP
    for _ in range(n_left):
        rows.append((46, top, ENTITY_W, eh))
        top += eh + gap
    rows.append((PAGE_W - 46 - ENTITY_W, 322, ENTITY_W, 132))
    return rows


def plan_ports(triples, rows, proc_x, proc_y):
    """Numeric routing plan shared by both emitters.

    Wires carry SHORT CODES only (E1.. / P1..). Each column gets evenly
    spread entity-side slots, vertical trunk lanes, and process-side
    anchor rows. Returns {flow: dict(lane, y, slot, code)}.
    """
    slots = {}
    for eidx in range(len(rows)):
        same = [i for i, (_, _, _, e) in enumerate(triples) if e == eidx]
        span = rows[eidx][3] - 18
        for k, i in enumerate(same):
            if len(same) == 1:
                slots[i] = rows[eidx][1] + rows[eidx][3] // 2
            else:
                slots[i] = int(rows[eidx][1] + 9
                               + span * k / (len(same) - 1))
    plan = {}
    for side, lane_pair in (("L", (LANE_L0, LANE_L1)),
                            ("R", (LANE_R0, LANE_R1))):
        idxs = [i for i, (_, _, _, e) in enumerate(triples)
                if (e in (0, 1)) == (side == "L")]
        for k, i in enumerate(idxs):
            span = PROC_H - 24
            y = proc_y + PROC_H // 2 if len(idxs) == 1 else int(
                proc_y + 12 + span * k / (len(idxs) - 1))
            plan[i] = {"side": side, "lane": lane_pair[k % 2], "y": y,
                       "code": triples[i][0], "slot": slots[i]}
    return plan, slots


def slot_ys(flow_idx, entity_idx, triples, rows):
    """Evenly spread entity-side ports along the inner edge of an entity."""
    (_, y, _, h) = rows[entity_idx]
    same = [i for i, (_, _, e) in enumerate(triples) if e == entity_idx]
    k = same.index(flow_idx)
    span = max(h - 16, 10)
    if len(same) == 1:
        return y + h // 2
    return int(y + 8 + span * k / (len(same) - 1))


def proc_slot_y(flow_idx, side, triples, proc_y):
    """Evenly spread process-side ports on one edge of the process bubble."""
    same = [i for i, (_, _, s) in enumerate(triples) if s == side]
    k = same.index(flow_idx)
    span = PROC_H - 20
    if len(same) == 1:
        return proc_y + PROC_H // 2
    return int(proc_y + 10 + span * k / (len(same) - 1))


# --------------------------------------------------------------------------
# draw.io (mxGraph plain XML) builder
# --------------------------------------------------------------------------

def text_cell(cid, text, x, y, w, h, size, bold=False, italic=False):
    st = ("rounded=0;html=1;whiteSpace=wrap;fillColor=none;strokeColor=none;"
          f"fontFamily={FONT};fontSize={size};fontColor={TEXT_COLOR};"
          "align=center;verticalAlign=middle;")
    if bold and italic:
        st += "fontStyle=3;"
    elif bold:
        st += "fontStyle=1;"
    elif italic:
        st += "fontStyle=2;"
    return (f'<mxCell id="{cid}" value="{esc(text)}" style="{st}" '
            f'vertex="1" parent="1"><mxGeometry x="{x}" y="{y}" '
            f'width="{w}" height="{h}" as="geometry"/></mxCell>')


def box_cell(cid, text, x, y, w, h, size, rounded=0, bold=False):
    st = (f"rounded={rounded};whiteSpace=wrap;html=1;fontFamily={FONT};"
          f"fontSize={size};fontColor={TEXT_COLOR};fillColor={FILL};"
          f"strokeColor={STROKE};strokeWidth={LINE_W};align=center;"
          "verticalAlign=middle;")
    if bold:
        st += "fontStyle=1;"
    return (f'<mxCell id="{cid}" value="{esc(text)}" style="{st}" '
            f'vertex="1" parent="1"><mxGeometry x="{x}" y="{y}" '
            f'width="{w}" height="{h}" as="geometry"/></mxCell>')


def edge_cell(eid, label, src, tgt, pts,
              exit_x, exit_y, entry_x, entry_y):
    """Orthogonal edge through waypoints pts=[(x,y)...]; label sits
    centered on the middle (horizontal) segment with a white halo."""
    st = ("edgeStyle=orthogonalEdgeStyle;rounded=0;orthogonalLoop=1;"
          "jettySize=auto;html=1;whiteSpace=wrap;"
          f"fontFamily={FONT};fontSize={LABEL_FS};"
          f"fontColor={TEXT_COLOR};labelBackgroundColor={FILL};"
          f"strokeColor={STROKE};strokeWidth={LINE_W};"
          f"endArrow={ARROW};endFill=0;"
          f"exitX={exit_x:.4f};exitY={exit_y:.4f};exitDx=0;exitDy=0;"
          f"entryX={entry_x:.4f};entryY={entry_y:.4f};entryDx=0;entryDy=0;")
    inner = "".join(f'<mxPoint x="{x}" y="{y}"/>' for (x, y) in pts[1:-1])
    return (f'<mxCell id="{eid}" value="{esc(label)}" style="{st}" '
            f'edge="1" parent="1" source="{src}" target="{tgt}">'
            f'<mxGeometry relative="1" as="geometry">'
            f'<mxPoint x="{pts[0][0]}" y="{pts[0][1]}" as="sourcePoint"/>'
            f'<mxPoint x="{pts[-1][0]}" y="{pts[-1][1]}" as="targetPoint"/>'
            f'<Array as="points">{inner}</Array>'
            f'</mxGeometry></mxCell>')


def port_map(triples, proc_y):
    """flow idx -> ('L'|'R', process-side y). Left set = entities 0,1."""
    left = [i for i in range(len(triples)) if triples[i][2] in (0, 1)]
    right = [i for i in range(len(triples)) if triples[i][2] == 2]
    out = {}
    span = PROC_H - 20
    for _, lst in (("L", left), ("R", right)):
        for k, i in enumerate(lst):
            y = proc_y + PROC_H // 2 if len(lst) == 1 else int(
                proc_y + 10 + span * k / (len(lst) - 1))
            out[i] = y
    return out, left, right


# --------------------------------------------------------------------------
# Page assembler: roadmap routing with per-edge horizontal lanes.
# --------------------------------------------------------------------------

def build_cells(title, sub, proc_text, entities, triples, caption):
    rows = entity_rows(entities)
    proc_x = (PAGE_W - PROC_W) // 2
    proc_y = 340
    zero_x = proc_x + PROC_W // 2 - 14

    cells = []
    cells.append(text_cell("title", title, 46, 24, PAGE_W - 92, 28,
                           TITLE_SIZE, bold=True))
    cells.append(text_cell("subtitle", sub, 46, 54, PAGE_W - 92, 26,
                           SUBTITLE_SIZE, bold=True))
    ent_ids = []
    for idx, name in enumerate(entities):
        (x, y, w, h) = rows[idx]
        eid = f"ent{idx}"
        ent_ids.append(eid)
        cells.append(box_cell(eid, name, x, y, w, h, BOX_FONT_SIZE,
                              rounded=0, bold=True))
    cells.append(box_cell("zero", "0", zero_x, proc_y - 34, 28, 28,
                          BOX_FONT_SIZE, rounded=1, bold=True))
    cells.append(box_cell("proc", proc_text, proc_x, proc_y, PROC_W,
                          PROC_H, BOX_FONT_SIZE, rounded=1))

    plan, _ = plan_ports(triples, rows, proc_x, proc_y)
    for i, (code, label, direction, eidx) in enumerate(triples):
        g = plan[i]
        (ex, ey, ew, eh) = rows[eidx]
        y = g["y"]
        lane = g["lane"]
        slot = g["slot"]
        if eidx in (0, 1):
            xx, xy, nx, ny = 1.0, 0.5, 0.0, 0.5
            if direction == ">":
                pts = [(ex + ew, slot), (lane, slot), (lane, y),
                       (proc_x, y)]
                src, tgt = ent_ids[eidx], "proc"
            else:
                pts = [(proc_x, y), (lane, y), (lane, slot),
                       (ex + ew, slot)]
                src, tgt = "proc", ent_ids[eidx]
        else:
            xx, xy, nx, ny = 0.0, 0.5, 1.0, 0.5
            if direction == ">":
                pts = [(ex, slot), (lane, slot), (lane, y),
                       (proc_x + PROC_W, y)]
                src, tgt = ent_ids[eidx], "proc"
            else:
                pts = [(proc_x + PROC_W, y), (lane, y), (lane, slot),
                       (ex, slot)]
                src, tgt = "proc", ent_ids[eidx]
        cells.append(edge_cell(f"e{i}", code, src, tgt, pts, xx, xy,
                               nx, ny))
    cells.extend(legend_block_cells(triples))
    cells.append(text_cell("cap", caption, 46, PAGE_H - 56, PAGE_W - 92,
                           28, CAPTION_SIZE, italic=True))
    return cells


# --------------------------------------------------------------------------
# Emitters: .drawio (mxfile) + .svg mirror + validation
# --------------------------------------------------------------------------

def drawio_xml(diagram_name, cells):
    body = ['<mxfile host="app.diagrams.net">']
    body.append(
        f'<diagram id="{diagram_name}" name="{esc(diagram_name)}">'
        '<mxGraphModel dx="1169" dy="827" grid="1" gridSize="10" '
        'guides="1" tooltips="1" connect="1" arrows="1" fold="1" '
        'page="1" pageScale="1" pageWidth="1169" pageHeight="827" '
        'math="0" shadow="0">'
        '<root><mxCell id="0"/><mxCell id="1" parent="0"/>')
    body.extend(cells)
    body.append('</root></mxGraphModel></diagram></mxfile>')
    return "".join(body)


def esc_svg(text):
    return sax.escape(text if text is not None else "").replace('"', "&quot;")


def svg_doc(title, sub, proc_text, entities, triples, caption):
    """Manual line-art mirror of the plan (labels with white halo)."""
    rows = entity_rows(entities)
    proc_x = (PAGE_W - PROC_W) // 2
    proc_y = 340
    parts = [f'<svg xmlns="http://www.w3.org/2000/svg" '
             f'width="{PAGE_W}" height="{PAGE_H}" '
             f'viewBox="0 0 {PAGE_W} {PAGE_H}">']
    parts.append('<rect x="0" y="0" width="1169" height="827" fill="white"/>')
    parts.append(
        f'<text x="{PAGE_W/2:.0f}" y="46" text-anchor="middle" '
        f'font-family="Arial" font-size="16" font-weight="bold" '
        f'fill="black">{esc_svg(title)}</text>')
    parts.append(
        f'<text x="{PAGE_W/2:.0f}" y="76" text-anchor="middle" '
        f'font-family="Arial" font-size="14" font-weight="bold" '
        f'fill="black">{esc_svg(sub)}</text>')
    for idx, name in enumerate(entities):
        (x, y, w, h) = rows[idx]
        parts.append(
            f'<rect x="{x}" y="{y}" width="{w}" height="{h}" fill="white" '
            'stroke="black" stroke-width="1"/>')
        words = label_lines(name)
        ty = y + h / 2 - 8 * (len(words) - 1)
        for wline in words:
            parts.append(
                f'<text x="{x + w/2:.0f}" y="{ty + 4:.0f}" '
                'text-anchor="middle" font-family="Arial" font-size="12" '
                f'font-weight="bold" fill="black">{esc_svg(wline)}</text>')
            ty += 16
    zero_x = proc_x + PROC_W // 2 - 14
    parts.append(
        f'<rect x="{zero_x}" y="{proc_y - 34}" width="28" height="28" rx="6" '
        'fill="white" stroke="black" stroke-width="1"/>')
    parts.append(
        f'<text x="{zero_x + 14}" y="{proc_y - 12}" text-anchor="middle" '
        'font-family="Arial" font-size="12" font-weight="bold" '
        'fill="black">0</text>')
    parts.append(
        f'<rect x="{proc_x}" y="{proc_y}" width="{PROC_W}" height="{PROC_H}" '
        'rx="12" fill="white" stroke="black" stroke-width="1"/>')
    plines = proc_text.split("\n")
    pty = proc_y + PROC_H / 2 - 8 * (len(plines) - 1)
    for pl in plines:
        parts.append(
            f'<text x="{proc_x + PROC_W/2:.0f}" y="{pty + 4:.0f}" '
            'text-anchor="middle" font-family="Arial" font-size="12" '
            f'fill="black">{esc_svg(pl.strip())}</text>')
        pty += 16
    plan, _ = plan_ports(triples, rows, proc_x, proc_y)
    for i, (code, label, direction, eidx) in enumerate(triples):
        g = plan[i]
        (ex, ey, ew, eh) = rows[eidx]
        y, lane, slot = g["y"], g["lane"], g["slot"]
        if eidx in (0, 1):
            if direction == ">":
                x1, y1, xk, yk = ex + ew, slot, proc_x, y
            else:
                x1, y1, xk, yk = proc_x, y, ex + ew, slot
        else:
            if direction == ">":
                x1, y1, xk, yk = ex, slot, proc_x + PROC_W, y
            else:
                x1, y1, xk, yk = proc_x + PROC_W, y, ex, slot
        parts.append(
            f'<path d="M {x1} {y1} L {lane} {y1} L {lane} {yk} '
            f'L {xk} {yk}" fill="none" stroke="black" stroke-width="1"/>')
        parts.append(arrow_head(xk, yk, "E" if xk > lane else "W"))
        parts.append(code_svg(g["code"], lane, xk, yk, eidx))
    parts.extend(legend_svg(triples, entities))
    parts.append(
        f'<text x="{PAGE_W/2:.0f}" y="{PAGE_H - 30}" text-anchor="middle" '
        'font-family="Arial" font-size="14" font-style="italic" '
        f'fill="black">{esc_svg(caption)}</text>')
    parts.append('</svg>')
    return "".join(parts)


def wrap_words(text, maxlen=22):
    words = text.split()
    lines, cur = [], ""
    for w in words:
        trial = (cur + " " + w).strip()
        if len(trial) <= maxlen or not cur:
            cur = trial
        else:
            lines.append(cur)
            cur = w
    if cur:
        lines.append(cur)
    return lines


def svg_label_lines(label, maxlen=26):
    """Wrap long labels so every figure label fits on its horizontal run."""
    return wrap_words(label, maxlen)


def code_svg(code, lane, xk, yk, eidx):
    """Short code glyph on the main horizontal run with a white halo."""
    if eidx in (0, 1):
        x0, x1 = lane, xk
    else:
        x0, x1 = xk, lane
    cx = (x0 + x1) / 2
    wpx = max(30, len(code) * 7 + 12)
    parts = [f'<rect x="{cx - wpx/2:.0f}" y="{yk - 9:.0f}" '
             f'width="{wpx:.0f}" height="18" fill="white"/>',
             f'<text x="{cx:.0f}" y="{yk + 4:.0f}" text-anchor="middle" '
             'font-family="Arial" font-size="11" font-weight="bold" '
             f'fill="black">{esc_svg(code)}</text>']
    return "".join(parts)


def legend_svg(triples, entities):
    """Two-column full-name legend (code -> full data-flow name) drawn in
    the lower band, clear of wires and entity boxes."""
    ent_names = {0: entities[0], 1: entities[1], 2: entities[2]}
    rows_out = []
    for (code, name, direction, eidx) in triples:
        arrow = "\u2192 process" if direction == ">" else "process \u2192"
        rows_out.append(f"{code} = {name}  [{ent_names[eidx]} {arrow}]")
    half = (len(rows_out) + 1) // 2
    cols = [rows_out[:half], rows_out[half:]]
    out = []
    base_y = PAGE_H - 56 - max(len(cols[0]), len(cols[1])) * LEGEND_LH
    for c, col in enumerate(cols):
        x = 56 + c * (LEGEND_COL_W + 20)
        y = base_y
        for row in col:
            line = esc_svg(row)
            while len(row) > 88:
                cut = row[:88].rsplit(" ", 1)[0]
                out.append(
                    f'<text x="{x}" y="{y:.0f}" font-family="Arial" '
                    f'font-size="{LEGEND_FS}" fill="black">'
                    f'{esc_svg(cut)}</text>')
                row = row[len(cut):].strip()
                y += LEGEND_LH
            out.append(
                f'<text x="{x}" y="{y:.0f}" font-family="Arial" '
                f'font-size="{LEGEND_FS}" fill="black">'
                f'{esc_svg(row)}</text>')
            y += LEGEND_LH
    return out


def audit_layout(triples, entities, tag):
    """Numeric overlap audit: legend band must clear wires/entities."""
    rows = entity_rows(entities)
    proc_x = (PAGE_W - PROC_W) // 2
    proc_y = 340
    plan, _ = plan_ports(triples, rows, proc_x, proc_y)
    wires_bottom = max(g["y"] for g in plan.values())
    slots_bottom = max(g["slot"] for g in plan.values())
    ent_bottom = max(y + h for (_, y, _, h) in rows)
    n_rows = max((len(triples) + 1) // 2, 1)
    legend_top = PAGE_H - 56 - n_rows * LEGEND_LH - 6
    proc_bottom = proc_y + PROC_H
    print(f"[audit:{tag}] wires_bottom={wires_bottom:.0f} "
          f"slots_bottom={slots_bottom:.0f} ent_bottom={ent_bottom} "
          f"proc_bottom={proc_bottom} legend_top={legend_top:.0f}")
    assert wires_bottom < legend_top, "wire lane hits legend band"
    assert proc_bottom < legend_top, "process bubble hits legend band"
    assert ent_bottom < PAGE_H - 56 - 14, "entity box hits caption"
    return legend_top


def label_svg_plan(label, lane, xk, yk, eidx):
    """Place the wrapped label on the longest horizontal segment.

    [(entity stub) -> lane -> (main run) -> anchor] so for a ">" edge the
    main run is [lane, anchor] at y=yk, and for a "<" edge it is
    [anchor, lane] at y=yk. Column text flows toward the page edge:
    left column right-aligned at lane, right column left-aligned at lane.
    A white halo rect is painted under the glyphs on its own sub-layer.
    """
    lines = label_lines(label)
    widest = max(len(ln) for ln in lines)
    wpx = widest * EST_CHAR + 2 * LABEL_PAD
    hpx = len(lines) * LABEL_LH + 2 * LABEL_PAD
    if eidx in (0, 1):
        x0 = lane - wpx
    else:
        x0 = lane
    y0 = yk - hpx / 2
    out = [f'<rect x="{x0:.0f}" y="{y0:.0f}" width="{wpx:.0f}" '
           f'height="{hpx:.0f}" fill="white"/>']
    ty = yk - (len(lines) - 1) * LABEL_LH / 2
    for ln in lines:
        out.append(
            f'<text x="{(x0 + wpx/2):.0f}" y="{ty + 3:.0f}" '
            'text-anchor="middle" font-family="Arial" '
            f'font-size="{LABEL_FS}" fill="black">{esc_svg(ln)}</text>')
        ty += LABEL_LH
    return "".join(out)


def arrow_head(x, y, direction):
    s = 7
    if direction == "E":
        return (f'<path d="M {x} {y} L {x - s} {y - 3} L {x - s} {y + 3} Z" '
                'fill="black"/>')
    return (f'<path d="M {x} {y} L {x + s} {y - 3} L {x + s} {y + 3} Z" '
            'fill="black"/>')


def label_cell_plan(cid, label, lane, xk, yk, eidx):
    """draw.io twin of label_svg_plan: centered wrapped label (white halo)
    positioned on the main horizontal run."""
    text = " | ".join(label_lines(label))
    wpx = max(60, len(text) * EST_CHAR + 2 * LABEL_PAD)
    hpx = LABEL_LH + 10
    if eidx in (0, 1):
        x0 = lane - wpx
    else:
        x0 = lane
    y0 = yk - hpx / 2
    st = ("rounded=0;html=1;whiteSpace=wrap;fillColor=white;strokeColor=none;"
          f"fontFamily={FONT};fontSize={LABEL_FS};fontColor={TEXT_COLOR};"
          "align=center;verticalAlign=middle;")
    return (f'<mxCell id="{cid}" value="{esc(text)}" style="{st}" '
            f'vertex="1" parent="1"><mxGeometry x="{x0:.0f}" y="{y0:.0f}" '
            f'width="{wpx:.0f}" height="{hpx:.0f}" as="geometry"/></mxCell>')


def label_svg(label, x, y):
    lines = svg_label_lines(label)
    h = 14 * len(lines)
    top = y - h / 2 - 3
    out = [f'<rect x="{x - 3:.0f}" y="{top:.0f}" width="6" height="{h + 6:.0f}" '
           'fill="white"/>']
    ty = y - 7 * (len(lines) - 1) + 4
    for ln in lines:
        out.append(
            f'<text x="{x:.0f}" y="{ty:.0f}" text-anchor="middle" '
            'font-family="Arial" font-size="11" fill="black">'
            f'{esc_svg(ln)}</text>')
        ty += 14
    return "".join(out)


def esc(text):
    return sax.escape(text if text is not None else "",
                       {'"': "&quot;"})


def legend_block_cells(triples):
    """Hidden carrier cells so the full flow names live inside the .drawio
    file (searchable/copyable); the visible legend is drawn in the SVG
    and documented in the .md/.docx narrative."""
    cells = []
    for (code, name, direction, eidx) in triples:
        cells.append(
            f'<mxCell id="leg{code}" value="{esc(code + " = " + name)}" '
            'style="rounded=0;html=1;whiteSpace=wrap;fillColor=none;'
            'strokeColor=none;fontFamily=Arial;fontSize=1;fontColor=white;" '
            'vertex="1" parent="1"><mxGeometry x="-100" y="-100" width="10" '
            'height="10" as="geometry"/></mxCell>')
    return cells


NARR_MD_EXIST = """# 3.4 Context Diagram

A context diagram is the highest-level data flow diagram of a system. It
represents the whole system as a single process, numbered 0, and shows only
the external entities that exchange data with it and the data flows that move
between them. No internal processes, data stores, or files appear at this
level; those details are decomposed in the data flow diagrams of Section 3.5.
Every flow carries an arrowhead at its destination: a flow pointing into the
process is an input, and a flow pointing out of the process is an output.

## 3.4.1 Context Diagram of the Existing System

Figure 3 presents the context diagram of the existing manual appointment
scheduling process of Abalos Dental Clinic. The single process, labelled 0,
stands for the entire manual workflow, and it exchanges data with three
external entities: PATIENT, CLINIC STAFF / FRONT DESK, and DENTIST.

The PATIENT initiates the workflow by submitting an appointment request
either as a walk-in or by phone, together with patient information such as
name, contact number, and dental concern. The same entity sends reschedule
or cancellation requests when the patient cannot attend, and service and fee
inquiries before booking. In return, the manual process issues the outputs
the patient takes home: an appointment confirmation written on an
appointment slip, a queue number for walk-in patients, an appointment
reminder delivered by call or text message, and a no-slot or conflict notice
whenever the requested schedule is unavailable.

The CLINIC STAFF / FRONT DESK entity feeds the process with the records it
maintains by hand: walk-in registration details, appointment logbook
records, dentist availability information such as rest days and day-offs,
and service and fee information given to patients. The process returns the
working documents the staff uses to run the clinic day to day: the daily
appointment list or schedule board, the patient record files, the monthly
appointment summary used for reporting, the no-show list of patients who
failed to attend, and the queue status of waiting patients.

The DENTIST provides working hours and availability, the treatment or
service duration used to size each appointment, the appointment status after
each visit (completed or no-show), and the treatment notes written during
consultation. The process returns the dentist's daily schedule and patient
list, the dental history of scheduled patients, and the confirmation details
of each booking.

Figure 3 therefore shows why the manual process is difficult to scale.
Appointments live in a logbook, dentist availability is confirmed verbally,
schedule conflicts and no-shows surface only after the fact, and there is no
automatic forecast of coming demand. These limitations motivate the proposed
system of the next section, in which the same three roles interact with a
centralized web-based system instead of with paper records.
"""


NARR_MD_PROP = """## 3.4.2 Context Diagram of the Proposed System

Figure 4 presents the context diagram of the proposed AI-Based Intelligent
Dental Appointment Scheduling System Using Machine Learning for Optimized
Clinic Management. The single process, labelled 0, stands for the whole
web-based system, and it exchanges data with three external entities:
PATIENT, DENTIST, and ADMINISTRATOR.

The PATIENT sends registration and login credentials, patient profile
information, and appointment requests that specify the dentist, service,
date, and time. Walk-in patients check in through the QR code flow of
walkin_qr.php, and any patient who cannot attend submits an appointment
concern that carries a reschedule or cancel request. In return, the system
returns an appointment confirmation with a reference number, the available
schedule slots computed by get_available_slots.php, appointment
notifications and reminders shown in the patient dashboard, the appointment
status drawn from the controlled vocabulary Pending, Confirmed, Rescheduled,
Cancelled, Completed, and No-Show, the queue number and queue status
assigned to walk-ins, and the updated patient record.

The DENTIST sends login credentials, availability and working schedule,
rest days and specific day-offs, appointment status updates after each
visit, and treatment notes or medical record entries. The system returns the
daily appointment schedule and patient list, the dental history and medical
records of scheduled patients, appointment notifications, and the
appointment reschedule concerns submitted by patients.

The ADMINISTRATOR sends user accounts and role management data, patient and
dentist records, dentist schedules with rest days and day-offs, services and
clinic settings such as operating hours, slot interval, and maximum patients
per day, appointment monitoring and confirmation actions, and concern
resolutions. The system returns the dashboard summary and key performance
indicators, appointment reports covering volume, attendance, and no-show
patterns, the seven-day demand forecast classified as Low, Medium, or High,
queue monitoring information, and the notification and system logs.

Because every role works through the same centralized process, the
administrator, dentist, and patient always act on the same schedule data,
the same appointment statuses, and the same forecast information. That
shared view is what the proposed context diagram in Figure 4 documents, and
Section 3.5 decomposes it into the internal processes of the system.
"""


def narrative_paragraphs():
    """(kind, text) list driving the .docx writer.

    Source strings are hard-wrapped for .md readability, so consecutive
    non-blank body lines are joined into ONE Word paragraph; blank lines
    and #-headings delimit paragraphs.
    """
    paras, buf = [], []

    def flush():
        if buf:
            paras.append(("P", " ".join(buf)))
            buf.clear()

    for block in (NARR_MD_EXIST, NARR_MD_PROP):
        for line in block.splitlines():
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
            else:
                buf.append(s)
        flush()
    return paras


def write_docx(path):
    from docx import Document
    from docx.shared import Pt
    from docx.enum.text import WD_ALIGN_PARAGRAPH
    doc = Document()
    normal = doc.styles["Normal"]
    normal.font.name = "Times New Roman"
    normal.font.size = Pt(12)
    normal.paragraph_format.space_after = Pt(6)
    normal.paragraph_format.line_spacing = 1.5
    for (kind, text) in narrative_paragraphs():
        if kind == "H1":
            p = doc.add_heading(text, level=1)
            run = p.runs[0]
        elif kind == "H2":
            p = doc.add_heading(text, level=2)
            run = p.runs[0]
        else:
            p = doc.add_paragraph(text)
            p.alignment = WD_ALIGN_PARAGRAPH.JUSTIFY
            run = p.runs[0]
        run.font.name = "Times New Roman"
        run.font.size = Pt(12 if kind == "P" else 14)
    doc.save(path)


OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)))


def validate_drawio(path, expect_labels):
    import xml.dom.minidom as minidom
    with open(path, "r", encoding="utf-8") as fh:
        raw = fh.read()
    dom = minidom.parseString(raw)
    cells = dom.getElementsByTagName("mxCell")
    vertices = [c for c in cells if c.getAttribute("vertex") == "1"]
    edges = [c for c in cells if c.getAttribute("edge") == "1"]
    values = [c.getAttribute("value") for c in edges]
    codes = {c.getAttribute("id"): c.getAttribute("value") for c in edges}
    carriers = {c.getAttribute("id"): c.getAttribute("value") for c in vertices
                if c.getAttribute("id").startswith("leg")}
    want = [re.sub(r"\s+", " ", lab) for lab in expect_labels]
    # Map each expected full name through its short code edge + carrier.
    by_code = {}
    for cid, code in codes.items():
        carrier = carriers.get("leg" + code, "")
        name = carrier.split(" = ", 1)[1] if " = " in carrier else ""
        by_code[code] = name
    have = [re.sub(r"\s+", " ", v) for v in by_code.values()]
    codeset = set(by_code.keys())
    want_codes = {f"E{i}" for i in range(1, 25)} | {f"P{i}" for i in range(1, 32)}
    assert codeset <= want_codes, codeset - want_codes
    missing = [lab for lab, w in zip(expect_labels, want) if w not in have]
    dup_vals = sorted({v for v in have if have.count(v) > 1 and v.strip()})
    assert not missing, f"missing labels in {path}: {missing}"
    assert not dup_vals, f"duplicate labels in {path}: {dup_vals}"
    no_arrow = [c.getAttribute("id") for c in edges
                if "endArrow=" not in c.getAttribute("style")]
    geo_bad = []
    for c in list(vertices) + edges:
        for g in c.getElementsByTagName("mxGeometry"):
            for attr in ("x", "y", "width", "height"):
                if g.hasAttribute(attr):
                    try:
                        float(g.getAttribute(attr))
                    except ValueError:
                        geo_bad.append((c.getAttribute("id"), attr))
    print(f"[drawio] {os.path.basename(path)}: cells={len(cells)} "
          f"vertices={len(vertices)} edges={len(edges)}")
    assert not no_arrow, f"edges without arrowhead: {no_arrow}"
    assert not geo_bad, f"bad geometry: {geo_bad}"
    return len(edges)


def validate_svg(path, expect_labels):
    import xml.dom.minidom as minidom
    with open(path, "r", encoding="utf-8") as fh:
        raw = fh.read()
    dom = minidom.parseString(raw)
    texts = "".join(
        "".join(n.data for n in t.childNodes
                if n.nodeType == n.TEXT_NODE)
        for t in dom.getElementsByTagName("text"))
    norm = re.sub(r"\s+", " ", texts)
    missing = []
    for lab in expect_labels:
        words = lab.split()
        hits = sum(1 for w in words if w in norm)
        if hits < max(1, len(words) - 1):
            missing.append(lab)
    assert not missing, f"labels not found in {path}: {missing}"
    print(f"[svg] {os.path.basename(path)}: text-nodes="
          f"{len(dom.getElementsByTagName('text'))} labels-ok={len(expect_labels)}")
    assert "<svg" in raw and "</svg>" in raw


def audit_svg_codes(path, codes):
    import xml.dom.minidom as minidom
    with open(path, "r", encoding="utf-8") as fh:
        raw = fh.read()
    dom = minidom.parseString(raw)
    texts = ["".join(n.data for n in t.childNodes
                     if n.nodeType == n.TEXT_NODE)
             for t in dom.getElementsByTagName("text")]
    norm = re.sub(r"\s+", " ", " ".join(texts))
    missing = [c for c in codes if f" {c} " not in f" {norm} "
               and not norm.startswith(c + " ")
               and not norm.endswith(" " + c)]
    # codes are short glyphs; also accept them inside legend rows
    missing = [c for c in missing if f"{c} =" not in norm]
    glyphs = [t for t in texts if t.strip() in codes]
    print(f"[svg-codes] {os.path.basename(path)}: glyphs={len(glyphs)} "
          f"missing={missing}")
    assert len(glyphs) == len(codes), (len(glyphs), len(codes))
    assert not missing


def main():
    specs = [
        ("EXISTING", EXISTING_TITLE, EXISTING_SUB, EXISTING_PROC,
         EXISTING_ENTITIES, EXISTING_FLOWS, EXISTING_CAPTION),
        ("PROPOSED", PROPOSED_TITLE, PROPOSED_SUB, PROPOSED_PROC,
         PROPOSED_ENTITIES, PROPOSED_FLOWS, PROPOSED_CAPTION),
    ]
    counts = {}
    for tag, title, sub, proc, entities, triples, caption in specs:
        cells = build_cells(title, sub, proc, entities, triples, caption)
        dpath = os.path.join(OUT, f"3.4_Context_Diagram_{tag}.drawio")
        with open(dpath, "w", encoding="utf-8", newline="\n") as fh:
            fh.write(drawio_xml(tag, cells))
        ed = validate_drawio(dpath, [lab for (_, lab, _, _) in triples])
        spath = os.path.join(OUT, f"3.4_Context_Diagram_{tag}.svg")
        with open(spath, "w", encoding="utf-8", newline="\n") as fh:
            fh.write(svg_doc(title, sub, proc, entities, triples, caption))
        validate_svg(spath, [lab for (_, lab, _, _) in triples])
        audit_svg_codes(spath, [code for (code, _, _, _) in triples])
        audit_layout(triples, entities, tag)
        counts[tag] = ed
    md = NARR_MD_EXIST.rstrip() + "\n\n" + NARR_MD_PROP
    md = re.sub(r"\n{3,}", "\n\n", md).rstrip() + "\n"
    md_path = os.path.join(OUT, "3.4_Context_Diagram_Narrative.md")
    with open(md_path, "w", encoding="utf-8", newline="\n") as fh:
        fh.write(md)
    docx_path = os.path.join(OUT, "3.4_Context_Diagram_Narrative.docx")
    write_docx(docx_path)
    total = sum(counts.values())
    assert total == 55, total
    print(f"[narrative] md={len(md)} chars paragraphs="
          f"{len(narrative_paragraphs())}")
    print(f"OK: EXISTING edges={counts['EXISTING']} "
          f"PROPOSED edges={counts['PROPOSED']} total={total}")


if __name__ == "__main__":
    sys.exit(main())









