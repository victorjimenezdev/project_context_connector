#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-https://example.com}"
KEY_ID="${PCC_KEY_ID:-prompt-bot}"
# Export PCC_SHARED_SECRET in the environment. Do not put it on the command line.
: "${PCC_SHARED_SECRET:?Export PCC_SHARED_SECRET}"
export PCC_SHARED_SECRET
METHOD="GET"
# Sign this path exactly as the request sends it. Do not decode or normalize it.
REQUEST_PATH="/project-context-connector/snapshot/signed"
TS="$(date +%s)"

# PHP reads the secret from its environment, so it never enters process arguments.
SIG="$(printf '%s\n%s\n%s' "$METHOD" "$REQUEST_PATH" "$TS" \
  | php -r 'echo hash_hmac("sha256", stream_get_contents(STDIN), getenv("PCC_SHARED_SECRET"));')"

curl -i "${BASE_URL%/}${REQUEST_PATH}" \
  -H "Accept: application/json" \
  -H "X-PCC-Key: ${KEY_ID}" \
  -H "X-PCC-Timestamp: ${TS}" \
  -H "X-PCC-Signature: ${SIG}"
