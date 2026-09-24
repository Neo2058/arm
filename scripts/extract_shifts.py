#!/usr/bin/env python3
"""Extract locomotive shift tables from weekday/weekend PDFs (same logic as the searching prototype)."""

from __future__ import annotations

import argparse
import re
import sys
from collections import defaultdict
from pathlib import Path

try:
    import pdfplumber
except ImportError:
    sys.stderr.write("Нужен pdfplumber: pip3 install pdfplumber\n")
    sys.exit(1)

COL = {
    "route": (0, 60),
    "shift": (60, 100),
    "start": (100, 158),
    "end": (158, 218),
    "line": (218, 268),
    "work": (268, 595),
    "rest": (595, 775),
    "dur": (775, 900),
}

TIME_RE = re.compile(r"^(\d{1,2}\.\d{2})([а-яёa-z]+)?", re.I)
SHIFT_ID_RE = re.compile(r"^(\d+)([+пp]|п)?\.?$", re.I)
ROUTE_RE = re.compile(r"(\d+)\s*мар", re.I)
NAMED_RE = re.compile(r"^(П-\d+|М-\d+|РЭ|РБ|Н\d+)$", re.I | re.U)


def col_text(words, name):
    x0, x1 = COL[name]
    return " ".join(w["text"] for w in words if x0 <= w["x0"] < x1).strip()


def parse_time_token(text):
    if not text:
        return None
    m = TIME_RE.match(text.replace(" ", ""))
    if not m:
        return None
    place = (m.group(2) or "").lower()
    if place.endswith("-чт") or place.endswith("-нчт"):
        place = place.split("-")[0]
    return m.group(1), place


def first_time(text):
    if not text:
        return None
    for part in text.replace(",", " ").split():
        t = parse_time_token(part)
        if t:
            return t
    return parse_time_token(text.replace(" ", ""))


def shift_id(text):
    t = text.strip().replace(" ", "")
    if not t:
        return None
    m = SHIFT_ID_RE.match(t)
    if not m:
        return None
    num, suf = m.group(1), m.group(2) or ""
    if suf in ("п", "p", "P"):
        return num + "п"
    if suf == "+":
        return num + "+"
    return num


def parse_block_code(route_t: str):
    if not route_t:
        return None
    rm = ROUTE_RE.search(route_t)
    if rm:
        return str(int(rm.group(1)))
    m = NAMED_RE.fullmatch(route_t.replace(" ", ""))
    if m:
        return m.group(1).upper()
    return None


def has_odd(text):
    return "нчт" in text.lower()


def has_even(text):
    return bool(re.search(r"чт", text.lower().replace("нчт", " ")))


def row_parity(texts):
    blob = " ".join(texts)
    o, e = has_odd(blob), has_even(blob)
    if o and not e:
        return "odd"
    if e and not o:
        return "even"
    return None


def group_rows(words):
    buckets = defaultdict(list)
    for w in words:
        buckets[round(w["top"] / 4) * 4].append(w)
    return [sorted(buckets[top], key=lambda w: w["x0"]) for top in sorted(buckets)]


def is_noise_row(words):
    t = " ".join(w["text"] for w in words)
    keys = (
        "РАЗБИВКА",
        "Начало",
        "Содержание",
        "Условные",
        "электродепо",
        "№№",
        "Выполняемая",
        "PAGE",
        "движение поездов до",
        "МАНЕВРЫ",
        "РЕЗЕРВ ст.",
    )
    return any(k in t for k in keys)


def infer_kind(title: str, filename: str, explicit: str | None) -> str:
    if explicit in ("work", "weekend"):
        return explicit
    blob = f"{title} {filename}".lower()
    if any(x in blob for x in ("выходн", "субботн", "воскресн")):
        return "weekend"
    if "рабоч" in blob:
        return "work"
    return "work"


class Variant:
    def __init__(self):
        self.parity = "both"
        self.start = None
        self.end = None
        self.line = ""
        self.duration = ""
        self.work = []
        self.rest = []

    def add_work(self, s):
        s = " ".join(s.split())
        if s:
            self.work.append(s)

    def add_rest(self, s):
        s = " ".join(s.split())
        if s:
            self.rest.append(s)


class ShiftGroup:
    def __init__(self, code, sid):
        self.code = code
        self.id = sid
        self.variants = []

    def last(self):
        if not self.variants:
            self.variants.append(Variant())
        return self.variants[-1]


def apply_row(group: ShiftGroup, start, end, line, work, rest, dur, new_variant: bool):
    if new_variant or not group.variants:
        v = Variant()
        if group.variants:
            prev = group.last()
            if start is None:
                v.start = prev.start
            if end is None:
                v.end = prev.end
            if not line:
                v.line = prev.line
        group.variants.append(v)
    v = group.last()
    if start:
        v.start = start
    if end:
        v.end = end
    if line:
        v.line = line
    if dur:
        v.duration = dur.replace(" ", "")
    par = row_parity([dur or "", work or "", rest or "", (start[1] if start else ""), (end[1] if end else "")])
    if par:
        v.parity = par
    if work:
        v.add_work(work)
    if rest:
        v.add_rest(rest)


def extract_pdf(path: Path):
    groups: list[ShiftGroup] = []
    title = ""
    current_code = None
    current: ShiftGroup | None = None

    with pdfplumber.open(str(path)) as doc:
        for page in doc.pages:
            words = page.extract_words(use_text_flow=False) or []
            if not title:
                raw = page.extract_text() or ""
                for line in raw.splitlines():
                    if "РАЗБИВКА" in line:
                        title = " ".join(line.split())
                        break
            for words_row in group_rows(words):
                if is_noise_row(words_row):
                    continue
                route_t = col_text(words_row, "route")
                shift_t = col_text(words_row, "shift")
                start_t = col_text(words_row, "start")
                end_t = col_text(words_row, "end")
                line_t = col_text(words_row, "line")
                work_t = col_text(words_row, "work")
                rest_t = col_text(words_row, "rest")
                dur_t = col_text(words_row, "dur")

                parsed_code = parse_block_code(route_t)
                if parsed_code:
                    current_code = parsed_code

                sid = shift_id(shift_t)
                start = first_time(start_t)
                end = first_time(end_t)
                line = line_t.replace(" ", "") if re.fullmatch(r"[ЛРлр/]+", line_t.replace(" ", "")) else ""

                if sid:
                    if current_code is None:
                        continue
                    current = ShiftGroup(current_code, sid)
                    groups.append(current)
                    apply_row(current, start, end, line, work_t, rest_t, dur_t, new_variant=True)
                    continue

                if current is None:
                    continue

                if start or (end and not work_t and not rest_t):
                    apply_row(current, start, end, line, work_t, rest_t, dur_t, new_variant=True)
                    continue

                if end and not start:
                    if end != current.last().end and (has_odd(work_t + dur_t + end_t) or has_even(work_t + dur_t + end_t)):
                        apply_row(current, None, end, line, work_t, rest_t, dur_t, new_variant=True)
                        continue
                    if current.last().end is None:
                        current.last().end = end

                if work_t or rest_t or dur_t:
                    par = row_parity([work_t, rest_t, dur_t])
                    targets = list(current.variants)
                    if par and len(current.variants) >= 2:
                        targets = [v for v in current.variants if v.parity == par] or [current.last()]
                    for v in targets:
                        if work_t:
                            v.add_work(work_t)
                        if rest_t:
                            v.add_rest(rest_t)
                        if dur_t and not v.duration:
                            v.duration = dur_t.replace(" ", "")

    for g in groups:
        if len(g.variants) == 2 and all(v.parity == "both" for v in g.variants):
            g.variants[0].parity = "even"
            g.variants[1].parity = "odd"
        if len(g.variants) == 1:
            g.variants[0].parity = g.variants[0].parity or "both"

    return title, groups


def format_groups(title: str, kind: str, groups: list[ShiftGroup]) -> str:
    lines = [f"KIND {kind}", f"TITLE {title}", ""]
    for g in groups:
        for v in g.variants:
            lines.append("SHIFT")
            if g.code.isdigit():
                lines.append(f"ROUTE {g.code}")
            lines.append(f"CODE {g.code}")
            lines.append(f"ID {g.id}")
            lines.append(f"PARITY {v.parity}")
            if v.start:
                lines.append(f"START {v.start[0]} {v.start[1]}".rstrip())
            if v.end:
                lines.append(f"END {v.end[0]} {v.end[1]}".rstrip())
            if v.line:
                lines.append(f"LINE {v.line}")
            if v.duration:
                lines.append(f"DURATION {v.duration}")
            for w in v.work:
                lines.append(f"WORK {w}")
            for r in v.rest:
                lines.append(f"REST {r}")
            lines.append("")
    return "\n".join(lines)


def main():
    parser = argparse.ArgumentParser(description="Extract shift breakdown PDF to SHIFT text")
    parser.add_argument("--pdf", required=True, help="Path to PDF")
    parser.add_argument("--kind", choices=["work", "weekend", "auto"], default="auto")
    parser.add_argument("--out", help="Write to file instead of stdout")
    args = parser.parse_args()

    pdf = Path(args.pdf)
    if not pdf.is_file():
        sys.stderr.write(f"нет файла {pdf}\n")
        sys.exit(2)

    title, groups = extract_pdf(pdf)
    kind = infer_kind(title, pdf.name, None if args.kind == "auto" else args.kind)
    text = format_groups(title, kind, groups)
    if args.out:
        Path(args.out).write_text(text, encoding="utf-8")
        sys.stderr.write(f"{kind}: {len(groups)} смен, title={title!r}\n")
    else:
        sys.stdout.write(text)


if __name__ == "__main__":
    main()
