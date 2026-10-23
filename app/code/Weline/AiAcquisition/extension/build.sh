#!/usr/bin/env bash
# 构建浏览器扩展的 WASM 策略内核。
# 依赖：wabt 的 wat2wasm（macOS: brew install wabt）。
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="${SCRIPT_DIR}/wasm/agent_core.wat"
OUT="${SCRIPT_DIR}/wasm/agent_core.wasm"

WAT2WASM="${WAT2WASM:-}"
if [[ -z "${WAT2WASM}" ]]; then
  if command -v wat2wasm >/dev/null 2>&1; then
    WAT2WASM="$(command -v wat2wasm)"
  elif [[ -x /opt/homebrew/bin/wat2wasm ]]; then
    WAT2WASM=/opt/homebrew/bin/wat2wasm
  elif [[ -x /usr/local/bin/wat2wasm ]]; then
    WAT2WASM=/usr/local/bin/wat2wasm
  fi
fi

if [[ -z "${WAT2WASM}" ]]; then
  echo "未找到 wat2wasm，请先安装 wabt（brew install wabt）" >&2
  exit 1
fi

if [[ ! -f "${SRC}" ]]; then
  echo "缺少源文件：${SRC}" >&2
  exit 1
fi

"${WAT2WASM}" "${SRC}" -o "${OUT}"

MAGIC="$(od -An -tx1 -N4 "${OUT}" | tr -d ' \n')"
if [[ "${MAGIC}" != "0061736d" ]]; then
  echo "WASM 魔数不正确：${MAGIC}" >&2
  exit 1
fi

echo "BUILD_OK ${OUT}"
