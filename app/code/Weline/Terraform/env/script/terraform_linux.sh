#!/usr/bin/env bash
set -e

ACTION="${1:-check}"
VERSION="${TERRAFORM_VERSION:-1.9.8}"

# 检测 terraform 是否可用：先看 PATH，再看常见安装路径（便于 env:check 与安装后验证一致）
terraform_cmd=""
if command -v terraform >/dev/null 2>&1; then
  terraform_cmd="terraform"
elif [ -x "/usr/local/bin/terraform" ]; then
  terraform_cmd="/usr/local/bin/terraform"
elif [ -n "${HOME}" ] && [ -x "${HOME}/.local/bin/terraform" ]; then
  terraform_cmd="${HOME}/.local/bin/terraform"
fi

if [ -n "$terraform_cmd" ]; then
  echo "INSTALLED"
  $terraform_cmd -version | head -n 1 || true
  exit 0
fi

if [ "$ACTION" = "check" ]; then
  echo "MISSING"
  echo "terraform not found in PATH or /usr/local/bin or ~/.local/bin"
  exit 1
fi

if [ "$ACTION" != "install" ]; then
  echo "MISSING"
  echo "unknown action: $ACTION"
  exit 1
fi

ARCH="amd64"
case "$(uname -m)" in
  x86_64|amd64) ARCH="amd64" ;;
  aarch64|arm64) ARCH="arm64" ;;
  *) echo "MISSING"; echo "unsupported arch: $(uname -m)"; exit 1 ;;
esac

case "$(uname -s)" in
  Linux)   OS="linux" ;;
  Darwin)  OS="darwin" ;;
  *) echo "MISSING"; echo "unsupported os: $(uname -s)"; exit 1 ;;
esac

URL="https://releases.hashicorp.com/terraform/${VERSION}/terraform_${VERSION}_${OS}_${ARCH}.zip"
TMP_DIR="$(mktemp -d)"

cleanup() { rm -rf "$TMP_DIR"; }
trap cleanup EXIT

if command -v curl >/dev/null 2>&1; then
  curl -fsSL "$URL" -o "$TMP_DIR/terraform.zip"
elif command -v wget >/dev/null 2>&1; then
  wget -q "$URL" -O "$TMP_DIR/terraform.zip"
else
  echo "MISSING"
  echo "curl or wget is required to download terraform"
  exit 1
fi

if command -v unzip >/dev/null 2>&1; then
  unzip -qo "$TMP_DIR/terraform.zip" -d "$TMP_DIR"
elif command -v python3 >/dev/null 2>&1; then
  python3 -m zipfile -e "$TMP_DIR/terraform.zip" "$TMP_DIR"
else
  echo "MISSING"
  echo "unzip or python3 is required to extract terraform"
  exit 1
fi

BIN_DIR="/usr/local/bin"
if [ ! -w "$BIN_DIR" ]; then
  BIN_DIR="${HOME}/.local/bin"
  mkdir -p "$BIN_DIR"
fi

install -m 0755 "$TMP_DIR/terraform" "$BIN_DIR/terraform"

# 用安装路径直接验证，避免依赖当前 PATH 是否包含 BIN_DIR（如 ~/.local/bin）
if [ -x "$BIN_DIR/terraform" ] && "$BIN_DIR/terraform" -version >/dev/null 2>&1; then
  echo "INSTALLED"
  "$BIN_DIR/terraform" -version | head -n 1 || true
  exit 0
fi

echo "MISSING"
echo "terraform install failed"
exit 1
