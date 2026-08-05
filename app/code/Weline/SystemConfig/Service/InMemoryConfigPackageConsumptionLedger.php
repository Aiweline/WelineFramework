<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Service;

/**
 * 进程内消费账本（单测 / 无库预览）；生产导入应使用 Model 实现。
 */
final class InMemoryConfigPackageConsumptionLedger implements ConfigPackageConsumptionLedgerInterface
{
    /** @var array<string, array{status:string,row:array<string,mixed>}> */
    private array $rows = [];

    public function claim(array $row): void
    {
        $uuid = \trim((string)($row['package_uuid'] ?? ''));
        if (\preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di',
            $uuid,
        ) !== 1) {
            throw new \InvalidArgumentException('config_envelope_package_uuid_invalid');
        }
        if (isset($this->rows[$uuid])) {
            throw new \RuntimeException('config_envelope_package_replayed');
        }
        $this->rows[$uuid] = [
            'status' => 'claimed',
            'row' => $row,
        ];
    }

    public function markApplied(string $packageUuid): void
    {
        if (!isset($this->rows[$packageUuid])) {
            throw new \RuntimeException('config_envelope_package_not_claimed');
        }
        $this->rows[$packageUuid]['status'] = 'applied';
    }

    public function markFailed(string $packageUuid): void
    {
        if (!isset($this->rows[$packageUuid])) {
            return;
        }
        $this->rows[$packageUuid]['status'] = 'failed';
    }

    public function isConsumed(string $packageUuid): bool
    {
        return isset($this->rows[$packageUuid]);
    }

    public function status(string $packageUuid): ?string
    {
        return $this->rows[$packageUuid]['status'] ?? null;
    }

    public function clear(): void
    {
        $this->rows = [];
    }
}
