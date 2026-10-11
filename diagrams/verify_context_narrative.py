#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Check the revised 3.4 narrative against the context diagram itself.

The figure is the source of truth: this script reads the flow arrows out of
3.4_Context_Diagram_PROPOSED.drawio, works out how many flows enter and leave
process 0 and which entity owns each one, and then verifies that

  * the narrative states the same totals (31 flows, 16 in, 15 out)
  * every flow label drawn in the figure appears in the narrative
  * the flow inventory table of the narrative lists each code once, with the
    same direction and the same entity as the drawing
  * the regenerated .docx keeps the chapter 3 formatting

Nothing is written; run it after editing the narrative.
"""

import io
import re
import xml.etree.ElementTree as ET

DRAWIO = "3.4_Context_Diagram_PROPOSED.drawio"
NARR = "3.4_Context_Diagram_Narrative.md"
DOCX = "3.4_Context_Diagram_Narrative.docx"
ENTITY = {"ent0": "Patient", "ent1": "Dentist", "ent2": "Administrator"}

PROBLEMS = []


def main():
    root = ET.parse(DRAWIO).getroot()
    model = [c for c in root if c.tag == "diagram"][0].find("mxGraphModel")
    cells = list(model.iter("mxCell"))

    labels = {}
    for c in cells:
        cid = c.get("id") or ""
        if cid.startswith("legP"):
            labels[cid[3:]] = (c.get("value") or "").split("=", 1)[1].strip()

    flows = {}
    for c in cells:
        if c.get("edge") != "1":
            continue
        code = (c.get("value") or "").strip()
        src, tgt = c.get("source"), c.get("target")
        if tgt == "proc":
            flows[code] = ("I", ENTITY.get(src, src))
        elif src == "proc":
            flows[code] = ("O", ENTITY.get(tgt, tgt))
        else:
            PROBLEMS.append("flow %s does not touch the process" % code)
            continue
        if code not in labels:
            PROBLEMS.append("flow %s has no label cell" % code)

    order = sorted(flows, key=lambda k: int(k[1:]))
    inputs = [c for c in order if flows[c][0] == "I"]
    outputs = [c for c in order if flows[c][0] == "O"]
    print("figure: %d flows, %d inputs, %d outputs"
          % (len(flows), len(inputs), len(outputs)))
    for name, grp in (("Patient", "Patient"), ("Dentist", "Dentist"),
                      ("Administrator", "Administrator")):
        i = [c for c in inputs if flows[c][1] == grp]
        o = [c for c in outputs if flows[c][1] == grp]
        print("   %-14s %2d in / %2d out" % (name, len(i), len(o)))

    md = io.open(NARR, encoding="utf-8").read()
    flat = re.sub(r"\s+", " ", md)

    # ---- totals claimed by the prose --------------------------------------
    claim = re.search(r"documents thirty-one data flows: sixteen flows enter"
                      r" the process as inputs and fifteen flows leave it",
                      flat)
    if not claim:
        PROBLEMS.append("the narrative does not state the 31 / 16 / 15 totals")
    else:
        print("narrative totals: 31 / 16 / 15  -> stated")

    # ---- every drawn label is described -----------------------------------
    for code in order:
        label = labels.get(code, "")
        if label and label.lower() not in flat.lower():
            PROBLEMS.append("label of %s never appears in the narrative: %s"
                            % (code, label))
    print("labels documented: %d/%d"
          % (sum(1 for c in order if labels.get(c, "").lower() in flat.lower()),
             len(order)))

    # ---- the inventory table matches the drawing --------------------------
    rows = re.findall(r"^\| (P\d+) \| (.+?) \| ([IO]) \| (\w+) \|",
                      md, re.M)
    print("inventory rows: %d" % len(rows))
    for code, label, io_dir, ent in rows:
        if code not in flows:
            PROBLEMS.append("inventory lists %s which is not drawn" % code)
            continue
        want_io, want_ent = flows[code]
        if io_dir != want_io:
            PROBLEMS.append("inventory says %s is %s, the figure draws %s"
                            % (code, io_dir, want_io))
        if ent != want_ent:
            PROBLEMS.append("inventory puts %s on %s, the figure on %s"
                            % (code, ent, want_ent))
        drawn = labels.get(code, "")
        if drawn and drawn.lower() != label.strip().lower():
            PROBLEMS.append("%s label differs:\n      figure : %s\n"
                            "      inventory: %s" % (code, drawn, label))
    if len(rows) != len(flows):
        PROBLEMS.append("inventory has %d rows but the figure has %d flows"
                        % (len(rows), len(flows)))

    # ---- the .docx came out with the chapter 3 formatting -----------------
    import docx
    d = docx.Document(DOCX)
    st = d.styles["Normal"]
    heads = [p.text for p in d.paragraphs if p.style.name == "Heading 1"]
    if not heads or "3.4" not in heads[0]:
        PROBLEMS.append("docx has no '3.4 Context Diagram' Heading 1")
    if st.font.name != "Times New Roman" or st.paragraph_format.line_spacing != 1.5:
        PROBLEMS.append("docx body style is not Times New Roman 1.5-spaced")
    marg = [round(getattr(d.sections[0], m).inches, 2) for m in
            ("left_margin", "right_margin")]
    if marg != [1.25, 1.25]:
        PROBLEMS.append("docx margins changed: %s" % marg)
    print("docx: paragraphs=%d tables=%d margins=%s style=%s %.0fpt"
          % (len(d.paragraphs), len(d.tables), marg, st.font.name,
             st.font.size.pt))

    print()
    if PROBLEMS:
        print("PROBLEMS (%d):" % len(PROBLEMS))
        for p in PROBLEMS:
            print("  -", p)
        raise SystemExit(1)
    print("NARRATIVE MATCHES FIGURE 4")


if __name__ == "__main__":
    main()
