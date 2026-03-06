#!/usr/bin/env bash
# WelineFramework install launcher. Use: ./bin/install.sh or bash bin/install.sh (sh bin/install.sh also works).
dir="$(cd "$(dirname "$0")" && pwd)"
[ -z "${BASH_VERSION:-}" ] && exec /usr/bin/env bash "$dir/install.bash" "$@"
exec /usr/bin/env bash "$dir/install.bash" "$@"
