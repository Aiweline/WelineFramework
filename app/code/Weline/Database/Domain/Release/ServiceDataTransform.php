<?php

declare(strict_types=1);

namespace Weline\Database\Domain\Release;

use Closure;
use Weline\Framework\Database\Release\Api\DataTransformInterface;
use Weline\Framework\Database\Release\Api\SchemaScopeInterface;

/**
 * Adapter for legacy data migrations whose work already lives in a service.
 *
 * The transform is intentionally a single idempotent batch: services used by
 * legacy one-shot backfills are expected to be idempotent. It never writes
 * schema; requiresSnapshot() is true so the release coordinator snapshots first.
 */
class ServiceDataTransform implements DataTransformInterface
{
    public function __construct(
        private readonly string $id,
        private readonly SchemaScopeInterface $scope,
        private readonly string $table,
        private readonly string $fromVersion,
        private readonly string $toVersion,
        private readonly Closure $runner,
        private readonly int $estimateRows = 0,
        private readonly int $batchSize = 1,
        private readonly int $throttleMs = 0,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function scope(): SchemaScopeInterface
    {
        return $this->scope;
    }

    public function fromVersion(): string
    {
        return $this->fromVersion;
    }

    public function toVersion(): string
    {
        return $this->toVersion;
    }

    public function table(): string
    {
        return $this->table;
    }

    public function estimateRows(): int
    {
        return $this->estimateRows;
    }

    public function plan(): array
    {
        return [
            'strategy' => 'service-backfill',
            'table' => $this->table,
            'from_version' => $this->fromVersion,
            'to_version' => $this->toVersion,
            'estimate_rows' => $this->estimateRows,
            'requires_snapshot' => true,
        ];
    }

    public function applyBatch(array $cursor, int $limit): array
    {
        if (!empty($cursor['done'])) {
            return ['done' => true, 'cursor' => ['done' => true], 'processed' => 0];
        }

        ($this->runner)();

        return ['done' => true, 'cursor' => ['done' => true], 'processed' => 1];
    }

    public function verify(array $cursor): array
    {
        return ['ok' => true, 'checked' => 1, 'cursor' => $cursor];
    }

    public function inverse(): ?self
    {
        return null;
    }

    public function requiresSnapshot(): bool
    {
        return true;
    }

    public function batchSize(): int
    {
        return $this->batchSize;
    }

    public function throttleMs(): int
    {
        return $this->throttleMs;
    }
}
