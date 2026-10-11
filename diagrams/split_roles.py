#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Split each role page of activity_diagrams.drawio into its own .drawio file.

Each output is a complete, self-contained draw.io document: <mxfile> wrapper
containing exactly one <diagram> page.  The master file is never modified.
"""

import io
import re
import xml.etree.ElementTree as ET

SRC = "activity_diagrams.drawio"

# page id -> output filename
SPLITS = [
    ("p2", "Activity_Diagram_Dentist.drawio"),
    ("p3", "Activity_Diagram_Administrator_P1.drawio"),
    ("p4", "Activity_Diagram_Administrator_P2.drawio"),
]


def main():
    raw = io.open(SRC, encoding="utf-8").read()

    # read the mxfile opening tag so host/agent/page metadata is carried over
    head_end = raw.index(">", raw.index("<mxfile"))
    head = raw[:head_end + 1]

    # slice out each <diagram ...> ... </diagram> block by id
    blocks = {}
    for m in re.finditer(r'<diagram id="(\w+)"[^>]*>', raw):
        pid = m.group(1)
        close = raw.index("</diagram>", m.end()) + len("</diagram>")
        blocks[pid] = raw[m.start():close]

    for pid, out in SPLITS:
        if pid not in blocks:
            raise SystemExit("missing page " + pid)
        block = blocks[pid]

        # a one-page document reports pages="1"
        single_head = re.sub(r'pages="\d+"', 'pages="1"', head, count=1)
        doc = single_head + "\n  " + block + "\n</mxfile>"

        # well-formedness gate - refuse to write anything that will not parse
        root = ET.fromstring(doc)
        pages = list(root)
        if len(pages) != 1:
            raise SystemExit("%s produced %d pages" % (out, len(pages)))

        cells = list(pages[0].find("mxGraphModel").iter("mxCell"))
        edges = [c for c in cells if c.get("edge") == "1"]
        ids = set(c.get("id") for c in cells)
        dangling = [c.get("id") for c in edges
                    if c.get("source") not in ids or c.get("target") not in ids]
        if dangling:
            raise SystemExit("%s has dangling edges: %s" % (out, dangling))

        io.open(out, "w", encoding="utf-8", newline="").write(doc)
        title = [c.get("value") for c in cells
                 if (c.get("id") or "").endswith("_title")]
        print("wrote %-46s cells=%3d edges=%3d  %s"
              % (out, len(cells), len(edges), title[0] if title else ""))


if __name__ == "__main__":
    main()
