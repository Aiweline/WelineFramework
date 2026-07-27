<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

use Weline\Framework\System\Process\Processer;
use Weline\Framework\Runtime\SchedulerSystem;

/**
 * Start/stop/reload the per-project managed nginx process.
 */
final class ManagedNginxProcessManager
{
    private const GRACEFUL_STOP_TIMEOUT_SECONDS = 30.0;
    private const RELOAD_OLD_WORKER_TIMEOUT_SECONDS = 15.0;

    public function __construct(private readonly ManagedNginxPaths $paths = new ManagedNginxPaths())
    {
    }

    /**
     * @return array{ok:bool,running:bool,pid:int|null,message:string}
     */
    public function status(): array
    {
        try {
            $pid = $this->readPid();
        } catch (\Throwable $throwable) {
            return [
                'ok' => false,
                'running' => false,
                'pid' => null,
                'message' => 'pid file is unreadable or malformed: ' . $throwable->getMessage(),
            ];
        }
        if ($pid === null) {
            return ['ok' => true, 'running' => false, 'pid' => null, 'message' => 'not running'];
        }
        $pidState = $this->pidState($pid);
        if ($pidState === Processer::PROCESS_STATE_EXITED) {
            return ['ok' => true, 'running' => false, 'pid' => $pid, 'message' => 'stale pid file'];
        }
        if ($pidState !== Processer::PROCESS_STATE_RUNNING) {
            return ['ok' => false, 'running' => false, 'pid' => $pid, 'message' => 'pid state is unknown'];
        }
        if (!$this->pidIdentityMatches($pid)) {
            return ['ok' => false, 'running' => false, 'pid' => $pid, 'message' => 'pid identity mismatch'];
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
        if (!($status['ok'] ?? false)) {
            return [
                'ok' => false,
                'message' => 'managed nginx PID identity is unsafe: ' . (string)$status['message'],
                'pid' => $status['pid'],
            ];
        }
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

        $started = $this->startNginx();
        if (($started['code'] ?? 1) !== 0) {
            return [
                'ok' => false,
                'message' => 'nginx start failed: ' . \trim((string)($started['output'] ?? '')),
                'pid' => null,
            ];
        }

        for ($i = 0; $i < 20; $i++) {
            SchedulerSystem::usleep(100000);
            $startedStatus = $this->status();
            if (!($startedStatus['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'message' => 'started nginx PID identity could not be verified',
                    'pid' => $startedStatus['pid'],
                ];
            }
            if ($startedStatus['running']) {
                return ['ok' => true, 'message' => 'started', 'pid' => $startedStatus['pid']];
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
     * @return array<string,mixed>
     */
    public function probeBinaryCapabilities(): array
    {
        if (!$this->paths->isInstalled()) {
            return [
                'ok' => false,
                'version' => '',
                'http2_module' => false,
                'http3_module' => false,
                'http3_configure_flag' => false,
                'http3_reason' => 'managed nginx binary missing',
                'quic_tls_recommended' => false,
                'shared_session_ticket_keys' => false,
                'http_ssl_module' => false,
                'rewrite_module' => false,
                'gzip_module' => false,
                'openssl_version' => '',
                'tls13_capable' => false,
                'message' => 'managed nginx binary missing',
                'output' => '',
            ];
        }
        $output = [];
        $code = 0;
        @\exec($this->shellCommand([$this->paths->binary(), '-V']) . ' 2>&1', $output, $code);
        $text = \implode("\n", $output);
        $version = '';
        if (\preg_match('/nginx\/(\d+\.\d+\.\d+)/i', $text, $match) === 1) {
            $version = (string)$match[1];
        }
        $http2Module = \str_contains($text, '--with-http_v2_module');
        $http3ConfigureFlag = \str_contains($text, '--with-http_v3_module');
        $http3Module = $http3ConfigureFlag && \PHP_OS_FAMILY !== 'Windows';
        $http3Reason = \PHP_OS_FAMILY === 'Windows'
            ? 'ngx_http_v3_module is not supported on Win32; using HTTP/2 with HTTP/1.1 fallback.'
            : ($http3Module
                ? 'ngx_http_v3_module is compiled in.' : 'nginx was built without --with-http_v3_module.');
        $httpSslModule = \str_contains($text, '--with-http_ssl_module');
        $rewriteModule = !\str_contains($text, '--without-http_rewrite_module');
        $gzipModule = !\str_contains($text, '--without-http_gzip_module');
        $opensslVersion = '';
        $opensslComparableVersion = '';
        if (\preg_match_all(
            '/(?:built|running) with OpenSSL\s+([0-9]+\.[0-9]+\.[0-9]+[^\s]*)/i',
            $text,
            $matches,
        ) > 0) {
            $detectedVersions = $matches[1];
            $opensslVersion = (string)\end($detectedVersions);
            if (\preg_match('/\A([0-9]+\.[0-9]+\.[0-9]+)/', $opensslVersion, $versionMatch) === 1) {
                $opensslComparableVersion = (string)$versionMatch[1];
            }
        }
        $tls13Capable = $httpSslModule
            && $opensslComparableVersion !== ''
            && \version_compare($opensslComparableVersion, '1.1.1', '>=');
        $ok = $code === 0 && $version !== '' && $rewriteModule;
        $quicTlsRecommended = $http3Module
            && $opensslComparableVersion !== ''
            && \version_compare($opensslComparableVersion, '3.5.1', '>=');
        $sharedSessionTicketKeys = $httpSslModule && $version !== ''
            && \version_compare($version, '1.23.2', '>=');
        return [
            'ok' => $ok,
            'version' => $version,
            'http2_module' => $http2Module,
            'http_ssl_module' => $httpSslModule,
            'rewrite_module' => $rewriteModule,
            'gzip_module' => $gzipModule,
            'http3_module' => $http3Module,
            'http3_configure_flag' => $http3ConfigureFlag,
            'http3_reason' => $http3Reason,
            'quic_tls_recommended' => $quicTlsRecommended,
            'shared_session_ticket_keys' => $sharedSessionTicketKeys,
            'openssl_version' => $opensslVersion,
            'tls13_capable' => $tls13Capable,
            'message' => $ok
                ? 'nginx binary capabilities detected'
                : ($code === 0 && $version !== '' && !$rewriteModule
                    ? 'managed nginx requires the HTTP rewrite module'
                    : 'unable to inspect managed nginx binary'),
            'output' => $text,
        ];
    }

    /** @return array{code:int,output:string} */
    public function testConfig(string $configFile): array
    {
        if (!\is_file($configFile) || \dirname($configFile) !== $this->paths->confDir()) {
            return ['code' => 1, 'output' => 'managed nginx candidate config is outside the isolated conf directory'];
        }

        $config = @\file_get_contents($configFile);
        if (!\is_string($config)) {
            return ['code' => 1, 'output' => 'managed nginx candidate config is unreadable'];
        }
        try {
            $token = \bin2hex(\random_bytes(8));
        } catch (\Throwable) {
            $token = \str_replace('.', '', \uniqid('', true));
        }
        $testPidName = 'nginx-config-test-' . $token . '.pid';
        $testConfig = $configFile . '.test.' . $token;
        $testPidFile = $this->paths->runDir() . DIRECTORY_SEPARATOR . $testPidName;
        $pidDirectivePattern = '/^\s*pid\s+[^;\r\n]+;\s*$/m';
        if (\preg_match_all($pidDirectivePattern, $config) !== 1) {
            return ['code' => 1, 'output' => 'managed nginx candidate must contain exactly one isolated pid directive'];
        }
        $replacementCount = 0;
        $isolatedConfig = \preg_replace(
            $pidDirectivePattern,
            'pid        run/' . $testPidName . ';',
            $config,
            1,
            $replacementCount,
        );
        if (!\is_string($isolatedConfig) || $replacementCount !== 1) {
            return ['code' => 1, 'output' => 'managed nginx candidate must contain exactly one isolated pid directive'];
        }
        if (@\file_put_contents($testConfig, $isolatedConfig, LOCK_EX) !== \strlen($isolatedConfig)) {
            @\unlink($testConfig);
            return ['code' => 1, 'output' => 'unable to write isolated managed nginx test config'];
        }
        try {
            return $this->runNginx(['-t'], $testConfig);
        } finally {
            @\unlink($testPidFile);
            @\unlink($testConfig);
        }
    }

    /**
     * @param list<string> $extra
     * @return array{code:int,output:string}
     */
    private function runNginx(array $extra, ?string $configFile = null): array
    {
        $cmd = \array_merge($this->baseCommand($configFile), $extra);
        $output = [];
        $code = 0;
        @\exec($this->shellCommand($cmd) . ' 2>&1', $output, $code);
        return ['code' => $code, 'output' => \implode("\n", $output)];
    }

    /** @return array{code:int,output:string} */
    private function startNginx(): array
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            return $this->runNginx([]);
        }
        // Cross a WMI-created broker before Start-Process so nginx cannot keep
        // the lifecycle command's console or remote-exec handles alive.
        $command = $this->baseCommand();
        $binary = $command[0] ?? null;
        if (!\is_string($binary) || $binary === '') {
            return ['code' => 1, 'output' => 'managed nginx binary is unavailable'];
        }
        try {
            $pid = Processer::createWindowsIsolatedArgv(
                $command,
                $this->paths->runtimeRoot(),
                'managed-nginx-' . \substr(\hash('sha256', \strtolower($binary)), 0, 16),
            );
        } catch (\Throwable $throwable) {
            return ['code' => 1, 'output' => $throwable->getMessage()];
        }

        // PID delivery can be ambiguous after a committed WMI submission. Do
        // not resubmit here: start() converges once against nginx's authoritative
        // pid file and command-line identity before it reports success/failure.
        return [
            'code' => 0,
            'output' => $pid > 0
                ? 'isolated nginx launch submitted, pid=' . $pid
                : 'isolated nginx launch submitted; awaiting authoritative pid file',
        ];
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public function stop(): array
    {
        $status = $this->status();
        if (!($status['ok'] ?? false)) {
            return ['ok' => false, 'message' => 'refusing stop: ' . (string)$status['message']];
        }
        if (!$status['running']) {
            @\unlink($this->paths->pidFile());
            return ['ok' => true, 'message' => 'not running'];
        }
        if (!$this->paths->isInstalled()) {
            return $this->killPid((int)$status['pid']);
        }

        // Freeze the already-verified master identity before asking Nginx to
        // quit. Windows can temporarily hide the exiting process command line,
        // so a transient status mismatch is not permission to signal anything.
        $masterPid = (int)$status['pid'];
        $cmd = \array_merge($this->baseCommand(), ['-s', 'quit']);
        $output = [];
        $code = 0;
        @\exec($this->shellCommand($cmd) . ' 2>&1', $output, $code);
        $deadline = \microtime(true) + self::GRACEFUL_STOP_TIMEOUT_SECONDS;
        do {
            SchedulerSystem::usleep(100_000);
            $masterState = $this->pidState($masterPid);
            try {
                $pidFilePid = $this->readPid();
            } catch (\Throwable) {
                continue;
            }
            if ($pidFilePid !== null && $pidFilePid !== $masterPid) {
                return [
                    'ok' => false,
                    'message' => 'nginx master PID changed during graceful stop; no signal was sent to the new identity',
                ];
            }
            if ($masterState === Processer::PROCESS_STATE_EXITED) {
                return $this->finalizeExitedMasterPidFile($masterPid);
            }
        } while (\microtime(true) < $deadline);

        $masterState = $this->pidState($masterPid);
        if ($masterState === Processer::PROCESS_STATE_EXITED) {
            return $this->finalizeExitedMasterPidFile($masterPid);
        }
        if ($masterState !== Processer::PROCESS_STATE_RUNNING) {
            return [
                'ok' => false,
                'message' => 'nginx master state remained unknown after graceful stop timeout',
            ];
        }
        if (!$this->pidIdentityMatches($masterPid)) {
            return [
                'ok' => false,
                'message' => 'nginx master remained alive with an unverifiable identity after graceful stop timeout',
            ];
        }
        $killed = $this->killPid($masterPid);
        $killed['message'] = ($killed['ok'] ?? false)
            ? 'killed after graceful shutdown timeout'
            : 'graceful shutdown timed out: ' . (string)($killed['message'] ?? 'termination failed');
        return $killed;
    }

    /**
     * @return array{ok:bool,message:string,exit_code:int|null}
     */
    public function reload(): array
    {
        $status = $this->status();
        if (!($status['ok'] ?? false)) {
            return [
                'ok' => false,
                'message' => 'refusing reload: ' . (string)$status['message'],
                'exit_code' => null,
            ];
        }
        if (!$status['running']) {
            return ['ok' => false, 'message' => 'managed nginx is not running', 'exit_code' => null];
        }
        if (!$this->paths->isInstalled()) {
            return ['ok' => false, 'message' => 'managed nginx binary missing', 'exit_code' => null];
        }
        $masterPid = (int)$status['pid'];
        $oldWorkerPids = $this->childWorkerPids($masterPid);
        if (\PHP_OS_FAMILY !== 'Windows'
            && ($oldWorkerPids === null || $oldWorkerPids === [])
        ) {
            return [
                'ok' => false,
                'message' => 'refusing reload: unable to enumerate the current nginx worker generation',
                'exit_code' => null,
            ];
        }
        $test = $this->testConfig($this->paths->confFile());
        if (($test['code'] ?? 1) !== 0) {
            return [
                'ok' => false,
                'message' => 'isolated nginx -t failed; existing workers were left unchanged: '
                    . \trim((string)($test['output'] ?? '')),
                'exit_code' => $test['code'] ?? 1,
            ];
        }
        $cmd = \array_merge($this->baseCommand(), ['-s', 'reload']);
        $output = [];
        $code = 0;
        @\exec($this->shellCommand($cmd) . ' 2>&1', $output, $code);
        $remainingOldWorkerPids = \is_array($oldWorkerPids) ? $oldWorkerPids : [];
        $workerProbeFailed = false;
        if ($code === 0 && $remainingOldWorkerPids !== []) {
            $deadline = \microtime(true) + self::RELOAD_OLD_WORKER_TIMEOUT_SECONDS;
            do {
                SchedulerSystem::usleep(100000);
                $currentWorkerPids = $this->childWorkerPids($masterPid);
                if ($currentWorkerPids === null) {
                    $workerProbeFailed = true;
                    break;
                }
                $remainingOldWorkerPids = \array_values(\array_intersect(
                    $oldWorkerPids,
                    $currentWorkerPids,
                ));
            } while ($remainingOldWorkerPids !== [] && \microtime(true) < $deadline);
        } else {
            SchedulerSystem::usleep(100000);
        }
        $finalStatus = $this->status();
        $running = (bool)($finalStatus['ok'] ?? false) && (bool)($finalStatus['running'] ?? false);
        $workersReplaced = !$workerProbeFailed && $remainingOldWorkerPids === [];
        return [
            'ok' => $code === 0 && $running && $workersReplaced,
            'message' => $code === 0 && $running && $workersReplaced
                ? 'configuration tested with an isolated PID and reloaded'
                : (!($finalStatus['ok'] ?? false)
                    ? 'nginx PID identity changed after reload'
                    : ($code !== 0
                        ? \trim(\implode("\n", $output))
                        : ($workerProbeFailed
                            ? 'unable to prove old nginx worker generation drain'
                            : (!$workersReplaced
                                ? 'old nginx workers did not drain before the reload generation deadline'
                                : 'nginx master exited after reload')))),
            'exit_code' => $code === 0 && $workersReplaced ? 0 : 1,
        ];
    }

    /** @return list<int>|null */
    private function childWorkerPids(int $masterPid): ?array
    {
        if ($masterPid < 1) {
            return null;
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            return [];
        }
        $output = [];
        if (\PHP_OS_FAMILY === 'Linux') {
            $childrenFile = '/proc/' . $masterPid . '/task/' . $masterPid . '/children';
            $children = @\file_get_contents($childrenFile);
            if (\is_string($children)) {
                $workers = [];
                foreach (\preg_split('/\s+/', \trim($children)) ?: [] as $child) {
                    if (!\ctype_digit($child)) {
                        continue;
                    }
                    $command = @\file_get_contents('/proc/' . $child . '/cmdline');
                    $title = \is_string($command) ? \str_replace("\0", ' ', $command) : '';
                    if (\str_contains(\strtolower($title), 'nginx: worker process')) {
                        $workers[(int)$child] = true;
                    }
                }
                $pids = \array_keys($workers);
                \sort($pids, SORT_NUMERIC);
                return $pids;
            }
        }
        $code = 1;
        @\exec('ps -axo pid=,ppid=,command= 2>/dev/null', $output, $code);
        if ($code !== 0) {
            return null;
        }
        $workers = [];
        foreach ($output as $line) {
            if (\preg_match('/\A\s*([1-9][0-9]*)\s+([1-9][0-9]*)\s+(.+)\z/D', $line, $match) !== 1
                || (int)$match[2] !== $masterPid
                || !\str_contains(\strtolower((string)$match[3]), 'nginx: worker process')
            ) {
                continue;
            }
            $workers[(int)$match[1]] = true;
        }
        $pids = \array_keys($workers);
        \sort($pids, SORT_NUMERIC);
        return $pids;
    }

    /**
     * @return list<string>
     */
    private function baseCommand(?string $configFile = null): array
    {
        $prefix = $this->nginxFsPath($this->paths->runtimeRoot()) . '/';
        $conf = $this->nginxFsPath($configFile ?? $this->paths->confFile());
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
        $contents = @\file_get_contents($file);
        if (!\is_string($contents)) {
            throw new \RuntimeException('read failed');
        }
        $raw = \trim($contents);
        if ($raw === '' || !\ctype_digit($raw)) {
            throw new \RuntimeException('expected one positive integer');
        }
        $pid = (int)$raw;
        if ($pid <= 0) {
            throw new \RuntimeException('PID must be positive');
        }
        return $pid;
    }

    private function pidState(int $pid): string
    {
        if ($pid <= 0) {
            return Processer::PROCESS_STATE_EXITED;
        }
        $state = Processer::probeProcessState($pid, true);
        return \in_array($state, [
            Processer::PROCESS_STATE_RUNNING,
            Processer::PROCESS_STATE_EXITED,
            Processer::PROCESS_STATE_UNKNOWN,
        ], true)
            ? $state
            : Processer::PROCESS_STATE_UNKNOWN;
    }

    /** @return array{ok:bool,message:string} */
    private function finalizeExitedMasterPidFile(int $masterPid): array
    {
        try {
            $pidFilePid = $this->readPid();
        } catch (\Throwable $throwable) {
            return [
                'ok' => false,
                'message' => 'nginx exited but its PID file is unreadable: ' . $throwable->getMessage(),
            ];
        }
        if ($pidFilePid === null) {
            return ['ok' => true, 'message' => 'stopped'];
        }
        if ($pidFilePid !== $masterPid) {
            return [
                'ok' => false,
                'message' => 'nginx exited but its PID file now belongs to a different identity',
            ];
        }
        if (!@\unlink($this->paths->pidFile()) && \is_file($this->paths->pidFile())) {
            return [
                'ok' => false,
                'message' => 'nginx exited but its stale PID file could not be removed',
            ];
        }

        return ['ok' => true, 'message' => 'stopped'];
    }

    private function pidIdentityMatches(int $pid): bool
    {
        $command = Processer::getProcessCommandLine($pid, true);
        if ($command === '') {
            return false;
        }
        $tokens = $this->tokenizeCommandLine($command);
        $binary = $this->normalizeIdentityPath($this->paths->binary());
        $prefix = $this->normalizeIdentityPath($this->paths->runtimeRoot());
        $config = $this->normalizeIdentityPath($this->paths->confFile());
        $binaryMatched = false;
        $prefixMatched = false;
        $configMatched = false;
        foreach ($tokens as $index => $token) {
            $normalized = $this->normalizeIdentityPath($token);
            if ($normalized === $binary) {
                $binaryMatched = true;
            }
            if ($token === '-p' && isset($tokens[$index + 1])) {
                $prefixMatched = $this->normalizeIdentityPath($tokens[$index + 1]) === $prefix;
            }
            if ($token === '-c' && isset($tokens[$index + 1])) {
                $configMatched = $this->normalizeIdentityPath($tokens[$index + 1]) === $config;
            }
        }

        return $binaryMatched && $prefixMatched && $configMatched;
    }

    /** @return list<string> */
    private function tokenizeCommandLine(string $command): array
    {
        \preg_match_all('/"([^"]*)"|\'([^\']*)\'|([^\\s]+)/', $command, $matches, PREG_SET_ORDER);
        $tokens = [];
        foreach ($matches as $match) {
            foreach ([1, 2, 3] as $index) {
                if (!isset($match[$index]) || $match[$index] === '') {
                    continue;
                }
                $tokens[] = \trim((string)$match[$index], " \t\n\r\0\x0B\"'");
                break;
            }
        }

        return $tokens;
    }

    private function normalizeIdentityPath(string $path): string
    {
        $normalized = \rtrim(\str_replace('\\', '/', \trim($path)), '/');

        return \PHP_OS_FAMILY === 'Windows' ? \strtolower($normalized) : $normalized;
    }

    /**
     * @return array{ok:bool,message:string}
     */
    private function killPid(int $pid): array
    {
        $state = $this->pidState($pid);
        if ($state === Processer::PROCESS_STATE_EXITED) {
            return $this->finalizeExitedMasterPidFile($pid);
        }
        if ($state !== Processer::PROCESS_STATE_RUNNING) {
            return ['ok' => false, 'message' => 'refusing to kill a PID whose process state is unknown'];
        }
        if (!$this->pidIdentityMatches($pid)) {
            return ['ok' => false, 'message' => 'refusing to kill a PID that does not match managed nginx identity'];
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            @\exec('taskkill /PID ' . $pid . ' /F 2>NUL', $output, $code);
        } elseif (\function_exists('posix_kill')) {
            @\posix_kill($pid, 15);
            SchedulerSystem::usleep(200_000);
            if ($this->pidState($pid) === Processer::PROCESS_STATE_RUNNING
                && $this->pidIdentityMatches($pid)
            ) {
                @\posix_kill($pid, 9);
            }
        } else {
            @\exec('kill -TERM ' . $pid . ' 2>/dev/null');
            SchedulerSystem::usleep(200_000);
            if ($this->pidState($pid) === Processer::PROCESS_STATE_RUNNING
                && $this->pidIdentityMatches($pid)
            ) {
                @\exec('kill -KILL ' . $pid . ' 2>/dev/null');
            }
        }
        for ($i = 0; $i < 20; $i++) {
            $state = $this->pidState($pid);
            if ($state === Processer::PROCESS_STATE_EXITED) {
                return $this->finalizeExitedMasterPidFile($pid);
            }
            SchedulerSystem::usleep(100_000);
        }

        $state = $this->pidState($pid);
        return [
            'ok' => false,
            'message' => $state === Processer::PROCESS_STATE_UNKNOWN
                ? 'managed nginx PID state remained unknown after termination'
                : 'managed nginx PID remained alive after termination',
        ];
    }
}
