#!/usr/bin/env python3
"""Insert or rebuild a "## Contents" list in long markdown reference files.

Usage:
    python3 add_contents.py <dir-or-file>... [--min-lines 100] [--check]

For each markdown file at or above --min-lines, builds a bullet list of the
file's "## " headings and places it as a "## Contents" section directly after
the first H1 (or after the frontmatter when there is no H1). An existing
Contents section is replaced, so the script is safe to run after refreshing a
file from upstream. --check reports files that would change and exits 1
without writing.

Files with fewer than two "## " headings are skipped: a one-item list tells the
reader nothing.
"""

from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

# Matches the house rule: references over 100 lines need a Contents list so a
# partial read (head -100) still shows the file's scope.
DEFAULT_MIN_LINES = 100
CONTENTS_RE = re.compile(r"^## contents\s*$", re.I)
H2_RE = re.compile(r"^## (?!contents\s*$)(.+?)\s*$", re.I)


H3_RE = re.compile(r"^### (.+?)\s*$")


def build_contents(lines: list[str]) -> str | None:
    heads = [m.group(1) for l in lines if (m := H2_RE.match(l))]
    if len(heads) < 2:
        # Upstream READMEs sometimes nest everything under H3; list those instead.
        heads = [m.group(1) for l in lines if (m := H3_RE.match(l))]
    if len(heads) < 2:
        return None
    return "## Contents\n\n" + "\n".join(f"- {h}" for h in heads) + "\n\n"


def strip_existing(lines: list[str]) -> list[str]:
    """Remove a previous Contents section: the heading, its bullets, and the blanks around them."""
    out, skipping = [], False
    for l in lines:
        if CONTENTS_RE.match(l):
            skipping = True
            continue
        if skipping:
            if not l.strip() or l.startswith("- "):
                continue
            skipping = False
        out.append(l)
    return out


def insert_point(lines: list[str]) -> int:
    """Index at which the Contents block goes: after the H1 and its trailing blank."""
    i = 0
    if lines and lines[0].strip() == "---":
        for j in range(1, len(lines)):
            if lines[j].strip() == "---":
                i = j + 1
                break
    for j in range(i, len(lines)):
        if lines[j].startswith("# "):
            i = j + 1
            break
    while i < len(lines) and not lines[i].strip():
        i += 1
    return i


def process(path: Path, min_lines: int, check: bool) -> bool:
    text = path.read_text(encoding="utf-8")
    if len(text.splitlines()) < min_lines:
        return False
    lines = strip_existing(text.splitlines(keepends=True))
    block = build_contents(lines)
    if block is None:
        return False
    i = insert_point(lines)
    new = "".join(lines[:i]) + block + "".join(lines[i:])
    if new == text:
        return False
    if not check:
        path.write_text(new, encoding="utf-8")
    return True


def main() -> int:
    ap = argparse.ArgumentParser(description="Insert or rebuild ## Contents in long markdown files.")
    ap.add_argument("paths", nargs="+", help="markdown files or directories")
    ap.add_argument("--min-lines", type=int, default=DEFAULT_MIN_LINES)
    ap.add_argument("--check", action="store_true", help="report only; exit 1 if any file would change")
    args = ap.parse_args()

    files: list[Path] = []
    for p in map(Path, args.paths):
        if p.is_dir():
            files.extend(sorted(p.rglob("*.md")))
        elif p.suffix == ".md":
            files.append(p)
        else:
            print(f"skip {p}: not a markdown file or directory", file=sys.stderr)
    changed = [f for f in files if process(f, args.min_lines, args.check)]
    verb = "would change" if args.check else "updated"
    for f in changed:
        print(f"{verb}: {f}")
    print(f"{len(changed)} of {len(files)} file(s) {verb}")
    return 1 if (args.check and changed) else 0


if __name__ == "__main__":
    sys.exit(main())
