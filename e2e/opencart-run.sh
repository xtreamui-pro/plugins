#!/usr/bin/env bash
# Runs plugins/e2e/opencart-harness.php against a panel API, with a throwaway
# MariaDB (Docker) for the part that exercises the order glue.
#
#   API_PORT_NUM=18095 API_KEY=xk_... plugins/e2e/opencart-run.sh
#   (API_URL overrides http://127.0.0.1:API_PORT_NUM; OC_DB_PORT changes the database port)
#
# The container is named xc-opencart-db and removed at the end. Without Docker
# run the harness directly: only the core part runs then.
set -euo pipefail

cd "$(dirname "$0")/../.."
: "${API_KEY:?set API_KEY (a reseller API key of the panel)}"
export API_KEY
export API_PORT_NUM="${API_PORT_NUM:-}"
export OC_DB_PORT="${OC_DB_PORT:-55462}"
export OC_DB_PASSWORD="xc_opencart_test"

cleanup() { docker rm -f xc-opencart-db >/dev/null 2>&1 || true; }
trap cleanup EXIT
cleanup

docker run -d --name xc-opencart-db -e MARIADB_ROOT_PASSWORD="$OC_DB_PASSWORD" -p "127.0.0.1:${OC_DB_PORT}:3306" mariadb:11 >/dev/null
for _ in $(seq 1 60); do
  if docker exec xc-opencart-db mariadb -uroot -p"$OC_DB_PASSWORD" -e 'SELECT 1' >/dev/null 2>&1; then
    break
  fi
  sleep 1
done

php plugins/e2e/opencart-harness.php
