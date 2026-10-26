<?php

declare(strict_types=1);

namespace Weline\Server\Service\Autostart;

/**
 * Plans Linux systemd oneshot + health timer units for a WLS project instance.
 */
final class LinuxSystemdAutostartPlanner
{
    /**
     * 生成物语义版本：改脚本/单元内容时必须 +1。
     * 指纹（写进 unit 的 marker）只覆盖输入参数，不含生成物正文，
     * 不加版本号会导致「生成器修好了但生产永远 already_installed」。
     */
    public const GENERATOR_VERSION = 3;

    public const MARKER = 'X-Weline-Wls-Autostart';
    public const MARKER_VALUE = '1';
    public const FINGERPRINT_HEADER = 'X-Weline-Wls-Autostart-Fingerprint';

    /**
     * @param array{
     *   project_root:string,
     *   php_binary:string,
     *   run_user:string,
     *   instance:string,
     *   project_hash:string,
     *   worker_count:int,
     *   worker_memory_limit:string,
     *   php_memory_limit:string,
     *   use_dispatcher:bool,
     *   cli_memory_flag:string
     * } $spec
     * @return array{
     *   unit_basename:string,
     *   service_unit:string,
     *   health_service_unit:string,
     *   health_timer_unit:string,
     *   script_path:string,
     *   health_script_path:string,
     *   fingerprint:string,
     *   service_body:string,
     *   health_service_body:string,
     *   health_timer_body:string,
     *   script_body:string,
     *   health_script_body:string
     * }
     */
    public function plan(array $spec): array
    {
        $projectRoot = $this->requireAbsolutePath((string)$spec['project_root'], 'project_root');
        $phpBinary = $this->requireAbsolutePath((string)$spec['php_binary'], 'php_binary');
        $runUser = $this->requireSafeName((string)$spec['run_user'], 'run_user');
        $instance = $this->requireSafeName((string)$spec['instance'], 'instance');
        $hash = \strtolower(\preg_replace('/[^a-f0-9]/i', '', (string)$spec['project_hash']) ?? '');
        if (\strlen($hash) < 8) {
            throw new \InvalidArgumentException('project_hash must contain at least 8 hex chars.');
        }
        $shortHash = \substr($hash, 0, 8);
        $workerCount = \max(1, (int)$spec['worker_count']);
        $workerMemoryLimit = $this->requireMemoryLimit((string)$spec['worker_memory_limit']);
        $phpMemoryLimit = $this->requireMemoryLimit((string)$spec['php_memory_limit']);
        $useDispatcher = (bool)$spec['use_dispatcher'];
        $healthPort = (int)($spec['health_port'] ?? 0);
        if ($healthPort < 1 || $healthPort > 65535) {
            throw new \InvalidArgumentException(
                'health_port must be the resolved WLS listen port (1-65535); refusing to generate a health check for a dead port.'
            );
        }
        $unitBasename = 'weline-wls-p' . $shortHash . '-' . $instance;
        $scriptPath = '/usr/local/sbin/' . $unitBasename . '.sh';
        $healthScriptPath = '/usr/local/sbin/' . $unitBasename . '-health.sh';
        $fingerprint = \hash('sha256', \implode("\n", [
            $projectRoot,
            $phpBinary,
            $runUser,
            $instance,
            (string)$workerCount,
            $workerMemoryLimit,
            $phpMemoryLimit,
            $useDispatcher ? '1' : '0',
            (string)self::GENERATOR_VERSION,
            (string)$healthPort,
            $scriptPath,
            $healthScriptPath,
            self::MARKER_VALUE,
        ]));

        $startArgs = ['server:start', $instance, '-n'];
        if ($useDispatcher) {
            $startArgs[] = '--dispatcher';
        }
        $startArgs[] = '-c';
        $startArgs[] = (string)$workerCount;
        $startArgs[] = '--worker-memory-limit=' . $workerMemoryLimit;

        $scriptBody = $this->buildStartScript(
            $projectRoot,
            $phpBinary,
            $runUser,
            $instance,
            $phpMemoryLimit,
            $startArgs,
        );
        $healthScriptBody = $this->buildHealthScript($scriptPath, $projectRoot, $healthPort, $unitBasename . '.service');

        $serviceBody = <<<UNIT
[Unit]
Description=Weline WLS autostart ({$unitBasename})
After=network-online.target
Wants=network-online.target
{$this->markerHeaders($fingerprint)}

[Service]
Type=oneshot
RemainAfterExit=yes
KillMode=process
TimeoutStartSec=180
ExecStart={$scriptPath} start
ExecStop={$scriptPath} stop

[Install]
WantedBy=multi-user.target
UNIT;

        $healthServiceBody = <<<UNIT
[Unit]
Description=Weline WLS health check ({$unitBasename})
After=network-online.target {$unitBasename}.service
Wants=network-online.target
{$this->markerHeaders($fingerprint)}

[Service]
Type=oneshot
KillMode=process
ExecStart={$healthScriptPath}
UNIT;

        $healthTimerBody = <<<UNIT
[Unit]
Description=Weline WLS health timer ({$unitBasename})
{$this->markerHeaders($fingerprint)}

[Timer]
OnBootSec=45s
OnUnitActiveSec=60s
AccuracySec=10s
Unit={$unitBasename}-health.service

[Install]
WantedBy=timers.target
UNIT;

        return [
            'unit_basename' => $unitBasename,
            'service_unit' => $unitBasename . '.service',
            'health_service_unit' => $unitBasename . '-health.service',
            'health_timer_unit' => $unitBasename . '-health.timer',
            'script_path' => $scriptPath,
            'health_script_path' => $healthScriptPath,
            'fingerprint' => $fingerprint,
            'service_body' => $serviceBody . "\n",
            'health_service_body' => $healthServiceBody . "\n",
            'health_timer_body' => $healthTimerBody . "\n",
            'script_body' => $scriptBody,
            'health_script_body' => $healthScriptBody,
        ];
    }

    public function markerHeaders(string $fingerprint): string
    {
        return self::MARKER . '=' . self::MARKER_VALUE . "\n"
            . self::FINGERPRINT_HEADER . '=' . $fingerprint;
    }

    /**
     * @param array<string,string> $unitTextByName unit file basename => body
     */
    public function isInstallSatisfied(array $unitTextByName, string $expectedFingerprint, bool $serviceEnabled, bool $timerEnabled): bool
    {
        if (!$serviceEnabled || !$timerEnabled) {
            return false;
        }
        foreach ($unitTextByName as $body) {
            if (!\str_contains($body, self::MARKER . '=' . self::MARKER_VALUE)) {
                return false;
            }
            if (!\str_contains($body, self::FINGERPRINT_HEADER . '=' . $expectedFingerprint)) {
                return false;
            }
        }

        return $unitTextByName !== [];
    }

    /**
     * @param array<int,string> $startArgs
     */
    private function buildStartScript(
        string $projectRoot,
        string $phpBinary,
        string $runUser,
        string $instance,
        string $phpMemoryLimit,
        array $startArgs,
    ): string {
        $escapedRoot = $this->shellSingleQuote($projectRoot);
        $escapedPhp = $this->shellSingleQuote($phpBinary);
        $escapedUser = $this->shellSingleQuote($runUser);
        $escapedInstance = $this->shellSingleQuote($instance);
        $escapedMem = $this->shellSingleQuote($phpMemoryLimit);
        $startArgLine = '';
        foreach ($startArgs as $arg) {
            $startArgLine .= ' ' . $this->shellSingleQuote($arg);
        }

        return <<<SH
#!/usr/bin/env bash
# Generated by Weline WLS LinuxSystemdAutostartPlanner. Do not hand-edit.
set -euo pipefail
PROJECT={$escapedRoot}
PHP={$escapedPhp}
RUN_USER={$escapedUser}
INSTANCE={$escapedInstance}
PHP_MEM={$escapedMem}
LOG=/var/log/weline-wls-autostart-\${INSTANCE}.log

mkdir -p /var/log
stamp() { date "+%Y-%m-%d %H:%M:%S"; }
log() { printf "[%s] %s\\n" "\$(stamp)" "\$*" | tee -a "\$LOG" >/dev/null; }

run_w() {
  # 参数按位置传给内层 bash：$0=项目根，$@=php 及 bin/w 参数。
  # 不要把参数拼进 -c 的双引号字符串（-n/--dispatcher 会打坏引号，systemd 启动直接 exit 2）。
  runuser -u "\$RUN_USER" -- /bin/bash -c 'cd "\$0" || exit 1; exec "\$@"' "\$PROJECT" "\$PHP" -d "memory_limit=\$PHP_MEM" bin/w "\$@" >>"\$LOG" 2>&1
}

case "\${1:-}" in
  start)
    log "autostart begin instance=\$INSTANCE"
    run_w server:shared:start -n || true
    run_w{$startArgLine}
    log "autostart finished instance=\$INSTANCE"
    ;;
  stop)
    log "autostop begin instance=\$INSTANCE"
    run_w server:stop "\$INSTANCE" -n || true
    log "autostop finished instance=\$INSTANCE"
    ;;
  *)
    echo "usage: \$0 start|stop" >&2
    exit 64
    ;;
esac
SH;
    }

    private function buildHealthScript(string $startScriptPath, string $projectRoot, int $healthPort, string $serviceUnit): string
    {
        $escapedStart = $this->shellSingleQuote($startScriptPath);
        $escapedRoot = $this->shellSingleQuote($projectRoot);
        $escapedServiceUnit = $this->shellSingleQuote($serviceUnit);

        return <<<SH
#!/usr/bin/env bash
# Generated by Weline WLS LinuxSystemdAutostartPlanner. Do not hand-edit.
# Must probe WLS (worker/markers). Never treat bare nginx HTTP→HTTPS 308 as healthy.
set -euo pipefail
LOCK=/run/weline-wls-health-\$(basename {$escapedStart}).lock
LOG=/var/log/weline-wls-health.log
START={$escapedStart}
PROJECT={$escapedRoot}
SERVICE_UNIT={$escapedServiceUnit}
COOLDOWN=/run/weline-wls-health-\$(basename {$escapedStart}).cooldown
COOLDOWN_SEC=300
UA="Mozilla/5.0 (compatible; weline-wls-health/1.1)"
HDR="\$(mktemp /tmp/weline-wls-health-hdr.XXXXXX)"
trap 'rm -f "\$HDR"' EXIT
PROBE_PORT={$healthPort}

mkdir -p /run /var/log
exec 9>"\$LOCK"
flock -n 9 || exit 0

stamp() { date "+%Y-%m-%d %H:%M:%S"; }
log() { printf "[%s] %s\\n" "\$(stamp)" "\$*" >> "\$LOG"; }

port_listening() {
  local port="\$1"
  ss -lnt 2>/dev/null | grep -qE ":\${port}[[:space:]]" || return 1
}

has_wls_marker() {
  grep -qiE "^(x-weline-request-id|x-wls-request-id|x-weline-fpc|x-wls-fpc-status|x-weline-cache-status|x-wls-edge-cache):[[:space:]]*.+" "\$HDR"
}

probe_worker() {
  local code
  : > "\$HDR"
  code="\$(curl -sk -D "\$HDR" -o /dev/null --max-time 8 -A "\$UA" \\
    -H "Host: localhost" \\
    -w "%{http_code}" \\
    "http://127.0.0.1:\${PROBE_PORT}/" 2>/dev/null || echo 000)"
  case "\$code" in
    200|301|302|303|307|308|401|403)
      if has_wls_marker || grep -qiE "^(server|content-type):" "\$HDR"; then
        return 0
      fi
      log "worker :\${PROBE_PORT} code=\${code} but no usable headers"
      ;;
    *)
      log "worker :\${PROBE_PORT} code=\${code}"
      ;;
  esac
  return 1
}

healthy() {
  port_listening "\$PROBE_PORT" || { log "missing :\$PROBE_PORT"; return 1; }
  pgrep -f "weline-wls-master-" >/dev/null 2>&1 || { log "master not running"; return 1; }
  probe_worker
}

if healthy; then
  exit 0
fi

now="\$(date +%s)"
if [[ -f "\$COOLDOWN" ]]; then
  last="\$(cat "\$COOLDOWN" 2>/dev/null || echo 0)"
  case "\$last" in ""|*[!0-9]*) last=0 ;; esac
  if [[ \$((now - last)) -lt "\$COOLDOWN_SEC" ]]; then
    log "unhealthy; heal cooldown remaining \$((COOLDOWN_SEC - (now - last)))s"
    exit 0
  fi
fi
printf "%s" "\$now" > "\$COOLDOWN"

# 由 systemd 拉起服务单元：health 服务是 Type=oneshot，直接在它的 cgroup 里
# exec 启动脚本时，单元结束时会把刚拉起的实例一起带走（2026-10-26 实测 origin 303→000）。
if [ "\$(id -u)" = "0" ] && command -v systemctl >/dev/null 2>&1; then
  log "unhealthy; systemctl start \$SERVICE_UNIT"
  exec 9>&-
  exec systemctl start "\$SERVICE_UNIT"
fi

log "unhealthy; invoking \$START start"
exec 9>&-
exec "\$START" start
SH;
    }

    private function requireAbsolutePath(string $path, string $label): string
    {
        $path = \trim($path);
        if ($path === '' || \str_contains($path, "\0") || !\str_starts_with($path, '/')) {
            throw new \InvalidArgumentException($label . ' must be an absolute POSIX path.');
        }
        if (\str_contains($path, '..')) {
            throw new \InvalidArgumentException($label . ' must not contain ..');
        }

        return \rtrim($path, '/');
    }

    private function requireSafeName(string $name, string $label): string
    {
        $name = \trim($name);
        if ($name === '' || !\preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $name)) {
            throw new \InvalidArgumentException($label . ' is not a safe systemd name token.');
        }

        return $name;
    }

    private function requireMemoryLimit(string $limit): string
    {
        $limit = \strtoupper(\trim($limit));
        if ($limit === '-1') {
            return '-1';
        }
        if (!\preg_match('/^[1-9]\d*[KMG]?$/D', $limit)) {
            throw new \InvalidArgumentException('Invalid memory limit: ' . $limit);
        }

        return $limit;
    }

    private function shellSingleQuote(string $value): string
    {
        return "'" . \str_replace("'", "'\\''", $value) . "'";
    }
}
