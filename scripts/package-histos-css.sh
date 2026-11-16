#!/usr/bin/env bash
# Builds the standalone Histeon CSS plugin (ADR 0015, M5; docs/spec/histos-css.md §9) from a git commit (default
# HEAD): histos-css/ (its own bootstrap and settings) plus Histeon's core of Histeon CSS, copied from
# plugin/src/Framework/HistosCss into the namespace HistosCss\Core, so both plugins can be installed together.
#
#   scripts/package-histos-css.sh [commit]   → dist/histos-css-<version>-<sha>.zip (+ .minisig with XB_SIGNING_KEY)
set -euo pipefail

root=$(cd "$(dirname "$0")/.." && pwd)
ref=${1:-HEAD}
sha=$(git -C "$root" rev-parse --short=7 --verify "$ref^{commit}")
stamp=$(git -C "$root" show -s --format=%cd --date=format:%Y%m%d%H%M.%S "$sha")
work=$(mktemp -d "${TMPDIR:-/tmp}/histos-css-package.XXXXXX")
tree="$work/tree"
cleanup() {
  git -C "$root" worktree remove --force "$tree" >/dev/null 2>&1 || true
  rm -rf "$work"
}
trap cleanup EXIT

# The panel is built with Histeon's editor (editor/src/histos-css), in a worktree at that commit with the
# dependencies from the lockfile, as scripts/package.sh does: uncommitted changes never end up in the package.
git -C "$root" worktree add --detach --quiet "$tree" "$sha"
(cd "$tree" && npm ci --ignore-scripts --no-audit --no-fund --loglevel=error && cd editor && npx vite build -c vite.histos-css.config.ts --logLevel error)
test -s "$tree/plugin/assets/builder/histos-css/panel.js" || { echo "panel.js mancante" >&2; exit 1; }

# The files at that commit, never the working tree.
git -C "$root" archive "$sha" histos-css plugin/src/Framework/HistosCss plugin/assets/tokens/xb-tokens.css plugin/assets/framework/histos.css plugin/assets/brand LICENSE scripts/sign-release.php | tar -x -C "$work"
stage="$work/stage/histos-css"
mkdir -p "$stage/src/Core" "$stage/assets"
cp "$work/histos-css/histos-css.php" "$work/histos-css/uninstall.php" "$work/histos-css/readme.txt" "$stage/"
cp "$work/histos-css/src/Plugin.php" "$stage/src/"
# The compiled English files (scripts/i18n-histos-css.sh); the maps in languages/en/ are sources and stay out.
mkdir -p "$stage/languages"
cp "$work"/histos-css/languages/histos-css* "$stage/languages/"
cp "$work/LICENSE" "$stage/LICENSE"

# The pure core: these files name nothing else of Histeon (checked below).
for class in Color Design DesignData Scale Tokens Utilities Recipes ThemeJson; do
  sed -e 's/^namespace XB\\Framework\\HistosCss;/namespace HistosCss\\Core;/' \
      -e "s/, 'xb' )/, 'histos-css' )/g" \
      -e 's/@package XB$/@package HistosCss/' \
      "$work/plugin/src/Framework/HistosCss/$class.php" > "$stage/src/Core/$class.php"
done
if grep -n 'XB\\' "$stage/src/Core/"*.php; then echo "il nucleo nomina ancora Histeon" >&2; exit 1; fi

cp "$work/plugin/assets/tokens/xb-tokens.css" "$work/plugin/assets/framework/histos.css" "$tree/plugin/assets/builder/histos-css/panel.js" "$stage/assets/"
cp "$work/plugin/assets/brand/histos-css-mark.svg" "$work/plugin/assets/brand/histos-css-icon.svg" "$stage/assets/"

if command -v php >/dev/null 2>&1; then
  find "$stage" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
fi
version=$(sed -n 's/^ \* Version: *//p' "$stage/histos-css.php" | head -1)
find "$stage" -exec touch -h -t "$stamp" {} +
mkdir -p "$root/dist"
zip_name="histos-css-$version-$sha.zip"
rm -f "$root/dist/$zip_name"
(cd "$work/stage" && find histos-css -print | LC_ALL=C sort | zip -q -X -D -@ "$root/dist/$zip_name")
(cd "$root/dist" && sha256sum "$zip_name" > "$zip_name.sha256")
if [ -n "${XB_SIGNING_KEY:-}" ]; then
  php "$work/scripts/sign-release.php" sign "$XB_SIGNING_KEY" "$root/dist/$zip_name"
fi
echo "› Pronto: dist/$zip_name"
(cd "$root/dist" && cat "$zip_name.sha256")
