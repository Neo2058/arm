#!/usr/bin/env python3
"""Extract text from a naryad PDF, keeping column layout for NaryadParser."""

from __future__ import annotations

import sys

try:
    import pdfplumber
except ImportError:
    sys.stderr.write("Нужен pdfplumber: pip3 install pdfplumber\n")
    sys.exit(1)

if len(sys.argv) < 2:
    sys.stderr.write("usage: extract_naryad_pdf.py FILE.pdf\n")
    sys.exit(1)

path = sys.argv[1]
parts: list[str] = []
with pdfplumber.open(path) as pdf:
    for page in pdf.pages:
        text = page.extract_text(layout=True) or page.extract_text() or ""
        parts.append(text.rstrip())

sys.stdout.write("\n".join(parts))
if parts:
    sys.stdout.write("\n")
