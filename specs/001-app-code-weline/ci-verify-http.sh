#!/usr/bin/env bash
set -euo pipefail

# CI helper (draft): verify critical API paths via HTTP and assert response content.
# Usage (CI): API_URL, API_KEY, ORIGINAL_MODEL_ID must be set in environment.
# Example: API_URL="https://example.com" API_KEY="xxx" ORIGINAL_MODEL_ID=123 ./ci-verify-http.sh

if [ -z "${API_URL:-}" ] || [ -z "${API_KEY:-}" ]; then
  echo "ERROR: API_URL and API_KEY must be set" >&2
  exit 2
fi

JQ_BIN=$(command -v jq || true)
if [ -z "$JQ_BIN" ]; then
  echo "ERROR: jq is required for response assertions" >&2
  exit 2
fi

echo "[ci-verify] Using API_URL=$API_URL"

verify_chat() {
  echo "[ci-verify] Verifying Chat endpoint..."
  resp=$(curl -sS -X POST "$API_URL/api/2024-01-15/chat" \
    -H "Authorization: Bearer $API_KEY" \
    -H "Content-Type: application/json" \
    -d '{"prompt":"Health check","version":"2024-01-15","locale":"zh-CN"}')

  data_exists=$(echo "$resp" | $JQ_BIN -e '.data.response' >/dev/null 2>&1 && echo yes || echo no)
  if [ "$data_exists" != yes ]; then
    echo "Chat verification failed: response.data.response missing" >&2
    echo "RESP: $resp" >&2
    return 1
  fi
  echo "Chat OK"
}

verify_model_copy() {
  if [ -z "${ORIGINAL_MODEL_ID:-}" ]; then
    echo "SKIP model copy: ORIGINAL_MODEL_ID not provided" >&2
    return 0
  fi
  echo "[ci-verify] Verifying Model Copy endpoint (origin id=$ORIGINAL_MODEL_ID)..."
  resp=$(curl -sS -X POST "$API_URL/api/2024-01-15/model/$ORIGINAL_MODEL_ID/copy" \
    -H "Authorization: Bearer $API_KEY" \
    -H "Content-Type: application/json" \
    -d '{"name":"ci-copy-temp"}')

  ok=$(echo "$resp" | $JQ_BIN -e '.data.id' >/dev/null 2>&1 && echo yes || echo no)
  if [ "$ok" != yes ]; then
    echo "Model copy verification failed" >&2
    echo "RESP: $resp" >&2
    return 1
  fi
  echo "Model copy OK"
}

verify_model_get() {
  if [ -z "${CHECK_MODEL_ID:-}" ]; then
    echo "SKIP model get: CHECK_MODEL_ID not provided" >&2
    return 0
  fi
  echo "[ci-verify] Verifying Model GET (id=$CHECK_MODEL_ID)..."
  resp=$(curl -sS -X GET "$API_URL/api/2024-01-15/model/$CHECK_MODEL_ID" \
    -H "Authorization: Bearer $API_KEY")

  ok=$(echo "$resp" | $JQ_BIN -e '.data.name' >/dev/null 2>&1 && echo yes || echo no)
  if [ "$ok" != yes ]; then
    echo "Model GET verification failed" >&2
    echo "RESP: $resp" >&2
    return 1
  fi
  echo "Model GET OK"
}

main() {
  verify_chat
  verify_model_copy
  verify_model_get
  echo "All verifications passed"
}

main


