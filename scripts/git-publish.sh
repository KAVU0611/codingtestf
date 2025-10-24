#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

REMOTE="${GIT_REMOTE:-origin}"
BRANCH="${GIT_BRANCH:-main}"

echo "[git-publish] Updating ${REMOTE}/${BRANCH}"
git pull --rebase "${REMOTE}" "${BRANCH}"

echo "[git-publish] Staging changes"
git add -A

if git diff --cached --quiet; then
  echo "[git-publish] No staged changes. Exiting."
  exit 0
fi

COMMIT_MESSAGE="${1:-}"
if [[ -z "${COMMIT_MESSAGE}" ]]; then
  read -rp "Commit message: " COMMIT_MESSAGE
fi

if [[ -z "${COMMIT_MESSAGE}" ]]; then
  echo "[git-publish] Commit message is required." >&2
  exit 1
fi

git commit -m "${COMMIT_MESSAGE}"
git push "${REMOTE}" "${BRANCH}"

echo "[git-publish] Published to ${REMOTE}/${BRANCH}."
