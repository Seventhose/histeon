#!/usr/bin/env sh
# Installs third-party plugin zips into the dev WordPress only (never into the repository).
# Usage: dev/install-third-party.sh path/to/plugin.zip [...]
set -eu
cd "$(dirname "$0")"
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
for zip in "$@"; do
  dir="$tmp/$(basename "$zip" .zip | tr -c 'A-Za-z0-9._\n-' '_')"
  mkdir -p "$dir"
  unzip -q "$zip" -d "$dir"
  for plugin in "$dir"/*/; do
    name=$(basename "$plugin")
    docker compose cp "$plugin" "wordpress:/var/www/html/wp-content/plugins/$name"
    docker compose exec -T wordpress chown -R www-data:www-data "/var/www/html/wp-content/plugins/$name"
    echo "installato: $name"
  done
done
