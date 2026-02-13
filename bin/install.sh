#!/usr/bin/env bash
# 安装脚本只负责：安装 PHP 主版本（Linux/macOS 下自动安装编译依赖并下载 php-src 编译到 extend/server/php）、
# 将 extend/server/* 加入 PATH，然后交给 run.php 处理其余（扩展/函数依赖、php.ini、env、composer、setup 等）。
# SOLID：Windows 流程由 install.bat 独立处理；本脚本仅处理 Linux/macOS。
# 用法：./install.sh [--path-only] [php] [pgsql] [mysql]  无参数时默认 php + pgsql（仅 PATH，pgsql/mysql 不安装只检测）
# 兼容：Bash 3.2+（macOS 默认）、GNU/BSD grep、常见 Linux 发行版

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
SERVER_DIR="$ROOT/extend/server"
PHP_SRC_CACHE="${PHP_SRC_CACHE:-$ROOT/var/tmp/php-src-cache}"
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

# Mac：确保 Homebrew 已安装（未安装则自动安装）
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

# Mac：用 Homebrew 安装 PHP 编译依赖（openssl、libxml2、curl 等）
install_macos_brew_deps() {
  ensure_brew_installed || return 1
  echo "Installing macOS build dependencies (brew)..."
  brew cleanup --prune=all 2>/dev/null || true
  brew install pkg-config zlib openssl libxml2 libxslt oniguruma curl libzip icu4c bison re2c
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

  if [[ "$PLATFORM" == "mac" ]]; then
    install_macos_brew_deps
    return $?
  fi

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
    export PATH="$brew_prefix/bin:$PATH"
    export PKG_CONFIG_PATH="$brew_prefix/lib/pkgconfig:${PKG_CONFIG_PATH:-}"
    export CPPFLAGS="-I$brew_prefix/include ${CPPFLAGS:-}"
    export LDFLAGS="-L$brew_prefix/lib ${LDFLAGS:-}"
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
    )
  fi

  if command -v pkg-config &>/dev/null && pkg-config --exists libzip 2>/dev/null; then
    conf+=("--with-zip")
  else
    echo "libzip not found; building PHP without zip extension."
  fi

  echo "Configuring php-src ..."
  if [[ "$PLATFORM" == "mac" && -n "$brew_prefix" ]]; then
    (
      export PATH="$brew_prefix/bin:$PATH"
      export PKG_CONFIG_PATH="$brew_prefix/lib/pkgconfig:${PKG_CONFIG_PATH:-}"
      export CPPFLAGS="-I$brew_prefix/include ${CPPFLAGS:-}"
      export LDFLAGS="-L$brew_prefix/lib ${LDFLAGS:-}"
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
  echo "Installing PHP $PHP_VERSION from php-src into $dest ..."
  install_php_system_deps
  install_php_from_source "$dest"
  add_to_path "$dest"
  [[ -d "$dest/bin" ]] && add_to_path "$dest/bin"
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
  if [[ -f /etc/debian_version ]]; then
    echo "  e.g. sudo apt install -y postgresql-${INSTALL_PGSQL_VERSION} postgresql-client-${INSTALL_PGSQL_VERSION}"
  elif [[ -f /etc/redhat-release ]]; then
    echo "  e.g. sudo dnf install -y postgresql${INSTALL_PGSQL_VERSION}-server postgresql${INSTALL_PGSQL_VERSION}"
  elif [[ "$PLATFORM" == "mac" ]]; then
    echo "  Install PostgreSQL manually (without brew if desired), then ensure psql is available in $dest/bin"
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

# 安装后：由 setup/server_installer/run.php 执行（与 Windows 一致；无 PHP 则报错退出）
PHP_EXE=""
[[ -x "$SERVER_DIR/php/bin/php" ]] && PHP_EXE="$SERVER_DIR/php/bin/php"
[[ -x "$SERVER_DIR/php/php" ]] && PHP_EXE="$SERVER_DIR/php/php"
[[ -z "$PHP_EXE" ]] && command -v php &>/dev/null && PHP_EXE="php"
if [[ -z "$PHP_EXE" ]] || ! "$PHP_EXE" -v &>/dev/null; then
  echo "ERROR: PHP not found. Install to $SERVER_DIR/php or add php to PATH." >&2
  exit 1
fi
echo ""
(cd "$ROOT" && "$PHP_EXE" setup/server_installer/run.php) || exit 1
echo ""
echo "Done. To apply PATH in this shell, run: source ~/.bashrc   (or source ~/.zshrc)"
echo "Or open a new terminal."
