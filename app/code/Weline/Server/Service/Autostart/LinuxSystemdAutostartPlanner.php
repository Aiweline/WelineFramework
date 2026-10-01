<?php

declare(strict_types=1);

namespace Weline\Server\Service\Autostart;

/**
 * Plans Linux systemd oneshot + health timer units for a WLS project instance.
 */
final class LinuxSystemdAutostartPlanner
{
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
        $healthScriptBody = $this->buildHealthScript($scriptPath, $projectRoot);

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
  runuser -u "\$RUN_USER" -- /bin/bash -c "cd \"\$PROJECT\"; exec \"\$PHP\" -d memory_limit=\$PHP_MEM bin/w \"\$@\"" _ "\$@" >>"\$LOG" 2>&1
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

    private function buildHealthScript(string $startScriptPath, string $projectRoot): string
    {
        $escapedStart = $this->shellSingleQuote($startScriptPath);
        $escapedRoot = $this->shellSingleQuote($projectRoot);

        return <<<SH
#!/usr/bin/env bash
# Generated by Weline WLS LinuxSystemdAutostartPlanner. Do not hand-edit.
set -euo pipefail
LOCK=/run/weline-wls-health-\$(basename {$escapedStart}).lock
LOG=/var/log/weline-wls-health.log
START={$escapedStart}
PROJECT={$escapedRoot}

mkdir -p /run /var/log
exec 9>"\$LOCK"
flock -n 9 || exit 0

stamp() { date "+%Y-%m-%d %H:%M:%S"; }
log() { printf "[%s] %s\\n" "\$(stamp)" "\$*" >> "\$LOG"; }

# Prefer managed HTTPS / loopback probes; accept common success redirects.
probe() {
  local url="\$1"
  local code
  code="\$(curl -sk -o /dev/null --max-time 8 -A "Mozilla/5.0 (compatible; weline-wls-health/1.0)" -w "%{http_code}" "\$url" 2>/dev/null || echo 000)"
  case "\$code" in
    200|301|302|303|307|308) return 0 ;;
  esac
  return 1
}

if probe "https://127.0.0.1/" || probe "http://127.0.0.1/"; then
  exit 0
fi

# Fallback: if Master lease exists and looks fresh enough, skip thrash.
if [[ -d "\$PROJECT/var/server/runtime" ]]; then
  if find "\$PROJECT/var/server/runtime" -name master_lease.json -mmin -2 2>/dev/null | grep -q .; then
    exit 0
  fi
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
