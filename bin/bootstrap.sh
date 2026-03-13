#!/usr/bin/env bash
# 一键安装引导脚本：克隆仓库并执行 bin/install。
# 用法（复制到终端执行，勿用 sudo，Homebrew 禁止 root 运行；需权限时会提示输入密码）：
#   Linux/macOS/Git Bash: curl -fsSL https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.sh | bash -s --
#   指定分支: curl -fsSL https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.sh | bash -s -- -b server-opt
# 依赖：macOS 会自动检测并安装 Xcode 命令行工具（含 Git）；Linux 会在克隆前按发行版自动安装 Git（apt/yum/dnf/zypper/apk）。

if [ -z "${BASH_VERSION:-}" ]; then
  exec /usr/bin/env bash "$0" "$@"
fi

set -e

# 项目根 = 含 bin/ 的目录（各平台一致）。优先用当前目录判断是否已在仓库内（兼容 curl | bash 时无脚本路径）
if [[ -f "./setup/server_installer/run.php" ]] && [[ -f "./bin/install" ]]; then
  echo "Already in WelineFramework. Running install..."
  if [[ -d .git ]] && command -v git &>/dev/null; then
    git pull origin master 2>/dev/null || git pull 2>/dev/null || true
  fi
  chmod +x ./bin/install ./bin/install.sh 2>/dev/null || true
  exec ./bin/install "$@"
fi

# macOS：克隆前必须已有 Xcode 命令行工具（git 依赖）。若未安装则脚本内用 softwareupdate 直接安装（终端可见进度），失败则回退到弹窗安装并等待
wait_for_clt_seconds=600
if [[ "$(uname -s)" == "Darwin" ]]; then
  need_clt=false
  if ! command -v git &>/dev/null; then need_clt=true; fi
  if ! xcode-select -p &>/dev/null; then need_clt=true; fi
  if [[ "$need_clt" == true ]]; then
    echo "未检测到 Xcode 命令行工具（Git 依赖），尝试在终端内直接安装（进度可见）…"
    clt_installed=false
    # 触发让 softwareupdate 列出「命令行工具」（部分系统需要此文件才会出现在列表中）
    touch /tmp/.com.apple.dt.CommandLineTools.installondemand.in-progress 2>/dev/null || true
    clt_label=$(softwareupdate -l 2>&1 | grep -E '\*.*Command Line Tools' | head -1 | sed -E 's/^[[:space:]]*\* Label: *//' | tr -d '\n')
    if [[ -n "$clt_label" ]]; then
      echo "正在安装: $clt_label"
      if sudo softwareupdate -i "$clt_label"; then
        clt_installed=true
      fi
    fi
    rm -f /tmp/.com.apple.dt.CommandLineTools.installondemand.in-progress 2>/dev/null || true
    if [[ "$clt_installed" != true ]]; then
      echo "终端内安装未成功，改为弹出系统安装窗口…"
      xcode-select --install 2>/dev/null || true
      echo "请在弹出的窗口中完成「命令行开发者工具」安装；安装完成后脚本将自动继续（最多等待 $((wait_for_clt_seconds/60)) 分钟）。"
      waited=0
      while [[ $waited -lt $wait_for_clt_seconds ]]; do
        if xcode-select -p &>/dev/null && command -v git &>/dev/null; then
          echo "Xcode 命令行工具已就绪，继续安装。"
          break
        fi
        sleep 10
        waited=$((waited + 10))
        printf "  已等待 %ds …\r" "$waited"
      done
    fi
    if ! xcode-select -p &>/dev/null || ! command -v git &>/dev/null; then
      echo "ERROR: 仍未检测到命令行工具。请完成安装后重新执行本脚本。" >&2
      exit 1
    fi
  fi
fi

# Linux：克隆前需有 Git；未安装则按发行版自动安装
if ! command -v git &>/dev/null; then
  echo "未检测到 Git，尝试按发行版自动安装…"
  if command -v apt-get &>/dev/null; then
    sudo apt-get update -qq && sudo apt-get install -y git
  elif command -v yum &>/dev/null; then
    sudo yum install -y git
  elif command -v dnf &>/dev/null; then
    sudo dnf install -y git
  elif command -v zypper &>/dev/null; then
    sudo zypper -n install git
  elif command -v apk &>/dev/null; then
    sudo apk add --no-cache git
  else
    echo "ERROR: 无法自动安装 Git，请先手动安装后重新执行本脚本。" >&2
    exit 1
  fi
  if ! command -v git &>/dev/null; then
    echo "ERROR: Git 安装后仍不可用，请检查环境。" >&2
    exit 1
  fi
  echo "Git 已安装，继续克隆。"
fi

REPO_URL="${WELINE_REPO_URL:-https://gitee.com/aiweline/WelineFramework.git}"
BRANCH="master"
INSTALL_DIR="weline"
WELINE_USER="${WELINE_USER:-weline}"

next_is_b=
for i in "$@"; do
  if [[ -n "$next_is_b" ]]; then BRANCH="$i"; next_is_b=; continue; fi
  [[ "$i" == "-b" ]] && next_is_b=1
done

is_empty_dir() { [[ -z "$(ls -A "$1" 2>/dev/null)" ]]; }

# Linux root：先创建 weline 用户并设置密码，再切换为 weline 执行克隆（项目归属 weline）
do_clone_and_install() {
  if is_empty_dir .; then
    echo "Cloning WelineFramework (branch: $BRANCH) into current directory (project root)..."
    git clone -b "$BRANCH" "$REPO_URL" .
  elif [[ -d "$INSTALL_DIR/.git" ]]; then
    echo "Directory $INSTALL_DIR already exists. Updating..."
    (cd "$INSTALL_DIR" && git fetch origin && git checkout "$BRANCH" 2>/dev/null || git pull origin "$BRANCH" 2>/dev/null || true)
    cd "$INSTALL_DIR"
  else
    echo "Cloning WelineFramework (branch: $BRANCH) into $INSTALL_DIR..."
    git clone -b "$BRANCH" "$REPO_URL" "$INSTALL_DIR"
    cd "$INSTALL_DIR"
  fi
  chmod +x bin/install bin/install.sh 2>/dev/null || true
  exec ./bin/install "$@"
}

if [[ "$(uname -s)" == "Linux" ]] && [[ "$(id -u)" -eq 0 ]]; then
  if ! id "$WELINE_USER" &>/dev/null; then
    echo "Creating user $WELINE_USER (project owner, with sudo)..."
    useradd -m -s /bin/bash "$WELINE_USER" || exit 1
    getent group wheel &>/dev/null && usermod -aG wheel "$WELINE_USER" 2>/dev/null || true
    getent group sudo &>/dev/null && usermod -aG sudo "$WELINE_USER" 2>/dev/null || true
    echo "User $WELINE_USER created."
    if [[ -n "${WELINE_USER_PASSWORD:-}" ]]; then
      echo "正在为 $WELINE_USER 设置密码（来自环境变量 WELINE_USER_PASSWORD）…"
      echo "$WELINE_USER:$WELINE_USER_PASSWORD" | chpasswd || {
        echo "自动设置密码失败，请手动输入：" >&2
        passwd "$WELINE_USER"
      }
    else
      echo "请为用户 $WELINE_USER 设置登录密码（用于 SSH、sudo 等）："
      passwd "$WELINE_USER"
    fi
  else
    echo "User $WELINE_USER already exists."
  fi
  # 配置 weline 免密 sudo，避免安装阶段 apt/yum 等待密码导致非交互失败
  if ! sudo -u "$WELINE_USER" sudo -n true 2>/dev/null; then
    echo "Configuring passwordless sudo for $WELINE_USER..."
    { echo "Defaults:$WELINE_USER !requiretty"; echo "$WELINE_USER ALL=(ALL) NOPASSWD: ALL"; } > /etc/sudoers.d/weline
    chmod 440 /etc/sudoers.d/weline
  fi
  # 准备克隆目录：若当前目录为 /root 等 weline 无法访问的路径，则使用 /home/weline（一层目录）
  WORK_DIR="$(pwd)"
  WELINE_HOME="$(eval echo ~$WELINE_USER)"
  USE_WELINE_HOME=false
  if [[ "$WORK_DIR" == /root ]] || [[ "$WORK_DIR" == /root/* ]]; then
    WORK_DIR="$WELINE_HOME"
    chown "$WELINE_USER":"$WELINE_USER" "$WORK_DIR"
    USE_WELINE_HOME=true
    echo "当前为 root 目录，将安装到 $WORK_DIR"
  elif is_empty_dir .; then
    chown "$WELINE_USER":"$WELINE_USER" "$WORK_DIR"
  elif [[ ! -d "$INSTALL_DIR" ]]; then
    mkdir -p "$INSTALL_DIR"
    chown "$WELINE_USER":"$WELINE_USER" "$INSTALL_DIR"
  elif [[ -d "$INSTALL_DIR/.git" ]]; then
    chown -R "$WELINE_USER":"$WELINE_USER" "$INSTALL_DIR"
  fi
  echo "Switching to user $WELINE_USER for clone and install..."
  exec sudo -u "$WELINE_USER" env REPO_URL="$REPO_URL" BRANCH="$BRANCH" INSTALL_DIR="$INSTALL_DIR" WORK_DIR="$WORK_DIR" USE_WELINE_HOME="$USE_WELINE_HOME" \
    bash -c 'cd "$WORK_DIR" && export REPO_URL BRANCH INSTALL_DIR WORK_DIR
    is_empty_dir() { [[ -z "$(ls -A "${1:-.}" 2>/dev/null)" ]]; }
    if [[ "$USE_WELINE_HOME" == true ]]; then
      echo "Cloning WelineFramework (branch: $BRANCH) into $WORK_DIR..."
      tmp_clone=$(mktemp -d)
      git clone -b "$BRANCH" "$REPO_URL" "$tmp_clone"
      cp -a "$tmp_clone"/. "$WORK_DIR/"
      rm -rf "$tmp_clone"
      cd "$WORK_DIR"
    elif is_empty_dir .; then
      echo "Cloning WelineFramework (branch: $BRANCH) into current directory (project root)..."
      git clone -b "$BRANCH" "$REPO_URL" .
    elif [[ -d "$INSTALL_DIR/.git" ]]; then
      echo "Directory $INSTALL_DIR already exists. Updating..."
      (cd "$INSTALL_DIR" && git fetch origin && git checkout "$BRANCH" 2>/dev/null || git pull origin "$BRANCH" 2>/dev/null || true)
      cd "$INSTALL_DIR"
    else
      echo "Cloning WelineFramework (branch: $BRANCH) into $INSTALL_DIR..."
      git clone -b "$BRANCH" "$REPO_URL" "$INSTALL_DIR"
      cd "$INSTALL_DIR"
    fi
    chmod +x bin/install bin/install.sh 2>/dev/null || true
    exec ./bin/install "$@"' - "$@"
fi

# 非 root 或 macOS：直接克隆并安装
do_clone_and_install
