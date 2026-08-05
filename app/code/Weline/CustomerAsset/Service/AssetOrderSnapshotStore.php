<?php

declare(strict_types=1);

namespace Weline\CustomerAsset\Service;

use Weline\CustomerAsset\Model\AssetOrderAllocationSnapshot;

final class AssetOrderSnapshotStore
{
    /** @var array<string, AssetOrderAllocationSnapshot> */
    private array $rows = [];

    public static function forTesting(): self
    {
        return new self();
    }

    public function put(AssetOrderAllocationSnapshot $snapshot): void
    {
        if (isset($this->rows[$snapshot->orderRef])) {
            throw new CustomerAssetConflictException(
                'customer_asset_order_snapshot_immutable',
                'snapshot exists',
                ['order_ref' => $snapshot->orderRef],
            );
        }
        $this->rows[$snapshot->orderRef] = $snapshot;
    }

    public function get(string $orderRef): ?AssetOrderAllocationSnapshot
    {
        return $this->rows[$orderRef] ?? null;
    }

    public function count(): int
    {
        return count($this->rows);
    }
}
