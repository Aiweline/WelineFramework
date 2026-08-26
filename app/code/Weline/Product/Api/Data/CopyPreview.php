<?php

declare(strict_types=1);

namespace Weline\Product\Api\Data;

/** Dry-run preview of a copy draft（无 DML）. */
final class CopyPreview
{
    /**
     * @param list<array<string, mixed>> $items
     * @param list<string> $warnings
     * @param list<int> $targetStoreIds
     */
    public function __construct(
        public readonly string $draftId,
        public readonly int $categoryCount = 0,
        public readonly int $productCount = 0,
        public readonly int $offerCount = 0,
        public readonly int $linkCount = 0,
        public readonly int $createCount = 0,
        public readonly int $skipCount = 0,
        public readonly int $updateCount = 0,
        public readonly bool $inventoryWillCopyQty = false,
        public readonly array $items = [],
        public readonly array $warnings = [],
        public readonly array $targetStoreIds = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'draft_id' => $this->draftId,
            'target_store_ids' => $this->targetStoreIds,
            'category_count' => $this->categoryCount,
            'product_count' => $this->productCount,
            'offer_count' => $this->offerCount,
            'link_count' => $this->linkCount,
            'create_count' => $this->createCount,
            'skip_count' => $this->skipCount,
            'update_count' => $this->updateCount,
            'inventory_will_copy_qty' => $this->inventoryWillCopyQty,
            'items' => $this->items,
            'warnings' => $this->warnings,
        ];
    }
}
