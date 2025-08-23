#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-https://example.com}"
KEY_ID="${PCC_KEY_ID:-prompt-bot}"
SECRET="${PCC_SHARED_SECRET:-change-me}"
METHOD="GET"
PATH="/project-context-connector/snapshot/signed"
TS="$(date +%s)"
BASE="${METHOD}\n${PATH}\n${TS}"

# Use openssl to compute hex hmac sha256
SIG="$(printf "%b" "$BASE" | openssl dgst -sha256 -hmac "$SECRET" -r | awk '{print $1}')"

curl -i "${BASE_URL%/}${PATH}" \
  -H "Accept: application/json" \
  -H "X-PCC-Key: ${KEY_ID}" \
  -H "X-PCC-Timestamp: ${TS}" \
  -H "X-PCC-Signature: ${SIG}"
