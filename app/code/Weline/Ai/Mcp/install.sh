#!/usr/bin/env sh
set -eu
ACTION=install
SOURCE=${WELINE_MCP_SOURCE:-github}
BRANCH=${WELINE_MCP_BRANCH:-main}
INSTALL_DIR=${WELINE_MCP_INSTALL_DIR:-"${XDG_DATA_HOME:-$HOME/.local/share}/weline-codex-mcp"}
PURGE_DATA=0
for argument in "$@"; do
    case "$argument" in
        install|status|uninstall) ACTION=$argument ;;
        github|gitee) SOURCE=$argument ;;
        --source=*) SOURCE=${argument#--source=} ;;
        --branch=*) BRANCH=${argument#--branch=} ;;
        --install-dir=*) INSTALL_DIR=${argument#--install-dir=} ;;
        --purge-data) PURGE_DATA=1 ;;
        -h|--help) echo "Usage: install.sh [install|status|uninstall] [--source=github|gitee] [--branch=main] [--install-dir=path] [--purge-data]"; exit 0 ;;
        *) echo "Unknown option: $argument" >&2; exit 2 ;;
    esac
done
case "$SOURCE" in github|gitee) ;; *) echo "Source must be github or gitee." >&2; exit 2 ;; esac
MARKER="$INSTALL_DIR/.weline-mcp-managed"
TEMP_ROOT=
cleanup() { if [ -n "$TEMP_ROOT" ] && [ -d "$TEMP_ROOT" ]; then rm -rf "$TEMP_ROOT"; fi; }
trap cleanup EXIT HUP INT TERM
download_source() {
    TEMP_ROOT=$(mktemp -d "${TMPDIR:-/tmp}/weline-mcp.XXXXXX")
    ARCHIVE="$TEMP_ROOT/source.tar.gz"; EXTRACT="$TEMP_ROOT/extract"; mkdir -p "$EXTRACT"
    if [ "$SOURCE" = gitee ]; then URL="https://gitee.com/aiweline/weline-codex-mcp/repository/archive/$BRANCH.tar.gz";
    else URL="https://github.com/Aiweline/Weline-Codex-Mcp/archive/refs/heads/$BRANCH.tar.gz"; fi
    if command -v curl >/dev/null 2>&1; then curl -fL --retry 2 "$URL" -o "$ARCHIVE";
    elif command -v wget >/dev/null 2>&1; then wget -O "$ARCHIVE" "$URL";
    else echo "curl or wget is required." >&2; exit 1; fi
    tar -xzf "$ARCHIVE" -C "$EXTRACT"
    ENTRY=$(find "$EXTRACT" -type f -path '*/bin/learning-mcp' -print | head -n 1)
    if [ -z "$ENTRY" ]; then echo "Downloaded archive does not contain bin/learning-mcp." >&2; exit 1; fi
    SOURCE_ROOT=$(dirname -- "$(dirname -- "$ENTRY")")
}
if [ "$ACTION" = install ]; then
    download_source
    if [ -e "$INSTALL_DIR" ] && [ ! -f "$MARKER" ]; then echo "Refusing to replace unowned directory: $INSTALL_DIR" >&2; exit 1; fi
    PARENT=$(dirname -- "$INSTALL_DIR"); mkdir -p "$PARENT"; BACKUP="$INSTALL_DIR.backup.$$"
    if [ -e "$INSTALL_DIR" ]; then mv "$INSTALL_DIR" "$BACKUP"; fi
    if ! mv "$SOURCE_ROOT" "$INSTALL_DIR"; then [ ! -e "$BACKUP" ] || mv "$BACKUP" "$INSTALL_DIR"; exit 1; fi
    : > "$MARKER"
    chmod +x "$INSTALL_DIR/install.sh" "$INSTALL_DIR/start.sh" "$INSTALL_DIR/bin/learning-mcp" "$INSTALL_DIR/bin/learningctl" 2>/dev/null || true
    if ! "$INSTALL_DIR/start.sh" install; then
        rm -rf "$INSTALL_DIR"; [ ! -e "$BACKUP" ] || mv "$BACKUP" "$INSTALL_DIR"
        echo "Installation failed; previous managed version restored." >&2; exit 1
    fi
    rm -rf "$BACKUP"; echo "Managed installation: $INSTALL_DIR"; exit 0
fi
if [ -f "$INSTALL_DIR/start.sh" ]; then SOURCE_ROOT=$INSTALL_DIR; else download_source; fi
if [ "$ACTION" = status ]; then "$SOURCE_ROOT/start.sh" status; exit $?; fi
if [ "$PURGE_DATA" -eq 1 ]; then "$SOURCE_ROOT/start.sh" uninstall --purge-data; else "$SOURCE_ROOT/start.sh" uninstall; fi
STATUS=$?
if [ "$STATUS" -eq 0 ] && [ -f "$MARKER" ]; then rm -rf "$INSTALL_DIR"; echo "Removed managed installation: $INSTALL_DIR"; fi
exit "$STATUS"
