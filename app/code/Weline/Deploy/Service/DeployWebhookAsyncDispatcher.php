<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

/**
 * Detach webhook release from the HTTP request so Gitee/GitHub ACK within platform read timeouts.
 *
 * Primary path: webhook hit → systemd-run `deploy:release` outside the WLS request cgroup.
 * Durable record: the job row itself; if no systemd-run exists it stays pending for the manual
 * `deploy:webhook:drain` CLI. No cron polls the queue — the one-shot maintenance below runs on
 * the webhook hit, so a stuck queue never needs a poller to be closed out.
 */
class DeployWebhookAsyncDispatcher
{
    /** Bounded reconcile limit per webhook hit (same bound the drain used). */
    public const MAINTENANCE_RECONCILE_LIMIT = 20;

    public function __construct(
        private readonly DeployWebhookJobQueue $jobQueue,
        private readonly DeployReleaseNotifier $releaseNotifier,
        private readonly DeployReleaseFlightGate $flightGate,
        private readonly DeployWebhookExternalJobReconciler $externalReconciler,
    ) {
    }

    /**
     * @return array{ok:bool,pid:int|null,log_path:string,command:string,error:string,job_id:string,transport:string}
     */
    public function dispatchRelease(string $ref, bool $force = false, bool $noBackup = false): array
    {
        $ref = trim($ref);
        if ($ref === '') {
            return [
                'ok' => false,
                'pid' => null,
                'log_path' => '',
                'command' => '',
                'error' => 'empty_ref',
                'job_id' => '',
                'transport' => '',
            ];
        }

        $deployRoot = rtrim(BP, "/\\");
        $phpBin = $this->resolvePhpBinary($deployRoot);
        $binW = $deployRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'w';
        if (!is_file($binW)) {
            return [
                'ok' => false,
                'pid' => null,
                'log_path' => '',
                'command' => '',
                'error' => 'bin_w_missing',
                'job_id' => '',
                'transport' => '',
            ];
        }

        $logDir = $deployRoot . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'deploy';
        if (!is_dir($logDir) && !@mkdir($logDir, 0755, true) && !is_dir($logDir)) {
            return [
                'ok' => false,
                'pid' => null,
                'log_path' => '',
                'command' => '',
                'error' => 'log_dir_unwritable',
                'job_id' => '',
                'transport' => '',
            ];
        }

        $stamp = date('Ymd-His') . '-' . substr(sha1($ref . microtime(true)), 0, 8);
        $logPath = $logDir . DIRECTORY_SEPARATOR . 'webhook-async-' . $stamp . '.log';
        $pidFile = $logPath . '.pid';

        $argv = [$phpBin, $binW, 'deploy:release', '-t', 'webhook', '-r', $ref];
        if ($force) {
            $argv[] = '--force';
        }
        if ($noBackup) {
            $argv[] = '--no-backup';
        }

        $header = '# webhook-async spawn ' . date('c') . "\n# php=" . $phpBin . "\n";
        @file_put_contents($logPath, $header);

        // Cheap one-shot maintenance on the webhook hit itself (there is no cron poller any more):
        // close out exited dispatched_external jobs (bounded) and abandon TTL-stale rows.
        // Fail-tolerant — maintenance trouble must never block this release.
        $stale = [];
        try {
            $this->reconcileExitedExternalJobs($logPath);
            $stale = $this->jobQueue->abandonStaleIncomplete();
        } catch (\Throwable $e) {
            @file_put_contents($logPath, '# maintenance_failed: ' . $e->getMessage() . "\n", FILE_APPEND);
        }

        // Newest webhook wins: abandon incomplete predecessors before enqueue.
        $superseded = $this->jobQueue->abandonIncomplete('superseded_by_newer_deploy');
        foreach (array_merge($superseded, $stale) as $row) {
            $abandonedId = (string)($row['job_id'] ?? '');
            if ($abandonedId === '') {
                continue;
            }
            $prev = (string)($row['previous_status'] ?? '');
            @file_put_contents(
                $logPath,
                '# abandoned_prior job_id=' . $abandonedId . ' previous_status=' . $prev . "\n",
                FILE_APPEND
            );
            $this->releaseNotifier->notifyPhase(DeployReleaseNotifier::PHASE_ABANDONED, [
                'job_id' => $abandonedId,
                'ref' => (string)($row['ref'] ?? ''),
                'trigger' => 'webhook',
                'log_path' => (string)($row['log_path'] ?? ''),
                'reason' => $prev !== '' ? ('superseded_or_stale:' . $prev) : 'superseded_or_stale',
            ]);
        }
        // Brief yield so killed release processes can drop the flock.
        if ($superseded !== [] || $stale !== []) {
            \usleep(300000);
        }

        // Always enqueue first — the job row is the durable record the reconciler/CLI drain reads.
        $queued = $this->jobQueue->enqueue($ref, $force, $noBackup, $logPath);
        if (empty($queued['ok'])) {
            return [
                'ok' => false,
                'pid' => null,
                'log_path' => $logPath,
                'command' => '',
                'error' => (string)($queued['error'] ?? 'queue_failed'),
                'job_id' => '',
                'transport' => '',
            ];
        }
        $jobId = (string)$queued['job_id'];
        // job_id known only after enqueue — append before any detach spawn.
        $argv[] = '--job-id=' . $jobId;
        @file_put_contents(
            $logPath,
            '# job_id=' . $jobId . "\n# argv=" . implode(' ', $argv) . "\n",
            FILE_APPEND
        );
        $this->releaseNotifier->notifyPhase(DeployReleaseNotifier::PHASE_QUEUED, [
            'job_id' => $jobId,
            'ref' => $ref,
            'trigger' => 'webhook',
            'log_path' => $logPath,
        ]);

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return [
                'ok' => true,
                'pid' => null,
                'log_path' => $logPath,
                'command' => 'queue',
                'error' => '',
                'job_id' => $jobId,
                'transport' => 'queue',
            ];
        }

        // Narrow race only: another release still holds the lock after supersede/kill.
        // Do not defer merely because older queue rows exist — those were abandoned above.
        if ($this->jobQueue->hasInFlight() || $this->flightGate->isLocked($deployRoot)) {
            @file_put_contents($logPath, "# transport=queue (deferred: release_busy)\n", FILE_APPEND);
            $this->releaseNotifier->notifyPhase(DeployReleaseNotifier::PHASE_DEFERRED, [
                'job_id' => $jobId,
                'ref' => $ref,
                'trigger' => 'webhook',
                'log_path' => $logPath,
                'reason' => DeployReleaseFlightGate::BUSY_MESSAGE,
            ]);

            return [
                'ok' => true,
                'pid' => null,
                'log_path' => $logPath,
                'command' => 'queue',
                'error' => '',
                'job_id' => $jobId,
                'transport' => 'queue',
            ];
        }

        // Optional fast path: systemd-run outside WLS cgroup (sudoers optional).
        // Narrow race (two POSTs both pass hasInFlight): child deploy:release takes flock;
        // loser returns busy and is re-queued — never Git/auto-rollback.
        $systemd = $this->systemdRunDetach($deployRoot, $argv, $logPath, $pidFile);
        if (!empty($systemd['ok'])) {
            $this->jobQueue->updateJob($jobId, [
                'status' => 'dispatched_external',
                'pid' => $systemd['pid'],
                'transport' => 'systemd-run',
            ]);

            return [
                'ok' => true,
                'pid' => $systemd['pid'],
                'log_path' => $logPath,
                'command' => (string)($systemd['command'] ?? 'systemd-run'),
                'error' => '',
                'job_id' => $jobId,
                'transport' => 'systemd-run',
            ];
        }

        // No systemd-run: leave the job pending for the manual `deploy:webhook:drain` CLI.
        // Do not best-effort fork here — WLS may reap it and a second runner would double-release.
        @file_put_contents($logPath, "# transport=queue (await deploy:webhook:drain)\n", FILE_APPEND);

        return [
            'ok' => true,
            'pid' => null,
            'log_path' => $logPath,
            'command' => 'queue',
            'error' => '',
            'job_id' => $jobId,
            'transport' => 'queue',
        ];
    }

    /**
     * Bounded reconcile of exited systemd-run jobs, so `hasInFlight()` cannot stay blocked
     * by a dead dispatched_external row while no cron runs.
     */
    private function reconcileExitedExternalJobs(string $logPath): void
    {
        $external = $this->externalReconciler->reconcile(self::MAINTENANCE_RECONCILE_LIMIT);
        $messages = [];
        foreach ($external['messages'] ?? [] as $message) {
            $messages[] = (string)$message;
        }
        if ($messages !== []) {
            @file_put_contents($logPath, '# reconciled: ' . implode(' | ', $messages) . "\n", FILE_APPEND);
        }
    }

    /**
     * @param list<string> $argv
     * @return array{ok:bool,pid:int|null,command?:string}
     */
    private function systemdRunDetach(string $deployRoot, array $argv, string $logPath, string $pidFile): array
    {
        if (!\is_executable('/usr/bin/systemd-run')) {
            return ['ok' => false, 'pid' => null];
        }

        $unit = 'weline-deploy-webhook-' . \substr(\sha1($logPath . \microtime(true)), 0, 12);
        $cmdParts = [];
        foreach ($argv as $arg) {
            $cmdParts[] = \escapeshellarg($arg);
        }
        $run = 'systemd-run --uid=' . \escapeshellarg($this->runtimeUser())
            . ' --gid=' . \escapeshellarg($this->runtimeUser())
            . ' --working-directory=' . \escapeshellarg($deployRoot)
            . ' --unit=' . \escapeshellarg($unit)
            . ' --property=StandardOutput=append:' . \escapeshellarg($logPath)
            . ' --property=StandardError=append:' . \escapeshellarg($logPath)
            . ' ' . \implode(' ', $cmdParts);

        // Workers usually are not root: allow NOPASSWD sudo for systemd-run only.
        $candidates = [$run, 'sudo -n ' . $run];
        $output = [];
        $exitCode = 1;
        $used = '';
        foreach ($candidates as $candidate) {
            $output = [];
            $exitCode = 1;
            @\exec($candidate . ' 2>&1', $output, $exitCode);
            $used = $candidate;
            if ($exitCode === 0) {
                break;
            }
        }
        if ($exitCode !== 0) {
            @\file_put_contents(
                $logPath,
                '# systemd-run failed: ' . \trim(\implode("\n", $output)) . "\n",
                FILE_APPEND
            );

            return ['ok' => false, 'pid' => null, 'command' => $used];
        }

        // Resolve MainPID from the transient unit.
        $pid = null;
        for ($i = 0; $i < 40 && $pid === null; $i++) {
            $pidOut = [];
            @\exec('systemctl show -p MainPID --value ' . \escapeshellarg($unit) . ' 2>/dev/null', $pidOut);
            $raw = \trim((string)($pidOut[0] ?? ''));
            if ($raw !== '' && $raw !== '0' && \preg_match('/^\d+$/', $raw) === 1) {
                $pid = (int)$raw;
                break;
            }
            \usleep(50000);
        }
        if ($pid !== null) {
            @\file_put_contents($pidFile, (string)$pid);
        }

        return [
            'ok' => $pid !== null,
            'pid' => $pid,
            'command' => $used,
        ];
    }

    private function runtimeUser(): string
    {
        if (\function_exists('posix_getpwuid') && \function_exists('posix_geteuid')) {
            $info = @\posix_getpwuid(@\posix_geteuid());
            if (\is_array($info) && !empty($info['name'])) {
                return (string)$info['name'];
            }
        }

        return 'weline';
    }

    private function resolvePhpBinary(string $deployRoot): string
    {
        $candidates = [
            $deployRoot . DIRECTORY_SEPARATOR . 'extend' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'php',
            PHP_BINARY !== '' ? PHP_BINARY : '',
            'php',
        ];
        foreach ($candidates as $bin) {
            if ($bin !== '' && \is_file($bin) && \is_executable($bin)) {
                return $bin;
            }
        }

        return 'php';
    }
}
