#!/usr/bin/env sh
# Runs the end-to-end browser tests (e2e/, Playwright). Extra arguments go to `playwright test`.
# The tests publish and restore releases, so by default they get their own stack (compose project xb-e2e on
# port 8898, started with dev/up.sh) and never touch the everyday dev site; XB_E2E_PROJECT and XB_E2E_PORT
# choose another one. The test admin's password is generated for each run and passed only through the environment.
set -eu
cd "$(dirname "$0")/.."
export COMPOSE_PROJECT_NAME="${XB_E2E_PROJECT:-xb-e2e}"
export XB_PORT="${XB_E2E_PORT:-8898}"
[ "${XB_E2E_SKIP_BUILD:-}" = 1 ] || npm run build -w editor >/dev/null
dev/up.sh
XB_E2E_URL="http://localhost:${XB_PORT}"
XB_E2E_PASSWORD="$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"
export XB_E2E_URL XB_E2E_PASSWORD
echo "e2e: progetto compose ${COMPOSE_PROJECT_NAME}, ${XB_E2E_URL}" >&2
exec npm run e2e -w e2e -- "$@"
