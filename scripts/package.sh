#!/usr/bin/env bash
# Builds the installable zips of the XB plugin, the Fondamenta theme and the XB Blank compatibility theme from a git
# commit (default: HEAD).
#
#   scripts/package.sh            → dist/xb-<version>-<sha>.zip, dist/fondamenta-<version>-<sha>.zip,
#                                   dist/xb-blank-<version>-<sha>.zip, dist/SHA256SUMS
#   scripts/package.sh <commit>
#   XB_WITHOUT_MODULES=1 scripts/package.sh → xb-<version>-<sha>-senza-moduli.zip, without Lingua Bridge and Static
#                                            Bridge (a clone that lacks their release ZIPs; Histeon works without them)
#
# The build runs in a temporary git worktree at that commit, with dependencies installed from the lockfile
# (`npm ci --ignore-scripts`): uncommitted changes in this checkout never end up in a package.
# Only runtime files are shipped (allowlist below): no tests, no vendor/, no dev configuration, no source maps.
set -euo pipefail

root=$(cd "$(dirname "$0")/.." && pwd)
ref=${1:-HEAD}
sha=$(git -C "$root" rev-parse --short=7 --verify "$ref^{commit}")
stamp=$(git -C "$root" show -s --format=%cd --date=format:%Y%m%d%H%M.%S "$sha")

work=$(mktemp -d "${TMPDIR:-/tmp}/xb-package.XXXXXX")
tree="$work/tree"
cleanup() {
  git -C "$root" worktree remove --force "$tree" >/dev/null 2>&1 || true
  rm -rf "$work"
}
trap cleanup EXIT

echo "› Worktree temporaneo a $sha"
git -C "$root" worktree add --detach --quiet "$tree" "$sha"

echo "› Dipendenze dal lockfile (npm ci --ignore-scripts)"
(cd "$tree" && npm ci --ignore-scripts --no-audit --no-fund --loglevel=error)

echo "› Build del builder"
(cd "$tree" && npm run build -w editor --silent >/dev/null)
test -s "$tree/plugin/assets/builder/builder.js" || { echo "builder.js mancante" >&2; exit 1; }
test -s "$tree/plugin/assets/gutenberg/inspector.js" || { echo "inspector.js mancante" >&2; exit 1; }
test -s "$tree/plugin/assets/gutenberg/inspector-code.js" || { echo "inspector-code.js mancante" >&2; exit 1; }
test -s "$tree/plugin/assets/builder/strings-en_US.js" || { echo "strings-en_US.js mancante" >&2; exit 1; }
test -s "$tree/plugin/assets/builder/histos-css/panel.js" || { echo "histos-css/panel.js mancante (cassetto Histeon CSS)" >&2; exit 1; }
test -s "$tree/plugin/assets/builder/media/encoder.js" || { echo "media/encoder.js mancante (codificatore delle immagini, M5)" >&2; exit 1; }
test -s "$tree/plugin/assets/builder/media/licenses/libavif-BSD-2-Clause.txt" || { echo "licenze dei codec mancanti" >&2; exit 1; }
if find "$tree/plugin/assets/builder" "$tree/plugin/assets/gutenberg" -name '*.map' | grep -q .; then echo "source map nel pacchetto" >&2; exit 1; fi

plugin_version=$(sed -n 's/^ \* Version: *//p' "$tree/plugin/xb.php" | head -1)
theme_version=$(sed -n 's/^Version: *//p' "$tree/theme/xb-blank/style.css" | head -1)
fondamenta_version=$(sed -n 's/^Version: *//p' "$tree/theme/fondamenta/style.css" | head -1)

# Stage: <slug>/… with only the files WordPress needs at runtime.
stage="$work/stage"
mkdir -p "$stage/xb" "$stage/xb-blank" "$stage/fondamenta"
for path in xb.php uninstall.php src blocks assets connector languages; do
  cp -R "$tree/plugin/$path" "$stage/xb/"
done
for path in style.css theme.json functions.php README.md templates parts languages; do
  cp -R "$tree/theme/xb-blank/$path" "$stage/xb-blank/"
done
for path in style.css theme.json functions.php README.md templates parts languages inc blocks assets; do
  cp -R "$tree/theme/fondamenta/$path" "$stage/fondamenta/"
done
cp "$tree/LICENSE" "$stage/xb/LICENSE"
# The notices of the third-party code bundled in the builder travel with it (MIT, BSD and Apache-2.0 ask for it).
node "$tree/scripts/third-party-licenses.mjs" "$stage/xb/assets/builder/THIRD-PARTY-LICENSES.md" >/dev/null
# The translation maps are sources (scripts/i18n.py): only the compiled files ship.
rm -rf "$stage/xb/languages/en" "$stage/xb-blank/languages/en" "$stage/fondamenta/languages/en"
# The modules (ADR 0015): release ZIPs pinned by modules.json, verified by their SHA-256.
suffix=""
if [ "${XB_WITHOUT_MODULES:-}" = "1" ]; then
  echo "› Senza moduli: Lingua Bridge e Static Bridge non entrano nel pacchetto"
  suffix="-senza-moduli"
else
  MODULES_BASE="$root" "$tree/scripts/modules.sh" "$stage/xb"
fi
cp "$tree/LICENSE" "$stage/xb-blank/LICENSE"
cp "$tree/LICENSE" "$stage/fondamenta/LICENSE"

echo "› Controlli"
if command -v php >/dev/null 2>&1; then
  find "$stage" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
fi
# Nothing from development may slip in.
if find "$stage" \( -name 'vendor' -o -name 'tests' -o -name 'node_modules' -o -name '*.map' -o -name '.env' -o -name 'composer.*' -o -name 'phpunit*' -o -name 'phpstan*' -o -name '.git*' \) | grep -q .; then
  echo "file di sviluppo nel pacchetto" >&2
  exit 1
fi

# Reproducible archives: fixed timestamps (commit time), sorted entries, no extra attributes.
find "$stage" -exec touch -h -t "$stamp" {} +
mkdir -p "$root/dist"
plugin_zip="xb-$plugin_version-$sha$suffix.zip"
theme_zip="xb-blank-$theme_version-$sha.zip"
fondamenta_zip="fondamenta-$fondamenta_version-$sha.zip"
rm -f "$root/dist/$plugin_zip" "$root/dist/$theme_zip" "$root/dist/$fondamenta_zip"
(cd "$stage" && find xb -print | LC_ALL=C sort | zip -q -X -D -@ "$root/dist/$plugin_zip")
(cd "$stage" && find xb-blank -print | LC_ALL=C sort | zip -q -X -D -@ "$root/dist/$theme_zip")
(cd "$stage" && find fondamenta -print | LC_ALL=C sort | zip -q -X -D -@ "$root/dist/$fondamenta_zip")
(cd "$root/dist" && sha256sum "$plugin_zip" "$fondamenta_zip" "$theme_zip" > SHA256SUMS)
# Signed for the updater (docs/spec/updates.md) when the release key is given: XB_SIGNING_KEY=<secret key file>.
if [ -n "${XB_SIGNING_KEY:-}" ]; then
  php "$tree/scripts/sign-release.php" sign "$XB_SIGNING_KEY" "$root/dist/$plugin_zip" "$root/dist/$fondamenta_zip" "$root/dist/$theme_zip"
fi

echo "› Pronti in dist/:"
(cd "$root/dist" && ls -l "$plugin_zip" "$fondamenta_zip" "$theme_zip" && cat SHA256SUMS)
