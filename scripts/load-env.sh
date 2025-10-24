#!/usr/bin/env bash
set -euo pipefail

if command -v realpath >/dev/null 2>&1; then
  SCRIPT_PATH="$(realpath "${BASH_SOURCE[0]}")"
elif command -v readlink >/dev/null 2>&1; then
  SCRIPT_PATH="$(readlink -f "${BASH_SOURCE[0]}")"
else
  SCRIPT_PATH="${BASH_SOURCE[0]}"
fi

SCRIPT_DIR="$(cd "$(dirname "${SCRIPT_PATH}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ENV_FILE="${ROOT_DIR}/.env"

log() {
  echo "[load-env] $*"
}

if [[ ! -f "${ENV_FILE}" ]]; then
  log "Missing .env file. Copy .env.example to .env and populate your secrets." >&2
  exit 1
fi

if grep -qi microsoft /proc/version; then
  log "WSL environment detected (Ubuntu on Windows)."
fi

# shellcheck disable=SC1090
set -a
source "${ENV_FILE}"
set +a

log "Environment variables from .env loaded."
