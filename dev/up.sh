#!/usr/bin/env sh
# Starts the XB dev WordPress and installs it on first run.
# Generated credentials live in dev/.env (git-ignored); the admin password is never printed.
set -eu
cd "$(dirname "$0")"
if [ ! -f .env ]; then
  {
    echo "XB_DB_PASSWORD=$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"
    echo "XB_ADMIN_PASSWORD=$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"
    echo "XB_PORT=8899"
  } > .env
fi
grep -q '^XB_MOCK_TOKEN=' .env || echo "XB_MOCK_TOKEN=$(head -c 32 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 40)" >> .env
# XB_PORT from the environment wins over dev/.env (a second stack, e.g. COMPOSE_PROJECT_NAME=xb-e2e in dev/e2e.sh).
port=${XB_PORT:-}
. ./.env
XB_PORT=${port:-$XB_PORT}
export XB_PORT
docker compose up -d --wait db wordpress cfmock
# The wordpress image copies core files into the volume on first start.
until docker compose exec -T wordpress test -f /var/www/html/wp-config.php; do sleep 1; done
wp() { docker compose run --rm -T cli wp "$@"; }
if ! wp core is-installed 2>/dev/null; then
  wp core install --url="http://localhost:${XB_PORT}" --title="XB Dev" --admin_user=admin \
    --admin_password="$XB_ADMIN_PASSWORD" --admin_email=admin@example.test --skip-email
  wp rewrite structure '/%postname%/'
fi
# Dev-only wiring: loopback origin for the static exporter, local Cloudflare mock.
wp config set WP_ENVIRONMENT_TYPE local --type=constant --quiet
wp config set XB_EXPORT_ORIGIN http://wordpress --type=constant --quiet
wp config set XB_CLOUDFLARE_API_BASE http://cfmock:8080/client/v4 --type=constant --quiet
wp theme activate fondamenta
# Italian site (Histeon is written in Italian; tests and e2e use its labels). English has its own tests. The constant,
# because the option only takes languages whose WordPress pack is installed (no download here).
wp config set WPLANG it_IT --type=constant --quiet
wp plugin activate xb
echo "XB dev: http://localhost:${XB_PORT} (admin: utente 'admin', password in dev/.env)"
