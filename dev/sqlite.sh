#!/usr/bin/env sh
# A second dev WordPress on SQLite (docs/spec/versioning.md §10): the SQLite Database Integration plugin from
# WordPress.org, pinned and checked by its SHA-256, as the db.php drop-in. Then run the suite on it with
#   COMPOSE_PROJECT_NAME=xb-sqlite XB_PORT=8894 dev/test.sh
# `dev/sqlite.sh --fresh` starts again from an empty database (one file: cheap). Listens on 127.0.0.1 only.
set -eu
cd "$(dirname "$0")"
[ -f .env ] || ./up.sh >/dev/null
export COMPOSE_PROJECT_NAME=xb-sqlite XB_PORT="${XB_PORT_SQLITE:-8894}"
VERSION=3.1.0
SHA256=e260e3ada78676f1c0f4e09eb9ebcb9ecce4d4b85f0c6e5a70fb9a93f5b9847f

docker compose up -d --wait db wordpress cfmock >/dev/null
until docker compose exec -T wordpress test -f /var/www/html/wp-config.php; do sleep 1; done
if ! docker compose exec -T wordpress test -f wp-content/db.php; then
  tmp=$(mktemp -d "${TMPDIR:-/tmp}/xb-sqlite.XXXXXX")
  trap 'rm -rf "$tmp"' EXIT
  curl -sSfL -o "$tmp/plugin.zip" "https://downloads.wordpress.org/plugin/sqlite-database-integration.$VERSION.zip"
  echo "$SHA256  $tmp/plugin.zip" | sha256sum -c - >/dev/null || { echo "SQLite Database Integration $VERSION: impronta SHA-256 diversa, non installato" >&2; exit 1; }
  (cd "$tmp" && unzip -q plugin.zip)
  docker compose cp "$tmp/sqlite-database-integration" wordpress:/var/www/html/wp-content/plugins/
  docker compose exec -T wordpress sh -c 'cp wp-content/plugins/sqlite-database-integration/db.copy wp-content/db.php && chown -R www-data:www-data wp-content/plugins/sqlite-database-integration wp-content/db.php'
fi
if [ "${1:-}" = "--fresh" ]; then
  # The database is one file, in a directory with a random name (wp-content/database/db-path.php says which).
  docker compose exec -T wordpress sh -c 'rm -f wp-content/database/.ht.*/.ht.sqlite wp-content/database/.ht.*/.ht.sqlite-*'
fi
wp() { docker compose run --rm -T cli wp "$@"; }
if ! wp core is-installed 2>/dev/null; then
  # Same title as the MySQL dev site: some tests print it. The admin password is random and never shown.
  wp core install --url="http://localhost:${XB_PORT}" --title="XB Dev" --admin_user=admin \
    --admin_password="$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')" --admin_email=admin@example.test --skip-email
  wp rewrite structure '/%postname%/'
fi
# Same dev-only wiring as dev/up.sh: the tests expect a local, Italian site on both databases.
wp config set WP_ENVIRONMENT_TYPE local --type=constant --quiet
wp config set XB_EXPORT_ORIGIN http://wordpress --type=constant --quiet
wp config set XB_CLOUDFLARE_API_BASE http://cfmock:8080/client/v4 --type=constant --quiet
wp theme activate fondamenta >/dev/null
wp config set WPLANG it_IT --type=constant --quiet
wp plugin activate xb sqlite-database-integration >/dev/null
echo "XB su SQLite: http://localhost:${XB_PORT} — test: COMPOSE_PROJECT_NAME=xb-sqlite XB_PORT=${XB_PORT} dev/test.sh"
