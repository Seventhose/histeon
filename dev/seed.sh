#!/usr/bin/env sh
# Seeds the dev site with a demo home page (DSL in dev/demo), a component and styles. Idempotent.
set -eu
cd "$(dirname "$0")"
cli="../packages/dsl/bin/xb-dsl.js"
[ -f ../packages/dsl/dist/index.js ] || npm run build -w packages/dsl --prefix .. >/dev/null
wp() { docker compose run --rm -T cli wp --user=admin "$@"; }

card=$(node "$cli" to-blocks --seed c demo/card.xb)
home=$(node "$cli" to-blocks --seed h demo/home.xb)

card_id=$(wp post list --post_type=wp_block --meta_key=xb_component_key --meta_value=card --field=ID --format=ids)
if [ -z "$card_id" ]; then
  card_id=$(wp post create --post_type=wp_block --post_status=publish --post_title="Card" --porcelain --post_content="$card")
  wp post meta update "$card_id" xb_component_key card >/dev/null
else
  wp post update "$card_id" --post_content="$card" >/dev/null
fi
wp post meta update "$card_id" xb_component_schema '{"props":{"title":{"type":"string"},"url":{"type":"url"},"image":{"type":"image"},"variant":{"type":"enum","default":"base"}}}' >/dev/null

home_id=$(wp post list --post_type=page --name=home --field=ID --format=ids)
if [ -z "$home_id" ]; then
  home_id=$(wp post create --post_type=page --post_status=publish --post_title="Home" --post_name=home --porcelain --post_content="$home")
else
  wp post update "$home_id" --post_content="$home" >/dev/null
fi
wp option update show_on_front page >/dev/null
wp option update page_on_front "$home_id" >/dev/null
wp option update xb_styles --format=json < demo/styles.json >/dev/null

for n in 1 2 3; do
  if [ -z "$(wp post list --name="articolo-demo-$n" --field=ID --format=ids)" ]; then
    wp post create --post_status=publish --post_title="Articolo demo $n" --post_name="articolo-demo-$n" \
      --post_content="<!-- wp:paragraph --><p>Testo dell'articolo demo numero $n, scritto con l'editor a blocchi di WordPress per vedere come XB lo mostra in un loop.</p><!-- /wp:paragraph -->" >/dev/null
  fi
done
echo "Demo pronta: home page $home_id, componente card $card_id."
