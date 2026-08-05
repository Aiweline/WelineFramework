<?php

declare(strict_types=1);

namespace Weline\Inventory\Service;

use Weline\Inventory\Model\InventoryLedger;
use Weline\Inventory\Model\InventoryStock;
use Weline\Inventory\Model\Reservation;
use Weline\Inventory\Model\Warehouse;
use Weline\Inventory\Model\WarehousePool;
use Weline\Inventory\Model\WarehouseStoreAuthorization;

/**
 * Read-only inventory administration projection.
 */
final class InventoryAdminViewService
{
    public function __construct(
        private readonly InventoryStock $stocks,
        private readonly InventoryLedger $ledger,
        private readonly Warehouse $warehouses,
        private readonly WarehouseStoreAuthorization $authorizations,
        private readonly Reservation $reservations,
        private readonly WarehousePool $warehousePools,
    ) {
    }

    /** @return array{rows:list<array<string,mixed>>,columns:list<string>} */
    public function load(string $section, int $websiteId): array
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException(__('website_id 不能小于 0'));
        }

        $rows = match ($section) {
            'stocks' => $this->rows($this->stocks, $websiteId),
            'adjustments' => array_values(array_filter(
                $this->rows($this->ledger, $websiteId),
                static fn(array $row): bool => in_array(
                    (string)($row[InventoryLedger::schema_fields_EVENT_TYPE] ?? ''),
                    [InventoryLedger::TYPE_STOCK_SET, InventoryLedger::TYPE_STOCK_ADJUST],
                    true,
                ),
            )),
            'warehouses' => $this->rows($this->warehouses, $websiteId),
            'authorizations' => $this->rows($this->authorizations, $websiteId),
            'reservations' => $this->rows($this->reservations, $websiteId),
            'leases' => array_values(array_filter(
                $this->rows($this->reservations, $websiteId),
                static fn(array $row): bool => ($row[Reservation::schema_fields_LEASE_EXPIRES_AT] ?? null) !== null,
            )),
            'ledger' => $this->rows($this->ledger, $websiteId),
            'migration' => $this->migrationRows($websiteId),
            default => throw new \InvalidArgumentException(__('未知库存管理区段：%{1}', [$section])),
        };
        $rows = array_slice(array_map([$this, 'sanitize'], $rows), 0, 100);

        return ['rows' => $rows, 'columns' => $this->columns($rows)];
    }

    /** @return list<array<string,mixed>> */
    private function rows(object $model, int $websiteId): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = $model->clear()->where('website_id', $websiteId)->select()->fetchArray();
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function migrationRows(int $websiteId): array
    {
        $rows = $this->rows($this->warehousePools, $websiteId);
        if ($rows === []) {
            return [[
                'website_id' => $websiteId,
                'migration_state' => 'not_started',
                'warehouse_pool_count' => 0,
            ]];
        }
        return array_map(static fn(array $row): array => [
            'website_id' => $websiteId,
            'migration_state' => 'warehouse_pool_ready',
            'warehouse_pool_id' => $row['pool_id'] ?? null,
            'warehouse_id' => $row['warehouse_id'] ?? null,
        ], $rows);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function sanitize(array $row): array
    {
        unset($row['request_hash'], $row['idempotency_key'], $row['lease_owner_attempt_code']);
        return $row;
    }

    /** @param list<array<string,mixed>> $rows @return list<string> */
    private function columns(array $rows): array
    {
        $columns = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $column) {
                if (!in_array($column, $columns, true)) {
                    $columns[] = $column;
                }
            }
        }
        return $columns;
    }
}
