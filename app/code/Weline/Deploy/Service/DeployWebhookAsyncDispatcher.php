<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

/**
 * Detach webhook release from the HTTP request so Gitee/GitHub ACK within platform read timeouts.
 *
 * Durable path (no sudo): enqueue job → Cron/`deploy:webhook:drain` runs outside WLS request cgroup.
 * Optional acceleration: systemd-run / fork when the host allows it.
 */
class DeployWebhookAsyncDispatcher
{
    public function __construct(
        private readonly DeployWebhookJobQueue $jobQueue,
        private readonly DeployReleaseNotifier $releaseNotifier,
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

        // Always enqueue first — Cron drain does not need sudo and is the durable guarantee.
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

        // Optional fast path: systemd-run outside WLS cgroup (sudoers optional).
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

        // No sudo/systemd-run: leave job pending for Cron/`deploy:webhook:drain`.
        // Do not best-effort fork here — WLS may reap it and Cron would double-release.
        @file_put_contents($logPath, "# transport=queue (await cron/cli drain)\n", FILE_APPEND);

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
