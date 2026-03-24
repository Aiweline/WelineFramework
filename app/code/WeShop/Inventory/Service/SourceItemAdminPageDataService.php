<?php

declare(strict_types=1);

namespace WeShop\Inventory\Service;

use WeShop\Inventory\Model\Source;
use WeShop\Inventory\Model\SourceItem;

class SourceItemAdminPageDataService
{
    public function __construct(
        private readonly SourceItemManagementService $sourceItemManagementService,
        private readonly SourceManagementService $sourceManagementService
    ) {
    }

    public function getListData(int $page = 1, int $pageSize = 20, array $filters = []): array
    {
        $normalizedFilters = $this->normalizeFilters($filters);
        $result = $this->sourceItemManagementService->getSourceItemList($page, $pageSize, $normalizedFilters);

        return [
            'sourceItems' => array_map(
                fn (array $row): array => $this->normalizeSourceItemRow($row),
                $result['items'] ?? []
            ),
            'pagination' => $result['pagination'] ?? [],
            'filters' => $normalizedFilters,
            'sources' => $this->normalizeSourceOptions(
                $this->sourceManagementService->getEnabledSources()
            ),
        ];
    }

    public function getEditData(int $sourceItemId): array
    {
        $sourceItem = $this->sourceItemManagementService->getSourceItemById($sourceItemId);
        if (!$sourceItem) {
            throw new \InvalidArgumentException((string) __('Inventory source item does not exist.'));
        }

        return [
            'item' => $this->normalizeSourceItemRow($sourceItem),
        ];
    }

    private function normalizeFilters(array $filters): array
    {
        return [
            'source_id' => max(0, (int) ($filters['source_id'] ?? 0)),
            'search' => trim((string) ($filters['search'] ?? '')),
        ];
    }

    private function normalizeSourceOptions(array $sources): array
    {
        return array_map(static function (array $source): array {
            return [
                'source_id' => (int) ($source[Source::schema_fields_ID] ?? $source['source_id'] ?? 0),
                'code' => (string) ($source[Source::schema_fields_CODE] ?? $source['code'] ?? ''),
                'name' => (string) ($source[Source::schema_fields_NAME] ?? $source['name'] ?? ''),
            ];
        }, $sources);
    }

    private function normalizeSourceItemRow(array $row): array
    {
        $status = (int) ($row[SourceItem::schema_fields_STATUS] ?? $row['status'] ?? SourceItem::STATUS_OUT_OF_STOCK);

        return [
            'source_item_id' => (int) ($row[SourceItem::schema_fields_ID] ?? $row['source_item_id'] ?? 0),
            'source_id' => (int) ($row[SourceItem::schema_fields_SOURCE_ID] ?? $row['source_id'] ?? 0),
            'product_id' => (int) ($row[SourceItem::schema_fields_PRODUCT_ID] ?? $row['product_id'] ?? 0),
            'sku' => (string) ($row[SourceItem::schema_fields_SKU] ?? $row['sku'] ?? ''),
            'quantity' => (float) ($row[SourceItem::schema_fields_QUANTITY] ?? $row['quantity'] ?? 0),
            'status' => $status,
            'status_label' => $status === SourceItem::STATUS_IN_STOCK ? (string) __('In Stock') : (string) __('Out of Stock'),
            'low_stock_threshold' => (int) ($row[SourceItem::schema_fields_LOW_STOCK_THRESHOLD] ?? $row['low_stock_threshold'] ?? 0),
            'source_name' => (string) ($row['source_name'] ?? ''),
            'source_code' => (string) ($row['source_code'] ?? ''),
            'product_name' => (string) ($row['product_name'] ?? ''),
            'product_sku' => (string) ($row['product_sku'] ?? ''),
            'product_price' => (string) ($row['product_price'] ?? ''),
        ];
    }
}
