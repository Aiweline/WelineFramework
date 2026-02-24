#!/usr/bin/env bash
# 安装脚本只负责：安装 PHP 主版本（Linux 下编译到 extend/server/php；macOS 下用 Homebrew 安装 PHP 及扩展，依赖可控）、
# 将 extend/server/php/bin 与 extend/server/pgsql/bin 加入 PATH（Linux/Mac 写 shell 配置；Windows 由 install.bat 写用户 PATH），
# 然后交给 run.php 处理其余（php.ini、env、composer、setup 等）。所有系统安装后均配置好 php 与 pgsql 环境变量。
# SOLID：Windows 流程由 install.bat 独立处理；本脚本仅处理 Linux/macOS。
# 用法：./bin/install.sh 或 bash bin/install.sh（必须用 bash，不要用 sh）
# 兼容：Bash 3.2+（macOS 默认）、GNU/BSD grep、常见 Linux 发行版

# 必须用 bash 运行（脚本使用 [[ ]]、数组等），若用 sh 调用则自动用 bash 重新执行
if [ -z "${BASH_VERSION:-}" ]; then
  exec /usr/bin/env bash "$0" "$@"
fi

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
SERVER_DIR="$ROOT/extend/server"
PHP_SRC_CACHE="${PHP_SRC_CACHE:-$ROOT/var/tmp/php-src-cache}"
VALID_COMPONENTS="php pgsql mysql"

# 默认版本（weline.env 可覆盖）
INSTALL_PGSQL_VERSION="${INSTALL_PGSQL_VERSION:-16}"
INSTALL_MYSQL_VERSION="${INSTALL_MYSQL_VERSION:-8.0}"

WELINE_REPO_URL="${WELINE_REPO_URL:-https://gitee.com/aiweline/WelineFramework.git}"

usage() {
  echo "Usage: $0 [--path-only] [-b BRANCH] [php] [pgsql] [mysql]"
  echo "  No args: install php and pgsql (default)."
  echo "  --path-only: only add extend/server/*/bin to PATH, do not download/install."
  echo "  -b BRANCH: when run.php is missing, clone this branch (default: master)."
  exit 0
}

# 解析参数
PATH_ONLY=false
BRANCH="master"
COMPONENTS=()
while [[ $# -gt 0 ]]; do
  case "$1" in
    -h|--help) usage ;;
    --path-only) PATH_ONLY=true; shift ;;
    -b) [[ -n "${2:-}" ]] && { BRANCH="$2"; shift 2; } || shift ;;
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

# PHP 版本：与 Windows 一致，优先 weline.env 的 INSTALL_PHP_VERSION，否则从 composer.json 解析
PHP_VERSION="${INSTALL_PHP_VERSION:-$(get_php_version)}"
[[ "$PHP_VERSION" =~ ^([0-9]+\.[0-9]+) ]] && PHP_VERSION="${BASH_REMATCH[1]}"
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

# 向 PATH 追加（避免重复）；同时在本 shell 中生效；Mac/Linux 下若配置文件不存在则创建，确保安装后新终端可用 php/pgsql
add_to_path() {
  local dir="$1"
  [[ ! -d "$dir" ]] && return
  export PATH="$dir:$PATH"
  local line="export PATH=\"$dir:\$PATH\""
  # Linux：.bashrc + .profile（登录 shell 常用）+ .bash_profile + .zshrc
  # Mac：默认 zsh，.zshrc + .zprofile（登录 shell）+ .bash_profile + .bashrc
  local rc_files=(~/.bashrc ~/.profile ~/.bash_profile ~/.zshrc)
  [[ "$PLATFORM" == "mac" ]] && rc_files=(~/.zshrc ~/.zprofile ~/.bash_profile ~/.bashrc)
  for f in "${rc_files[@]}"; do
    if [[ ! -f "$f" ]]; then
      touch "$f" 2>/dev/null || continue
    fi
    if grep -qF "$dir" "$f" 2>/dev/null; then
      continue
    fi
    echo "" >> "$f"
    echo "# WelineFramework install: $dir" >> "$f"
    echo "$line" >> "$f"
    echo "Added to $f: $dir"
  done
}

run_privileged() {
  if [[ "$(id -u)" -eq 0 ]]; then
    "$@"
    return $?
  fi
  if command -v sudo &>/dev/null; then
    sudo "$@"
    return $?
  fi
  echo "ERROR: sudo not found and current user is not root. Please install dependencies manually." >&2
  return 1
}

# 确保 git 已安装（拉取代码时需要）；按平台自动安装
ensure_git_installed() {
  if command -v git &>/dev/null; then
    return 0
  fi
  echo "Git not found. Installing Git..."
  if [[ "$PLATFORM" == "mac" ]]; then
    if command -v brew &>/dev/null || [[ -x /opt/homebrew/bin/brew ]] || [[ -x /usr/local/bin/brew ]]; then
      ensure_brew_installed || return 1
      brew install git || return 1
    else
      echo "Installing Xcode Command Line Tools (includes Git)..."
      xcode-select --install 2>/dev/null || true
      local wait_clt=600
      echo "请在弹出的窗口中完成「命令行开发者工具」安装。安装完成后脚本将自动继续（最多等待 $((wait_clt/60)) 分钟）。"
      local waited=0
      while [[ $waited -lt $wait_clt ]]; do
        if xcode-select -p &>/dev/null && command -v git &>/dev/null; then
          echo "Xcode 命令行工具已就绪。"
          break
        fi
        sleep 10
        waited=$((waited + 10))
        printf "  已等待 %ds …\r" "$waited"
      done
      if ! command -v git &>/dev/null; then
        echo "ERROR: 等待超时，仍未检测到 Git。请完成安装后重新执行本脚本。" >&2
        return 1
      fi
    fi
  elif [[ "$PLATFORM" == "linux" ]]; then
    if [[ -f /etc/debian_version ]]; then
      run_privileged apt-get update && run_privileged apt-get install -y git || return 1
    elif [[ -f /etc/redhat-release ]]; then
      if command -v dnf &>/dev/null; then
        run_privileged dnf install -y git || return 1
      elif command -v yum &>/dev/null; then
        run_privileged yum install -y git || return 1
      else
        echo "ERROR: Neither dnf nor yum found. Install git manually." >&2
        return 1
      fi
    elif [[ -f /etc/os-release ]] && grep -qEi "suse|opensuse" /etc/os-release 2>/dev/null; then
      run_privileged zypper install -y git || return 1
    elif [[ -f /etc/alpine-release ]]; then
      run_privileged apk add --no-cache git || return 1
    else
      echo "ERROR: Unsupported Linux distro for auto Git install. Install git manually (apt/dnf/yum/zypper/apk)." >&2
      return 1
    fi
  else
    echo "ERROR: Unsupported platform for Git install: $PLATFORM" >&2
    return 1
  fi
  echo "Git installed."
  return 0
}

# Mac：确保 Homebrew 已安装（未安装则自动安装；需要 sudo 时会提示输入密码）
ensure_brew_installed() {
  if command -v brew &>/dev/null; then
    return 0
  fi
  # 安装后可能未加入当前 shell 的 PATH，先尝试常见路径
  if [[ -x /opt/homebrew/bin/brew ]]; then
    eval "$(/opt/homebrew/bin/brew shellenv)"
    return 0
  fi
  if [[ -x /usr/local/bin/brew ]]; then
    eval "$(/usr/local/bin/brew shellenv)"
    return 0
  fi
  echo "Homebrew not found. Installing Homebrew ..."
  NONINTERACTIVE=1 /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)" || return 1
  # 安装完成后注入 PATH（Apple Silicon: /opt/homebrew，Intel: /usr/local）
  if [[ -x /opt/homebrew/bin/brew ]]; then
    eval "$(/opt/homebrew/bin/brew shellenv)"
  elif [[ -x /usr/local/bin/brew ]]; then
    eval "$(/usr/local/bin/brew shellenv)"
  else
    echo "ERROR: Homebrew install may have failed. Try: https://brew.sh" >&2
    return 1
  fi
}

# Mac：用 Homebrew 安装 PHP 及扩展（不自行编译，依赖由 brew 管理）
install_php_via_brew() {
  ensure_brew_installed || return 1
  local formula="php"
  # 指定主版本时使用 php@8.4 等，便于与项目要求一致
  if [[ "$PHP_VERSION" == "8.4" ]]; then
    formula="php@8.4"
  elif [[ "$PHP_VERSION" == "8.3" ]]; then
    formula="php@8.3"
  fi
  echo "Installing PHP via Homebrew ($formula)..."
  brew install "$formula" || return 1
  local php_prefix
  php_prefix="$(brew --prefix "$formula" 2>/dev/null)"
  [[ -z "$php_prefix" ]] && php_prefix="$(brew --prefix php 2>/dev/null)"
  if [[ -n "$php_prefix" ]] && [[ -d "$php_prefix/bin" ]]; then
    add_to_path "$php_prefix/bin"
    echo "PHP (brew $formula) added to PATH: $php_prefix/bin"
    # 在 extend/server/php/bin 做软链，便于框架和 run.php 统一从该路径读取
    mkdir -p "$SERVER_DIR/php/bin"
    ln -sf "$php_prefix/bin/php" "$SERVER_DIR/php/bin/php"
    [[ -x "$php_prefix/bin/php-fpm" ]] && ln -sf "$php_prefix/bin/php-fpm" "$SERVER_DIR/php/bin/php-fpm" 2>/dev/null || true
    echo "Symlink: $SERVER_DIR/php/bin/php -> $php_prefix/bin/php"
  else
    echo "WARNING: Could not get brew PHP path. Ensure \`php\` is in your PATH." >&2
  fi
}

install_php_system_deps() {
  if [[ "$PLATFORM" == "linux" ]]; then
    if [[ -f /etc/debian_version ]]; then
      echo "Installing Linux build dependencies (apt)..."
      run_privileged apt-get update
      run_privileged apt-get install -y \
        build-essential autoconf libtool pkg-config bison re2c \
        libxml2-dev libssl-dev libcurl4-openssl-dev libsqlite3-dev \
        libzip-dev libpng-dev libjpeg-dev libfreetype6-dev libonig-dev \
        libxslt1-dev libpq-dev libicu-dev zlib1g-dev
      return
    fi
    if [[ -f /etc/redhat-release ]]; then
      echo "Installing Linux build dependencies (dnf/yum)..."
      if command -v dnf &>/dev/null; then
        run_privileged dnf install -y \
          gcc gcc-c++ make autoconf libtool pkgconfig bison re2c \
          libxml2-devel openssl-devel libcurl-devel sqlite-devel \
          libzip-devel libpng-devel libjpeg-turbo-devel freetype-devel \
          oniguruma-devel libxslt-devel postgresql-devel libicu-devel zlib-devel
      elif command -v yum &>/dev/null; then
        run_privileged yum install -y \
          gcc gcc-c++ make autoconf libtool pkgconfig bison re2c \
          libxml2-devel openssl-devel libcurl-devel sqlite-devel \
          libzip-devel libpng-devel libjpeg-turbo-devel freetype-devel \
          oniguruma-devel libxslt-devel postgresql-devel libicu-devel zlib-devel
      else
        echo "ERROR: Neither dnf nor yum found. Please install PHP build dependencies manually." >&2
        return 1
      fi
      return
    fi
    echo "ERROR: Unsupported Linux distribution for auto dependency install. Install build deps manually." >&2
    return 1
  fi

  # Mac 不再在此安装依赖，PHP 由 install_php_via_brew 用 brew 安装
  echo "ERROR: Unsupported platform for dependency install: $PLATFORM" >&2
  return 1
}

download_php_source() {
  local tarball="$1"
  local found=""
  local used_cache=""
  mkdir -p "$PHP_SRC_CACHE"
  # 若缓存中已有该大版本任一补丁，优先用缓存中版本最高的，避免重复下载
  for p in 20 19 18 17 16 15 14 13 12 11 10 9 8 7 6 5 4 3 2 1 0; do
    local ver="${PHP_VERSION}.${p}"
    local cache_file="$PHP_SRC_CACHE/php-${ver}.tar.gz"
    if [[ -f "$cache_file" ]]; then
      echo "Using cached php-src version: $ver"
      cp -f "$cache_file" "$tarball"
      found="$ver"
      used_cache=1
      break
    fi
  done
  if [[ -n "$found" ]]; then
    return 0
  fi
  for p in 20 19 18 17 16 15 14 13 12 11 10 9 8 7 6 5 4 3 2 1 0; do
    local ver="${PHP_VERSION}.${p}"
    local cache_file="$PHP_SRC_CACHE/php-${ver}.tar.gz"
    local url="https://www.php.net/distributions/php-${ver}.tar.gz"
    echo "Trying PHP source ${ver} ..."
    if command -v curl &>/dev/null; then
      if curl -L -s -f -o "$tarball" "$url"; then
        cp -f "$tarball" "$cache_file"
        found="$ver"
        break
      fi
    elif command -v wget &>/dev/null; then
      if wget -q -O "$tarball" "$url"; then
        cp -f "$tarball" "$cache_file"
        found="$ver"
        break
      fi
    else
      echo "ERROR: curl or wget is required to download php-src." >&2
      return 1
    fi
  done
  if [[ -z "$found" ]]; then
    echo "Download failed. Check network/VPN, then retry." >&2
    return 1
  fi
  [[ -z "$used_cache" ]] && echo "Downloaded php-src version: $found"
  return 0
}

install_php_from_source() {
  local dest="$1"
  local build_root="$ROOT/var/tmp/php-build-${PHP_VERSION}-$$"
  local tarball="$build_root/php-src.tar.gz"
  mkdir -p "$build_root"

  download_php_source "$tarball"

  echo "Extracting php-src ..."
  tar -xzf "$tarball" -C "$build_root"
  local src_dir
  src_dir=$(find "$build_root" -maxdepth 1 -type d -name "php-${PHP_VERSION}.*" | head -1)
  if [[ -z "$src_dir" ]]; then
    src_dir=$(find "$build_root" -maxdepth 1 -type d -name "php-*" | head -1)
  fi
  if [[ -z "$src_dir" ]]; then
    echo "ERROR: php-src extract failed." >&2
    rm -rf "$build_root"
    return 1
  fi

  local jobs
  jobs=$(nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null || echo 2)

  mkdir -p "$dest"
  pushd "$src_dir" >/dev/null

  local brew_prefix=""
  if [[ "$PLATFORM" == "mac" ]]; then
    ensure_brew_installed || { popd >/dev/null; return 1; }
    brew_prefix="$(brew --prefix)"
    export PATH="$brew_prefix/bin:$brew_prefix/opt/libpq/bin:$PATH"
    # 包含 icu4c、libzip、oniguruma 等 opt 公式的 pkgconfig，便于 configure 一次性找到所有依赖
    export PKG_CONFIG_PATH="$brew_prefix/lib/pkgconfig:$brew_prefix/opt/icu4c/lib/pkgconfig:$brew_prefix/opt/libzip/lib/pkgconfig:$brew_prefix/opt/oniguruma/lib/pkgconfig:${PKG_CONFIG_PATH:-}"
    export CPPFLAGS="-I$brew_prefix/include -I$brew_prefix/opt/icu4c/include -I$brew_prefix/opt/libpq/include ${CPPFLAGS:-}"
    export LDFLAGS="-L$brew_prefix/lib -L$brew_prefix/opt/icu4c/lib -L$brew_prefix/opt/libpq/lib ${LDFLAGS:-}"
  fi

  local -a conf
  conf=(
    "./configure"
    "--prefix=$dest"
    "--with-config-file-path=$dest"
    "--with-config-file-scan-dir=$dest/conf.d"
    "--with-zlib"
    "--with-openssl"
    "--with-curl"
    "--enable-mbstring"
    "--enable-exif"
    "--enable-intl"
    "--enable-bcmath"
    "--enable-opcache"
    "--enable-pcntl"
    "--with-xsl"
    "--with-pdo-pgsql"
    "--with-pgsql"
    "--with-sqlite3"
    "--with-pdo-sqlite"
    "--with-iconv"
  )

  if [[ "$PLATFORM" == "mac" && -n "$brew_prefix" ]]; then
    conf+=(
      "--with-openssl=$brew_prefix/opt/openssl"
      "--with-curl=$brew_prefix/opt/curl"
      "--with-zlib=$brew_prefix/opt/zlib"
      "--with-libxml=$brew_prefix/opt/libxml2"
      "--with-xsl=$brew_prefix/opt/libxslt"
      "--with-iconv=$brew_prefix/opt/libiconv"
      "--with-libzip=$brew_prefix/opt/libzip"
      "--with-onig=$brew_prefix/opt/oniguruma"
      "--with-pgsql=$brew_prefix/opt/libpq"
    )
  fi

  if [[ "$PLATFORM" != "mac" ]]; then
    if command -v pkg-config &>/dev/null && pkg-config --exists libzip 2>/dev/null; then
      conf+=("--with-zip")
    else
      echo "libzip not found; building PHP without zip extension."
    fi
  fi

  echo "Configuring php-src ..."
  if [[ "$PLATFORM" == "mac" && -n "$brew_prefix" ]]; then
    (
      export PATH="$brew_prefix/bin:$brew_prefix/opt/libpq/bin:$PATH"
      export PKG_CONFIG_PATH="$brew_prefix/lib/pkgconfig:$brew_prefix/opt/icu4c/lib/pkgconfig:$brew_prefix/opt/libzip/lib/pkgconfig:$brew_prefix/opt/oniguruma/lib/pkgconfig:${PKG_CONFIG_PATH:-}"
      export CPPFLAGS="-I$brew_prefix/include -I$brew_prefix/opt/icu4c/include -I$brew_prefix/opt/libpq/include ${CPPFLAGS:-}"
      export LDFLAGS="-L$brew_prefix/lib -L$brew_prefix/opt/icu4c/lib -L$brew_prefix/opt/libpq/lib ${LDFLAGS:-}"
      # 显式指定 ICU，避免 configure 报 icu-uc/icu-io/icu-i18n not found
      export ICU_CFLAGS="-I$brew_prefix/opt/icu4c/include"
      export ICU_LIBS="-L$brew_prefix/opt/icu4c/lib -licuuc -licui18n -licudata"
      # 显式指定 libpq，避免 configure 报 Cannot find libpq-fe.h or pq library
      export PGSQL_CFLAGS="-I$brew_prefix/opt/libpq/include"
      export PGSQL_LIBS="-L$brew_prefix/opt/libpq/lib -lpq"
      "${conf[@]}"
    )
  else
    "${conf[@]}"
  fi
  echo "Building php-src (jobs=$jobs) ..."
  make -j"$jobs"
  echo "Installing php to $dest ..."
  make install

  popd >/dev/null
  rm -rf "$build_root"

  if [[ ! -x "$dest/bin/php" ]] && [[ ! -x "$dest/php" ]]; then
    echo "ERROR: PHP install verification failed at $dest." >&2
    return 1
  fi
  echo "PHP installed to $dest."
  return 0
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

# ---- PHP ----（Linux：检测 extend/server/php 或编译安装；Mac：仅用 Homebrew 安装，不自行编译）
install_php() {
  local dest="$SERVER_DIR/php"
  local php_exe=""

  # Mac：统一用 Homebrew 安装 PHP 及扩展，依赖由 brew 管理，不自行编译
  if [[ "$PLATFORM" == "mac" ]]; then
    echo "========== PHP (Mac, Homebrew) =========="
    if [[ "$PATH_ONLY" == true ]]; then
      local p
      for p in "$(brew --prefix php@8.4 2>/dev/null)/bin" "$(brew --prefix php@8.3 2>/dev/null)/bin" "$(brew --prefix php 2>/dev/null)/bin"; do
        [[ -d "$p" ]] && add_to_path "$p"
      done
      echo "(--path-only) Brew PHP paths added if present."
      return
    fi
    ensure_brew_installed || return 1
    # 尝试从 PATH 或常见 brew 路径获取已有 php
    php_exe="$(command -v php 2>/dev/null)"
    [[ -z "$php_exe" ]] && [[ -x "$(brew --prefix php 2>/dev/null)/bin/php" ]] && php_exe="$(brew --prefix php)/bin/php"
    [[ -z "$php_exe" ]] && [[ -x "$(brew --prefix php@8.4 2>/dev/null)/bin/php" ]] && php_exe="$(brew --prefix php@8.4)/bin/php"
    [[ -z "$php_exe" ]] && [[ -x "$(brew --prefix php@8.3 2>/dev/null)/bin/php" ]] && php_exe="$(brew --prefix php@8.3)/bin/php"
    if [[ -n "$php_exe" ]]; then
      local installed
      installed=$(get_installed_php_major_minor "$php_exe")
      if [[ -n "$installed" ]] && [[ "$installed" == "$PHP_VERSION" ]]; then
        local php_prefix
        php_prefix="$(brew --prefix php@${PHP_VERSION} 2>/dev/null)" || php_prefix="$(brew --prefix php 2>/dev/null)"
        if [[ -n "$php_prefix" ]] && [[ -d "$php_prefix/bin" ]]; then
          add_to_path "$php_prefix/bin"
          mkdir -p "$SERVER_DIR/php/bin"
          ln -sf "$php_prefix/bin/php" "$SERVER_DIR/php/bin/php"
          [[ -x "$php_prefix/bin/php-fpm" ]] && ln -sf "$php_prefix/bin/php-fpm" "$SERVER_DIR/php/bin/php-fpm" 2>/dev/null || true
        fi
        echo "PHP already installed via brew (version $installed). Skipping."
        return
      fi
    fi
    install_php_via_brew
    return
  fi

  # Linux：检测 extend/server/php 或从源码编译
  [[ -x "$dest/php.exe" ]] && php_exe="$dest/php.exe"
  [[ -z "$php_exe" ]] && [[ -x "$dest/php" ]] && php_exe="$dest/php"
  [[ -z "$php_exe" ]] && [[ -x "$dest/bin/php" ]] && php_exe="$dest/bin/php"
  if [[ -n "$php_exe" ]]; then
    local installed
    installed=$(get_installed_php_major_minor "$php_exe")
    if [[ -n "$installed" ]] && [[ "$installed" == "$PHP_VERSION" ]]; then
      echo "PHP already installed at $dest (version $installed). Skipping build and install."
    elif [[ -n "$installed" ]]; then
      echo "PHP at $dest is $installed (required $PHP_VERSION). Keeping existing; adding to PATH."
    else
      echo "PHP already present at $dest. Adding to PATH."
    fi
    add_to_path "$dest"
    [[ -d "$dest/bin" ]] && add_to_path "$dest/bin"
    return
  fi
  if [[ "$PATH_ONLY" == true ]]; then
    echo "(--path-only) PHP not found at $dest; add PATH manually if needed."
    return
  fi
  echo "Installing PHP $PHP_VERSION from php-src into $dest ..."
  install_php_system_deps
  install_php_from_source "$dest"
  add_to_path "$dest"
  [[ -d "$dest/bin" ]] && add_to_path "$dest/bin"
}

# Mac：用 Homebrew 安装 PostgreSQL，并在 extend/server/pgsql 做软链（与 Windows 路径一致，初始化逻辑复用 run.php）
install_pgsql_via_brew() {
  ensure_brew_installed || return 1
  local formula="postgresql@${INSTALL_PGSQL_VERSION}"
  echo "Installing PostgreSQL via Homebrew ($formula)..."
  brew install "$formula" || return 1
  brew services start "$formula" 2>/dev/null || true
  local pg_prefix
  pg_prefix="$(brew --prefix "$formula" 2>/dev/null)"
  if [[ -z "$pg_prefix" ]] || [[ ! -d "$pg_prefix/bin" ]]; then
    echo "WARNING: Could not get brew PostgreSQL path." >&2
    return 1
  fi
  mkdir -p "$SERVER_DIR/pgsql"
  rm -rf "$SERVER_DIR/pgsql/bin"
  ln -sf "$pg_prefix/bin" "$SERVER_DIR/pgsql/bin"
  add_to_path "$SERVER_DIR/pgsql/bin"
  echo "PostgreSQL (brew $formula) linked at $SERVER_DIR/pgsql/bin -> $pg_prefix/bin"
}

# ---- PostgreSQL ----（Linux 仅检测；Mac 用 brew 自动安装并软链到 extend/server/pgsql；初始化与 Win 一致由 run.php Step 5b 完成）
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
  if [[ "$PLATFORM" == "mac" ]]; then
    echo "========== PostgreSQL (Mac, Homebrew) =========="
    install_pgsql_via_brew
    return
  fi
  echo "PostgreSQL not at $dest. Install manually, then run: $0 --path-only pgsql"
  if [[ -f /etc/debian_version ]]; then
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
    php)
      if ! install_php; then
        echo "ERROR: PHP installation failed." >&2
        echo "  On Mac: 若因权限报错，请将当前用户设为管理员后重试，或由管理员执行: brew install php@$PHP_VERSION" >&2
        exit 1
      fi
      ;;
    pgsql) install_pgsql ;;
    mysql) install_mysql ;;
  esac
done

# 安装后：将 php 与 pgsql 的 bin 目录写入环境变量（Linux/Mac 新开终端即可用 php、psql）
[[ -d "$SERVER_DIR/php/bin" ]] && add_to_path "$SERVER_DIR/php/bin"
[[ -d "$SERVER_DIR/pgsql/bin" ]] && add_to_path "$SERVER_DIR/pgsql/bin"

# 安装后：由 setup/server_installer/run.php 执行（与 Windows 一致；无 PHP 则报错退出）
# 优先用 extend/server/php/bin/php（含 Mac 下指向 brew 的软链），再尝试 PATH 中的 php，Mac 下再显式查 brew 路径
PHP_EXE=""
[[ -x "$SERVER_DIR/php/bin/php" ]] && PHP_EXE="$SERVER_DIR/php/bin/php"
[[ -z "$PHP_EXE" ]] && [[ -x "$SERVER_DIR/php/php" ]] && PHP_EXE="$SERVER_DIR/php/php"
[[ -z "$PHP_EXE" ]] && command -v php &>/dev/null && PHP_EXE="$(command -v php)"
if [[ -z "$PHP_EXE" ]] && [[ "$PLATFORM" == "mac" ]]; then
  for p in "$(brew --prefix php@8.4 2>/dev/null)" "$(brew --prefix php@8.3 2>/dev/null)" "$(brew --prefix php 2>/dev/null)"; do
    [[ -n "$p" ]] && [[ -x "$p/bin/php" ]] && PHP_EXE="$p/bin/php" && break
  done
fi
if [[ -z "$PHP_EXE" ]] || ! "$PHP_EXE" -v &>/dev/null; then
  echo "ERROR: PHP not found. Install to $SERVER_DIR/php or add php to PATH. On Mac: brew install php@$PHP_VERSION" >&2
  exit 1
fi

# 若 setup/server_installer/run.php 不存在，说明代码未安装：按 -b 指定分支拉取，未指定则 master
if [[ ! -f "$ROOT/setup/server_installer/run.php" ]]; then
  ensure_git_installed || { echo "ERROR: Git is required to install code. Install Git and re-run." >&2; exit 1; }
  echo "run.php not found. Installing framework code from gitee (branch: $BRANCH)..."
  if [[ -d "$ROOT/.git" ]]; then
    git -C "$ROOT" fetch origin 2>/dev/null || true
    git -C "$ROOT" checkout "$BRANCH" 2>/dev/null || git -C "$ROOT" pull origin "$BRANCH" 2>/dev/null || true
  else
    tmp_clone="$(mktemp -d)"
    if ! git clone -b "$BRANCH" "$WELINE_REPO_URL" "$tmp_clone"; then
      echo "ERROR: Clone failed. Manual: git clone -b $BRANCH $WELINE_REPO_URL ." >&2
      rm -rf "$tmp_clone"
      exit 1
    fi
    if [[ ! -f "$tmp_clone/setup/server_installer/run.php" ]]; then
      echo "ERROR: Branch $BRANCH has no run.php. Try -b master or another branch." >&2
      rm -rf "$tmp_clone"
      exit 1
    fi
    cp -R "$tmp_clone"/. "$ROOT/"
    rm -rf "$tmp_clone"
  fi
  if [[ ! -f "$ROOT/setup/server_installer/run.php" ]]; then
    echo "ERROR: Code install failed. Ensure setup/server_installer/run.php exists." >&2
    exit 1
  fi
  echo "Code installed (branch: $BRANCH)."
fi

echo ""
(cd "$ROOT" && "$PHP_EXE" setup/server_installer/run.php) || exit 1
echo ""
echo "Done. php and pgsql have been added to PATH (written to shell config)."
echo "  Linux: ~/.bashrc, ~/.profile  |  Mac: ~/.zshrc, ~/.zprofile"
echo "To use in this terminal now:  source ~/.bashrc   (Linux) or source ~/.zshrc   (Mac)"
echo "Or open a new terminal window."
