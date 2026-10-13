<?php

declare(strict_types=1);

namespace LearningMcp;

use Throwable;

/**
 * Schedule incremental index refresh without forking the long-lived STDIO MCP process.
 * Prefer unix-socket send to an existing sidecar; otherwise proc_open a detached PHP worker.
 *
 * Detached workers carry a wall-clock deadline and reduced CPU nice so unbounded
 * index_project cannot starve sibling MCP servers (aoci) on the same host.
 */
final class IndexRefreshScheduler
{
    /**
     * @param list<string> $paths
     * @return array{
     *   deferred:bool,
     *   mode:string,
     *   pid:?int,
     *   reason:string,
     *   sidecar:array<string,mixed>,
     *   worker?:array<string,mixed>
     * }
     */
    public static function scheduleFromStdio(Config $config, string $repository, array $paths = []): array
    {
        $sidecar = IndexSidecar::status($config);
        $repository = realpath(trim($repository)) ?: trim($repository);
        if ($repository === '' || !is_dir($repository)) {
            return [
                'deferred' => false,
                'mode' => 'failed',
                'pid' => null,
                'reason' => 'repository_missing',
                'sidecar' => $sidecar,
                'worker' => self::workerStatus($config),
            ];
        }
        if (!RepositoryScope::isAllowed($config, $repository)) {
            return [
                'deferred' => false,
                'mode' => 'skipped_out_of_scope',
                'pid' => null,
                'reason' => 'PROJECT_SCOPE_VIOLATION',
                'sidecar' => $sidecar,
                'worker' => self::workerStatus($config),
            ];
        }

        if ((bool) ($config->get('index.sidecar_enabled', true))
            && in_array('unix', stream_get_transports(), true)
            && IndexSidecar::trySend($config, $repository, $paths)) {
            return [
                'deferred' => true,
                'mode' => 'sidecar_send',
                'pid' => 0,
                'reason' => 'queued_on_existing_sidecar',
                'sidecar' => IndexSidecar::status($config),
                'worker' => self::workerStatus($config),
            ];
        }

        // Single-flight: many prepare_project turns must not pile detached indexers.
        // Unbounded workers starve the machine and Cursor cannot lease sibling MCP (aoci).
        $existingPid = self::liveDetachedWorkerPid($config, $repository);
        if ($existingPid > 0) {
            if (self::reclaimOverBudgetWorker($config, $existingPid)) {
                self::appendLog($config, 'reclaimed over-budget index worker pid=' . $existingPid);
            } else {
                self::appendLog($config, 'coalesce index refresh; worker already running pid=' . $existingPid);

                return [
                    'deferred' => true,
                    'mode' => 'coalesced',
                    'pid' => $existingPid,
                    'reason' => 'index_refresh_already_running',
                    'sidecar' => $sidecar,
                    'worker' => self::workerStatus($config),
                ];
            }
        }

        $spawned = self::spawnDetachedWorker($config, $repository, $paths);
        if ($spawned['ok']) {
            if (($spawned['reason'] ?? '') === 'index_refresh_already_running') {
                return [
                    'deferred' => true,
                    'mode' => 'coalesced',
                    'pid' => $spawned['pid'],
                    'reason' => 'index_refresh_already_running',
                    'sidecar' => $sidecar,
                    'worker' => self::workerStatus($config),
                ];
            }

            return [
                'deferred' => true,
                'mode' => 'proc_open_reexec',
                'pid' => $spawned['pid'],
                'reason' => 'detached_learningctl_index_project',
                'sidecar' => $sidecar,
                'worker' => self::workerStatus($config),
            ];
        }

        return [
            'deferred' => false,
            'mode' => 'failed',
            'pid' => null,
            'reason' => (string) ($spawned['reason'] ?? 'spawn_failed'),
            'sidecar' => $sidecar,
            'worker' => self::workerStatus($config),
        ];
    }

    /** @return array<string,mixed> */
    public static function workerStatus(Config $config): array
    {
        $meta = self::readWorkerMeta($config);
        $pid = (int) ($meta['pid'] ?? 0);
        if ($pid < 2) {
            $pidPath = self::workerPidPath($config);
            if (is_file($pidPath)) {
                $pid = (int) trim((string) @file_get_contents($pidPath));
            }
        }
        $deadline = (int) ($meta['deadline_epoch'] ?? 0);
        $started = (int) ($meta['started_at'] ?? 0);
        $nice = (int) ($meta['nice'] ?? $config->get('index.refresh_worker_nice', 19));
        $maxSeconds = max(60, (int) $config->get('index.refresh_worker_max_seconds', 120));
        $alive = $pid > 1 && self::processAlive($pid);
        if ($alive && $started < 1) {
            $started = self::processStartEpoch($pid) ?? 0;
        }
        if ($alive && $deadline < 1 && $started > 0) {
            // Legacy workers spawned before meta/deadline existed.
            $deadline = $started + $maxSeconds;
        }
        $now = time();
        $elapsed = $alive && $started > 0 ? max(0, $now - $started) : 0;
        $overBudget = $alive && $deadline > 0 && $now >= $deadline;

        return [
            'pid' => $alive ? $pid : null,
            'alive' => $alive,
            'started_at' => $started > 0 ? $started : null,
            'deadline_epoch' => $deadline > 0 ? $deadline : null,
            'elapsed_seconds' => $elapsed,
            'over_budget' => $overBudget,
            'nice' => $nice,
            'repository' => isset($meta['repository']) ? (string) $meta['repository'] : null,
            'max_seconds' => $maxSeconds,
        ];
    }

    public static function activeDeadlineEpoch(Config $config): ?int
    {
        $meta = self::readWorkerMeta($config);
        $deadline = (int) ($meta['deadline_epoch'] ?? 0);
        $pid = (int) ($meta['pid'] ?? 0);
        if ($deadline < 1 || $pid < 2 || !self::processAlive($pid)) {
            return null;
        }

        return $deadline;
    }

    public static function defaultDeadlineEpoch(Config $config): int
    {
        $maxSeconds = max(60, (int) $config->get('index.refresh_worker_max_seconds', 120));

        return time() + $maxSeconds;
    }

    /**
     * @param list<string> $paths
     * @return array{ok:bool,pid:?int,reason?:string}
     */
    private static function spawnDetachedWorker(Config $config, string $repository, array $paths): array
    {
        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $ctl = dirname(__DIR__) . '/bin/learningctl';
        if (!is_file($ctl)) {
            return ['ok' => false, 'pid' => null, 'reason' => 'learningctl_missing'];
        }

        $gatePath = self::workerGatePath($config);
        $gate = @fopen($gatePath, 'c+');
        if (!is_resource($gate)) {
            return ['ok' => false, 'pid' => null, 'reason' => 'spawn_gate_open_failed'];
        }
        if (!flock($gate, LOCK_EX | LOCK_NB)) {
            $existing = self::liveDetachedWorkerPid($config, $repository);
            flock($gate, LOCK_UN);
            fclose($gate);

            return [
                'ok' => true,
                'pid' => $existing > 0 ? $existing : null,
                'reason' => 'index_refresh_already_running',
            ];
        }

        try {
            $existing = self::liveDetachedWorkerPid($config, $repository);
            if ($existing > 0) {
                return [
                    'ok' => true,
                    'pid' => $existing,
                    'reason' => 'index_refresh_already_running',
                ];
            }

            $nice = max(0, min(19, (int) $config->get('index.refresh_worker_nice', 19)));
            $deadline = self::defaultDeadlineEpoch($config);
            $startedAt = time();

            $command = [
                $php,
                $ctl,
                'intelligence',
                'index_project',
                '--repository',
                $repository,
            ];
            if (is_string($config->sourcePath) && $config->sourcePath !== '') {
                $command[] = '--config';
                $command[] = $config->sourcePath;
            }
            $command[] = '--data-dir';
            $command[] = $config->dataDir();
            $input = [
                'mode' => 'incremental',
                'deadline_epoch' => $deadline,
            ];
            $paths = Text::uniqueStrings($paths);
            if ($paths !== []) {
                $input['paths'] = $paths;
            }
            $command[] = '--input';
            $command[] = Json::encode($input);

            // Launcher forks then exits so STDIO MCP never waits on the indexer and never forks itself.
            // Pid is written to a file (not a pipe): a forked child inheriting a stdout pipe write-end
            // would otherwise keep stream_get_contents()/proc_close blocked forever.
            $args = array_values(array_slice($command, 1));
            $pidFile = $config->dataDir() . '/index-refresh-spawn-' . bin2hex(random_bytes(6)) . '.pid';
            @unlink($pidFile);
            $stablePidFile = self::workerPidPath($config);
            $logPath = $config->dataDir() . '/auto-index.log';
            $startMsg = 'index-refresh-worker start deadline=' . $deadline . ' nice=' . $nice;
            $launcher = 'if (!function_exists("pcntl_fork") || !function_exists("pcntl_exec")) { fwrite(STDERR, "pcntl_unavailable\n"); exit(2);} '
                . '$pid = pcntl_fork(); if ($pid === -1) { fwrite(STDERR, "fork_failed\n"); exit(1);} '
                . 'if ($pid > 0) { file_put_contents(' . var_export($pidFile, true) . ', (string) $pid); '
                . 'file_put_contents(' . var_export($stablePidFile, true) . ', (string) $pid); exit(0);} '
                . 'if (function_exists("posix_setsid")) { @posix_setsid(); } '
                . 'if (function_exists("proc_nice")) { @proc_nice(' . (int) $nice . '); } '
                . '@fclose(STDIN); @fclose(STDOUT); @fclose(STDERR); '
                . '$log = fopen(' . var_export($logPath, true) . ', "a"); '
                . 'if (is_resource($log)) { fwrite($log, ' . var_export($startMsg . "\n", true) . '); } '
                . 'pcntl_exec(' . var_export($php, true) . ', ' . var_export($args, true) . '); '
                . 'if (is_resource($log)) { fwrite($log, "exec_failed\n"); fclose($log); } exit(127);';

            $descriptors = [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', $config->dataDir() . '/auto-index.log', 'a'],
            ];
            $process = @proc_open(
                [$php, '-r', $launcher],
                $descriptors,
                $pipes,
                dirname($ctl),
                null,
                ['bypass_shell' => true],
            ); // nosemgrep: php.lang.security.exec-use.exec-use -- fixed local php -r launcher; no shell metacharacters.
            if (!is_resource($process)) {
                @unlink($pidFile);

                return ['ok' => false, 'pid' => null, 'reason' => 'proc_open_failed'];
            }
            $exit = proc_close($process);
            $pid = 0;
            if (is_file($pidFile)) {
                $pid = (int) trim((string) @file_get_contents($pidFile));
                @unlink($pidFile);
            }
            if ($exit !== 0 || $pid < 1) {
                self::appendLog($config, 'spawn launcher exit=' . $exit . ' pid=' . $pid);

                return ['ok' => false, 'pid' => $pid > 0 ? $pid : null, 'reason' => 'spawn_failed'];
            }
            self::writeWorkerMeta($config, [
                'pid' => $pid,
                'started_at' => $startedAt,
                'deadline_epoch' => $deadline,
                'repository' => $repository,
                'nice' => $nice,
            ]);
            self::appendLog(
                $config,
                'spawned detached index_project pid=' . $pid
                . ' repo=' . $repository
                . ' deadline=' . $deadline
                . ' nice=' . $nice,
            );

            return ['ok' => true, 'pid' => $pid, 'reason' => 'spawned'];
        } finally {
            flock($gate, LOCK_UN);
            fclose($gate);
        }
    }

    private static function reclaimOverBudgetWorker(Config $config, int $pid): bool
    {
        $status = self::workerStatus($config);
        if (!($status['over_budget'] ?? false)) {
            return false;
        }
        $target = (int) ($status['pid'] ?? $pid);
        if ($target > 1 && function_exists('posix_kill')) {
            @posix_kill($target, SIGTERM);
        }
        @unlink(self::workerPidPath($config));
        @unlink(self::workerMetaPath($config));

        return true;
    }

    private static function processStartEpoch(int $pid): ?int
    {
        if ($pid < 2 || !is_executable('/bin/ps')) {
            return null;
        }
        $raw = @shell_exec('/bin/ps -p ' . $pid . ' -o etime=');
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $elapsed = trim($raw);
        // [[dd-]hh:]mm:ss
        if (!preg_match('/^(?:(?:(\d+)-)?(\d+):)?(\d+):(\d+)$/', $elapsed, $m)) {
            return null;
        }
        $days = (int) ($m[1] ?? 0);
        $hours = (int) ($m[2] ?? 0);
        $minutes = (int) ($m[3] ?? 0);
        $seconds = (int) ($m[4] ?? 0);
        $total = ($days * 86400) + ($hours * 3600) + ($minutes * 60) + $seconds;

        return time() - $total;
    }

    private static function workerGatePath(Config $config): string
    {
        return $config->dataDir() . '/index-refresh-worker.lock';
    }

    private static function workerPidPath(Config $config): string
    {
        return $config->dataDir() . '/index-refresh-worker.pid';
    }

    private static function workerMetaPath(Config $config): string
    {
        return $config->dataDir() . '/index-refresh-worker.meta.json';
    }

    /** @param array<string,mixed> $meta */
    private static function writeWorkerMeta(Config $config, array $meta): void
    {
        @file_put_contents(self::workerMetaPath($config), Json::encode($meta));
    }

    /** @return array<string,mixed> */
    private static function readWorkerMeta(Config $config): array
    {
        $path = self::workerMetaPath($config);
        if (!is_file($path)) {
            $pidPath = self::workerPidPath($config);
            if (!is_file($pidPath)) {
                return [];
            }
            $pid = (int) trim((string) @file_get_contents($pidPath));

            return $pid > 1 ? ['pid' => $pid] : [];
        }
        try {
            $decoded = Json::object((string) file_get_contents($path), 'index refresh worker meta');

            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    }

    private static function liveDetachedWorkerPid(Config $config, string $repository): int
    {
        $pidPath = self::workerPidPath($config);
        if (is_file($pidPath)) {
            $pid = (int) trim((string) @file_get_contents($pidPath));
            if ($pid > 1 && self::processLooksLikeIndexWorker($pid, $repository, $config->dataDir())) {
                return $pid;
            }
            @unlink($pidPath);
            @unlink(self::workerMetaPath($config));
        }

        // Fallback: scan for leaked workers from before single-flight existed.
        if (!is_executable('/bin/ps')) {
            return 0;
        }
        $listed = @shell_exec('/bin/ps -ax -o pid= -o command=');
        if (!is_string($listed) || $listed === '') {
            return 0;
        }
        $needle = 'learningctl intelligence index_project';
        foreach (preg_split('/\R/', $listed) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, $needle) || !str_contains($line, $repository)) {
                continue;
            }
            if (!preg_match('/^(\d+)\s+/', $line, $matches)) {
                continue;
            }
            $pid = (int) $matches[1];
            if ($pid > 1 && self::processLooksLikeIndexWorker($pid, $repository, $config->dataDir())) {
                @file_put_contents($pidPath, (string) $pid);

                return $pid;
            }
        }

        return 0;
    }

    private static function processLooksLikeIndexWorker(int $pid, string $repository, string $dataDir): bool
    {
        if ($pid < 2 || !self::processAlive($pid)) {
            return false;
        }
        $commandPath = '/bin/ps';
        if (!is_executable($commandPath)) {
            return true;
        }
        $raw = @shell_exec($commandPath . ' -p ' . $pid . ' -o command=');
        if (!is_string($raw) || $raw === '') {
            return false;
        }

        return str_contains($raw, 'learningctl')
            && str_contains($raw, 'index_project')
            && str_contains($raw, $repository)
            && ($dataDir === '' || str_contains($raw, $dataDir));
    }

    private static function processAlive(int $pid): bool
    {
        return $pid > 1 && function_exists('posix_kill') && @posix_kill($pid, 0);
    }

    private static function appendLog(Config $config, string $message): void
    {
        $line = sprintf("%s index-refresh-scheduler: %s\n", Clock::now(), $message);
        @file_put_contents($config->dataDir() . '/auto-index.log', $line, FILE_APPEND | LOCK_EX);
    }
}
