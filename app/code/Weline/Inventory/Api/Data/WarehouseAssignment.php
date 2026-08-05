<?php

declare(strict_types=1);

namespace Weline\Inventory\Api\Data;

/**
 * Public immutable default-Warehouse projection.
 */
final readonly class WarehouseAssignment
{
    public function __construct(
        public int $warehouseId,
        public int $websiteId,
        public string $warehouseCode,
        public string $mode,
        public string $warehouseType,
        public bool $writerEnabled = false,
    ) {
        if ($warehouseId <= 0 || $websiteId < 0 || trim($warehouseCode) === '') {
            throw new \InvalidArgumentException('inventory_warehouse_assignment_invalid');
        }
    }

    /** @return array{warehouse_id:int,website_id:int,warehouse_code:string,mode:string,warehouse_type:string,writer_enabled:bool} */
    public function toArray(): array
    {
        return [
            'warehouse_id' => $this->warehouseId,
            'website_id' => $this->websiteId,
            'warehouse_code' => $this->warehouseCode,
            'mode' => $this->mode,
            'warehouse_type' => $this->warehouseType,
            'writer_enabled' => $this->writerEnabled,
        ];
    }
}
