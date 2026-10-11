#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Generate the Chapter 3.8 use case diagram assets for the dental AI capstone.

Outputs (into <workspace>/diagrams/):
    use_case_diagrams.drawio              master, 3 pages (p1..p3)
    Use_Case_Patient.drawio               Figure 13, one page
    Use_Case_Dentist.drawio               Figure 14, one page
    Use_Case_Administrator.drawio         Figure 15, one page
    Use_Case_<NAME>.svg                   Word-ready preview per figure

Style contract, taken from the sample use case figure supplied by the
researcher (a fitness-app use case diagram):

    actor        stick figure at the far left, label underneath
    boundary     plain rectangle, no label, no shading
    use cases    plain ellipses, no divider line, two columns
    associations straight solid lines fanning out from the actor to the
                 left edge of every use case the actor owns
    cross links  dashed lines with an open arrowhead between use cases,
                 carrying a small italic stereotype label

Layout consequence of that contract, and the reason it never tangles:
associations only ever reach the LEFT EDGE of a left-column oval, so every
actor sits in the left margin (x < the oval column) and no association can
cross an oval.  Include/extend arrows only ever run from a left-column oval's
RIGHT EDGE to a right-column oval's LEFT EDGE, so they travel entirely inside
the empty gutter between the columns.  The verifier re-checks both rules
numerically.

The file is plain uncompressed mxfile XML, so draw.io opens it directly and
"Extras -> Edit Diagram" shows the identical XML.

Standard library only.
"""
from __future__ import annotations

import io
import math
import os
import xml.sax.saxutils as sax

# --------------------------------------------------------------------------
# Canvas and style constants (A3 portrait, draw.io px)
# --------------------------------------------------------------------------
PAGE_W = 1169
PAGE_H = 1654
FONT = "Arial"

MARGIN = 40
BOUND_X0, BOUND_Y0 = 200, 40
BOUND_X1, BOUND_Y1 = 1129, 1440

OVAL_W, OVAL_H = 320, 56
COL_L_CX = 420                      # left column spans 260 .. 580
COL_R_CX = 909                      # right column spans 749 .. 1069
ROW_TOP, ROW_BOT = 100, 1395

ACTOR_W, ACTOR_H = 60, 90
ACTOR_X = 40
SEC_W, SEC_H = 48, 72

FS_OVAL = 11
FS_ACTOR = 11
FS_ACTOR2 = 10
FS_LINK = 9

ST_OVAL = ("ellipse;whiteSpace=wrap;html=1;fillColor=#FFFFFF;"
           "strokeColor=#000000;fontFamily=%s;fontSize=%d;fontColor=#000000;"
           % (FONT, FS_OVAL))

ST_ACTOR = ("shape=umlActor;html=1;verticalLabelPosition=bottom;"
            "verticalAlign=top;align=center;horizontal=0;outlineConnect=0;"
            "fillColor=#FFFFFF;strokeColor=#000000;fontFamily=%s;fontSize=%d;"
            "fontColor=#000000;" % (FONT, FS_ACTOR))

ST_ACTOR_SEC = ("shape=umlActor;html=1;verticalLabelPosition=bottom;"
                "verticalAlign=top;align=center;horizontal=0;outlineConnect=0;"
                "fillColor=#FFFFFF;strokeColor=#6B7280;fontFamily=%s;"
                "fontSize=%d;fontColor=#374151;" % (FONT, FS_ACTOR2))

ST_BOUNDARY = ("rounded=0;whiteSpace=wrap;html=1;fillColor=none;"
               "strokeColor=#000000;strokeWidth=1;")

ST_ASSOC = ("endArrow=none;html=1;strokeColor=#000000;strokeWidth=1;"
            "edgeStyle=none;exitX=1;exitY=0.5;exitDx=0;exitDy=0;"
            "entryX=0;entryY=0.5;entryDx=0;entryDy=0;")

SLOT = "html=1;strokeColor=#000000;strokeWidth=1;edgeStyle=none;"
ST_LINK = ("endArrow=open;endFill=0;dashed=1;" + SLOT +
           "labelBackgroundColor=#FFFFFF;fontFamily=%s;fontSize=%d;"
           "fontStyle=2;" % (FONT, FS_LINK))

STEREOTYPE = {"include": "&lt;&lt;include&gt;&gt;",
              "extend": "&lt;&lt;extend&gt;&gt;"}


def esc(text):
    """Escape a value for an XML attribute (keeps << >> readable once parsed)."""
    return sax.escape(text if text is not None else "", {'"': "&quot;"})


# --------------------------------------------------------------------------
# Figure content.  Every entry is traceable to a handler in the code base;
# the citations live in 3.8_Use_Case_Diagram_Narrative.txt.
#   left  : (use case label, [actors that own it])   "primary" = the role actor
#   right : (use case label, [])                     include/extend targets only
#   links : (source label, target label, "include" | "extend")
# --------------------------------------------------------------------------

PATIENT = {
    "pid": "p1",
    "name": "Figure 13 - Patient",
    "actor": "Patient",
    "left": [
        ("Log in", ["primary"]),                             # login.php:73
        ("View patient dashboard", ["primary"]),             # patient_dashboard.php
        ("Book an appointment", ["primary"]),                # book_appointment.php
        ("View appointment calendar", ["primary"]),          # calendar.php
        ("View my appointments", ["primary"]),               # my_appointments.php
        ("Confirm attendance", ["primary"]),                 # confirm_attendance
        ("Request reschedule", ["primary"]),                 # request_reschedule
        ("Cancel appointment", ["primary"]),                 # cancel_appointment
        ("Submit appointment concern", ["primary"]),         # appointment_concern.php
        ("View notifications", ["primary"]),                 # notifications.php
        ("Update profile", ["primary"]),                      # profile.php:update_profile
        ("Log out", ["primary"]),                            # logout.php
        ("Register account", ["Guest"]),                     # register.php
        ("Recover password", ["Guest"]),                     # forgot_password.php
        ("Submit walk-in details (QR)", ["Walk-in Patient"]),  # walkin_qr.php
    ],
    "right": [
        ("View available time slots", []),                   # get_available_slots.php
        ("Check dentist availability", []),                  # book_appointment.php
        ("Detect schedule conflict", []),                    # book_appointment.php
        ("Send confirmation email", []),                     # config.php:sendClinicGmail
        ("View available dental services", []),              # clinic_services
        ("View appointment history", []),                    # my_appointments.php
        ("Change password", []),                             # profile.php:change_password
    ],
    "links": [
        ("Book an appointment", "View available time slots", "include"),
        ("Book an appointment", "Check dentist availability", "include"),
        ("Book an appointment", "Detect schedule conflict", "include"),
        ("Request reschedule", "Check dentist availability", "include"),
        ("Request reschedule", "Detect schedule conflict", "include"),
        ("Book an appointment", "Send confirmation email", "include"),
        ("Book an appointment", "View available dental services", "include"),
        ("View my appointments", "View appointment history", "include"),
        ("Update profile", "Change password", "include"),
    ],
}

DENTIST = {
    "pid": "p2",
    "name": "Figure 14 - Dentist",
    "actor": "Dentist",
    "left": [
        ("Log in", ["primary"]),                             # login.php:88
        ("View today's schedule", ["primary"]),              # dentist_dashboard.php
        ("View patient queue", ["primary"]),                 # dentist/queue.php
        ("Start serving next patient", ["primary"]),         # queue.php:status Serving
        ("Complete patient visit", ["primary"]),             # queue.php:status Completed
        ("View appointments", ["primary"]),                  # dentist/appointments.php
        ("View notifications", ["primary"]),                 # dentist/notifications.php
        ("Update profile", ["primary"]),                     # dentist/profile.php
        ("Log out", ["primary"]),                            # logout.php
    ],
    "right": [
        ("Review patient record", []),                       # dentist/patients.php
        ("View patient appointment history", []),            # dentist/patients.php
        ("Change password", []),                             # dentist/settings.php
    ],
    "links": [
        ("Start serving next patient", "Review patient record", "include"),
        ("Review patient record", "View patient appointment history", "include"),
        ("Update profile", "Change password", "include"),
        ("Complete patient visit", "Start serving next patient", "extend"),
    ],
}

ADMIN = {
    "pid": "p3",
    "name": "Figure 15 - Administrator",
    "actor": "Clinic Administrator",
    "left": [
        ("Log in", ["primary"]),                             # login.php:84
        ("View clinic dashboard", ["primary"]),              # admin/dashboard.php
        ("Manage patients", ["primary"]),                    # admin/patients.php
        ("Schedule an appointment", ["primary"]),            # appointments.php:action add
        ("Update appointment status", ["primary"]),          # appointments.php:action status
        ("Reschedule appointment", ["primary"]),             # appointments.php:action reschedule
        ("Manage daily queue", ["primary"]),                 # admin/queue.php:action status
        ("Register walk-in patient (QR)", ["primary"]),      # admin/queue.php:action qr_add
        ("Mark no-show", ["primary"]),                       # admin/no_show.php
        ("Manage appointment concerns", ["primary"]),        # appointment_concern.php
        ("Manage dentists", ["primary"]),                    # admin/dentists.php:action add
        ("Manage clinic settings", ["primary"]),             # settings.php:action clinic
        ("Generate reports", ["primary"]),                   # admin/reports.php
        ("Send appointment reminder",
         ["primary", "Email Service"]),                      # appointments.php:action remind
    ],
    "right": [
        ("Check dentist availability", []),                  # appointments.php
        ("Detect schedule conflict", []),                    # appointments.php
        ("Manage dentist schedules", []),                    # dentists.php:action schedule
        ("Register day-off", []),                            # dentists.php:dayoff_add
        ("Manage dental services", []),                      # settings.php:service_save
        ("Manage user accounts", []),                        # settings.php:user_save
        ("Validate clinic operating hours", []),             # settings.php:action clinic
        ("Generate AI demand forecast", []),                 # demand_forecast.php:246
    ],
    "links": [
        ("Schedule an appointment", "Check dentist availability", "include"),
        ("Reschedule appointment", "Check dentist availability", "include"),
        ("Schedule an appointment", "Detect schedule conflict", "include"),
        ("Manage dentists", "Manage dentist schedules", "include"),
        ("Manage dentist schedules", "Register day-off", "include"),
        ("Manage clinic settings", "Manage dental services", "include"),
        ("Manage clinic settings", "Manage user accounts", "include"),
        ("Manage clinic settings", "Validate clinic operating hours", "include"),
        ("Generate reports", "Generate AI demand forecast", "include"),
    ],
}

FIGURES = [PATIENT, DENTIST, ADMIN]

# output paths, keyed by page id
STANDALONE = {
    "p1": "Use_Case_Patient.drawio",
    "p2": "Use_Case_Dentist.drawio",
    "p3": "Use_Case_Administrator.drawio",
}
MASTER = "use_case_diagrams.drawio"


# --------------------------------------------------------------------------
# Layout: two columns of ovals inside the boundary, actors in the left margin
# --------------------------------------------------------------------------
def vcell(cid, value, style, x, y, w, h):
    return {"type": "vertex", "id": cid, "value": value, "style": style,
            "x": x, "y": y, "w": w, "h": h}


def ecell(cid, value, style, source, target, pt, dashed):
    return {"type": "edge", "id": cid, "value": value, "style": style,
            "source": source, "target": target, "dashed": dashed,
            "exitX": pt[0], "exitY": pt[1], "entryX": pt[2], "entryY": pt[3]}


def vertex_xml(c):
    return ('        <mxCell id="%s" value="%s" style="%s" vertex="1" '
            'parent="1">\n'
            '          <mxGeometry x="%.1f" y="%.1f" width="%.1f" '
            'height="%.1f" as="geometry" />\n'
            '        </mxCell>\n'
            % (c["id"], esc(c["value"]), c["style"], c["x"], c["y"],
               c["w"], c["h"]))


def edge_xml(c):
    return ('        <mxCell id="%s" value="%s" style="%s" edge="1" '
            'parent="1" source="%s" target="%s">\n'
            '          <mxGeometry relative="1" as="geometry" />\n'
            '        </mxCell>\n'
            % (c["id"], esc(c["value"]), c["style"], c["source"], c["target"]))


def crossings(segs):
    """Count proper crossings of straight column-to-column connectors.

    Two segments leaving one column at row a and arriving in the other at
    row b cross exactly when (a1-a2) and (b1-b2) have opposite signs; sharing
    a source row or a target row only makes them meet, not cross.
    """
    n = 0
    for i in range(len(segs)):
        for j in range(i + 1, len(segs)):
            a1, b1 = segs[i]
            a2, b2 = segs[j]
            if a1 == a2 or b1 == b2:
                continue
            if (a1 - a2) * (b1 - b2) < 0:
                n += 1
    return n


def order_right(fig):
    """Choose the right-column order that draws the fewest crossing arrows.

    The declared order stays the logical order of the narrative; the drawing
    is allowed to stack the target use cases differently so the <<include>>
    arrows fan out cleanly instead of tangling.
    """
    from itertools import permutations

    right = list(fig["right"])
    if len(right) < 2:
        return right
    lrow = {lbl: i for i, (lbl, _) in enumerate(fig["left"])}
    rlabels = [r[0] for r in right]
    links = [(lrow[s], t) for s, t, _ in fig["links"]
             if s in lrow and t in rlabels]

    best = None
    for perm in permutations(range(len(right))):
        drawn = {rlabels[p]: i for i, p in enumerate(perm)}
        segs = [(a, drawn[t]) for a, t in links]
        dev = sum(abs(i - perm[i]) for i in range(len(perm)))
        key = (crossings(segs), dev)
        if best is None or key < best[0]:
            best = (key, perm)
    return [right[p] for p in best[1]]


def link_style(spos, tpos):
    """Straight dashed arrow between two ovals, routed to stay in the gutter."""
    scol, srow = spos[2], spos[1]
    tcol, trow = tpos[2], tpos[1]
    if scol == "L" and tcol == "R":                 # across the empty gutter
        ex, ey, nx, ny = 1.0, 0.5, 0.0, 0.5
    elif srow < trow:                               # straight down a column
        ex, ey, nx, ny = 0.5, 1.0, 0.5, 0.0
    else:                                           # straight up a column
        ex, ey, nx, ny = 0.5, 0.0, 0.5, 1.0
    style = ("endArrow=open;endFill=0;dashed=1;" + SLOT +
             "labelBackgroundColor=#FFFFFF;fontFamily=%s;fontSize=%d;"
             "fontStyle=2;exitX=%.1f;exitY=%.1f;exitDx=0;exitDy=0;"
             "entryX=%.1f;entryY=%.1f;entryDx=0;entryDy=0;"
             % (FONT, FS_LINK, ex, ey, nx, ny))
    return style, (ex, ey, nx, ny)


def layout(fig):
    """Build the cell list for one page and return (cells, geometry report)."""
    n_rows = max(len(fig["left"]), len(fig["right"]))
    pitch = (ROW_BOT - ROW_TOP) / (n_rows - 1) if n_rows > 1 else 0.0

    def row_y(i):
        return ROW_TOP + i * pitch

    cells = []
    boxes = {}          # label -> (x, y, w, h)   for the geometry checker
    pos = {}            # label -> (cx, cy, "L"|"R")

    # --- system boundary ---------------------------------------------------
    cells.append(vcell("b0", "", ST_BOUNDARY, BOUND_X0, BOUND_Y0,
                       BOUND_X1 - BOUND_X0, BOUND_Y1 - BOUND_Y0))
    boxes["b0"] = (BOUND_X0, BOUND_Y0, BOUND_X1 - BOUND_X0,
                   BOUND_Y1 - BOUND_Y0)

    # --- use case ovals ----------------------------------------------------
    ids = {}
    n = 0
    for col, items, cx in (("L", list(fig["left"]), COL_L_CX),
                           ("R", order_right(fig), COL_R_CX)):
        for i, item in enumerate(items):
            label = item[0]
            n += 1
            cid = "o%d" % n
            ids[label] = cid
            cy = row_y(i)
            pos[label] = (cx, i, col)
            box = (cx - OVAL_W / 2, cy - OVAL_H / 2, OVAL_W, OVAL_H)
            boxes[cid] = box
            cells.append(vcell(cid, label, ST_OVAL, box[0], box[1],
                               OVAL_W, OVAL_H))

    # --- actors ------------------------------------------------------------
    own = {}                          # actor key -> [row index it owns]
    for i, item in enumerate(fig["left"]):
        for name in item[1]:
            own.setdefault(name, []).append(i)
    if "primary" not in own:
        raise SystemExit("%s: no primary actor rows" % fig["pid"])

    def make_actor(name, style):
        cy = (row_y(min(own[name])) + row_y(max(own[name]))) / 2.0
        sec = style == ST_ACTOR_SEC
        cid = ("a" + name.lower().replace(" ", "").replace("-", "")
               if sec else "a0")
        label = name if sec else fig["actor"]
        return name, cid, label, style, ACTOR_X + (6 if sec else 0), cy

    placed = [make_actor("primary", ST_ACTOR)]
    for name in sorted(own, key=lambda k: min(own[k])):
        if name != "primary":
            placed.append(make_actor(name, ST_ACTOR_SEC))

    for name, cid, label, style, x, cy in placed:
        sec = style == ST_ACTOR_SEC
        w, h = (SEC_W, SEC_H) if sec else (ACTOR_W, ACTOR_H)
        cells.append(vcell(cid, label, style, x, cy - h / 2.0, w, h))
        boxes[cid] = (x, cy - h / 2.0, w, h)

    # --- associations: actor -> left edge of each oval it owns -------------
    n = 0
    for name, cid, label, style, x, cy in placed:
        for row in own[name]:
            n += 1
            cells.append(ecell("e%d" % n, "", ST_ASSOC, cid,
                               ids[fig["left"][row][0]],
                               (1.0, 0.5, 0.0, 0.5), False))

    # --- include / extend --------------------------------------------------
    n = 0
    for src, dst, kind in fig["links"]:
        if src not in ids:
            raise SystemExit("%s: link source not drawn: %s" % (fig["pid"], src))
        if dst not in ids:
            raise SystemExit("%s: link target not drawn: %s" % (fig["pid"], dst))
        n += 1
        style_e, pt = link_style(pos[src], pos[dst])
        cells.append(ecell("k%d" % n, STEREOTYPE[kind], style_e,
                           ids[src], ids[dst], pt, True))
    return cells, boxes, pos



# --------------------------------------------------------------------------
# SVG preview (so the layout can be eyeballed without opening draw.io)
# --------------------------------------------------------------------------
def svg_doc(fig, cells):
    """Render the same geometry to standalone SVG.  Not used by Word; the
    .drawio is the deliverable and the SVG is only a proof of the spacing."""
    parts = []
    w, h = PAGE_W, PAGE_H
    parts.append('<?xml version="1.0" encoding="UTF-8"?>\n')
    parts.append('<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" '
                 'viewBox="0 0 %d %d">\n' % (w, h, w, h))
    parts.append('<rect x="0" y="0" width="%d" height="%d" fill="#FFFFFF"/>\n'
                 % (w, h))

    # geometry is re-derived from the cells so the preview cannot drift
    geo = {}
    for c in cells:
        cid = c["id"]
        if c["type"] == "vertex":
            geo[cid] = c
    for c in cells:
        if c["type"] == "vertex":
            x, y, cw, ch = c["x"], c["y"], c["w"], c["h"]
            if c["id"] == "b0":
                parts.append('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" '
                             'fill="none" stroke="#000000" stroke-width="1"/>\n'
                             % (x, y, cw, ch))
            elif c["style"].startswith("ellipse"):
                parts.append('<ellipse cx="%.1f" cy="%.1f" rx="%.1f" ry="%.1f" '
                             'fill="#FFFFFF" stroke="#000000" stroke-width="1"/>\n'
                             % (x + cw / 2, y + ch / 2, cw / 2, ch / 2))
                parts.append('<text x="%.1f" y="%.1f" font-family="%s" '
                             'font-size="%d" text-anchor="middle" '
                             'dominant-baseline="middle">%s</text>\n'
                             % (x + cw / 2, y + ch / 2, FONT, FS_OVAL,
                                svg_esc(c["value"])))
            elif "umlActor" in c["style"]:
                sec = "6B7280" in c["style"]
                col = "#6B7280" if sec else "#000000"
                cx = x + cw / 2
                head_r = ch * 0.085
                parts.append('<circle cx="%.1f" cy="%.1f" r="%.1f" fill="none" '
                             'stroke="%s" stroke-width="1.4"/>\n'
                             % (cx, y + ch * 0.16, head_r, col))
                parts.append('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" '
                             'stroke="%s" stroke-width="1.4"/>\n'
                             % (cx, y + ch * 0.16 + head_r, cx, y + ch * 0.62,
                                col))
                parts.append('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" '
                             'stroke="%s" stroke-width="1.4"/>\n'
                             % (cx - cw * 0.28, y + ch * 0.36,
                                cx + cw * 0.28, y + ch * 0.36, col))
                parts.append('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" '
                             'stroke="%s" stroke-width="1.4"/>\n'
                             % (cx, y + ch * 0.62, cx - cw * 0.26,
                                y + ch * 0.88, col))
                parts.append('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" '
                             'stroke="%s" stroke-width="1.4"/>\n'
                             % (cx, y + ch * 0.62, cx + cw * 0.26,
                                y + ch * 0.88, col))
                parts.append('<text x="%.1f" y="%.1f" font-family="%s" '
                             'font-size="%d" text-anchor="middle" fill="%s">%s'
                             '</text>\n'
                             % (cx, y + ch + 16, FONT,
                                FS_ACTOR2 if sec else FS_ACTOR, col,
                                svg_esc(c["value"])))

    for c in cells:
        if c["type"] != "edge":
            continue
        s, t = geo[c["source"]], geo[c["target"]]
        x1, y1 = point_on(s, c["exitX"], c["exitY"])
        x2, y2 = point_on(t, c["entryX"], c["entryY"])
        dash = ' stroke-dasharray="6 4"' if c["dashed"] else ""
        parts.append('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" '
                     'stroke="#000000" stroke-width="1"%s/>\n'
                     % (x1, y1, x2, y2, dash))
        if c["dashed"]:
            parts.append('<polygon points="%.1f,%.1f %.1f,%.1f %.1f,%.1f" '
                         'fill="#FFFFFF" stroke="#000000" stroke-width="1"/>\n'
                         % (x2, y2, x2 - 9, y2 - 4, x2 - 9, y2 + 4))
            mx, my = (x1 + x2) / 2, (y1 + y2) / 2
            parts.append('<text x="%.1f" y="%.1f" font-family="%s" '
                         'font-size="%d" font-style="italic" text-anchor="middle" '
                         'dominant-baseline="middle">%s</text>\n'
                         % (mx, my, FONT, FS_LINK, svg_esc(c["value"])))
    parts.append("</svg>\n")
    return "".join(parts)


def svg_esc(text):
    return sax.escape(text, {'"': "&quot;"})


def point_on(cell, fx, fy):
    return (cell["x"] + cell["w"] * fx, cell["y"] + cell["h"] * fy)


# --------------------------------------------------------------------------
# mxfile assembly
# --------------------------------------------------------------------------
def page_xml(fig, cells):
    body = "".join(vertex_xml(c) if c["type"] == "vertex" else edge_xml(c)
                   for c in cells)
    return ('  <diagram id="%s" name="%s">\n'
            '    <mxGraphModel dx="1169" dy="1654" grid="0" gridSize="10" '
            'guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" '
            'pageScale="1" pageWidth="%d" pageHeight="%d" math="0" '
            'shadow="0">\n'
            '      <root>\n'
            '        <mxCell id="0" />\n'
            '        <mxCell id="1" parent="0" />\n'
            '%s'
            '      </root>\n'
            '    </mxGraphModel>\n'
            '  </diagram>\n'
            % (fig["pid"], fig["name"], PAGE_W, PAGE_H, body))


def mxfile(pages, count):
    return ('<mxfile host="app.diagrams.net" agent="Use case diagram '
            'generator" pages="%d">\n%s</mxfile>\n' % (count, "".join(pages)))


def main():
    pages = []
    print("%-30s %6s %6s %6s %6s" % ("figure", "ovals", "assoc", "links",
                                     "actors"))
    for fig in FIGURES:
        cells, boxes, pos = layout(fig)
        pages.append(page_xml(fig, cells))

        # one-page document for the manuscript
        out = STANDALONE[fig["pid"]]
        io.open(out, "w", encoding="utf-8", newline="").write(
            mxfile([page_xml(fig, cells)], 1))

        # SVG preview of exactly the same geometry
        io.open(out.replace(".drawio", ".svg"), "w", encoding="utf-8",
                newline="").write(svg_doc(fig, cells))

        ovals = sum(1 for c in cells if c["type"] == "vertex"
                    and c["style"].startswith("ellipse"))
        assoc = sum(1 for c in cells if c["type"] == "edge" and not c["dashed"])
        links = sum(1 for c in cells if c["type"] == "edge" and c["dashed"])
        actors = sum(1 for c in cells if c["type"] == "vertex"
                     and "umlActor" in c["style"])
        print("%-30s %6d %6d %6d %6d" % (fig["name"], ovals, assoc, links,
                                         actors))

    io.open(MASTER, "w", encoding="utf-8", newline="").write(
        mxfile(pages, len(pages)))
    print("\nwrote %s + %d single-page files + %d SVG previews"
          % (MASTER, len(STANDALONE), len(STANDALONE)))


if __name__ == "__main__":
    main()




