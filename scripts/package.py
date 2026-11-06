#!/usr/bin/env python3
"""Create or verify the exact installable release archive."""
import argparse
import json
import re
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
VERSION = re.compile(r"(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\Z")


def field(text, pattern, label):
    matches = re.findall(pattern, text, re.MULTILINE)
    if len(matches) != 1:
        raise ValueError(f"Expected exactly one {label}")
    return matches[0].strip()


def files_and_version(root, tag=None):
    header = field((root / "pv-redirects.php").read_text(), r"^\s*\*\s*Version:\s*(\S+)\s*$", "plugin version")
    stable = field((root / "readme.txt").read_text(), r"^Stable tag:\s*(\S+)\s*$", "stable tag")
    package = json.loads((root / "package.json").read_text())["version"]
    if not VERSION.fullmatch(header) or header != stable or header != package:
        raise ValueError("Plugin, readme, and package versions must match as numeric X.Y.Z")
    if tag is not None and tag != f"v{header}":
        raise ValueError(f"Tag must match v{header}")
    files = [root / name for name in ("pv-redirects.php", "uninstall.php", "readme.txt", "LICENSE", "composer.json")]
    for name in ("src", "build", "languages"):
        directory = root / name
        if directory.exists():
            files.extend(path for path in directory.rglob("*") if path.is_file() and not any(part.startswith(".") for part in path.relative_to(directory).parts) and not path.name.endswith(".map"))
    required = [root / name for name in ("src", "build/index.js", "build/index.asset.php", "build/sidebar.js", "build/sidebar.asset.php")]
    if any(not path.exists() for path in files + required):
        raise ValueError("Required release files are missing")
    return header, {f"pv-redirects/{path.relative_to(root).as_posix()}": path.read_bytes() for path in sorted(files)}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--tag", help="Validate release tag, e.g. v1.0.0")
    parser.add_argument("--verify", type=Path, help="Verify an existing archive against this source tree")
    parser.add_argument("--output", type=Path, default=ROOT / "dist" / "pv-redirects.zip")
    args = parser.parse_args()
    try:
        version, files = files_and_version(ROOT, args.tag)
        if args.verify:
            with zipfile.ZipFile(args.verify) as archive:
                names = archive.namelist()
                if len(names) != len(set(names)) or set(names) != set(files):
                    raise ValueError("Archive contents differ from release files")
                if any(archive.read(name) != content for name, content in files.items()):
                    raise ValueError("Archive content differs from tagged source")
            print(f"Verified {args.verify} ({version})")
        else:
            args.output.parent.mkdir(parents=True, exist_ok=True)
            with zipfile.ZipFile(args.output, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
                for name, content in files.items():
                    entry = zipfile.ZipInfo(name, (1980, 1, 1, 0, 0, 0))
                    entry.compress_type = zipfile.ZIP_DEFLATED
                    entry.external_attr = 0o100644 << 16
                    archive.writestr(entry, content, compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
            print(f"Created {args.output} ({version})")
    except (OSError, ValueError, KeyError, zipfile.BadZipFile) as error:
        print(f"Release validation failed: {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
