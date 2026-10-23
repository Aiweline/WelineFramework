#!/usr/bin/env bash
# Live storefront generation acceptance (local WLS, no root).
# Verifies: ScopeBootstrap returns current wire id + 16-hex page token;
# ?w_pg=<token> serves the same generation; missing slot corrects to current.
#
# Run: BASE=http://127.0.0.1:9555 bash tests/accept_theme_generation_live.sh
set -euo pipefail

BASE="${BASE:-http://127.0.0.1:9555}"
UA="Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/130.0 Safari/537.36"
TMP="$(mktemp -d)"
trap 'rm -rf "${TMP}"' EXIT

fail() {
    echo "FAIL: $*" >&2
    exit 1
}

echo "== 1. ScopeBootstrap current generation =="
code="$(curl -s --max-time 20 -o "${TMP}/bootstrap.json" -w '%{http_code}' \
    -X POST "${BASE}/api/frontend/scope-bootstrap" \
    -H 'Content-Type: application/json' \
    -H "Origin: ${BASE}" \
    -H "Referer: ${BASE}/" \
    --data '{"path":"/"}')"
[[ "${code}" == "200" ]] || fail "ScopeBootstrap HTTP ${code}"
version="$(php -r '$j=json_decode(file_get_contents($argv[1]),true); echo (string)($j["theme_published_version_id"]??"");' "${TMP}/bootstrap.json")"
token="$(php -r '$j=json_decode(file_get_contents($argv[1]),true); echo (string)($j["page_generation_token"]??"");' "${TMP}/bootstrap.json")"
[[ "${version}" != "" ]] || fail "empty theme_published_version_id"
[[ "${version}" =~ \.e[0-9]+$ ]] || fail "wire id missing TU epoch: ${version}"
[[ "${token}" =~ ^[a-f0-9]{16}$ ]] || fail "page_generation_token not 16 hex: ${token}"
echo "  version=${version} token=${token}"

echo "== 2. ?w_pg=<current token> serves that generation =="
code="$(curl -s --max-time 25 -o "${TMP}/current.html" -D "${TMP}/current.headers" -w '%{http_code}' \
    -A "${UA}" "${BASE}/?w_pg=${token}")"
[[ "${code}" == "200" ]] || fail "current w_pg HTTP ${code}"
grep -qi '^x-wls-fpc-status: HIT' "${TMP}/current.headers" \
    || grep -qi '^x-weline-fpc: HIT' "${TMP}/current.headers" \
    || fail "current w_pg was not an FPC hit"
grep -q "${version}" "${TMP}/current.html" || fail "current page does not carry ${version}"
echo "  FPC HIT carries ${version}"

echo "== 3. missing slot corrects to current, never 404 =="
code="$(curl -s --max-time 25 -o "${TMP}/stale.html" -w '%{http_code}' \
    -A "${UA}" "${BASE}/?w_pg=deadbeefdeadbeef")"
[[ "${code}" == "200" ]] || fail "missing slot HTTP ${code}"
grep -q "${version}" "${TMP}/stale.html" || fail "missing slot did not correct to ${version}"
echo "  missing slot served current ${version}"

echo "ACCEPT PASS"
