#!/usr/bin/env python3
"""Icon library for the builder (E10) from a copy of the lucide package (ISC): reads dist/esm/icons/*.js as
text (nothing is run), keeps only drawing elements and their geometry, writes plugin/assets/icons/lucide.json
and the licence beside it.

Usage: scripts/icons-lucide.py /path/to/node_modules/lucide
"""
import json, re, sys
from pathlib import Path

TAGS = {'path', 'circle', 'rect', 'line', 'polyline', 'polygon', 'ellipse'}
ATTRS = {'fill', 'd', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'width', 'height', 'points'}
VALUE = re.compile(r'^[0-9a-zA-Z .,\-]*$')


def icon(source: str):
    body = re.search(r'const \w+ = (\[.*?\]);\s*export', source, re.S)
    if not body:
        return None
    text = re.sub(r'([{,]\s*)([A-Za-z][\w-]*)\s*:', r'\1"\2":', body.group(1))
    nodes = json.loads(text)
    out = []
    for tag, attrs in nodes:
        if tag not in TAGS:
            return None
        clean = {k: v for k, v in attrs.items() if k in ATTRS and isinstance(v, str) and VALUE.match(v)}
        if len(clean) != len([k for k in attrs if k != 'key']):
            return None
        out.append([tag, clean])
    return out


def main(root: str) -> None:
    pkg = Path(root)
    version = json.loads((pkg / 'package.json').read_text())['version']
    icons = {}
    for file in sorted((pkg / 'dist/esm/icons').glob('*.js')):
        drawn = icon(file.read_text())
        if drawn:
            icons[file.stem] = drawn
    target = Path(__file__).resolve().parent.parent / 'plugin/assets/icons'
    target.mkdir(parents=True, exist_ok=True)
    (target / 'lucide.json').write_text(json.dumps({'name': 'Lucide', 'version': version, 'license': 'ISC', 'icons': icons}, separators=(',', ':')))
    (target / 'LICENSE-lucide.txt').write_text((pkg / 'LICENSE').read_text())
    print(f'{len(icons)} icons, lucide {version}')


if __name__ == '__main__':
    main(sys.argv[1])
