<?php

declare(strict_types=1);

namespace WeShop\Inventory\Service;

use WeShop\Inventory\Model\Source;
use WeShop\Inventory\Model\SourceItem;
use WeShop\Product\Model\Product;

class SourceItemManagementService
{
    public function __construct(
        private readonly SourceItem $sourceItem
    ) {
    }

    public function getSourceItemList(int $page = 1, int $pageSize = 20, array $filters = []): array
    {
        $sourceItem = $this->sourceItem->reset()
            ->joinModel(
                Source::class,
                'source',
                'main_table.source_id = source.source_id',
                'left',
                'source.name as source_name, source.code as source_code'
            )
            ->joinModel(
                Product::class,
                'product',
                'main_table.product_id = product.product_id',
                'left',
                'product.name as product_name, product.price as product_price'
            );

        if (!empty($filters['source_id'])) {
            $sourceItem->where('main_table.source_id', (int) $filters['source_id']);
        }

        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $sourceItem->where('(main_table.sku LIKE ? OR product.name LIKE ?)', ['%' . $search . '%', '%' . $search . '%']);
        }

        $sourceItem->order('main_table.source_item_id', 'DESC')
            ->pagination($page, $pageSize);

        return [
            'items' => $sourceItem->select()->fetchArray(),
            'pagination' => $sourceItem->getPagination(),
            'total' => $sourceItem->getTotalCount(),
        ];
    }

    public function getSourceItemById(int $sourceItemId): ?array
    {
        $item = $this->sourceItem->reset()
            ->joinModel(
                Source::class,
                'source',
                'main_table.source_id = source.source_id',
                'left',
                'source.name as source_name'
            )
            ->joinModel(
                Product::class,
                'product',
                'main_table.product_id = product.product_id',
                'left',
                'product.name as product_name, product.sku as product_sku'
            )
            ->where('main_table.source_item_id', $sourceItemId)
            ->find()
            ->fetch();

        return $item->getId() ? $item->getData() : null;
    }

    public function updateSourceItem(int $sourceItemId, array $payload): SourceItem
    {
        $quantity = (float) ($payload['quantity'] ?? 0);
        $threshold = (int) ($payload['low_stock_threshold'] ?? 0);

        if ($sourceItemId <= 0) {
            throw new \InvalidArgumentException((string) __('Invalid source item id.'));
        }
        if ($quantity < 0) {
            throw new \InvalidArgumentException((string) __('Quantity cannot be negative.'));
        }
        if ($threshold < 0) {
            throw new \InvalidArgumentException((string) __('Low stock threshold cannot be negative.'));
        }

        $sourceItem = $this->sourceItem->reset()->load($sourceItemId);
        if (!$sourceItem->getId()) {
            throw new \InvalidArgumentException((string) __('Inventory source item does not exist.'));
        }

        $status = $quantity > 0 ? SourceItem::STATUS_IN_STOCK : SourceItem::STATUS_OUT_OF_STOCK;
        $sourceItem
            ->setQuantity($quantity)
            ->setStatus($status)
            ->setLowStockThreshold($threshold)
            ->save();

        return $sourceItem;
    }

    public function batchAdjust(array $adjustments): int
    {
        $adjusted = 0;
        foreach ($adjustments as $adjustment) {
            if (!is_array($adjustment)) {
                continue;
            }

            $productId = (int) ($adjustment['product_id'] ?? 0);
            $sourceId = (int) ($adjustment['source_id'] ?? 0);
            $quantityChange = (float) ($adjustment['quantity_change'] ?? 0);
            if ($productId <= 0 || $sourceId <= 0 || $quantityChange == 0.0) {
                continue;
            }

            if ($quantityChange > 0) {
                if ($this->sourceItem->increaseStock($productId, $sourceId, $quantityChange)) {
                    $adjusted++;
                }
                continue;
            }

            if ($this->sourceItem->decreaseStock($productId, $sourceId, abs($quantityChange))) {
                $adjusted++;
            }
        }

        return $adjusted;
    }

    public function getProductStockSummary(int $productId): array
    {
        if ($productId <= 0) {
            throw new \InvalidArgumentException((string) __('Invalid product id.'));
        }

        return [
            'product_id' => $productId,
            'total_quantity' => $this->sourceItem->getTotalQuantityByProductId($productId),
            'sources' => $this->sourceItem->getItemsByProductId($productId),
        ];
    }

    public function getStatusOptions(): array
    {
        return [
            SourceItem::STATUS_IN_STOCK => (string) __('In Stock'),
            SourceItem::STATUS_OUT_OF_STOCK => (string) __('Out of Stock'),
        ];
    }
}

