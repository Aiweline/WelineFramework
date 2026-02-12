#!/usr/bin/env bash
# 安装脚本只负责：安装 PHP 主版本（Mac/Linux 下用 brew 或提示包管理器）、将 extend/server/* 加入 PATH，然后交给 run.php 处理其余（扩展/函数依赖、php.ini、env、composer、setup 等）。SOLID：其余一律由 PHP 根据环境自行处理。
# 用法：./install.sh [--path-only] [php] [pgsql] [mysql]  无参数时默认 php + pgsql（仅 PATH，pgsql/mysql 不安装只检测）
# 兼容：Bash 3.2+（macOS 默认）、GNU/BSD grep、常见 Linux 发行版

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
SERVER_DIR="$ROOT/extend/server"
VALID_COMPONENTS="php pgsql mysql"

# 默认版本（weline.env 可覆盖）
INSTALL_PGSQL_VERSION="${INSTALL_PGSQL_VERSION:-16}"
INSTALL_MYSQL_VERSION="${INSTALL_MYSQL_VERSION:-8.0}"

usage() {
  echo "Usage: $0 [--path-only] [php] [pgsql] [mysql]"
  echo "  No args: install php and pgsql (default)."
  echo "  --path-only: only add extend/server/*/bin to PATH, do not download/install."
  exit 0
}

# 解析参数
PATH_ONLY=false
COMPONENTS=()
while [[ $# -gt 0 ]]; do
  case "$1" in
    -h|--help) usage ;;
    --path-only) PATH_ONLY=true; shift ;;
    php|pgsql|mysql)
      if [[ " $VALID_COMPONENTS " == *" $1 "* ]]; then
        COMPONENTS+=("$1"); shift
      else
        shift
      fi
      ;;
    *) echo "Unknown component or option: $1" >&2; exit 1 ;;
  esac
done
# 无组件时默认安装 php 和 pgsql
[[ ${#COMPONENTS[@]} -eq 0 ]] && COMPONENTS=(php pgsql)

# 读取 weline.env 并检查配置完整性（与 Windows 一致）
WELINE_ENV_INCOMPLETE=false
if [[ -f "$ROOT/weline.env" ]]; then
  while IFS= read -r line || [[ -n "$line" ]]; do
    [[ "$line" =~ ^[[:space:]]*# ]] && continue
    [[ -z "${line//[[:space:]]/}" ]] && continue
    if [[ "$line" =~ ^([A-Za-z_][A-Za-z0-9_]*)=(.*)$ ]]; then
      export "${BASH_REMATCH[1]}=${BASH_REMATCH[2]}"
    else
      WELINE_ENV_INCOMPLETE=true
    fi
  done < "$ROOT/weline.env"
fi
INSTALL_PGSQL_VERSION="${INSTALL_PGSQL_VERSION:-16}"
INSTALL_MYSQL_VERSION="${INSTALL_MYSQL_VERSION:-8.0}"

# 从 composer.json 解析 PHP 主版本（兼容 GNU/BSD grep 与 bash 3.x）
get_php_version() {
  local c="$ROOT/composer.json"
  if [[ -f "$c" ]]; then
    local line
    line=$(grep '"php"' "$c" 2>/dev/null | head -1)
    if [[ -n "$line" ]] && [[ "$line" =~ ([0-9]+\.[0-9]+) ]]; then
      echo "${BASH_REMATCH[1]}"
      return
    fi
  fi
  echo "8.4"
}

PHP_VERSION=$(get_php_version)
mkdir -p "$SERVER_DIR"

# 安装前：weline.env 配置完整性检查（与 Windows 一致）
if [[ "$WELINE_ENV_INCOMPLETE" == true ]]; then
  printf '\033[31m警告: weline.env 存在格式错误（每行须为 KEY=VALUE 或 # 注释）。请检查后重试或选择继续使用默认配置。\033[0m\n'
  read -p "是否继续安装？(y/N) " -r
  [[ ! "$REPLY" =~ ^[yY] ]] && exit 1
fi

# 检测 OS（仅 Linux / Mac；Windows 请使用 bin\install.bat）
OS="$(uname -s)"
case "$OS" in
  Darwin)  PLATFORM="mac" ;;
  Linux)   PLATFORM="linux" ;;
  *)       echo "Unsupported OS: $OS. On Windows use: bin\\install.bat" >&2; exit 1 ;;
esac

# 向 PATH 追加（避免重复）
add_to_path() {
  local dir="$1"
  [[ ! -d "$dir" ]] && return
  local line="export PATH=\"$dir:\$PATH\""
  for f in ~/.bashrc ~/.zshrc ~/.bash_profile; do
    [[ -f "$f" ]] || continue
    if grep -qF "$dir" "$f" 2>/dev/null; then
      continue
    fi
    echo "" >> "$f"
    echo "# WelineFramework install: $dir" >> "$f"
    echo "$line" >> "$f"
    echo "Added to $f: $dir"
  done
}

# 从已安装的 php 可执行文件获取主次版本（如 8.4.16 -> 8.4），与 Windows install.bat 逻辑一致
get_installed_php_major_minor() {
  local exe="$1"
  [[ -z "$exe" ]] || [[ ! -f "$exe" ]] && echo "" && return
  local out
  out=$("$exe" -v 2>/dev/null) || true
  if [[ "$out" =~ PHP[^0-9]*([0-9]+\.[0-9]+) ]]; then
    echo "${BASH_REMATCH[1]}"
  else
    echo ""
  fi
}

# ---- PHP ----（与 Windows 一致：检测到 extend/server/php 且版本符合则跳过下载）
install_php() {
  local dest="$SERVER_DIR/php"
  local php_exe=""
  [[ -f "$dest/php.exe" ]] && php_exe="$dest/php.exe"
  [[ -f "$dest/php" ]] && php_exe="$dest/php"
  [[ -f "$dest/bin/php" ]] && php_exe="$dest/bin/php"
  if [[ -n "$php_exe" ]]; then
    local installed
    installed=$(get_installed_php_major_minor "$php_exe")
    if [[ -n "$installed" ]] && [[ "$installed" == "$PHP_VERSION" ]]; then
      echo "PHP already present at $dest (version $installed, matches required $PHP_VERSION). Skipping download."
    elif [[ -n "$installed" ]]; then
      echo "PHP at $dest is $installed (required $PHP_VERSION). Keeping existing; adding to PATH."
    else
      echo "PHP already present at $dest (version check failed). Adding to PATH."
    fi
    add_to_path "$dest"
    [[ -d "$dest/bin" ]] && add_to_path "$dest/bin"
    return
  fi
  if [[ "$PATH_ONLY" == true ]]; then
    echo "(--path-only) PHP not found at $dest; add PATH manually if needed."
    return
  fi
  if [[ "$PLATFORM" == "mac" ]]; then
    if command -v brew &>/dev/null; then
      echo "Installing PHP $PHP_VERSION via Homebrew..."
      brew install "php@${PHP_VERSION}" 2>/dev/null || brew install php
      local prefix
      prefix=$(brew --prefix php 2>/dev/null || brew --prefix "php@${PHP_VERSION}" 2>/dev/null || echo "/usr/local")
      add_to_path "$prefix/bin"
      return
    fi
    echo "Homebrew not found. Download PHP from https://www.php.net/downloads and extract to $dest"
    echo "Then run: $0 --path-only php"
    return
  fi
  # Linux：不在此脚本安装 PHP，仅提示；扩展与依赖由 run.php 根据环境处理
  if command -v php &>/dev/null; then
    echo "PHP already in PATH ($(command -v php)). To use extend/server, install manually to $dest and run --path-only."
    return
  fi
  echo "Linux: install PHP via your package manager, then run: $0 --path-only php"
  if [[ -f /etc/debian_version ]]; then
    echo "  e.g. sudo apt update && sudo apt install -y php-cli"
  elif [[ -f /etc/redhat-release ]]; then
    echo "  e.g. sudo dnf install -y php-cli"
  else
    echo "  Or download from https://www.php.net/downloads and extract to $dest"
  fi
}

# ---- PostgreSQL ----（仅检测并加 PATH，不安装；安装与配置交给 run.php / env）
install_pgsql() {
  local dest="$SERVER_DIR/pgsql"
  if [[ -f "$dest/bin/psql" ]] || [[ -f "$dest/bin/postgres" ]]; then
    echo "PostgreSQL already present at $dest."
    add_to_path "$dest/bin"
    return
  fi
  if [[ "$PATH_ONLY" == true ]]; then
    echo "(--path-only) PostgreSQL not found at $dest."
    return
  fi
  echo "PostgreSQL not at $dest. Install manually, then run: $0 --path-only pgsql"
  if [[ "$PLATFORM" == "mac" ]] && command -v brew &>/dev/null; then
    echo "  e.g. brew install postgresql@${INSTALL_PGSQL_VERSION}"
  elif [[ -f /etc/debian_version ]]; then
    echo "  e.g. sudo apt install -y postgresql-${INSTALL_PGSQL_VERSION} postgresql-client-${INSTALL_PGSQL_VERSION}"
  elif [[ -f /etc/redhat-release ]]; then
    echo "  e.g. sudo dnf install -y postgresql${INSTALL_PGSQL_VERSION}-server postgresql${INSTALL_PGSQL_VERSION}"
  fi
}

# ---- MySQL ----（缺省为 pgsql，仅当已安装 MySQL 时显示并加 PATH）
install_mysql() {
  local dest="$SERVER_DIR/mysql"
  if [[ -f "$dest/bin/mysql" ]] || [[ -f "$dest/bin/mysqld" ]]; then
    echo "========== MySQL =========="
    echo "MySQL already present at $dest."
    add_to_path "$dest/bin"
    return
  fi
  if [[ "$PATH_ONLY" == true ]]; then
    echo "(--path-only) MySQL not found at $dest."
    return
  fi
  # 未安装则不显示，避免与缺省 pgsql 重复提示
}

# 执行安装
echo "WelineFramework install: components=${COMPONENTS[*]}, path-only=$PATH_ONLY, PHP version=$PHP_VERSION, pgsql=$INSTALL_PGSQL_VERSION, mysql=$INSTALL_MYSQL_VERSION"
for c in "${COMPONENTS[@]}"; do
  case "$c" in
    php)   install_php ;;
    pgsql) install_pgsql ;;
    mysql) install_mysql ;;
  esac
done

# 安装后：由 setup/server_installer/run.php 执行（composer、env、setup、server）
PHP_EXE=""
[[ -x "$SERVER_DIR/php/bin/php" ]] && PHP_EXE="$SERVER_DIR/php/bin/php"
[[ -x "$SERVER_DIR/php/php" ]] && PHP_EXE="$SERVER_DIR/php/php"
[[ -z "$PHP_EXE" ]] && command -v php &>/dev/null && PHP_EXE="php"
if [[ -n "$PHP_EXE" ]] && "$PHP_EXE" -v &>/dev/null; then
  echo ""
  (cd "$ROOT" && "$PHP_EXE" setup/server_installer/run.php) || exit 1
fi

echo ""
echo "Done. To apply PATH in this shell, run: source ~/.bashrc   (or source ~/.zshrc)"
echo "Or open a new terminal."
