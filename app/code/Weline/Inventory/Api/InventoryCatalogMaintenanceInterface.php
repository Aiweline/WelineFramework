<?php

declare(strict_types=1);

namespace Weline\Inventory\Api;

/**
 * Narrow maintenance port for guarded deletion of catalog-owned inventory rows.
 * Immutable ledger is reported as preserved audit history; active order-like
 * references remain protected. Neither kind is ever deleted by this port.
 */
interface InventoryCatalogMaintenanceInterface
{
    /**
     * @param list<int> $offerIds
     * @return array{
     *   stock_items:int,
     *   reservations:int,
     *   ledger_events:int,
     *   protected_references:list<array<string,mixed>>,
     *   preserved_audit_references:list<array<string,mixed>>
     * }
     */
    public function previewCatalogPurge(int $websiteId, array $offerIds): array;

    /**
     * @param list<int> $offerIds
     * @return array{stock_items:int,reservations:int,ledger_events:int}
     */
    public function purgeCatalogOffers(int $websiteId, array $offerIds): array;
}
