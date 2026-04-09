#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGETS=("$ROOT_DIR/pmc3/index.html" "$ROOT_DIR/pmc3/api-proxy.php")

found=0
for file in "${TARGETS[@]}"; do
  if [[ ! -f "$file" ]]; then
    continue
  fi
  if rg -n "<<<<<<<|=======|>>>>>>>" "$file" >/tmp/pmc3_conflicts.txt 2>/dev/null; then
    echo "[CONFLICT] $file"
    cat /tmp/pmc3_conflicts.txt
    found=1
  fi
done

if [[ $found -eq 1 ]]; then
  echo
  echo "Resolve conflict markers before running/deploying pmc3."
  exit 1
fi

echo "No merge conflict markers found in pmc3/index.html or pmc3/api-proxy.php."
