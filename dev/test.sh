#!/usr/bin/env sh
# Runs the integration suite inside the dev WordPress (start it first with dev/up.sh).
set -eu
cd "$(dirname "$0")"
docker compose run --rm -T -w /var/www/html/wp-content/plugins/xb cli php vendor/bin/phpunit -c phpunit-integration.xml.dist "$@"
