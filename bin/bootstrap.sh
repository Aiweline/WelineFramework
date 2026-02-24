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

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

# 已在项目目录内（存在 run.php 与 bin/install）则直接执行 install，不 clone
if [[ -f "$ROOT/setup/server_installer/run.php" ]] && [[ -f "$ROOT/bin/install" ]]; then
  echo "Already in WelineFramework. Running install..."
  cd "$ROOT" && exec ./bin/install "$@"
fi

# macOS：克隆前必须已有 Xcode 命令行工具（git 依赖）。若未安装则自动触发安装并等待完成后继续
wait_for_clt_seconds=600
if [[ "$(uname -s)" == "Darwin" ]]; then
  need_clt=false
  if ! command -v git &>/dev/null; then need_clt=true; fi
  if ! xcode-select -p &>/dev/null; then need_clt=true; fi
  if [[ "$need_clt" == true ]]; then
    echo "未检测到 Xcode 命令行工具（Git 依赖）。正在请求安装…"
    xcode-select --install 2>/dev/null || true
    echo "请在弹出的窗口中完成「命令行开发者工具」安装。安装完成后脚本将自动继续（最多等待 $((wait_for_clt_seconds/60)) 分钟）。"
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
    if ! xcode-select -p &>/dev/null || ! command -v git &>/dev/null; then
      echo "ERROR: 等待超时，仍未检测到命令行工具。请完成安装后重新执行本脚本。" >&2
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

if [[ -d "$INSTALL_DIR/.git" ]]; then
  echo "Directory $INSTALL_DIR already exists. Updating..."
  (cd "$INSTALL_DIR" && git fetch origin && git checkout "$BRANCH" 2>/dev/null || git pull origin "$BRANCH" 2>/dev/null || true)
else
  echo "Cloning WelineFramework (branch: $BRANCH) into $INSTALL_DIR..."
  git clone -b "$BRANCH" "$REPO_URL" "$INSTALL_DIR"
fi

cd "$INSTALL_DIR"
exec ./bin/install "$@"
