#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Add anti-CSRF tokens to the state-changing pages of the dental system.

Usage:

    python tools/apply_csrf.py            # report only, changes nothing
    python tools/apply_csrf.py --write    # apply the changes

What the script does, once per target file:

  1. Inserts one request guard before the first REQUEST_METHOD test:

         if ($_SERVER['REQUEST_METHOD'] === 'POST') {
             requireCsrf();
         }

     The guard is placed before the first test rather than inside a single
     handler, so that every POST handler of the page, including the ones
     written across several lines, is covered by exactly one check.

  2. Inserts <?= csrfField() ?> immediately after the opening tag of every
     <form> whose attributes select the POST method, so that the token
     travels with the submission the guard validates.

The script is idempotent: a file that already carries the guard or the
token field is left as it is, and the report says so.

walkin_qr.php is deliberately NOT a target. It is the anonymous walk-in
kiosk reached by scanning a QR code, it serves visitors who have no account
and may not keep session cookies, and it performs no action on behalf of a
signed-in user, so there is no privileged session for a forged request to
borrow. Section 3.13 of the manuscript records this decision.
"""

import io
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

TARGETS = [
    "forgot_password.php",
    "login.php",
    "reset_password.php",
    "admin/appointments.php",
    "admin/appointment_concern.php",
    "admin/dentists.php",
    "admin/no_show.php",
    "admin/patients.php",
    "admin/queue.php",
    "admin/register.php",
    "admin/settings.php",
    "dentist/appointments.php",
    "dentist/profile.php",
    "dentist/queue.php",
    "dentist/settings.php",
    "patient/appointment_concern.php",
    "patient/book_appointment.php",
    "patient/book_scheduling.php",
    "patient/my_appointments.php",
    "patient/profile.php",
    "patient/settings.php",
]

# NOT targets, and why:
#
#   walkin_qr.php
#       The anonymous walk-in kiosk reached by scanning a QR code. It serves
#       visitors who have no account and may not keep session cookies, and it
#       performs no action on behalf of a signed-in user, so there is no
#       privileged session for a forged request to borrow.
#
#   patient/patient_dashboard.php, dentist/dentist_dashboard.php
#       Both hold a POST handler but the pages carry no <form> and no script
#       that submits one, so no token could travel with a request to them.
#       The same actions are alive in patient/my_appointments.php and in
#       dentist/queue.php with dentist/appointments.php, which ARE guarded.
#       Guarding them would turn the legacy handler into a hard failure if it
#       is ever reached, so they are reported instead of changed.
UNTARGETED = [
    "walkin_qr.php",
    "patient/patient_dashboard.php",
    "dentist/dentist_dashboard.php",
]

REQUEST_TEST = re.compile(r"""\$_SERVER\s*\[\s*['"]REQUEST_METHOD['"]\s*\]""")

# The two programs that post without a form element. They read the token from
# the hidden input that the same page already renders, so no extra output is
# needed in PHP.
JS_PATCHES = [
    (
        "admin/appointment_concern.php",
        '                    fd.append("notification_id", notifId);',
        '                    var csrfEl = document.querySelector(\'input[name="csrf_token"]\');\n'
        '                    if (csrfEl) fd.append("csrf_token", csrfEl.value);',
    ),
    (
        "patient/my_appointments.php",
        "    formData.append('appointment_id', btn.getAttribute('data-appointment-id') || '');",
        "    var csrfEl = document.querySelector('input[name=\"csrf_token\"]');\n"
        "    if (csrfEl) formData.append('csrf_token', csrfEl.value);",
    ),
]

GUARD = (
    "\n"
    "/*\n"
    "|--------------------------------------------------------------------------\n"
    "| CROSS-SITE REQUEST FORGERY CHECK\n"
    "|--------------------------------------------------------------------------\n"
    "| Every request that changes data is compared with the token held in the\n"
    "| session before any handler of this page runs. A submission that carries\n"
    "| no token, or a token that does not match, is refused.\n"
    "*/\n"
    "if ($_SERVER['REQUEST_METHOD'] === 'POST') {\n"
    "    requireCsrf();\n"
    "}\n"
)

FORM_TOKEN = "\n<?= csrfField() ?>"

POST_ATTR = re.compile(r"""method\s*=\s*["']?post\b""", re.I)


def find_form_tags(text):
    """Yield (start, end) of every <form ...> tag, quote aware.

    end is the index just past the '>' that closes the opening tag.
    """
    for m in re.finditer(r"<form\b", text, re.I):
        i = m.end()
        quote = None
        while i < len(text):
            ch = text[i]
            if quote:
                if ch == quote:
                    quote = None
            elif ch in "\"'":
                quote = ch
            elif ch == ">":
                yield m.start(), i + 1
                break
            i += 1


IF_OPEN = re.compile(r"\bif\s*\(")


def guard_index(text):
    """Index at the start of the statement holding the first REQUEST_METHOD test.

    The test is often written on a line of its own inside a condition that
    spans several lines, as in

        if (
            $_SERVER['REQUEST_METHOD'] === 'POST'
            && ($_POST['action'] ?? '') === 'upload_photo'
        ) {

    so the search walks back to the 'if (' that opens that statement. The
    candidate is only accepted when nothing between the two positions ends a
    statement, which is always true inside a condition.
    """
    m = REQUEST_TEST.search(text)
    if not m:
        return -1

    pos = m.start()
    best = -1
    for cand in IF_OPEN.finditer(text, 0, pos):
        between = text[cand.end():pos]
        if any(ch in between for ch in ";{}"):
            continue
        best = cand.start()

    if best == -1:
        return text.rfind("\n", 0, pos) + 1

    return text.rfind("\n", 0, best) + 1


def apply_js_patches(path, text, write):
    """Give the two form-less POST programs the token they must send."""
    changed = False
    for rel, needle, addition in JS_PATCHES:
        if rel.replace("/", os.sep) != os.path.relpath(path, ROOT):
            continue
        if "csrf_token" in needle:
            continue
        if "csrfEl" in text:
            continue
        if needle not in text:
            return text, "JS patch anchor not found"
        text = text.replace(needle, needle + "\n" + addition, 1)
        changed = True
    if changed and write:
        with io.open(path, "w", encoding="utf-8", newline="") as fh:
            fh.write(text)
    return text, ("JS token appended" if changed else "")


def process(path, write):
    with io.open(path, encoding="utf-8", newline="") as fh:
        text = fh.read()

    original = text
    notes = []

    # --- 1. the request guard -------------------------------------------
    if "requireCsrf();" in text:
        notes.append("guard already present")
    else:
        idx = guard_index(text)
        if idx == -1:
            notes.append("SKIPPED: no REQUEST_METHOD test")
        else:
            head = text[:idx].lower()
            loader = max(head.find("config.php"), head.find("functions.php"))
            if loader == -1:
                notes.append("SKIPPED: config.php is not loaded first")
            else:
                text = text[:idx] + GUARD + text[idx:]
                notes.append("guard added")

    # --- 2. the token field in every POST form ---------------------------
    added = 0
    out = []
    last = 0
    for start, end in find_form_tags(text):
        tag = text[start:end]
        out.append(text[last:end])
        last = end
        if POST_ATTR.search(tag):
            # skip if this form already carries the field
            if "csrfField" not in text[end:end + 300]:
                out.append(FORM_TOKEN)
                added += 1
    out.append(text[last:])
    text = "".join(out)

    if added:
        notes.append("%d token field(s) added" % added)

    # --- 3. the two form-less POST programs ------------------------------
    text, js_note = apply_js_patches(path, text, write)
    if js_note:
        notes.append(js_note)

    changed = text != original

    if changed and write:
        with io.open(path, "w", encoding="utf-8", newline="") as fh:
            fh.write(text)

    return changed, "; ".join(notes)


def main():
    write = "--write" in sys.argv
    changed_total = 0
    for rel in TARGETS:
        path = os.path.join(ROOT, rel.replace("/", os.sep))
        if not os.path.exists(path):
            print("MISSING  %s" % rel)
            continue
        changed, notes = process(path, write)
        if changed:
            changed_total += 1
        print("%-38s %-9s %s" % (rel, "CHANGED" if changed else "ok", notes))

    print()
    for rel in UNTARGETED:
        path = os.path.join(ROOT, rel.replace("/", os.sep))
        guarded = False
        if os.path.exists(path):
            with io.open(path, encoding="utf-8", newline="") as fh:
                guarded = "requireCsrf();" in fh.read()
        print("%-38s %-9s %s"
              % (rel, "ok", "guarded" if guarded else "left unguarded (by design)"))

    print()
    print("%s: %d file(s) %s"
          % ("APPLIED" if write else "DRY RUN",
             changed_total,
             "written" if write else "would change"))
    return 0


if __name__ == "__main__":
    sys.exit(main())
