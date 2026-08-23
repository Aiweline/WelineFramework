#!/usr/bin/env sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ACTION=${1:-install}
if [ "$#" -gt 0 ]; then shift; fi
if [ -z "${HOME:-}" ]; then
    echo "HOME is required to create the default MCP configuration." >&2
    exit 1
fi
CONFIG_PATH=${LEARNING_MCP_CONFIG:-"$HOME/.learning-mcp/config.yaml"}
case "$CONFIG_PATH" in "~/"*) CONFIG_PATH="$HOME/${CONFIG_PATH#~/}" ;; esac
export LEARNING_MCP_CONFIG="$CONFIG_PATH"

runtime_ready() {
    command -v php >/dev/null 2>&1 \
        && command -v git >/dev/null 2>&1 \
        && php -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' \
        && php -r 'foreach (["pdo_sqlite", "json", "mbstring", "openssl"] as $extension) { if (!extension_loaded($extension)) { exit(1); } }'
}
sudo_prefix() {
    if [ "$(id -u)" -eq 0 ]; then printf '%s' "";
    elif command -v sudo >/dev/null 2>&1; then printf '%s' "sudo";
    else echo "A root shell or sudo is required to install MCP dependencies." >&2; exit 1; fi
}
install_dependencies() {
    echo "Installing PHP 8.2+, SQLite/mbstring/OpenSSL extensions, and Git..." >&2
    if command -v brew >/dev/null 2>&1; then
        brew install php git 1>&2
        PATH="$(brew --prefix)/bin:$PATH"; export PATH; return
    fi
    SUDO=$(sudo_prefix)
    if command -v apt-get >/dev/null 2>&1; then
        $SUDO apt-get update 1>&2
        $SUDO apt-get install -y php-cli php-sqlite3 php-mbstring php-common git 1>&2
    elif command -v dnf >/dev/null 2>&1; then
        $SUDO dnf install -y php-cli php-pdo php-mbstring php-process git 1>&2
    elif command -v yum >/dev/null 2>&1; then
        $SUDO yum install -y php-cli php-pdo php-mbstring php-process git 1>&2
    elif command -v pacman >/dev/null 2>&1; then
        $SUDO pacman -Sy --needed --noconfirm php php-sqlite git 1>&2
    elif command -v zypper >/dev/null 2>&1; then
        $SUDO zypper --non-interactive install php8 php8-sqlite php8-mbstring php8-openssl git 1>&2
    else
        echo "Install PHP 8.2+, pdo_sqlite, json, mbstring, openssl, and Git, then retry." >&2
        exit 1
    fi
}
if ! runtime_ready; then install_dependencies; hash -r; fi
if ! runtime_ready; then echo "MCP runtime verification failed after installation." >&2; exit 1; fi

case "$ACTION" in
    install|status|uninstall) exec php "$SCRIPT_DIR/scripts/install.php" "$ACTION" --config "$CONFIG_PATH" "$@" ;;
    serve)
        CONFIG_DIR=$(dirname -- "$CONFIG_PATH"); umask 077; mkdir -p "$CONFIG_DIR"
        if [ ! -f "$CONFIG_PATH" ]; then cp "$SCRIPT_DIR/config.example.yaml" "$CONFIG_PATH"; fi
        exec php "$SCRIPT_DIR/bin/learning-mcp" --config "$CONFIG_PATH" "$@"
        ;;
    *) echo "Usage: start.sh [install|status|uninstall|serve] [options]" >&2; exit 2 ;;
esac
