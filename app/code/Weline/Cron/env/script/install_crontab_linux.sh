#!/usr/bin/env bash
set -e

ACTION="${1:-check}"

# 检测 crontab 是否可用
crontab_cmd=""
if command -v crontab >/dev/null 2>&1; then
  crontab_cmd="crontab"
elif [ -x "/usr/bin/crontab" ]; then
  crontab_cmd="/usr/bin/crontab"
fi

if [ -n "$crontab_cmd" ]; then
  echo "INSTALLED"
  echo "crontab found: $crontab_cmd"
  exit 0
fi

if [ "$ACTION" = "check" ]; then
  echo "MISSING"
  echo "crontab not found in PATH or /usr/bin"
  exit 1
fi

if [ "$ACTION" != "install" ]; then
  echo "MISSING"
  echo "unknown action: $ACTION"
  exit 1
fi

# 检测是否为 root 或有 sudo
SUDO=""
if [ "$(id -u)" -ne 0 ]; then
  if command -v sudo >/dev/null 2>&1; then
    SUDO="sudo"
  else
    echo "MISSING"
    echo "Need root or sudo to install crontab"
    exit 1
  fi
fi

# 按发行版选择包管理器和包名
install_crontab() {
  local pkg=""
  local cmd=""
  
  # Alpine
  if command -v apk >/dev/null 2>&1; then
    pkg="dcron"
    cmd="$SUDO apk add --no-cache $pkg"
  # Debian/Ubuntu
  elif command -v apt-get >/dev/null 2>&1; then
    pkg="cron"
    cmd="$SUDO apt-get update -qq && $SUDO apt-get install -y -qq $pkg"
  # Fedora/RHEL 新版
  elif command -v dnf >/dev/null 2>&1; then
    pkg="cronie"
    cmd="$SUDO dnf install -y -q $pkg"
  # CentOS/RHEL 旧版
  elif command -v yum >/dev/null 2>&1; then
    pkg="cronie"
    cmd="$SUDO yum install -y -q $pkg"
  # Arch/Manjaro
  elif command -v pacman >/dev/null 2>&1; then
    pkg="cronie"
    cmd="$SUDO pacman -S --noconfirm --needed $pkg"
  else
    echo "MISSING"
    echo "Unsupported distribution: no apk/apt/dnf/yum/pacman found"
    exit 1
  fi
  
  echo "Installing crontab ($pkg)..."
  eval "$cmd"
  
  # 安装后启动服务（如可用）
  if command -v systemctl >/dev/null 2>&1; then
    $SUDO systemctl enable cron 2>/dev/null || $SUDO systemctl enable crond 2>/dev/null || true
    $SUDO systemctl start cron 2>/dev/null || $SUDO systemctl start crond 2>/dev/null || true
  elif command -v service >/dev/null 2>&1; then
    $SUDO service cron start 2>/dev/null || $SUDO service crond start 2>/dev/null || true
  fi
}

install_crontab

# 验证安装
if command -v crontab >/dev/null 2>&1; then
  echo "INSTALLED"
  echo "crontab installed successfully"
  exit 0
else
  echo "MISSING"
  echo "crontab installation failed"
  exit 1
fi
