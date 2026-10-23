<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Kernel;

final class UpgradeSession
{
    private string $operationId;
    private string $runDir;
    private bool $keepMaintenance;
    private string $source;
    private array $stages = [];
    private array $skips = [];
    private array $fatal = [];
    private string $startedAt;
    private bool $began = false;

    public function __construct(string $operationId, string $runRoot, bool $keepMaintenance = false, string $source = 'cli')
    {
        $this->operationId = $operationId;
        $this->runDir = rtrim($runRoot, '/\\') . '/' . $operationId;
        $this->keepMaintenance = $keepMaintenance;
        $this->source = $source;
        $this->startedAt = date('c');
    }

    public static function load(string $runDir, bool $keepMaintenance = false): ?self
    {
        $runDir = rtrim($runDir, '/\\');
        $progress = $runDir . DIRECTORY_SEPARATOR . 'progress.json';
        if (!is_file($progress)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($progress), true);
        if (!is_array($data)) {
            return null;
        }
        $operationId = (string)($data['operation_id'] ?? basename($runDir));
        $source = (string)($data['source'] ?? 'cli');
        $session = new self($operationId, dirname($runDir), $keepMaintenance, $source);
        $session->stages = is_array($data['stages'] ?? null) ? $data['stages'] : [];
        $session->skips = is_array($data['skips'] ?? null) ? $data['skips'] : [];
        $session->fatal = is_array($data['fatal'] ?? null) ? array_values($data['fatal']) : [];
        $session->startedAt = (string)($data['started_at'] ?? date('c'));
        $session->began = true;
        return $session;
    }

    public function completedStages(): array
    {
        $keys = [];
        foreach ($this->stages as $stage) {
            if (is_array($stage) && ($stage['status'] ?? '') === 'ok' && isset($stage['key'])) {
                $keys[] = (string)$stage['key'];
            }
        }
        return array_values(array_unique($keys));
    }

    public static function isIdempotentStage(string $key): bool
    {
        return in_array($key, ['classmap', 'psr4', 'extends', 'framework', 'reflection', 'registry', 'file'], true);
    }

    public function begin(): void
    {
        if ($this->began) {
            return;
        }
        $this->began = true;
        $this->createRunDir();
        $this->writeProgress();
    }

    public function recordStage(string $key, string $status, float $durationMs, array $meta = []): void
    {
        $this->stages[] = ['key' => $key, 'status' => $status, 'duration_ms' => $durationMs, 'meta' => $meta];
        $this->writeProgress();
    }

    public function recordSkip(string $key, string $reason): void
    {
        $this->skips[] = ['key' => $key, 'reason' => $reason];
        $this->writeProgress();
    }

    public function fatal(string $message): void
    {
        $this->fatal[] = $message;
        $this->writeProgress();
    }

    public function hasFatal(): bool
    {
        return !empty($this->fatal);
    }

    public function keepMaintenance(): bool
    {
        return $this->keepMaintenance;
    }

    public function progressPath(): string
    {
        return $this->runDir . '/progress.json';
    }

    public function reportPath(): string
    {
        return $this->runDir . '/report.json';
    }

    public function closeout(bool $maintenanceClosed): int
    {
        $this->createRunDir();
        $data = [
            'operation_id' => $this->operationId,
            'status' => $this->hasFatal() ? 'failed' : 'completed',
            'completed_at' => date('c'),
            'keep_maintenance' => $this->keepMaintenance,
            'source' => $this->source,
            'stages' => $this->stages,
            'skips' => $this->skips,
            'fatal' => $this->fatal,
        ];
        file_put_contents($this->reportPath(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return ($this->hasFatal() || !$maintenanceClosed) ? 1 : 0;
    }

    private function createRunDir(): void
    {
        if (!is_dir($this->runDir)) {
            mkdir($this->runDir, 0777, true);
        }
    }

    private function writeProgress(): void
    {
        $this->createRunDir();
        $data = [
            'operation_id' => $this->operationId,
            'status' => 'running',
            'started_at' => $this->startedAt,
            'source' => $this->source,
            'stages' => $this->stages,
            'skips' => $this->skips,
            'fatal' => $this->fatal,
        ];
        file_put_contents($this->progressPath(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
