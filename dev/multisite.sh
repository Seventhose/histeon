#!/usr/bin/env sh
# A dev WordPress as a multisite network (doc 26 of Histeon Publish, voce 208): there the administrator of a site
# has no unfiltered_html, so what the sanitizer keeps and refuses can be tried as it really happens. Then run
#   COMPOSE_PROJECT_NAME=xb-multisite XB_PORT=8895 dev/test.sh --group multisite
# Listens on 127.0.0.1 only. The admin password is random and never shown. The network's domain is `localhost`
# (what the test bootstrap uses): it is for the tests, not for a browser.
set -eu
cd "$(dirname "$0")"
[ -f .env ] || ./up.sh >/dev/null
export COMPOSE_PROJECT_NAME="${XB_MULTISITE_PROJECT:-xb-multisite}" XB_PORT="${XB_PORT_MULTISITE:-8895}"
docker compose up -d --wait db wordpress cfmock >/dev/null
until docker compose exec -T wordpress test -f /var/www/html/wp-config.php; do sleep 1; done
wp() { docker compose run --rm -T cli wp "$@"; }
if ! wp core is-installed 2>/dev/null; then
  # The domain is plain `localhost`, as the test bootstrap asks for it (a multisite answers only its own host).
  wp core multisite-install --url="http://localhost" --title="XB Dev" --admin_user=admin \
    --admin_password="$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')" --admin_email=admin@example.test --skip-email --skip-config >/dev/null
fi
# The network constants, once (wp-config.php is the image's: WORDPRESS_CONFIG_EXTRA is not used here).
if ! docker compose exec -T wordpress grep -q "MULTISITE" wp-config.php; then
  wp config set WP_ALLOW_MULTISITE true --raw --type=constant --quiet
  wp config set MULTISITE true --raw --type=constant --quiet
  wp config set SUBDOMAIN_INSTALL false --raw --type=constant --quiet
  wp config set DOMAIN_CURRENT_SITE localhost --type=constant --quiet
  wp config set PATH_CURRENT_SITE / --type=constant --quiet
  wp config set SITE_ID_CURRENT_SITE 1 --raw --type=constant --quiet
  wp config set BLOG_ID_CURRENT_SITE 1 --raw --type=constant --quiet
fi
wp config set WP_ENVIRONMENT_TYPE local --type=constant --quiet
wp config set XB_EXPORT_ORIGIN http://wordpress --type=constant --quiet
wp config set XB_CLOUDFLARE_API_BASE http://cfmock:8080/client/v4 --type=constant --quiet
wp config set WPLANG it_IT --type=constant --quiet
wp theme enable fondamenta --network >/dev/null 2>&1 || true
wp theme activate fondamenta >/dev/null
wp plugin activate xb --network >/dev/null
echo "XB multisite: http://localhost:${XB_PORT} — test: COMPOSE_PROJECT_NAME=${COMPOSE_PROJECT_NAME} XB_PORT=${XB_PORT} dev/test.sh --group multisite"
