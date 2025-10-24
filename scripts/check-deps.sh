#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

log() {
  echo "[check-deps] $*"
}

warn() {
  echo "[check-deps][warn] $*" >&2
}

detect_wsl() {
  if grep -qi microsoft /proc/version; then
    echo "wsl"
  else
    echo ""
  fi
}

APT_UPDATED=0
apt_update_once() {
  if [[ "${APT_UPDATED}" -eq 0 ]]; then
    log "Running apt-get update (sudo may prompt for your password)"
    sudo apt-get update
    APT_UPDATED=1
  fi
}

ensure_apt_package() {
  local package="$1"
  if dpkg -s "${package}" >/dev/null 2>&1; then
    return
  fi
  apt_update_once
  log "Installing ${package}"
  sudo apt-get install -y "${package}"
}

ensure_curl() {
  if ! command -v curl >/dev/null 2>&1; then
    ensure_apt_package "curl"
  fi
}

ensure_unzip() {
  if ! command -v unzip >/dev/null 2>&1; then
    ensure_apt_package "unzip"
  fi
}

ensure_jq() {
  if ! command -v jq >/dev/null 2>&1; then
    ensure_apt_package "jq"
  fi
}

install_aws_cli() {
  ensure_curl
  ensure_unzip
  local tmpdir
  tmpdir="$(mktemp -d)"
  log "Downloading AWS CLI v2 installer"
  curl -fsSL "https://awscli.amazonaws.com/awscli-exe-linux-x86_64.zip" -o "${tmpdir}/awscliv2.zip"
  unzip -q "${tmpdir}/awscliv2.zip" -d "${tmpdir}"
  log "Installing AWS CLI v2 (sudo may prompt for your password)"
  sudo "${tmpdir}/aws/install" --update
  rm -rf "${tmpdir}"
}

ensure_aws_cli() {
  if command -v aws >/dev/null 2>&1; then
    if aws --version 2>&1 | grep -q "aws-cli/2"; then
      log "AWS CLI v2 detected: $(aws --version 2>&1)"
      return
    fi
    warn "AWS CLI detected but not v2; upgrading."
  else
    log "AWS CLI v2 not found."
  fi
  install_aws_cli
  log "AWS CLI version after install: $(aws --version 2>&1)"
}

install_node() {
  ensure_curl
  log "Setting up NodeSource repository for Node.js 20.x"
  curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
  apt_update_once
  log "Installing nodejs package"
  sudo apt-get install -y nodejs
}

ensure_node() {
  if command -v node >/dev/null 2>&1; then
    local version_major
    version_major="$(node -v | sed -E 's/^v([0-9]+).*/\1/')"
    if [[ "${version_major}" -ge 18 ]]; then
      log "Node.js detected: $(node -v)"
      return
    fi
    warn "Node.js version $(node -v) is below 18; upgrading."
  else
    log "Node.js not found."
  fi
  install_node
  log "Node.js version after install: $(node -v)"
}

ensure_npm() {
  if command -v npm >/dev/null 2>&1; then
    log "npm detected: $(npm -v)"
  else
    warn "npm missing after Node.js install; reinstalling nodejs package."
    install_node
  fi
}

ensure_php() {
  if command -v php >/dev/null 2>&1; then
    log "PHP detected: $(php -v | head -n1)"
    return
  fi
  apt_update_once
  log "Installing PHP and common extensions"
  sudo apt-get install -y php php-cli php-mbstring php-xml libapache2-mod-php
}

ensure_make() {
  if command -v make >/dev/null 2>&1; then
    return
  fi
  apt_update_once
  log "Installing build-essential for make"
  sudo apt-get install -y build-essential
}

main() {
  if [[ "$(detect_wsl)" == "wsl" ]]; then
    log "WSL Ubuntu environment detected."
  else
    warn "WSL was not detected; continuing anyway."
  fi

  ensure_jq
  ensure_aws_cli
  ensure_node
  ensure_npm
  ensure_php
  ensure_make

  log "All required dependencies are installed."
}

main "$@"
