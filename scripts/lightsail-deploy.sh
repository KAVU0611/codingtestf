#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

source "${SCRIPT_DIR}/load-env.sh"

log() {
  echo "[lightsail] $*"
}

warn() {
  echo "[lightsail][warn] $*" >&2
}

err() {
  echo "[lightsail][error] $*" >&2
  exit 1
}

require_cmd() {
  local cmd="$1"
  if ! command -v "${cmd}" >/dev/null 2>&1; then
    err "Command '${cmd}' is required but not installed. Run scripts/check-deps.sh first."
  fi
}

prompt_var() {
  local var="$1"
  local prompt="$2"
  local default="${3:-}"
  local current="${!var:-}"

  if [[ -n "${current}" ]]; then
    return
  fi

  if [[ -n "${default}" ]]; then
    read -rp "${prompt} [${default}]: " current
    current="${current:-$default}"
  else
    read -rp "${prompt}: " current
  fi

  if [[ -z "${current}" ]]; then
    err "Value for ${var} is required."
  fi

  printf -v "${var}" "%s" "${current}"
  export "${var}"
}

expand_path() {
  local path="$1"
  eval "echo ${path}"
}

require_cmd aws
require_cmd jq
require_cmd ssh
require_cmd scp
if command -v rsync >/dev/null 2>&1; then
  USE_RSYNC=1
else
  warn "rsync not found; falling back to tar/scp for file sync (slower)."
  USE_RSYNC=0
fi

AWS_PROFILE="${AWS_PROFILE:-default}"
AWS_REGION="${AWS_REGION:-${AWS_DEFAULT_REGION:-}}"
LIGHTSAIL_REGION="${LIGHTSAIL_REGION:-${AWS_REGION}}"

prompt_var LIGHTSAIL_REGION "Lightsail region" "${AWS_REGION}"
prompt_var LIGHTSAIL_AVAILABILITY_ZONE "Lightsail availability zone (e.g., ap-northeast-1a)"
prompt_var LIGHTSAIL_BLUEPRINT_ID "Lightsail blueprint ID" "ubuntu_22_04"
prompt_var LIGHTSAIL_BUNDLE_ID "Lightsail bundle ID" "nano_2_0"
prompt_var LIGHTSAIL_INSTANCE_NAME "Lightsail instance name" "playlist-bedrock"
prompt_var LIGHTSAIL_KEY_PAIR_NAME "Lightsail key pair name"
prompt_var LIGHTSAIL_SSH_USER "SSH user" "ubuntu"
prompt_var LIGHTSAIL_SSH_KEY_PATH "Path to Lightsail SSH private key"
prompt_var LIGHTSAIL_REMOTE_PATH "Remote deployment path" "/var/www/playlist"

LIGHTSAIL_SSH_KEY_PATH="$(expand_path "${LIGHTSAIL_SSH_KEY_PATH}")"
[[ -f "${LIGHTSAIL_SSH_KEY_PATH}" ]] || err "SSH key not found at ${LIGHTSAIL_SSH_KEY_PATH}"

SSH_OPTIONS=(
  -i "${LIGHTSAIL_SSH_KEY_PATH}"
  -o StrictHostKeyChecking=no
  -o UserKnownHostsFile=/dev/null
)

LIGHTSAIL_DOMAIN="${LIGHTSAIL_DOMAIN:-}"
LIGHTSAIL_CERT_EMAIL="${LIGHTSAIL_CERT_EMAIL:-}"
LIGHTSAIL_SNAPSHOT_NAME="${LIGHTSAIL_SNAPSHOT_NAME:-${LIGHTSAIL_INSTANCE_NAME}-snapshot}"
AUTO_SNAPSHOT_TIME="${AUTO_SNAPSHOT_TIME:-02:00}"

LIGHTSAIL_HOST=""

get_instance_details() {
  aws lightsail get-instance \
    --profile "${AWS_PROFILE}" \
    --region "${LIGHTSAIL_REGION}" \
    --instance-name "${LIGHTSAIL_INSTANCE_NAME}"
}

ensure_instance_ip() {
  local details ip
  details="$(get_instance_details 2>/dev/null || true)"
  if [[ -z "${details}" ]]; then
    return 1
  fi
  ip="$(echo "${details}" | jq -r '.instance.publicIpAddress')"
  if [[ "${ip}" == "null" || -z "${ip}" ]]; then
    return 1
  fi
  LIGHTSAIL_HOST="${ip}"
  export LIGHTSAIL_HOST
  return 0
}

wait_for_instance() {
  log "Waiting for Lightsail instance '${LIGHTSAIL_INSTANCE_NAME}' to reach running state..."
  local status
  for _ in {1..60}; do
    status="$(aws lightsail get-instance \
      --profile "${AWS_PROFILE}" \
      --region "${LIGHTSAIL_REGION}" \
      --instance-name "${LIGHTSAIL_INSTANCE_NAME}" \
      --query 'instance.state.name' \
      --output text 2>/dev/null || echo "unknown")"
    if [[ "${status}" == "running" ]]; then
      log "Instance is running."
      ensure_instance_ip && return 0
    fi
    sleep 10
  done
  err "Timed out waiting for instance to become running."
}

provision_instance() {
  if ensure_instance_ip; then
    log "Instance '${LIGHTSAIL_INSTANCE_NAME}' already exists with IP ${LIGHTSAIL_HOST}."
    return
  fi

  log "Creating Lightsail instance '${LIGHTSAIL_INSTANCE_NAME}' in ${LIGHTSAIL_REGION}/${LIGHTSAIL_AVAILABILITY_ZONE}"
  aws lightsail create-instances \
    --profile "${AWS_PROFILE}" \
    --region "${LIGHTSAIL_REGION}" \
    --instance-names "${LIGHTSAIL_INSTANCE_NAME}" \
    --availability-zone "${LIGHTSAIL_AVAILABILITY_ZONE}" \
    --blueprint-id "${LIGHTSAIL_BLUEPRINT_ID}" \
    --bundle-id "${LIGHTSAIL_BUNDLE_ID}" \
    --key-pair-name "${LIGHTSAIL_KEY_PAIR_NAME}"

  wait_for_instance
}

remote_exec() {
  local cmd="$1"
  ssh "${SSH_OPTIONS[@]}" "${LIGHTSAIL_SSH_USER}@${LIGHTSAIL_HOST}" "${cmd}"
}

remote_exec_sudo() {
  local cmd="$1"
  remote_exec "sudo bash -lc ${cmd@Q}"
}

sync_files() {
  log "Synchronizing project files to ${LIGHTSAIL_REMOTE_PATH}"
  remote_exec_sudo "mkdir -p ${LIGHTSAIL_REMOTE_PATH@Q}"
  remote_exec_sudo "chown ${LIGHTSAIL_SSH_USER}:www-data ${LIGHTSAIL_REMOTE_PATH@Q}"

  if [[ "${USE_RSYNC}" -eq 1 ]]; then
    rsync -az --delete \
      --exclude '.git' \
      --exclude '.env' \
      --exclude '.env.example' \
      --exclude 'node_modules' \
      --exclude 'vendor' \
      --exclude 'snap' \
      --exclude '.codex' \
      -e "ssh ${SSH_OPTIONS[*]}" \
      "${ROOT_DIR}/" "${LIGHTSAIL_SSH_USER}@${LIGHTSAIL_HOST}:${LIGHTSAIL_REMOTE_PATH}/"
  else
    local tmp_tar
    tmp_tar="$(mktemp)"
    tar -czf "${tmp_tar}" \
      --exclude='.git' \
      --exclude='.env' \
      --exclude='.env.example' \
      --exclude='node_modules' \
      --exclude='vendor' \
      --exclude='snap' \
      --exclude='.codex' \
      -C "${ROOT_DIR}" .
    scp "${SSH_OPTIONS[@]}" "${tmp_tar}" "${LIGHTSAIL_SSH_USER}@${LIGHTSAIL_HOST}:/tmp/app.tgz"
    remote_exec_sudo "mkdir -p ${LIGHTSAIL_REMOTE_PATH@Q} && tar -xzf /tmp/app.tgz -C ${LIGHTSAIL_REMOTE_PATH@Q}"
    remote_exec "rm -f /tmp/app.tgz"
    rm -f "${tmp_tar}"
  fi

  remote_exec_sudo "chown -R ${LIGHTSAIL_SSH_USER}:www-data ${LIGHTSAIL_REMOTE_PATH@Q}"
  remote_exec_sudo "chmod -R g+rwX ${LIGHTSAIL_REMOTE_PATH@Q}"
}

configure_apache() {
  log "Configuring Apache virtual host"
  remote_exec_sudo "apt-get update"
  remote_exec_sudo "DEBIAN_FRONTEND=noninteractive apt-get install -y apache2 php php-cli php-mbstring php-xml libapache2-mod-php unzip rsync certbot python3-certbot-apache"
  remote_exec_sudo "a2enmod rewrite"

  local env_lines=()
  [[ -n "${AWS_REGION}" ]] && env_lines+=("    SetEnv AWS_REGION ${AWS_REGION}")
  [[ -n "${AWS_PROFILE}" ]] && env_lines+=("    SetEnv BEDROCK_PROFILE ${AWS_PROFILE}")
  [[ -n "${BEDROCK_KNOWLEDGE_BASE_ID:-}" ]] && env_lines+=("    SetEnv BEDROCK_KNOWLEDGE_BASE_ID ${BEDROCK_KNOWLEDGE_BASE_ID}")
  [[ -n "${BEDROCK_MODEL_ARN:-}" ]] && env_lines+=("    SetEnv BEDROCK_MODEL_ARN ${BEDROCK_MODEL_ARN}")
  [[ -n "${BEDROCK_AGENT_ID:-}" ]] && env_lines+=("    SetEnv BEDROCK_AGENT_ID ${BEDROCK_AGENT_ID}")

  local vhost_config
  vhost_config="<VirtualHost *:80>
    ServerName ${LIGHTSAIL_DOMAIN:-localhost}
    DocumentRoot ${LIGHTSAIL_REMOTE_PATH}/public
    <Directory ${LIGHTSAIL_REMOTE_PATH}/public>
        AllowOverride All
        Require all granted
    </Directory>
$(printf '%s\n' "${env_lines[@]}")
    ErrorLog \${APACHE_LOG_DIR}/playlist-error.log
    CustomLog \${APACHE_LOG_DIR}/playlist-access.log combined
</VirtualHost>"

  printf "%s\n" "${vhost_config}" | remote_exec_sudo "cat > /etc/apache2/sites-available/playlist.conf"
  remote_exec_sudo "a2dissite 000-default.conf || true"
  remote_exec_sudo "a2ensite playlist.conf"
  remote_exec_sudo "systemctl enable apache2"
  remote_exec_sudo "systemctl restart apache2"
}

enable_tls() {
  if [[ -z "${LIGHTSAIL_DOMAIN}" || -z "${LIGHTSAIL_CERT_EMAIL}" ]]; then
    warn "Skipping TLS setup because LIGHTSAIL_DOMAIN or LIGHTSAIL_CERT_EMAIL is not set."
    return
  fi
  log "Requesting Let's Encrypt certificate for ${LIGHTSAIL_DOMAIN}"
  remote_exec_sudo "certbot --apache --non-interactive --agree-tos --redirect -m ${LIGHTSAIL_CERT_EMAIL@Q} -d ${LIGHTSAIL_DOMAIN@Q}"
}

create_snapshot() {
  local snapshot_name="${1:-${LIGHTSAIL_SNAPSHOT_NAME}}"
  log "Creating Lightsail snapshot '${snapshot_name}'"
  aws lightsail create-instance-snapshot \
    --profile "${AWS_PROFILE}" \
    --region "${LIGHTSAIL_REGION}" \
    --instance-name "${LIGHTSAIL_INSTANCE_NAME}" \
    --instance-snapshot-name "${snapshot_name}"
}

enable_auto_snapshots() {
  log "Enabling Lightsail auto snapshots at ${AUTO_SNAPSHOT_TIME}"
  if ! aws lightsail enable-add-on \
    --profile "${AWS_PROFILE}" \
    --region "${LIGHTSAIL_REGION}" \
    --resource-name "${LIGHTSAIL_INSTANCE_NAME}" \
    --add-on-type AutoSnapshot \
    --auto-snapshot-add-on-request snapshotTimeOfDay="${AUTO_SNAPSHOT_TIME}"; then
    warn "Auto snapshot enablement failed. Configure snapshots manually in the Lightsail console."
  fi
}

deploy() {
  provision_instance
  ensure_instance_ip || err "Failed to determine instance IP after provisioning."
  log "Using instance IP ${LIGHTSAIL_HOST}"

  log "Checking SSH connectivity..."
  if ! ssh "${SSH_OPTIONS[@]}" "${LIGHTSAIL_SSH_USER}@${LIGHTSAIL_HOST}" "echo ok" >/dev/null; then
    err "Unable to SSH to ${LIGHTSAIL_SSH_USER}@${LIGHTSAIL_HOST}. Check key pair and security group."
  fi

  sync_files
  configure_apache
  enable_tls
  enable_auto_snapshots
}

usage() {
  cat <<'USAGE'
Usage: scripts/lightsail-deploy.sh <command>

Commands:
  deploy          Provision instance (if needed), sync code, configure Apache, enable TLS, enable auto snapshots.
  provision       Only create the Lightsail instance.
  sync            Sync project files and refresh Apache configuration.
  tls             Run Let's Encrypt via certbot (requires LIGHTSAIL_DOMAIN + LIGHTSAIL_CERT_EMAIL).
  snapshot [name] Create a one-off Lightsail instance snapshot.
  autosnapshot    Enable daily automatic snapshots.
  help            Show this help message.
USAGE
}

command="${1:-deploy}"
case "${command}" in
  deploy)
    deploy
    ;;
  provision)
    provision_instance
    ;;
  sync)
    ensure_instance_ip || err "Instance not found. Run provision first."
    sync_files
    configure_apache
    ;;
  tls)
    ensure_instance_ip || err "Instance not found. Run provision first."
    enable_tls
    ;;
  snapshot)
    shift || true
    create_snapshot "${1:-${LIGHTSAIL_SNAPSHOT_NAME}}"
    ;;
  autosnapshot)
    enable_auto_snapshots
    ;;
  help|-h|--help)
    usage
    ;;
  *)
    usage
    err "Unknown command: ${command}"
    ;;
esac

log "Done."
