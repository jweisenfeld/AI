#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PORT="${PORT:-18081}"
BASE_URL="http://127.0.0.1:${PORT}"
TMP_DIR="$(mktemp -d)"
SERVER_LOG="${TMP_DIR}/php-server.log"
STREAM_OUT="${TMP_DIR}/stream.out"
STATS_OUT="${TMP_DIR}/stats.json"
PROMPT_OUT="${TMP_DIR}/prompt.json"

cleanup() {
  if [[ -n "${PHP_PID:-}" ]] && kill -0 "${PHP_PID}" 2>/dev/null; then
    kill "${PHP_PID}" || true
    wait "${PHP_PID}" 2>/dev/null || true
  fi
  rm -rf "${TMP_DIR}"
}
trap cleanup EXIT

php -S "127.0.0.1:${PORT}" -t "${ROOT_DIR}" >"${SERVER_LOG}" 2>&1 &
PHP_PID=$!
sleep 1

# stream=test must emit SSE text chunks and [DONE]
curl -sS "${BASE_URL}/api-proxy.php?stream=test" > "${STREAM_OUT}"
grep -q 'data: {"text":"PMC "' "${STREAM_OUT}"
grep -q 'data: \[DONE\]' "${STREAM_OUT}"

# prompt route should return JSON with a prompt string
curl -sS "${BASE_URL}/api-proxy.php?prompt=1" > "${PROMPT_OUT}"
python - <<'PY' "${PROMPT_OUT}"
import json, sys
data = json.load(open(sys.argv[1], "r", encoding="utf-8"))
assert isinstance(data.get("prompt"), str) and len(data["prompt"]) > 100
print("prompt_ok")
PY

# stats route should return JSON object or a clear error JSON
curl -sS "${BASE_URL}/api-proxy.php?stats=1" > "${STATS_OUT}"
python - <<'PY' "${STATS_OUT}"
import json, sys
data = json.load(open(sys.argv[1], "r", encoding="utf-8"))
if isinstance(data, dict) and "error" in data:
    print("stats_error_json_ok")
elif isinstance(data, dict):
    print("stats_obj_ok")
else:
    raise AssertionError(f"Unexpected stats shape: {type(data)}")
PY

echo "proxy_smoke_ok"
