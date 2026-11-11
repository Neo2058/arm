#!/usr/bin/env python3
"""Check repository-local file destinations in project Markdown without network access."""
from pathlib import Path
import re
import subprocess
import sys
from urllib.parse import unquote, urlsplit

ROOT = Path(__file__).resolve().parents[2]


def destinations(text):
    text = re.sub(r'(?ms)^\s*(`{3,}|~{3,})[^\n]*\n.*?^\s*\1\s*$', '', text)
    for match in re.finditer(r'!?\[[^\]\n]*\]\(\s*(<[^>]+>|[^\s)]+)', text):
        yield match.group(1).strip('<>')
    for match in re.finditer(r'(?m)^\s*\[[^\]]+\]:\s*(<[^>]+>|\S+)', text):
        yield match.group(1).strip('<>')
    for match in re.finditer(r'<(?:img|a)\b[^>]*?\b(?:src|href)=["\x27]([^"\x27]+)', text):
        yield match.group(1)


def main():
    names = subprocess.check_output(
        ['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], cwd=ROOT
    ).decode().split('\0')
    files = sorted({ROOT / n for n in names if n.endswith('.md') and (ROOT / n).is_file()})
    errors = []
    checked = 0
    skipped_absolute = 0
    for file in files:
        for url in destinations(file.read_text()):
            parsed = urlsplit(url)
            if parsed.scheme or parsed.netloc or not parsed.path:
                continue
            path = Path(unquote(parsed.path))
            if path.is_absolute():
                # Local IDE links are portable only if inside this repository.
                if not path.is_relative_to(ROOT):
                    skipped_absolute += 1
                    continue
                target = path
            else:
                target = file.parent / path
            checked += 1
            if not target.exists():
                errors.append(f'{file.relative_to(ROOT)}: {url}')
    for error in errors:
        print('BROKEN', error)
    print(f'{len(files)} Markdown files; {checked} local destinations; '
          f'{len(errors)} broken; {skipped_absolute} external absolute paths skipped.')
    return bool(errors)


if __name__ == '__main__':
    sys.exit(main())
