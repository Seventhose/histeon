#!/usr/bin/env sh
# Compiles the Fondamenta templates from DSL (fondamenta/src/*.xb) to block markup (templates/*.html, parts/*.html).
# Deterministic uids (--seed) keep the generated files stable across builds. XB Blank, the compatibility theme,
# gets the same files: one source, two themes that look the same.
set -eu
here=$(cd "$(dirname "$0")" && pwd)
cli="$here/../packages/dsl/bin/xb-dsl.js"
for kind in templates parts; do
  rm -f "$here"/xb-blank/$kind/*.html
  for src in "$here"/fondamenta/src/$kind/*.xb; do
    name=$(basename "$src" .xb)
    node "$cli" to-blocks --seed "t$(printf '%s' "$kind$name" | cksum | cut -c1-3)" "$src" > "$here/fondamenta/$kind/$name.html"
    cp "$here/fondamenta/$kind/$name.html" "$here/xb-blank/$kind/$name.html"
  done
done
# The builder's interactions, for Fondamenta without the builder (inc/comportamenti.php): the same files.
cp "$here/../plugin/assets/front/behaviors.js" "$here/../plugin/assets/front/behaviors.css" "$here/fondamenta/assets/"
# The filters' script (inc/filtri.php), the builder's own too.
cp "$here/../plugin/assets/front/facets.js" "$here/fondamenta/assets/"
echo "Fondamenta e XB Blank: template compilati."
