#!/usr/bin/env bash
# 一键安装引导脚本：克隆仓库并执行 bin/install。
# 用法（复制到终端执行）：
#   Linux/macOS/Git Bash: curl -fsSL https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.sh | bash -s --
#   指定分支: curl -fsSL https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.sh | bash -s -- -b server-opt
# 依赖：macOS 会自动检测并等待 Xcode 命令行工具（含 Git）；Linux 下需已安装 Git 或由 install.sh 按发行版自动安装。

if [ -z "${BASH_VERSION:-}" ]; then
  exec /usr/bin/env bash "$0" "$@"
fi

set -e

# 项目根 = 含 bin/ 的目录（各平台一致）。优先用当前目录判断是否已在仓库内（兼容 curl | bash 时无脚本路径）
if [[ -f "./setup/server_installer/run.php" ]] && [[ -f "./bin/install" ]]; then
  echo "Already in WelineFramework. Running install..."
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

REPO_URL="${WELINE_REPO_URL:-https://gitee.com/aiweline/WelineFramework.git}"
BRANCH="master"
INSTALL_DIR="weline"

next_is_b=
for i in "$@"; do
  if [[ -n "$next_is_b" ]]; then BRANCH="$i"; next_is_b=; continue; fi
  [[ "$i" == "-b" ]] && next_is_b=1
done

# 当前目录为空则克隆到当前目录（项目根 = 含 bin/ 的目录）；否则克隆到 INSTALL_DIR 子目录
is_empty_dir() { [[ -z "$(ls -A "$1" 2>/dev/null)" ]]; }
CLONE_TO_ROOT=false
if is_empty_dir .; then
  echo "Cloning WelineFramework (branch: $BRANCH) into current directory (project root)..."
  git clone -b "$BRANCH" "$REPO_URL" .
  CLONE_TO_ROOT=true
elif [[ -d "$INSTALL_DIR/.git" ]]; then
  echo "Directory $INSTALL_DIR already exists. Updating..."
  (cd "$INSTALL_DIR" && git fetch origin && git checkout "$BRANCH" 2>/dev/null || git pull origin "$BRANCH" 2>/dev/null || true)
  cd "$INSTALL_DIR"
else
  echo "Cloning WelineFramework (branch: $BRANCH) into $INSTALL_DIR..."
  git clone -b "$BRANCH" "$REPO_URL" "$INSTALL_DIR"
  cd "$INSTALL_DIR"
fi

# 保证 bin/install 可执行（克隆后可能无 x 位）
chmod +x bin/install bin/install.sh 2>/dev/null || true
exec ./bin/install "$@"
