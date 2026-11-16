#!/usr/bin/env bash
# English files of the standalone Histeon CSS (docs/spec/i18n.md): its core is Histeon's own code with the domain
# renamed (scripts/package-histos-css.sh), so the .pot comes from the same staged files and the translations from
# Histeon's maps plus histos-css/languages/en/. Needs Docker (wp-cli image of the dev stack).
#   scripts/i18n-histos-css.sh   → histos-css/languages/histos-css{.pot,-en_US.po,-en_US.mo}
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
stage="$work/histos-css"
mkdir -p "$stage/src/Core" "$stage/assets" "$work/maps"
cp "$root/histos-css/histos-css.php" "$stage/"
cp "$root/histos-css/src/Plugin.php" "$stage/src/"
for class in Color Design DesignData Scale Tokens Utilities Recipes ThemeJson; do
  sed -e 's/^namespace XB\\Framework\\HistosCss;/namespace HistosCss\\Core;/' \
      -e "s/, 'xb' )/, 'histos-css' )/g" \
      "$root/plugin/src/Framework/HistosCss/$class.php" > "$stage/src/Core/$class.php"
done
docker run --rm -u "$(id -u):$(id -g)" -v "$stage":/app -w /app wordpress:cli-php8.3 \
  wp i18n make-pot . histos-css.pot --domain=histos-css --skip-audit >/dev/null
cp "$root"/plugin/languages/en/*.json "$work/maps/"
cp "$root"/histos-css/languages/en/*.json "$work/maps/zz-histos-css.json"
mkdir -p "$root/histos-css/languages"
cp "$stage/histos-css.pot" "$root/histos-css/languages/histos-css.pot"
python3 "$root/scripts/i18n.py" po "$root/histos-css/languages/histos-css.pot" "$work/maps" "$root/histos-css/languages/histos-css-en_US.po" --strict
