#!/usr/bin/env bash
# 一键安装引导脚本：克隆仓库并执行 bin/install。
# 用法（复制到终端执行）：
#   Linux/macOS/Git Bash: curl -fsSL https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.sh | bash -s --
#   指定分支: curl -fsSL https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.sh | bash -s -- -b server-opt
# 需已安装 Git；若未安装，安装脚本会尝试自动安装。

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
