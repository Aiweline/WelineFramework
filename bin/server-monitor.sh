#!/usr/bin/env bash

set -euo pipefail

START_COMMAND="php bin/w s:start -r"
CRON_MARKER="# WELINE_QIPAISAAS_WATCHDOG"
ENV_FILE_RELATIVE="app/etc/env.php"
resolve_project_dir() {
  local script_path script_dir dir_name
  script_path="$(resolve_script_path)"
  script_dir="$(cd "$(dirname "${script_path}")" && pwd)"
  dir_name="$(basename "${script_dir}")"

  if [ "${dir_name}" = "bin" ]; then
    cd "${script_dir}/.."
    pwd
    return 0
  fi

  echo "${script_dir}"
}


resolve_script_path() {
  if command -v readlink >/dev/null 2>&1; then
    local p
    p="$(readlink -f "$0" 2>/dev/null || true)"
    if [ -n "${p}" ] && [ -f "${p}" ]; then
      echo "${p}"
      return 0
    fi
  fi
  local dir
  dir="$(cd "$(dirname "$0")" && pwd)"
  echo "${dir}/$(basename "$0")"
}

is_site_available() {
  local target_url status
  target_url="$1"
  status="$(curl -L -sS -o /dev/null -I --connect-timeout 8 --max-time 15 -w "%{http_code}" "${target_url}" || true)"
  [ -n "${status}" ] && [ "${status}" != "000" ]
}

resolve_target_url() {
  local project_dir env_file
  project_dir="$1"
  env_file="${project_dir}/${ENV_FILE_RELATIVE}"

  if [ ! -f "${env_file}" ]; then
    return 1
  fi

  php -r '
    $file = $argv[1];
    $config = include $file;
    $wlsHost = (string)($config["wls"]["host"] ?? "");
    $cliPort = (int)($config["cli_server"]["port"] ?? 0);
    if ($wlsHost === "") {
      fwrite(STDERR, "missing wls.host\n");
      exit(2);
    }
    $hasScheme = (bool)preg_match("#^https?://#i", $wlsHost);
    $url = $hasScheme ? $wlsHost : ("https://" . $wlsHost);
    if (parse_url($url, PHP_URL_PORT) === null && $cliPort > 0) {
      $url .= ":" . $cliPort;
    }
    echo $url;
  ' "${env_file}"
}

run_watchdog() {
  local now project_dir target_url
  now="$(date '+%Y-%m-%d %H:%M:%S')"
  project_dir="$(resolve_project_dir)"
  if ! target_url="$(resolve_target_url "${project_dir}")"; then
    echo "[${now}] 无法从 ${project_dir}/${ENV_FILE_RELATIVE} 解析检测地址。"
    exit 1
  fi

  if is_site_available "${target_url}"; then
    echo "[${now}] ${target_url} 可访问，跳过重启。"
    exit 0
  fi

  echo "[${now}] ${target_url} 不可访问，执行恢复命令。"

  if [ ! -d "${project_dir}" ]; then
    echo "[${now}] 目录不存在：${project_dir}"
    exit 1
  fi

  cd "${project_dir}"
  ${START_COMMAND}
}

install_cron() {
  local script_path cron_cmd tmp_cron
  script_path="$(resolve_script_path)"
  cron_cmd="*/10 * * * * /usr/bin/env bash \"${script_path}\" --run ${CRON_MARKER}"
  tmp_cron="$(mktemp)"

  crontab -l 2>/dev/null | grep -v "WELINE_QIPAISAAS_WATCHDOG" > "${tmp_cron}" || true
  echo "${cron_cmd}" >> "${tmp_cron}"
  crontab "${tmp_cron}"
  rm -f "${tmp_cron}"

  echo "已安装定时任务：每10分钟检测 app/etc/env.php 中 wls.host 地址"
  echo "当前任务："
  crontab -l | grep "WELINE_QIPAISAAS_WATCHDOG" || true
}

main() {
  if ! command -v crontab >/dev/null 2>&1; then
    echo "未检测到 crontab，请先安装 cron/cronie。"
    exit 1
  fi

  if ! command -v curl >/dev/null 2>&1; then
    echo "未检测到 curl，请先安装 curl。"
    exit 1
  fi

  if [ "${1:-}" = "--run" ]; then
    run_watchdog
    exit 0
  fi

  install_cron
}

main "$@"
