#!/usr/bin/env bash
# Host-Nginx FPC acceptance (no root required).
# Run: bash tests/accept_host_nginx_fpc.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"

echo "== 1. publisher map/location/include/idempotency/permission =="
php tests/accept_host_nginx_fpc.php

echo "== 1b. Debian/CentOS/BaoTa/Homebrew path layouts + command =="
php tests/accept_host_nginx_fpc_layouts.php

echo "== 2. one-command wrapper syntax/help =="
bash -n bin/wls-host-nginx-fpc
wrapper_help="$(bin/wls-host-nginx-fpc --help 2>&1)"
grep -q 'wls-host-nginx-fpc' <<<"${wrapper_help}"

echo "== 3. non-root run must refuse and print sudo command =="
set +e
out="$(bin/wls-host-nginx-fpc --host=shop.test -p=9555 2>&1)"
code=$?
set -e
if [ "${code}" -eq 0 ]; then
    echo "FAIL: non-root wrapper must not install" >&2
    exit 1
fi
grep -q 'sudo bash' <<<"${out}"

echo "== 4. server:nginx:host-fpc command is registered =="
command_help="$(php bin/w server:nginx:host-fpc --help 2>&1)"
grep -q 'server:nginx:host-fpc' <<<"${command_help}"

echo "ACCEPT PASS"
