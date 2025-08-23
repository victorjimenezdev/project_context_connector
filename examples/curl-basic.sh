#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-https://example.com}"
USER="${PCC_USER:-pcc_bot}"
PASS="${PCC_PASS:-change-me}"

curl -i \
  -H "Accept: application/json" \
  -u "${USER}:${PASS}" \
  "${BASE_URL%/}/project-context-connector/snapshot"