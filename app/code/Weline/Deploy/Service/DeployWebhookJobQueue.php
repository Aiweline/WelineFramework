<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

/**
 * Durable webhook release jobs — survive WLS HTTP worker teardown without sudo/systemd-run.
 * Drain via Cron or `php bin/w deploy:webhook:drain` (CLI process tree, not the request cgroup).
 */
class DeployWebhookJobQueue
{
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
            'status' => 'pending',
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
     * @return list<array<string,mixed>>
     */
    public function listPending(int $limit = 5): array
    {
        $dir = $this->queueDir();
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        sort($files);
        $out = [];
        foreach ($files as $file) {
            $job = $this->readJob($file);
            if ($job === null) {
                continue;
            }
            if (($job['status'] ?? '') !== 'pending') {
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
