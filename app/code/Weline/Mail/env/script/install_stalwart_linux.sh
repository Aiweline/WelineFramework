#!/usr/bin/env bash
# Weline Mail · Stalwart 原生依赖安装（供 env:install / mail:env:install 调用）
# 委托官方 install.sh，PREFIX=/opt/stalwart，与 StalwartEngineAdapter 约定一致。
set -euo pipefail

ACTION="${1:-check}"
INSTALL_DIR="${STALWART_INSTALL_DIR:-/opt/stalwart}"
BIN_CANDIDATES=(
  "${INSTALL_DIR}/bin/stalwart"
  "/usr/local/bin/stalwart"
  "/usr/bin/stalwart"
)
# 官方脚本：https://github.com/stalwartlabs/stalwart/blob/main/install.sh
INSTALLER_URL="${STALWART_INSTALLER_URL:-https://raw.githubusercontent.com/stalwartlabs/stalwart/main/install.sh}"

find_binary() {
  local p
  for p in "${BIN_CANDIDATES[@]}"; do
    if [ -x "$p" ]; then
      echo "$p"
      return 0
    fi
  done
  if command -v stalwart >/dev/null 2>&1; then
    command -v stalwart
    return 0
  fi
  return 1
}

service_active() {
  if command -v systemctl >/dev/null 2>&1; then
    systemctl is-active --quiet stalwart.service 2>/dev/null && return 0
    systemctl is-active --quiet stalwart 2>/dev/null && return 0
  fi
  return 1
}

if [ "$ACTION" = "check" ]; then
  if BIN="$(find_binary)"; then
    echo "INSTALLED"
    echo "Stalwart found: $BIN"
    if service_active; then
      echo "service: active"
    else
      echo "service: inactive_or_unknown"
    fi
    exit 0
  fi
  echo "MISSING"
  echo "Stalwart not found under ${INSTALL_DIR}/bin or PATH"
  exit 1
fi

if [ "$ACTION" != "install" ]; then
  echo "UNSUPPORTED"
  echo "Unsupported action: $ACTION (use check|install)"
  exit 2
fi

# 已安装则幂等成功（避免推荐项重试反复下载）
if BIN="$(find_binary)"; then
  echo "INSTALLED"
  echo "Stalwart already present: $BIN"
  if ! service_active && command -v systemctl >/dev/null 2>&1; then
    echo "Attempting to start stalwart.service..."
    if [ "$(id -u)" -eq 0 ]; then
      systemctl enable --now stalwart.service 2>/dev/null || systemctl enable --now stalwart 2>/dev/null || true
    elif command -v sudo >/dev/null 2>&1; then
      sudo -n systemctl enable --now stalwart.service 2>/dev/null \
        || sudo systemctl enable --now stalwart.service 2>/dev/null \
        || true
    fi
  fi
  exit 0
fi

SUDO=""
if [ "$(id -u)" -ne 0 ]; then
  if command -v sudo >/dev/null 2>&1; then
    # 非交互优先；失败再允许交互 sudo（本机运维场景）
    if sudo -n true 2>/dev/null; then
      SUDO="sudo -n"
    else
      SUDO="sudo"
    fi
  else
    echo "MISSING"
    echo "Need root or sudo to install Stalwart into ${INSTALL_DIR}"
    exit 1
  fi
fi

need_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "MISSING"
    echo "Required command not found: $1"
    exit 1
  fi
}

need_cmd curl
need_cmd tar
need_cmd mktemp

TMP="$(mktemp -d)"
cleanup() { rm -rf "$TMP"; }
trap cleanup EXIT

echo "Downloading official Stalwart installer..."
curl -fsSL "$INSTALLER_URL" -o "$TMP/install.sh"
chmod 0755 "$TMP/install.sh"

echo "Running official installer with PREFIX=${INSTALL_DIR}..."
# 官方脚本要求 root；PREFIX 走自包含布局：bin/etc/logs/data
# shellcheck disable=SC2086
$SUDO sh "$TMP/install.sh" "$INSTALL_DIR"

if BIN="$(find_binary)"; then
  echo "INSTALLED"
  echo "Stalwart installed: $BIN"
  if service_active; then
    echo "service: active"
  else
    echo "service: started_by_installer_or_pending"
  fi
  echo "Bootstrap admin (recovery): http://127.0.0.1:8080/admin — password in journalctl -u stalwart"
  exit 0
fi

echo "MISSING"
echo "Stalwart install finished but binary not found under ${INSTALL_DIR}/bin"
exit 1
