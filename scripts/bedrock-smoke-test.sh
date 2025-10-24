#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

# Load environment variables
source "${SCRIPT_DIR}/load-env.sh"

log() {
  echo "[bedrock-smoke-test] $*"
}

require_cmd() {
  local cmd="$1"
  if ! command -v "${cmd}" >/dev/null 2>&1; then
    log "Command '${cmd}' is required but not found. Run scripts/check-deps.sh first." >&2
    exit 1
  fi
}

require_var() {
  local name="$1"
  if [[ -z "${!name:-}" ]]; then
    log "Environment variable ${name} is required. Update .env before retrying." >&2
    exit 1
  fi
}

require_cmd aws
require_cmd jq

log "aws --version => $(aws --version 2>&1)"

AWS_PROFILE="${AWS_PROFILE:-default}"
AWS_REGION="${AWS_REGION:-${AWS_DEFAULT_REGION:-}}"

require_var AWS_REGION
require_var BEDROCK_KNOWLEDGE_BASE_ID
require_var BEDROCK_MODEL_ARN

SESSION_ID="${BEDROCK_SESSION_ID:-bedrock-smoke-session}"
PROMPT_TEXT="${BEDROCK_PROMPT_TEXT:-Hello from the Bedrock smoke test.}"

log "Verifying AWS caller identity (profile: ${AWS_PROFILE}, region: ${AWS_REGION})"
aws sts get-caller-identity --profile "${AWS_PROFILE}" --region "${AWS_REGION}"

log "Preparing Bedrock CLI payload"
INPUT_JSON="$(jq -n --arg text "${PROMPT_TEXT}" '{text: $text}')"
RETRIEVE_CONF="$(jq -n \
  --arg kb "${BEDROCK_KNOWLEDGE_BASE_ID}" \
  --arg model "${BEDROCK_MODEL_ARN}" \
  '{type: "KNOWLEDGE_BASE", knowledgeBaseConfiguration: {knowledgeBaseId: $kb, modelArn: $model}}')"

if [[ -n "${BEDROCK_AGENT_ID:-}" ]]; then
  RETRIEVE_CONF="$(jq -n \
    --argjson base "${RETRIEVE_CONF}" \
    --arg agent "${BEDROCK_AGENT_ID}" \
    '$base + {agentConfiguration: {agentId: $agent}}')"
fi

log "Calling aws bedrock-agent-runtime retrieve-and-generate"
aws bedrock-agent-runtime retrieve-and-generate \
  --profile "${AWS_PROFILE}" \
  --region "${AWS_REGION}" \
  --session-id "${SESSION_ID}" \
  --input "${INPUT_JSON}" \
  --retrieve-and-generate-configuration "${RETRIEVE_CONF}"

log "Bedrock smoke test completed."
