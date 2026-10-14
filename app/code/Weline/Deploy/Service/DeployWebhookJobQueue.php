<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

/**
 * Durable webhook release jobs — survive WLS HTTP worker teardown without sudo/systemd-run.
 * Drain via Cron or `php bin/w deploy:webhook:drain` (CLI process tree, not the request cgroup).
 *
 * Incomplete jobs (pending / running / dispatched_external) are superseded when a newer webhook
 * arrives, and are abandoned after {@see self::STALE_INCOMPLETE_SECONDS} even without a successor.
 */
class DeployWebhookJobQueue
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DISPATCHED_EXTERNAL = 'dispatched_external';
    public const STATUS_ABANDONED = 'abandoned';

    /** Incomplete jobs older than this (wall clock from created/started) are abandoned. */
    public const STALE_INCOMPLETE_SECONDS = 1800;

    /** @var list<string> */
    public const INCOMPLETE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RUNNING,
        self::STATUS_DISPATCHED_EXTERNAL,
    ];

    public function queueDir(): string
    {
        $dir = rtrim(BP, "/\\") . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'deploy' . DIRECTORY_SEPARATOR . 'webhook-queue';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir;
    }

    /**
     * @return array{ok:bool,job_id:string,path:string,error:string}
     */
    public function enqueue(string $ref, bool $force, bool $noBackup, string $logPath = ''): array
    {
        $ref = trim($ref);
        if ($ref === '') {
            return ['ok' => false, 'job_id' => '', 'path' => '', 'error' => 'empty_ref'];
        }

        $dir = $this->queueDir();
        if (!is_dir($dir) || !is_writable($dir)) {
            return ['ok' => false, 'job_id' => '', 'path' => '', 'error' => 'queue_unwritable'];
        }

        $jobId = date('Ymd-His') . '-' . substr(sha1($ref . microtime(true)), 0, 10);
        $path = $dir . DIRECTORY_SEPARATOR . $jobId . '.json';
        $payload = [
            'id' => $jobId,
            'ref' => $ref,
            'force' => $force,
            'no_backup' => $noBackup,
            'status' => self::STATUS_PENDING,
            'log_path' => $logPath,
            'created_at' => time(),
            'updated_at' => time(),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($path, $json . "\n") === false) {
            return ['ok' => false, 'job_id' => '', 'path' => $path, 'error' => 'write_failed'];
        }

        return ['ok' => true, 'job_id' => $jobId, 'path' => $path, 'error' => ''];
    }

    /**
     * Mark incomplete jobs abandoned. When $onlyOlderThanSeconds is null, every incomplete job
     * is abandoned (supersede-before-new). When set, only jobs whose age anchor exceeds the TTL.
     *
     * @return list<array{job_id:string,ref:string,previous_status:string,pid:int,log_path:string}>
     */
    public function abandonIncomplete(string $reason, ?int $onlyOlderThanSeconds = null, ?string $exceptJobId = null): array
    {
        $reason = trim($reason) !== '' ? trim($reason) : 'abandoned';
        $exceptJobId = $exceptJobId !== null ? trim($exceptJobId) : '';
        $now = time();
        $abandoned = [];
        $dir = $this->queueDir();
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        sort($files);
        foreach ($files as $file) {
            $job = $this->readJob($file);
            if ($job === null) {
                continue;
            }
            $jobId = trim((string)($job['id'] ?? ''));
            if ($jobId === '' || ($exceptJobId !== '' && $jobId === $exceptJobId)) {
                continue;
            }
            $status = (string)($job['status'] ?? '');
            if (!\in_array($status, self::INCOMPLETE_STATUSES, true)) {
                continue;
            }
            if ($onlyOlderThanSeconds !== null) {
                $age = $now - $this->incompleteAgeAnchor($job);
                if ($age < $onlyOlderThanSeconds) {
                    continue;
                }
            }
            $pid = (int)($job['pid'] ?? 0);
            if ($pid > 0 && $this->isProcessAlive($pid)) {
                $this->terminateProcess($pid);
            }
            $ok = $this->updateJob($jobId, [
                'status' => self::STATUS_ABANDONED,
                'message' => $reason,
                'abandoned_reason' => $reason,
                'abandoned_at' => $now,
                'finished_at' => $now,
            ]);
            if (!$ok) {
                continue;
            }
            $abandoned[] = [
                'job_id' => $jobId,
                'ref' => (string)($job['ref'] ?? ''),
                'previous_status' => $status,
                'pid' => $pid,
                'log_path' => (string)($job['log_path'] ?? ''),
            ];
        }

        return $abandoned;
    }

    /**
     * Abandon incomplete jobs that have been open longer than {@see self::STALE_INCOMPLETE_SECONDS}.
     *
     * @return list<array{job_id:string,ref:string,previous_status:string,pid:int,log_path:string}>
     */
    public function abandonStaleIncomplete(?int $staleSeconds = null): array
    {
        $ttl = $staleSeconds !== null && $staleSeconds > 0
            ? $staleSeconds
            : self::STALE_INCOMPLETE_SECONDS;

        return $this->abandonIncomplete('stale_incomplete_' . $ttl . 's', $ttl);
    }

    /**
     * @param array<string,mixed> $job
     */
    private function incompleteAgeAnchor(array $job): int
    {
        $started = (int)($job['started_at'] ?? 0);
        if ($started > 0) {
            return $started;
        }
        $created = (int)($job['created_at'] ?? 0);

        return $created > 0 ? $created : time();
    }

    private function terminateProcess(int $pid): void
    {
        if ($pid <= 0) {
            return;
        }
        if (\function_exists('posix_kill')) {
            @\posix_kill($pid, 15);
            \usleep(200000);
            if ($this->isProcessAlive($pid)) {
                @\posix_kill($pid, 9);
            }

            return;
        }
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return;
        }
        @exec('kill -15 ' . $pid . ' 2>/dev/null');
        \usleep(200000);
        if ($this->isProcessAlive($pid)) {
            @exec('kill -9 ' . $pid . ' 2>/dev/null');
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listPending(int $limit = 5): array
    {
        return $this->listByStatus('pending', $limit);
    }

    /**
     * True when a release job is already claimed (running or live external dispatch).
     * Stale dispatched_external (dead pid + aged) do not block — reconciler will close them.
     */
    public function hasInFlight(int $staleExternalGraceSeconds = 120): bool
    {
        $dir = $this->queueDir();
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        $now = time();
        foreach ($files as $file) {
            $job = $this->readJob($file);
            if ($job === null) {
                continue;
            }
            $status = (string)($job['status'] ?? '');
            if ($status === 'running') {
                return true;
            }
            if ($status !== 'dispatched_external') {
                continue;
            }
            $pid = (int)($job['pid'] ?? 0);
            if ($pid > 0 && $this->isProcessAlive($pid)) {
                return true;
            }
            $updated = (int)($job['updated_at'] ?? $job['created_at'] ?? 0);
            if ($updated > 0 && ($now - $updated) < $staleExternalGraceSeconds) {
                return true;
            }
        }

        return false;
    }

    private function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (\function_exists('posix_kill')) {
            return @\posix_kill($pid, 0);
        }
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return false;
        }
        $out = [];
        @exec('kill -0 ' . $pid . ' 2>/dev/null', $out, $code);

        return $code === 0;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listByStatus(string $status, int $limit = 5): array
    {
        $status = trim($status);
        if ($status === '' || $limit < 1) {
            return [];
        }

        $dir = $this->queueDir();
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        sort($files);
        $out = [];
        foreach ($files as $file) {
            $job = $this->readJob($file);
            if ($job === null) {
                continue;
            }
            if (($job['status'] ?? '') !== $status) {
                continue;
            }
            $out[] = $job;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $patch
     */
    public function updateJob(string $jobId, array $patch): bool
    {
        $path = $this->queueDir() . DIRECTORY_SEPARATOR . $jobId . '.json';
        $job = $this->readJob($path);
        if ($job === null) {
            return false;
        }
        foreach ($patch as $key => $value) {
            $job[$key] = $value;
        }
        $job['updated_at'] = time();
        $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            return false;
        }

        return @file_put_contents($path, $json . "\n") !== false;
    }

    /**
     * Find any job (any status) whose ref matches the given ref after normalization.
     *
     * @return array<string,mixed>|null
     */
    public function findByRef(string $ref): ?array
    {
        return $this->findByRefCreatedSince($ref, null);
    }

    /**
     * Find a job matching ref whose created_at is >= $minCreatedAt (inclusive).
     * When $minCreatedAt is null, any matching job is returned (newest first).
     *
     * @return array<string,mixed>|null
     */
    public function findByRefCreatedSince(string $ref, ?int $minCreatedAt): ?array
    {
        $needle = $this->normalizeRef($ref);
        if ($needle === '') {
            return null;
        }

        $dir = $this->queueDir();
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        // Newest first — prefer recent arrivals for the same tag/branch.
        rsort($files);
        foreach ($files as $file) {
            $job = $this->readJob($file);
            if ($job === null) {
                continue;
            }
            if ($this->normalizeRef((string)($job['ref'] ?? '')) !== $needle) {
                continue;
            }
            if ($minCreatedAt !== null) {
                $created = (int)($job['created_at'] ?? 0);
                if ($created < $minCreatedAt) {
                    continue;
                }
            }

            return $job;
        }

        return null;
    }

    public function normalizeRef(string $ref): string
    {
        $ref = trim($ref);
        if ($ref === '') {
            return '';
        }
        if (str_starts_with($ref, 'refs/tags/')) {
            return $ref;
        }
        if (str_starts_with($ref, 'refs/heads/')) {
            return $ref;
        }
        // Bare tag or commit-ish → treat as tag name for expect-watch matching.
        if (!str_contains($ref, '/')) {
            return 'refs/tags/' . $ref;
        }

        return $ref;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readJob(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }
}
