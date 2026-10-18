#!/usr/bin/env bash
# Weline Mail · 将 Stalwart recovery 管理员设为 admin:<password> 并真正重启服务
# 本机 launchd（macOS）启动命令会 source /opt/stalwart/etc/stalwart.env —— 必须写该文件。
# 用法：
#   bash bind_stalwart_recovery_admin.sh <password>
#   bash bind_stalwart_recovery_admin.sh --elevate <password>   # macOS osascript 提权
set -euo pipefail

ELEVATE=0
PASSWORD=""
if [ "${1:-}" = "--elevate" ]; then
  ELEVATE=1
  PASSWORD="${2:-}"
else
  PASSWORD="${1:-}"
fi

if [ -z "${PASSWORD}" ] || [ "${#PASSWORD}" -lt 16 ]; then
  echo "ERR"
  echo "Password must be at least 16 characters."
  exit 2
fi

if ! printf '%s' "${PASSWORD}" | grep -Eq '^[A-Za-z0-9._~+/=-]{16,190}$'; then
  echo "ERR"
  echo "Password contains unsupported characters."
  exit 2
fi

SELF="$(cd "$(dirname "$0")" && pwd)/$(basename "$0")"
ENV_LINE="STALWART_RECOVERY_ADMIN=admin:${PASSWORD}"
STALWART_ENV="/opt/stalwart/etc/stalwart.env"
ETC_ENV="/etc/weline/stalwart-recovery.env"
PLIST="/Library/LaunchDaemons/stalwart.plist"
DROPIN_DIR="/etc/systemd/system/stalwart.service.d"
DROPIN_FILE="${DROPIN_DIR}/weline-recovery-admin.conf"

write_env_file() {
  local target="$1"
  local dir
  dir="$(dirname "${target}")"
  mkdir -p "${dir}"
  umask 077
  if [ -f "${target}" ]; then
    # 保留其它变量，替换/追加 STALWART_RECOVERY_ADMIN
    local tmp
    tmp="$(mktemp)"
    grep -v '^STALWART_RECOVERY_ADMIN=' "${target}" > "${tmp}" || true
    printf '%s\n' "${ENV_LINE}" >> "${tmp}"
    cat "${tmp}" > "${target}"
    rm -f "${tmp}"
  else
    printf '%s\n' "${ENV_LINE}" > "${target}"
  fi
  chmod 600 "${target}"
}

restart_macos_launchd() {
  # 正式安装用 launchd，不走 brew services / systemd
  if [ ! -f "${PLIST}" ]; then
    echo "ERR"
    echo "Missing ${PLIST}; cannot restart Stalwart via launchd."
    return 1
  fi
  # Prefer kickstart -k (kill+restart); fall back to bootout/bootstrap
  if launchctl kickstart -k "system/stalwart" 2>/dev/null; then
    :
  else
    launchctl bootout system "${PLIST}" 2>/dev/null || true
    sleep 1
    launchctl bootstrap system "${PLIST}" 2>/dev/null \
      || launchctl load -w "${PLIST}" 2>/dev/null \
      || true
  fi
  # Wait until process is back
  local i
  for i in 1 2 3 4 5 6 7 8 9 10; do
    if pgrep -f '/opt/stalwart/bin/stalwart' >/dev/null 2>&1; then
      echo "RESTARTED"
      return 0
    fi
    sleep 0.5
  done
  echo "ERR"
  echo "launchd restart did not bring stalwart process back."
  return 1
}

restart_linux_systemd() {
  mkdir -p "${DROPIN_DIR}"
  cat > "${DROPIN_FILE}" <<EOF
[Service]
EnvironmentFile=-${ETC_ENV}
EnvironmentFile=-${STALWART_ENV}
Environment=${ENV_LINE}
EOF
  chmod 644 "${DROPIN_FILE}"
  systemctl daemon-reload 2>/dev/null || true
  if systemctl restart stalwart.service 2>/dev/null \
    || systemctl restart stalwart 2>/dev/null; then
    echo "RESTARTED"
    return 0
  fi
  echo "ERR"
  echo "systemctl restart stalwart failed."
  return 1
}

do_bind_as_root() {
  # 权威：Stalwart 进程实际加载的 env（macOS launchd ProgramArguments 已钉死该路径）
  write_env_file "${STALWART_ENV}"
  if id -u _stalwart >/dev/null 2>&1; then
    chown _stalwart:_stalwart "${STALWART_ENV}" 2>/dev/null || true
  fi
  # 旁路副本（文档/跟迁对照；不替代 stalwart.env）
  write_env_file "${ETC_ENV}" || true

  if [ "$(uname -s)" = "Darwin" ]; then
    restart_macos_launchd
  elif command -v systemctl >/dev/null 2>&1; then
    restart_linux_systemd
  else
    echo "ERR"
    echo "No supported service manager (launchd/systemd)."
    return 1
  fi

  echo "BOUND"
  echo "Wrote ${STALWART_ENV}; service restarted."
}

if [ "$(id -u)" -eq 0 ]; then
  do_bind_as_root
  exit $?
fi

if command -v sudo >/dev/null 2>&1 && sudo -n true 2>/dev/null; then
  sudo -n bash "${SELF}" "${PASSWORD}"
  exit $?
fi

if [ "${ELEVATE}" = "1" ] && [ "$(uname -s)" = "Darwin" ] && command -v osascript >/dev/null 2>&1; then
  ESCAPED_SELF=$(printf '%s' "${SELF}" | sed "s/'/'\\\\''/g")
  ESCAPED_PWD=$(printf '%s' "${PASSWORD}" | sed "s/'/'\\\\''/g")
  if osascript -e "do shell script \"bash '${ESCAPED_SELF}' '${ESCAPED_PWD}'\" with administrator privileges"; then
    exit 0
  fi
fi

echo "ERR"
echo "Need root/sudo (or macOS --elevate) to write ${STALWART_ENV} and restart launchd/systemd."
echo "Manual:"
echo "  printf '%s\\n' '${ENV_LINE}' | sudo tee ${STALWART_ENV}"
echo "  sudo chown _stalwart:_stalwart ${STALWART_ENV}"
echo "  sudo launchctl kickstart -k system/stalwart   # macOS"
echo "  sudo systemctl restart stalwart               # Linux"
exit 1
