#!/usr/bin/env bash
# Puts the modules of Histeon (ADR 0015) into <plugin dir>/modules/<id>/, from the release ZIPs that modules.json pins
# by path and SHA-256. A ZIP whose fingerprint differs is refused: nothing unverified ships or runs.
#
#   scripts/modules.sh                → plugin/modules/ of this checkout (development)
#   scripts/modules.sh <plugin dir>   → that folder (the package script's stage)
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
target=${1:-"$root/plugin"}
manifest="$root/modules.json"
[ -f "$manifest" ] || { echo "modules.json mancante" >&2; exit 1; }
mkdir -p "$target/modules"
# ZIP paths are relative to MODULES_BASE (default: this checkout), so a package built in a worktree finds them.
python3 -I - "${MODULES_BASE:-$root}" "$target/modules" "$manifest" <<'PY'
import hashlib, json, os, shutil, sys, tempfile, zipfile
root, out, manifest = sys.argv[1], sys.argv[2], sys.argv[3]
for mid, m in json.load(open(manifest)).items():
    if not mid.replace('-', '').isalnum() or not m['folder'].replace('-', '').isalnum():
        sys.exit(f'id o cartella non validi: {mid}')
    path = os.path.normpath(os.path.join(root, m['zip']))
    if not os.path.isfile(path):
        sys.exit(f'{mid}: ZIP non trovato ({m["zip"]})')
    digest = hashlib.sha256(open(path, 'rb').read()).hexdigest()
    if digest != m['sha256']:
        sys.exit(f'{mid}: impronta diversa da modules.json ({digest})')
    dest = os.path.join(out, mid)
    with tempfile.TemporaryDirectory() as tmp, zipfile.ZipFile(path) as z:
        for name in z.namelist():
            # Only files under the plugin's own folder, never absolute paths or ../
            if name.startswith('/') or '..' in name.split('/') or not name.startswith(m['folder'] + '/'):
                sys.exit(f'{mid}: percorso non ammesso nello ZIP: {name}')
        z.extractall(tmp)
        if not os.path.isfile(os.path.join(tmp, m['folder'], m['main'])):
            sys.exit(f'{mid}: {m["main"]} mancante')
        shutil.rmtree(dest, ignore_errors=True)
        shutil.copytree(os.path.join(tmp, m['folder']), dest)
    print(f'{mid}: {m["zip"]} verificato, in modules/{mid}/')
PY
