#!/usr/bin/env python3
"""Prepare version metadata and extract release notes from CHANGELOG.md."""
import argparse
import datetime
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
VERSION = re.compile(r'(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)\Z')


def notes(changelog, version):
    match = re.search(r'^## \[' + re.escape(version) + r'\] - \d{4}-\d{2}-\d{2}\s*\n(.*?)(?=^## \[|\Z)', changelog, re.M | re.S)
    if not match or not match.group(1).strip():
        raise ValueError(f'No changelog notes for {version}')
    return match.group(1).strip()


def replace_once(text, pattern, replacement, label):
    result, count = re.subn(pattern, lambda _: replacement, text, flags=re.M)
    if count != 1:
        raise ValueError(f'Expected exactly one {label}')
    return result


def prepare(root, version, date):
    if not VERSION.fullmatch(version):
        raise ValueError('Version must be numeric X.Y.Z')
    datetime.date.fromisoformat(date)
    changelog = (root / 'CHANGELOG.md').read_text()
    match = re.search(r'^## \[Unreleased\]\s*\n(.*?)(?=^## \[|\Z)', changelog, re.M | re.S)
    if not match or not match.group(1).strip():
        raise ValueError('Unreleased changelog must contain release notes')
    if re.search(r'^## \[' + re.escape(version) + r'\]', changelog, re.M):
        raise ValueError('Version already exists in changelog')
    body = match.group(1).strip()
    header = (root / 'pv-redirects.php').read_text()
    readme = (root / 'readme.txt').read_text()
    package = (root / 'package.json').read_text()
    lock = (root / 'package-lock.json').read_text()
    old = json.loads(package)['version']
    lock_data = json.loads(lock)
    if not VERSION.fullmatch(old) or tuple(map(int, version.split('.'))) <= tuple(map(int, old.split('.'))):
        raise ValueError('New version must be greater than current version')
    if lock_data['version'] != old or lock_data['packages']['']['version'] != old:
        raise ValueError('Lockfile version differs from package version')
    header = replace_once(header, r'(?<=Version: )' + re.escape(old) + r'(?=\s*$)', version, 'plugin header version')
    header = replace_once(header, r"(?<=PV_REDIRECTS_VERSION', ')" + re.escape(old) + r"(?='\s*\))", version, 'plugin runtime version')
    readme = replace_once(readme, r'(?<=Stable tag: )' + re.escape(old) + r'(?=\s*$)', version, 'stable tag')
    package = package.replace('"version": "' + old + '"', '"version": "' + version + '"', 1)
    old_token = '"version": "' + old + '"'
    if lock.count(old_token) < 2:
        raise ValueError('Expected lockfile root versions')
    lock = lock.replace(old_token, '"version": "' + version + '"', 2)
    readme_notes = re.sub(r'^### (.+)$', r'\1:', body, flags=re.M)
    readme_notes = re.sub(r'^- ', '* ', readme_notes, flags=re.M)
    readme = replace_once(readme, r'^== Changelog ==$', '== Changelog ==\n\n= ' + version + ' =\n' + readme_notes, 'readme changelog')
    updated = changelog[:match.start()] + '## [Unreleased]\n\n## [' + version + '] - ' + date + '\n\n' + body + '\n\n' + changelog[match.end():]
    for name, content in {'CHANGELOG.md': updated, 'pv-redirects.php': header, 'readme.txt': readme, 'package.json': package, 'package-lock.json': lock}.items():
        (root / name).write_text(content)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest='command', required=True)
    prep = sub.add_parser('prepare')
    prep.add_argument('version')
    prep.add_argument('--date', default=datetime.date.today().isoformat())
    extract = sub.add_parser('notes')
    extract.add_argument('version')
    args = parser.parse_args()
    try:
        if args.command == 'prepare':
            prepare(ROOT, args.version, args.date)
            print(f'Prepared {args.version}; review, test, commit, and tag the release.')
        else:
            print(notes((ROOT / 'CHANGELOG.md').read_text(), args.version.removeprefix('v')))
    except (OSError, ValueError, KeyError) as error:
        print(f'Release error: {error}', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
