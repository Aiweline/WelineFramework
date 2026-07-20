<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

/**
 * Start/stop/reload the per-project managed nginx process.
 */
final class ManagedNginxProcessManager
{
    public function __construct(private readonly ManagedNginxPaths $paths = new ManagedNginxPaths())
    {
    }

    /**
     * @return array{ok:bool,running:bool,pid:int|null,message:string}
     */
    public function status(): array
    {
        $pid = $this->readPid();
        if ($pid === null) {
            return ['ok' => true, 'running' => false, 'pid' => null, 'message' => 'not running'];
        }
        if (!$this->pidAlive($pid)) {
            return ['ok' => true, 'running' => false, 'pid' => $pid, 'message' => 'stale pid file'];
        }
        return ['ok' => true, 'running' => true, 'pid' => $pid, 'message' => 'running'];
    }

    /**
     * @return array{ok:bool,message:string,pid:int|null}
     */
    public function start(): array
    {
        if (!$this->paths->isInstalled()) {
            return [
                'ok' => false,
                'message' => 'managed nginx binary missing; run php bin/w server:nginx:install',
                'pid' => null,
            ];
        }
        if (!\is_file($this->paths->confFile())) {
            return ['ok' => false, 'message' => 'managed nginx.conf missing; generate config first', 'pid' => null];
        }
        $status = $this->status();
        if ($status['running']) {
            return ['ok' => true, 'message' => 'already running', 'pid' => $status['pid']];
        }

        $this->paths->ensureRuntimeDirectories();
        $test = $this->runNginx(['-t']);
        if (($test['code'] ?? 1) !== 0) {
            return [
                'ok' => false,
                'message' => 'nginx -t failed: ' . \trim((string)($test['output'] ?? '')),
                'pid' => null,
            ];
        }

        // Clear stale pid so a fresh master writes a new one.
        if (\is_file($this->paths->pidFile()) && !$status['running']) {
            @\unlink($this->paths->pidFile());
        }

        $started = $this->runNginx([]);
        if (($started['code'] ?? 1) !== 0) {
            return [
                'ok' => false,
                'message' => 'nginx start failed: ' . \trim((string)($started['output'] ?? '')),
                'pid' => null,
            ];
        }

        for ($i = 0; $i < 20; $i++) {
            \usleep(100000);
            $pid = $this->readPid();
            if ($pid !== null && $this->pidAlive($pid)) {
                return ['ok' => true, 'message' => 'started', 'pid' => $pid];
            }
        }

        return [
            'ok' => false,
            'message' => 'nginx exited immediately after start; check '
                . $this->paths->logsDir() . DIRECTORY_SEPARATOR . 'error.log',
            'pid' => null,
        ];
    }

    /**
     * @param list<string> $extra
     * @return array{code:int,output:string}
     */
    private function runNginx(array $extra): array
    {
        $cmd = \array_merge($this->baseCommand(), $extra);
        $output = [];
        $code = 0;
        @\exec($this->shellCommand($cmd) . ' 2>&1', $output, $code);
        return ['code' => $code, 'output' => \implode("\n", $output)];
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public function stop(): array
    {
        $status = $this->status();
        if (!$status['running']) {
            @\unlink($this->paths->pidFile());
            return ['ok' => true, 'message' => 'not running'];
        }
        if (!$this->paths->isInstalled()) {
            return $this->killPid((int)$status['pid']);
        }
        $cmd = \array_merge($this->baseCommand(), ['-s', 'stop']);
        $output = [];
        $code = 0;
        @\exec($this->shellCommand($cmd) . ' 2>&1', $output, $code);
        \usleep(200000);
        if ($this->status()['running']) {
            return $this->killPid((int)$status['pid']);
        }
        return ['ok' => true, 'message' => 'stopped'];
    }

    /**
     * @return array{ok:bool,message:string,exit_code:int|null}
     */
    public function reload(): array
    {
        $status = $this->status();
        if (!$status['running']) {
            return ['ok' => false, 'message' => 'managed nginx is not running', 'exit_code' => null];
        }
        if (!$this->paths->isInstalled()) {
            return ['ok' => false, 'message' => 'managed nginx binary missing', 'exit_code' => null];
        }
        $cmd = \array_merge($this->baseCommand(), ['-s', 'reload']);
        $output = [];
        $code = 0;
        @\exec($this->shellCommand($cmd) . ' 2>&1', $output, $code);
        return [
            'ok' => $code === 0,
            'message' => $code === 0 ? 'reloaded' : \trim(\implode("\n", $output)),
            'exit_code' => $code,
        ];
    }

    /**
     * @return list<string>
     */
    private function baseCommand(): array
    {
        $prefix = $this->nginxFsPath($this->paths->runtimeRoot()) . '/';
        $conf = $this->nginxFsPath($this->paths->confFile());
        return [
            $this->paths->binary(),
            '-p',
            $prefix,
            '-c',
            $conf,
        ];
    }

    private function nginxFsPath(string $path): string
    {
        return \str_replace('\\', '/', $path);
    }

    /**
     * @param list<string> $cmd
     */
    private function shellCommand(array $cmd): string
    {
        $parts = [];
        foreach ($cmd as $part) {
            $parts[] = \escapeshellarg($part);
        }
        return \implode(' ', $parts);
    }

    private function readPid(): ?int
    {
        $file = $this->paths->pidFile();
        if (!\is_file($file)) {
            return null;
        }
        $raw = \trim((string)@\file_get_contents($file));
        if ($raw === '' || !\ctype_digit($raw)) {
            return null;
        }
        $pid = (int)$raw;
        return $pid > 0 ? $pid : null;
    }

    private function pidAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            $out = [];
            @\exec('tasklist /FI "PID eq ' . $pid . '" /NH 2>NUL', $out);
            $joined = \strtolower(\implode("\n", $out));
            return \str_contains($joined, (string)$pid) && !\str_contains($joined, 'no tasks');
        }
        if (\function_exists('posix_kill')) {
            return @\posix_kill($pid, 0);
        }
        $out = [];
        @\exec('kill -0 ' . $pid . ' 2>/dev/null', $out, $code);
        return $code === 0;
    }

    /**
     * @return array{ok:bool,message:string}
     */
    private function killPid(int $pid): array
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            @\exec('taskkill /PID ' . $pid . ' /F 2>NUL');
        } elseif (\function_exists('posix_kill')) {
            @\posix_kill($pid, 15);
            \usleep(200000);
            if ($this->pidAlive($pid)) {
                @\posix_kill($pid, 9);
            }
        } else {
            @\exec('kill -TERM ' . $pid . ' 2>/dev/null');
            \usleep(200000);
            if ($this->pidAlive($pid)) {
                @\exec('kill -KILL ' . $pid . ' 2>/dev/null');
            }
        }
        @\unlink($this->paths->pidFile());
        return ['ok' => true, 'message' => 'killed'];
    }
}
