<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

/**
 * Close out systemd-run (dispatched_external) jobs after the process exits.
 * Does not re-run release — only PID liveness + log/trailer evidence.
 */
class DeployWebhookExternalJobReconciler
{
    public const STATUS_EXTERNAL = 'dispatched_external';

    public const SOURCE = 'external_reconciler';

    /** Seconds after created_at / log mtime before fail-closed with no evidence. */
    public const GRACE_SECONDS = 120;

    public const RESULT_TRAILER_PREFIX = '# deploy-job-result ';

    public function __construct(
        private readonly DeployWebhookJobQueue $queue,
    ) {
    }

    /**
     * @return array{reconciled:int,ok:int,failed:int,skipped:int,messages:list<string>}
     */
    public function reconcile(int $limit = 20): array
    {
        $reconciled = 0;
        $ok = 0;
        $failed = 0;
        $skipped = 0;
        $messages = [];

        foreach ($this->queue->listByStatus(self::STATUS_EXTERNAL, $limit) as $job) {
            $jobId = (string)($job['id'] ?? '');
            if ($jobId === '') {
                $skipped++;
                $messages[] = 'skip invalid external job';
                continue;
            }

            $pid = (int)($job['pid'] ?? 0);
            if ($pid > 0 && $this->isProcessAlive($pid)) {
                $skipped++;
                $messages[] = $jobId . ': pid_alive ' . $pid;
                continue;
            }

            $logPath = trim((string)($job['log_path'] ?? ''));
            $evidence = $this->readEvidence($logPath);
            if ($evidence['kind'] === 'success') {
                $this->queue->updateJob($jobId, [
                    'status' => 'done',
                    'release_id' => (string)($evidence['release_id'] ?? ''),
                    'deploy_version' => (string)($evidence['deploy_version'] ?? ''),
                    'finished_at' => time(),
                    'reconcile_source' => self::SOURCE,
                ]);
                $reconciled++;
                $ok++;
                $messages[] = $jobId . ': reconciled done ' . (string)($evidence['deploy_version'] ?? '');
                continue;
            }

            if ($evidence['kind'] === 'busy') {
                $this->queue->updateJob($jobId, [
                    'status' => 'pending',
                    'pid' => null,
                    'transport' => 'queue',
                    'message' => (string)($evidence['message'] ?? DeployReleaseFlightGate::BUSY_MESSAGE),
                    'updated_at' => time(),
                    'reconcile_source' => self::SOURCE . '_busy',
                ]);
                $reconciled++;
                $skipped++;
                $messages[] = $jobId . ': requeued busy';
                continue;
            }

            if ($evidence['kind'] === 'failed') {
                $this->queue->updateJob($jobId, [
                    'status' => 'failed',
                    'message' => (string)($evidence['message'] ?? 'release_failed'),
                    'finished_at' => time(),
                    'reconcile_source' => self::SOURCE,
                ]);
                $reconciled++;
                $failed++;
                $messages[] = $jobId . ': reconciled failed ' . (string)($evidence['message'] ?? '');
                continue;
            }

            if (!$this->pastGrace($job, $logPath)) {
                $skipped++;
                $messages[] = $jobId . ': grace_wait';
                continue;
            }

            $this->queue->updateJob($jobId, [
                'status' => 'failed',
                'message' => 'reconcile_no_evidence',
                'finished_at' => time(),
                'reconcile_source' => self::SOURCE,
            ]);
            $reconciled++;
            $failed++;
            $messages[] = $jobId . ': reconcile_no_evidence';
        }

        return compact('reconciled', 'ok', 'failed', 'skipped', 'messages');
    }

    public function isProcessAlive(int $pid): bool
    {
        if ($pid < 1) {
            return false;
        }
        if (\function_exists('posix_kill')) {
            return @\posix_kill($pid, 0);
        }
        if (is_dir('/proc/' . $pid)) {
            return true;
        }

        return false;
    }

    /**
     * @return array{kind:string,deploy_version?:string,release_id?:string,message?:string}
     */
    public function readEvidence(string $logPath): array
    {
        if ($logPath === '' || !is_file($logPath)) {
            return ['kind' => 'none'];
        }
        $raw = @file_get_contents($logPath);
        if (!is_string($raw) || $raw === '') {
            return ['kind' => 'none'];
        }

        $trailer = $this->parseTrailer($raw);
        if ($trailer !== null) {
            return $trailer;
        }

        // Legacy logs (pre-1.1.16): Chinese success/fail lines from deploy:release.
        if (preg_match('/版本戳已写入[:：]\s*(\S+)/u', $raw, $m)
            || preg_match('/发布成功[:：]\s*(\S+)/u', $raw, $m)
        ) {
            return [
                'kind' => 'success',
                'deploy_version' => rtrim((string)$m[1], "。.;,，"),
                'release_id' => '',
            ];
        }
        if (preg_match('/发布失败[:：]\s*(.+)$/mu', $raw, $m)) {
            return [
                'kind' => 'failed',
                'message' => trim((string)$m[1]),
            ];
        }

        return ['kind' => 'none'];
    }

    /**
     * @return array{kind:string,deploy_version?:string,release_id?:string,message?:string}|null
     */
    private function parseTrailer(string $raw): ?array
    {
        $pos = strrpos($raw, self::RESULT_TRAILER_PREFIX);
        if ($pos === false) {
            return null;
        }
        $line = substr($raw, $pos + strlen(self::RESULT_TRAILER_PREFIX));
        $nl = strpos($line, "\n");
        if ($nl !== false) {
            $line = substr($line, 0, $nl);
        }
        $data = json_decode(trim($line), true);
        if (!is_array($data)) {
            return null;
        }
        if (!empty($data['success'])) {
            return [
                'kind' => 'success',
                'deploy_version' => (string)($data['deploy_version'] ?? ''),
                'release_id' => (string)($data['release_id'] ?? ''),
            ];
        }
        $message = (string)($data['message'] ?? 'release_failed');
        if (!empty($data['busy']) || $message === DeployReleaseFlightGate::BUSY_MESSAGE) {
            return [
                'kind' => 'busy',
                'message' => $message !== '' ? $message : DeployReleaseFlightGate::BUSY_MESSAGE,
                'deploy_version' => (string)($data['deploy_version'] ?? ''),
                'release_id' => (string)($data['release_id'] ?? ''),
            ];
        }

        return [
            'kind' => 'failed',
            'message' => $message,
            'deploy_version' => (string)($data['deploy_version'] ?? ''),
            'release_id' => (string)($data['release_id'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $job
     */
    private function pastGrace(array $job, string $logPath): bool
    {
        $anchor = (int)($job['created_at'] ?? 0);
        if ($logPath !== '' && is_file($logPath)) {
            $mtime = (int)@filemtime($logPath);
            if ($mtime > $anchor) {
                $anchor = $mtime;
            }
        }
        if ($anchor < 1) {
            return true;
        }

        return (time() - $anchor) >= self::GRACE_SECONDS;
    }
}
