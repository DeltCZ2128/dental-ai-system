"""Generate the Chapter 3.6 activity-diagram asset for the dental AI capstone.

Output (into <workspace>/diagrams/):
  3.6_Activity_Diagram.drawio   single page, three swimlanes (Figure 7)

Canvas: A3 landscape 1654x1169 draw.io px, Arial, black-and-white line art --
same canvas and conventions as the 3.4 context figures and 3.5 DFDs.

Lanes (vertical partitions, flow runs top to bottom):
  PATIENT          x   40.. 560
  SYSTEM           x  560..1200
  ADMINISTRATOR    x 1200..1614

draw.io import notes:
  - File -> Open the .drawio (plain uncompressed mxfile XML).
  - Extras -> Edit Diagram shows the same XML printed below.
  - File -> Export As -> PNG/PDF per page for the manuscript figure.
  - Arial renders 1:1 everywhere; switch to Times New Roman in draw.io only
    via the Style tab if the adviser requires it.

Standard library only.
"""
from __future__ import annotations

import os
import xml.etree.ElementTree as ET

# --------------------------------------------------------------------------
# Canvas + shared style
# --------------------------------------------------------------------------
PAGE_W, PAGE_H = 1654, 1169
FONT = "Arial"
BLACK, WHITE, HDR_FILL = "#000000", "#ffffff", "#eeeeee"

TITLE_Y, SUB_Y = 22, 52
HDR_Y, HDR_H = 96, 34
BODY_Y, BODY_H = 130, 962          # lane bodies span y 130..1092
LEG_HEAD_Y, LEG_Y, CAP_Y = 1094, 1110, 1130

LANES = [("PATIENT", 40, 520), ("SYSTEM", 560, 640),
         ("ADMINISTRATOR", 1200, 414)]

TITLE = "3.6 ACTIVITY DIAGRAM"
SUBTITLE = "PROPOSED SYSTEM - APPOINTMENT SCHEDULING (FIGURE 7)"
LEG_HEAD = "ACTIVITY DIAGRAM NOTATION"
LEGEND = ("filled circle = initial node; circle with inner disc = "
          "activity-final node; rounded box = action; diamond = decision; "
          "thick bar = fork (parallel flow); [text] = guard condition.")
CAPTION = "Figure 7: Activity Diagram (Appointment Scheduling)"

# --------------------------------------------------------------------------
# Node table: (id, kind, x, y, w, h, value)
#   kind: act = action box, dec = decision diamond, start/end = control
#   nodes, bar = fork bar. Multi-line values use <br> (html=1 labels).
#   Flow: login -> book form -> validation chain -> save Pending -> fork ->
#   patient receipt + admin review -> status update -> notify -> end.
# --------------------------------------------------------------------------
NODES = [
    ("n_start", "start", 288, 140, 24, 24, ""),
    ("p_login", "act", 170, 184, 260, 44,
     "Log in with username and password"),
    ("s_verify", "act", 760, 184, 240, 44,
     "Verify credentials against<br>the users table"),
    ("d_cred", "dec", 805, 240, 150, 60, "Credentials<br>valid?"),
    ("p_open", "act", 170, 316, 260, 44, "Open Book Appointment module"),
    ("p_select", "act", 170, 376, 260, 48,
     "Select dentist, service, date<br>and available start time"),
    ("p_submit", "act", 170, 440, 260, 44, "Submit appointment request"),
    ("s_v1", "act", 760, 440, 240, 44,
     "Validate required fields, date<br>and service duration"),
    ("s_v2", "act", 760, 496, 240, 48,
     "Check Sunday closure, rest day,<br>day-off, schedule and conflicts"),
    ("d_sched", "dec", 805, 556, 150, 60, "Schedule<br>valid?"),
    ("s_error", "act", 570, 564, 190, 44,
     "Display error message:<br>select another schedule"),
    ("s_save", "act", 760, 632, 240, 44,
     "Save appointment record<br>(status: Pending)"),
    ("s_notify", "act", 760, 688, 240, 44,
     "Create patient notification"),
    ("fork", "bar", 620, 748, 520, 8, ""),
    ("p_receipt", "act", 170, 772, 260, 44,
     "View booking confirmation<br>(status: Pending)"),
    ("a_review", "act", 1312, 772, 190, 44,
     "Review new<br>appointment request"),
    ("d_admin", "dec", 1332, 828, 150, 60, "Confirm or<br>cancel?"),
    ("a_cancel", "act", 1210, 904, 190, 44, "Set status =<br>Cancelled"),
    ("a_conf", "act", 1414, 904, 190, 44, "Set status =<br>Confirmed"),
    ("s_status", "act", 760, 960, 240, 44,
     "Record status change<br>and notify patient"),
    ("p_status", "act", 170, 1008, 260, 44,
     "Receive appointment status<br>notification (Confirmed / Cancelled)"),
    ("n_final", "end", 288, 1060, 24, 24, ""),
]

# --------------------------------------------------------------------------
# Edge table: (id, src, tgt, exit, entry, pts)
#   exit/entry are (fx, fy) attachment fractions; pts[0]/pts[-1] become the
#   explicit source/target points, pts[1:-1] the waypoints.
# --------------------------------------------------------------------------
EDGES = [
    ("e1", "n_start", "p_login", (0.5, 1.0), (0.5, 0.0),
     [(300, 164), (300, 184)]),
    ("e2", "p_login", "s_verify", (1.0, 0.5), (0.0, 0.5),
     [(430, 206), (760, 206)]),
    ("e3", "s_verify", "d_cred", (0.5, 1.0), (0.5, 0.0),
     [(880, 228), (880, 240)]),
    ("e4", "d_cred", "p_login", (0.0, 0.5), (1.0, 0.8182),
     [(805, 270), (490, 270), (490, 220), (430, 220)]),
    ("e5", "d_cred", "p_open", (0.5, 1.0), (0.5, 0.0),
     [(880, 300), (880, 308), (300, 308), (300, 316)]),
    ("e6", "p_open", "p_select", (0.5, 1.0), (0.5, 0.0),
     [(300, 360), (300, 376)]),
    ("e7", "p_submit", "s_v1", (1.0, 0.5), (0.0, 0.5),
     [(430, 462), (760, 462)]),
    ("e8", "s_v1", "s_v2", (0.5, 1.0), (0.5, 0.0),
     [(880, 484), (880, 496)]),
    ("e9", "s_v2", "d_sched", (0.5, 1.0), (0.5, 0.0),
     [(880, 544), (880, 556)]),
    ("e10", "d_sched", "s_error", (0.0, 0.5), (1.0, 0.5),
     [(805, 586), (760, 586)]),
    ("e11", "s_error", "p_select", (0.0, 0.5), (0.0, 0.5),
     [(570, 586), (110, 586), (110, 400), (170, 400)]),
    ("e12", "d_sched", "s_save", (0.5, 1.0), (0.5, 0.0),
     [(880, 616), (880, 632)]),
    ("e13", "s_save", "s_notify", (0.5, 1.0), (0.5, 0.0),
     [(880, 676), (880, 688)]),
    ("e14", "s_notify", "fork", (0.5, 1.0), (0.5, 0.0),
     [(880, 732), (880, 748)]),
    ("e15", "fork", "p_receipt", (0.1538, 1.0), (0.5, 0.0),
     [(700, 756), (700, 764), (300, 764), (300, 772)]),
    ("e16", "fork", "a_review", (0.8462, 1.0), (0.5, 0.0),
     [(1060, 756), (1060, 764), (1407, 764), (1407, 772)]),
    ("e17", "p_receipt", "p_status", (0.5, 1.0), (0.5, 0.0),
     [(300, 816), (300, 1008)]),
    ("e18", "a_review", "d_admin", (0.5, 1.0), (0.5, 0.0),
     [(1407, 816), (1407, 828)]),
    ("e19", "d_admin", "a_conf", (1.0, 0.5), (0.5, 0.0),
     [(1482, 858), (1509, 858), (1509, 904)]),
    ("e20", "d_admin", "a_cancel", (0.0, 0.5), (0.5, 0.0),
     [(1332, 858), (1305, 858), (1305, 904)]),
    ("e21", "a_cancel", "s_status", (0.5, 1.0), (0.5, 0.0),
     [(1305, 948), (1305, 956), (880, 956), (880, 960)]),
    ("e22", "a_conf", "s_status", (0.5, 1.0), (1.0, 0.5),
     [(1509, 948), (1509, 982), (1000, 982)]),
    ("e23", "s_status", "p_status", (0.0, 0.5), (1.0, 0.5),
     [(760, 982), (500, 982), (500, 1030), (430, 1030)]),
    ("e24", "p_status", "n_final", (0.5, 1.0), (0.5, 0.0),
     [(300, 1052), (300, 1060)]),
]

# Guard labels drawn as separate white-backed cells (same convention as the
# 3.4/3.5 figures, so placement is identical in draw.io and any export):
# (id, text, x, y, w, h)
LABELS = [
    ("lb4", "[invalid credentials]", 570, 261, 140, 18),
    ("lb5", "[valid]", 573, 299, 54, 18),
    ("lb10", "[No]", 760, 577, 44, 18),
    ("lb12", "[Yes]", 893, 613, 44, 18),
    ("lb17", "[after review]", 251, 903, 98, 18),
    ("lb19", "[Confirm]", 1475, 871, 68, 18),
    ("lb20", "[Cancel]", 1274, 871, 62, 18),
]

# --------------------------------------------------------------------------
# Emitters
# --------------------------------------------------------------------------
def esc(text):
    return (text.replace("&", "&amp;").replace("<", "&lt;")
            .replace(">", "&gt;"))


def vert(cid, value, style, x, y, w, h):
    return ('<mxCell id="' + cid + '" value="' + esc(value) +
            '" style="' + style + '" vertex="1" parent="1">' +
            '<mxGeometry x="' + str(x) + '" y="' + str(y) +
            '" width="' + str(w) + '" height="' + str(h) +
            '" as="geometry"/></mxCell>')


def text_cell(cid, value, x, y, w, h, size, bold=False, italic=False,
              align="center"):
    st = ("rounded=0;html=1;whiteSpace=wrap;fillColor=none;strokeColor=none;"
          "fontFamily=" + FONT + ";fontSize=" + str(size) +
          ";fontColor=" + BLACK + ";align=" + align +
          ";verticalAlign=middle;")
    flags = (1 if bold else 0) | (2 if italic else 0)
    st += {0: "", 1: "fontStyle=1;", 2: "fontStyle=2;",
           3: "fontStyle=3;"}[flags]
    return vert(cid, value, st, x, y, w, h)


ACT = ("rounded=1;arcSize=12;whiteSpace=wrap;html=1;fontFamily=" + FONT +
       ";fontSize=12;fontColor=" + BLACK + ";fillColor=" + WHITE +
       ";strokeColor=#000000;strokeWidth=1.2;align=center;"
       "verticalAlign=middle;")
DEC = ("shape=rhombus;perimeter=rhombusPerimeter;whiteSpace=wrap;html=1;"
       "fontFamily=" + FONT + ";fontSize=12;fontColor=" + BLACK +
       ";fillColor=" + WHITE +
       ";strokeColor=#000000;strokeWidth=1.2;align=center;"
       "verticalAlign=middle;")
START = "ellipse;whiteSpace=wrap;html=1;fillColor=#000000;strokeColor=#000000;"
END_O = ("ellipse;whiteSpace=wrap;html=1;fillColor=#ffffff;"
         "strokeColor=#000000;strokeWidth=1.5;")
END_I = "ellipse;html=1;fillColor=#000000;strokeColor=#000000;"
BAR = "rounded=0;html=1;fillColor=#000000;strokeColor=#000000;"
HDR = ("rounded=0;whiteSpace=wrap;html=1;fillColor=" + HDR_FILL +
       ";strokeColor=#000000;strokeWidth=1.5;fontFamily=" + FONT +
       ";fontSize=13;fontStyle=1;align=center;verticalAlign=middle;")
BODY = ("rounded=0;html=1;fillColor=#ffffff;strokeColor=#000000;"
        "strokeWidth=1.5;")
LBL = ("rounded=0;html=1;whiteSpace=wrap;fillColor=#ffffff;strokeColor=none;"
       "fontFamily=" + FONT + ";fontSize=11;fontColor=" + BLACK +
       ";align=center;verticalAlign=middle;")
EDGE_ST = ("edgeStyle=orthogonalEdgeStyle;rounded=0;orthogonalLoop=1;"
           "jettySize=auto;html=1;whiteSpace=wrap;fontFamily=" + FONT +
           ";fontSize=11;fontColor=" + BLACK +
           ";strokeColor=#000000;strokeWidth=1.2;endArrow=classic;"
           "endFill=1;endSize=6;")


def node_cell(nid, kind, x, y, w, h, value):
    if kind == "end":
        return vert(nid, "", END_O, x, y, w, h)
    st = {"act": ACT, "dec": DEC, "start": START, "bar": BAR}[kind]
    return vert(nid, value, st, x, y, w, h)


def edge_cell(eid, src, tgt, sfr, tfr, pts):
    st = (EDGE_ST + "exitX=" + str(sfr[0]) + ";exitY=" + str(sfr[1]) +
          ";exitDx=0;exitDy=0;entryX=" + str(tfr[0]) +
          ";entryY=" + str(tfr[1]) + ";entryDx=0;entryDy=0;")
    inner = "".join('<mxPoint x="' + str(x) + '" y="' + str(y) + '"/>'
                    for (x, y) in pts[1:-1])
    return ("<mxCell id=\"" + eid + "\" value=\"\" style=\"" + st +
            "\" edge=\"1\" parent=\"1\" source=\"" + src +
            "\" target=\"" + tgt + "\">" +
            '<mxGeometry relative="1" as="geometry">' +
            '<mxPoint x="' + str(pts[0][0]) + '" y="' + str(pts[0][1]) +
            '" as="sourcePoint"/>' +
            '<mxPoint x="' + str(pts[-1][0]) + '" y="' + str(pts[-1][1]) +
            '" as="targetPoint"/>' +
            '<Array as="points">' + inner + '</Array>' +
            '</mxGeometry></mxCell>')


def build_cells():
    cells = [text_cell("title", TITLE, 40, TITLE_Y, PAGE_W - 80, 30, 18,
                       bold=True),
             text_cell("sub", SUBTITLE, 40, SUB_Y, PAGE_W - 80, 26, 14,
                       bold=True)]
    for i, (name, lx, lw) in enumerate(LANES):
        cells.append(vert("hdr" + str(i), name, HDR, lx, HDR_Y, lw, HDR_H))
        cells.append(vert("body" + str(i), "", BODY, lx, BODY_Y, lw, BODY_H))
    for n in NODES:
        cells.append(node_cell(*n))
    for e in EDGES:
        cells.append(edge_cell(*e))
    for (cid, txt, x, y, w, h) in LABELS:
        cells.append(vert(cid, txt, LBL, x, y, w, h))
    cells.append(vert("fino", "", END_I, 293, 1065, 14, 14))
    cells.append(text_cell("leghead", LEG_HEAD, 40, LEG_HEAD_Y, 500, 15,
                           11, bold=True, align="left"))
    cells.append(text_cell("legrow", LEGEND, 40, LEG_Y, PAGE_W - 80, 15,
                           10, align="left"))
    cells.append(text_cell("cap", CAPTION, 40, CAP_Y, PAGE_W - 80, 26,
                           14, italic=True))
    return cells


def drawio_xml(cells):
    body = ['<mxfile host="app.diagrams.net">',
            '<diagram id="act36" name="3.6 Activity Diagram">',
            '<mxGraphModel dx="1654" dy="1169" grid="1" gridSize="10" '
            'guides="1" tooltips="1" connect="1" arrows="1" fold="1" '
            'page="1" pageScale="1" pageWidth="1654" '
            'pageHeight="1169" math="0" shadow="0">',
            '<root><mxCell id="0"/><mxCell id="1" parent="0"/>']
    body.extend(cells)
    body.append('</root></mxGraphModel></diagram></mxfile>')
    return "\n".join(body) + "\n"

# --------------------------------------------------------------------------
# Validation: parse, wiring, bounds, overlaps, text fit, segment crossings
# --------------------------------------------------------------------------
def overlaps(a, b, slack=1.0):
    (ax, ay, aw, ah), (bx, by, bw, bh) = a, b
    return (ax < bx + bw - slack and bx < ax + aw - slack and
            ay < by + bh - slack and by < ay + ah - slack)


def seg_hits_rect(p, q, rect, pad=1.0):
    """True if axis-aligned segment p-q passes through rect interior."""
    x, y, w, h = rect
    x0, y0, x1, y1 = x + pad, y + pad, x + w - pad, y + h - pad
    (px, py), (qx, qy) = p, q
    if px == qx:
        if x0 < px < x1:
            lo, hi = sorted((py, qy))
            return hi > y0 and lo < y1
        return False
    if py == qy:
        if y0 < py < y1:
            lo, hi = sorted((px, qx))
            return hi > x0 and lo < x1
        return False
    raise AssertionError("non-orthogonal segment %s -> %s" % (p, q))


def est_w(text, fs):
    return int(len(text) * fs * 0.56) + 1


def validate(xml_text):
    root = ET.fromstring(xml_text)
    ids, edges = set(), []
    for cell in root.iter("mxCell"):
        cid = cell.get("id")
        assert cid and cid not in ids, "duplicate id: %r" % cid
        ids.add(cid)
        if cell.get("edge") == "1":
            edges.append(cell)
    assert len(edges) == len(EDGES), (len(edges), len(EDGES))
    for cell in edges:
        assert cell.get("source") in ids, "edge %s: bad source" % cell.get("id")
        assert cell.get("target") in ids, "edge %s: bad target" % cell.get("id")
    for n in NODES:
        (nid, kind, x, y, w, h, value) = n
        assert 0 <= x and x + w <= PAGE_W and 0 <= y and y + h <= PAGE_H, nid
        assert BODY_Y <= y and y + h <= BODY_Y + BODY_H, (nid, "not in body")
        cxx = x + w / 2.0
        for (_nm, lx, lw) in LANES:
            if lx <= cxx < lx + lw:
                assert x >= lx - 1 and x + w <= lx + lw + 1, (nid, "lane spill")
                break
        else:
            raise AssertionError("no lane for %s" % nid)
        for line in value.split("<br>"):
            assert est_w(line, 12) <= w - 8, (nid, "text overflow", line)
    for i in range(len(NODES)):
        for j in range(i + 1, len(NODES)):
            a, b = NODES[i], NODES[j]
            ra = (a[2], a[3], a[4], a[5])
            rb = (b[2], b[3], b[4], b[5])
            assert not overlaps(ra, rb), "overlap: %s / %s" % (a[0], b[0])
    for (cid, txt, x, y, w, h) in LABELS:
        assert 0 <= x and x + w <= PAGE_W and 0 <= y and y + h <= PAGE_H, cid
        assert est_w(txt, 11) <= w - 4, (cid, "label overflow", txt)
    for e in EDGES:
        (eid, src, tgt, _sf, _tf, pts) = e
        assert len(pts) >= 2, eid
        for (p, q) in zip(pts, pts[1:]):
            assert p != q, (eid, "zero-length segment")
            for n in NODES:
                if n[0] in (src, tgt):
                    continue
                r = (n[2], n[3], n[4], n[5])
                assert not seg_hits_rect(p, q, r), "%s hits %s" % (eid, n[0])
    return len(ids), len(edges)


def main():
    cells = build_cells()
    xml = drawio_xml(cells)
    n_cells, n_edges = validate(xml)
    out = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                       "3.6_Activity_Diagram.drawio")
    with open(out, "w", encoding="utf-8", newline="\n") as fh:
        fh.write(xml)
    print("[3.6] wrote %s" % out)
    print("[3.6] cells=%d nodes=%d edges=%d labels=%d" %
          (n_cells, len(NODES), n_edges, len(LABELS)))


if __name__ == "__main__":
    main()

# --------------------------------------------------------------------------
# Narrative (.md + .docx): same structure and writer as the 3.4/3.5 assets
# --------------------------------------------------------------------------
import re

NARR_MD = """# 3.6 Activity Diagram

An activity diagram models the flow of control through the activities of a
process: rounded rectangles are actions, diamonds are decisions whose
outgoing edges carry guard conditions, a filled circle marks the start of
the flow, a circle with a thick inner disc marks its end, and a thick bar
splits the flow into parallel threads. In a swimlane layout every action
sits in the lane of the role that performs it, so the diagram shows not
only what happens but who makes it happen. Section 3.6 applies this
notation to the appointment scheduling process of the proposed system,
drawn from the working prototype so that every action and guard condition
corresponds to implemented code.

Figure 7 presents the activity diagram of the appointment scheduling
process of the proposed AI-Based Intelligent Dental Appointment
Scheduling System Using Machine Learning for Optimized Clinic
Management. The diagram is a single-page swimlane activity diagram with
three vertical lanes, PATIENT, SYSTEM, and ADMINISTRATOR, so that every
activity is attributed to the role that performs it while the flow still
reads from top to bottom. The process begins at the initial node at the
head of the PATIENT lane and terminates at the activity-final node after
the patient has received the final appointment status notification.

The PATIENT lane contains the activities performed through the patient
web interface. The flow starts with the login activity, in which the user
submits a username and password on the login page. The patient then
opens the Book Appointment module and selects the dentist, service,
date, and an available start time; the time slots offered in the form
are queried from the system for the chosen dentist, service, and date,
so that only schedules the system can actually serve are displayed.
Submitting the form hands the request to the SYSTEM lane. Later, after
the appointment has been stored, control returns to the PATIENT lane
twice: first to view the booking confirmation, which shows the new
appointment with the status Pending, and finally to receive the
appointment status notification (Confirmed or Cancelled) after the
administrator has decided on the request.

The SYSTEM lane contains every automatic validation and decision. The
submitted credentials are first verified against the users table, which
reaches the Credentials valid? decision. The rejected branch, guarded
[invalid credentials], returns to the login activity, while the [valid]
branch releases the flow to the Book Appointment module. When the
request is submitted, the system executes the scheduling validations of
the booking module in sequence. The first block validates the required
fields, the time format, the end time, which must be later than the
start time, and the requested date, which must not be in the past. The
second block evaluates the scheduling rules:

- The requested date is not a Sunday, because the clinic is closed on that day.
- The date does not fall on the dentist's weekly rest day.
- The date does not coincide with a specific day-off assigned to the dentist.
- The requested slot does not overlap an existing appointment of the same dentist on the same date, counting every status except Cancelled and No-Show.
- The requested slot is covered by a schedule configured for the dentist on that date, whenever schedules are maintained.

All checks feed the Schedule valid? decision. The [No] branch leads to
the error-handling activity, in which the system displays the message
matching the failed rule and asks the patient to select another
schedule; a feedback edge then returns the flow to the slot-selection
activity in the PATIENT lane so that the request can be corrected and
resubmitted. The [Yes] branch inserts the appointment record with the
status Pending and creates the patient notification titled Appointment
Request Submitted, which informs the patient that the request is
waiting for confirmation. A fork then splits the flow into two parallel
threads: in the patient thread the booking confirmation becomes visible
with the Pending status, while in the administrator thread the new
Pending request appears in the appointment-monitoring screen for
review. The fork expresses that both threads exist at the same time
once a booking is stored; neither thread blocks the other.

The ADMINISTRATOR lane contains the review activities performed in the
appointment-management screen. The administrator reviews the new
appointment request and reaches the Confirm or cancel? decision. The
[Confirm] branch sets the appointment status to Confirmed, and the
[Cancel] branch sets it to Cancelled; both branches merge into the
status-change activity, in which the system records the new status and
notifies the patient with the corresponding confirmation or
cancellation message. The same screen also applies the remaining
statuses of the controlled vocabulary, Completed after the visit and
Rescheduled through the reschedule action, but Figure 7 focuses on the
confirmation decision that every submitted request must pass. The
notified patient then receives the appointment status notification in
the PATIENT lane, and the flow ends at the activity-final node.

The diagram therefore documents three termination paths. The
successful path runs from the initial node through credential
verification, form submission, validation, insertion of the Pending
appointment, administrator confirmation, and the status notification
to the final node. The credential-rejection path never leaves the login
cycle until valid credentials are entered, so it cannot reach the final
node. The schedule-rejection path loops back to slot selection and
re-enters the validation chain, which guarantees that only appointments
that satisfy every scheduling rule are ever stored. Because both
rejection paths are labeled, every branch leaving a decision diamond in
Figure 7 carries an explicit guard condition taken from the code.

The figure uses standard UML 2 activity notation: a filled circle for
the initial node, a circle with a thick inner disc for the
activity-final node, a rounded rectangle for an action, a diamond for a
decision, a thick bar for the fork, and bracketed text such as [Yes]
for a guard on an edge; a brief legend at the foot of the figure
repeats these symbols. The deliverables for this section are the figure
itself in draw.io format (**3.6_Activity_Diagram.drawio**, regenerated
by **gen_activity36.py**), this narrative
(**3.6_Activity_Diagram_Narrative.md**), and its Word copy
(**3.6_Activity_Diagram_Narrative.docx**).
"""


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
        else:
            p = doc.add_paragraph(text)
            p.alignment = WD_ALIGN_PARAGRAPH.JUSTIFY
        for run in p.runs:
            run.font.name = "Times New Roman"
            run.font.size = Pt(12 if kind == "P" else 14)
    doc.save(path)


def write_narrative():
    out = os.path.dirname(os.path.abspath(__file__))
    md = re.sub(r"\n{3,}", "\n\n", NARR_MD).rstrip() + "\n"
    md_path = os.path.join(out, "3.6_Activity_Diagram_Narrative.md")
    with open(md_path, "w", encoding="utf-8", newline="\n") as fh:
        fh.write(md)
    paras = nar_paragraphs(md)
    docx_path = os.path.join(out, "3.6_Activity_Diagram_Narrative.docx")
    write_docx(docx_path, paras)
    print("[3.6] wrote %s (%d paragraphs)" % (md_path, len(paras)))
    print("[3.6] wrote %s" % docx_path)


if __name__ == "__main__":
    write_narrative()
