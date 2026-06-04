#!/usr/bin/env bash
set -euo pipefail

ACTION="${1:-check}"
INSTALL_DIR="${STALWART_INSTALL_DIR:-/opt/stalwart}"

if [ "$ACTION" = "check" ]; then
  if command -v stalwart >/dev/null 2>&1 || [ -x "$INSTALL_DIR/bin/stalwart" ]; then
    echo "Stalwart found"
    exit 0
  fi
  echo "Stalwart not found"
  exit 1
fi

if [ "$ACTION" != "install" ]; then
  echo "Unsupported action: $ACTION"
  exit 2
fi

cat <<EOF
Stalwart Linux native install plan:
1. Download the latest Stalwart Linux binary from the official release page.
2. Create $INSTALL_DIR/bin, $INSTALL_DIR/etc, $INSTALL_DIR/data, $INSTALL_DIR/logs.
3. Place stalwart binary at $INSTALL_DIR/bin/stalwart.
4. Generate config under $INSTALL_DIR/etc.
5. Register a systemd service named stalwart.

Automatic binary download is intentionally not performed yet to avoid changing system services without an operator-reviewed source URL.
EOF
exit 1
