<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归WeShop所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2024/01/15
 * 描述：产品库存项模型（产品-库存源-数量关联）
 */
namespace WeShop\Inventory\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Manager\ObjectManager;
#[Table(comment: '产品库存项表（产品-库存源-数量）')]
#[Index(name: 'idx_unique_source_product', columns: ['source_id', 'product_id'], type: 'UNIQUE', comment: '库存源-产品唯一索引')]
#[Index(name: 'idx_source_id', columns: ['source_id'], comment: '库存源ID索引')]
#[Index(name: 'idx_product_id', columns: ['product_id'], comment: '产品ID索引')]
#[Index(name: 'idx_sku', columns: ['sku'], comment: 'SKU索引')]
#[Index(name: 'idx_status', columns: ['status'], comment: '状态索引')]
class SourceItem extends Model
{
    public const schema_table = 'weshop_inventory_source_item';
    public const schema_primary_key = 'source_item_id';
    public const indexer = 'inventory_source_item_indexer';
    public array $_unit_primary_keys = ['source_item_id'];
    public array $_index_sort_keys = ['source_id', 'product_id', 'sku', 'status', 'quantity'];
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '库存项ID')]
    public const schema_fields_ID = 'source_item_id';
    #[Col(type: 'int', nullable: false, comment: '库存源ID')]
    public const schema_fields_SOURCE_ID = 'source_id';
    #[Col(type: 'int', nullable: false, comment: '产品ID')]
    public const schema_fields_PRODUCT_ID = 'product_id';
    #[Col(type: 'varchar', length: 60, nullable: false, comment: '产品SKU')]
    public const schema_fields_SKU = 'sku';
    #[Col(type: 'decimal', length: '12,4', nullable: true, default: '0', comment: '库存数量')]
    public const schema_fields_QUANTITY = 'quantity';
    #[Col(type: 'tinyint', length: 1, nullable: true, default: 1, comment: '库存状态：1=有货，0=缺货')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'int', nullable: true, default: 0, comment: '低库存阈值')]
    public const schema_fields_LOW_STOCK_THRESHOLD = 'low_stock_threshold';
    public const STATUS_IN_STOCK = 1;
    public const STATUS_OUT_OF_STOCK = 0;

    public function getSourceId(): int
    {
        return (int)$this->getData(self::schema_fields_SOURCE_ID);
    }
    public function setSourceId(int $sourceId): static
    {
        return $this->setData(self::schema_fields_SOURCE_ID, $sourceId);
    }
    public function getProductId(): int
    {
        return (int)$this->getData(self::schema_fields_PRODUCT_ID);
    }
    public function setProductId(int $productId): static
    {
        return $this->setData(self::schema_fields_PRODUCT_ID, $productId);
    }
    public function getSku(): string
    {
        return (string)$this->getData(self::schema_fields_SKU);
    }
    public function setSku(string $sku): static
    {
        return $this->setData(self::schema_fields_SKU, $sku);
    }
    public function getQuantity(): float
    {
        return (float)$this->getData(self::schema_fields_QUANTITY);
    }
    public function setQuantity(float $quantity): static
    {
        return $this->setData(self::schema_fields_QUANTITY, $quantity);
    }
    public function getStatus(): int
    {
        return (int)$this->getData(self::schema_fields_STATUS);
    }
    public function setStatus(int $status): static
    {
        return $this->setData(self::schema_fields_STATUS, $status);
    }
    public function isInStock(): bool
    {
        return $this->getStatus() === self::STATUS_IN_STOCK && $this->getQuantity() > 0;
    }
    public function getLowStockThreshold(): int
    {
        return (int)$this->getData(self::schema_fields_LOW_STOCK_THRESHOLD);
    }
    public function setLowStockThreshold(int $threshold): static
    {
        return $this->setData(self::schema_fields_LOW_STOCK_THRESHOLD, $threshold);
    }
    public function isLowStock(): bool
    {
        $threshold = $this->getLowStockThreshold();
        return $threshold > 0 && $this->getQuantity() <= $threshold;
    }
    /**
     * 获取产品在所有库存源的总库存
     */
    public function getTotalQuantityByProductId(int $productId): float
    {
        $result = $this->reset()
            ->where(self::schema_fields_PRODUCT_ID, $productId)
            ->where(self::schema_fields_STATUS, self::STATUS_IN_STOCK)
            ->fields('SUM(' . self::schema_fields_QUANTITY . ') as total_qty')
            ->find()
            ->fetch();
        return (float)($result['total_qty'] ?? 0);
    }
    /**
     * 获取产品在指定库存源的库存
     */
    public function getByProductAndSource(int $productId, int $sourceId): ?static
    {
        $item = $this->reset()
            ->where(self::schema_fields_PRODUCT_ID, $productId)
            ->where(self::schema_fields_SOURCE_ID, $sourceId)
            ->find()
            ->fetch();
        return $item->getId() ? $item : null;
    }
    /**
     * 获取产品在所有库存源的库存列表
     */
    public function getItemsByProductId(int $productId): array
    {
        return $this->reset()
            ->where(self::schema_fields_PRODUCT_ID, $productId)
            ->joinModel(Source::class, 'source', 'main_table.source_id = source.source_id')
            ->select()
            ->fetchArray();
    }
    /**
     * 增加库存
     */
    public function increaseStock(int $productId, int $sourceId, float $quantity): bool
    {
        $item = $this->getByProductAndSource($productId, $sourceId);
        if ($item) {
            $newQty = $item->getQuantity() + $quantity;
            $item->setQuantity($newQty);
            if ($newQty > 0) {
                $item->setStatus(self::STATUS_IN_STOCK);
            }
            $item->save();
            $this->dispatchStockChangeEvent($productId, $sourceId, $quantity, 'increase');
            return true;
        }
        return false;
    }
    /**
     * 减少库存
     */
    public function decreaseStock(int $productId, int $sourceId, float $quantity): bool
    {
        $item = $this->getByProductAndSource($productId, $sourceId);
        if ($item) {
            $newQty = max(0, $item->getQuantity() - $quantity);
            $item->setQuantity($newQty);
            if ($newQty <= 0) {
                $item->setStatus(self::STATUS_OUT_OF_STOCK);
            }
            $item->save();
            $this->dispatchStockChangeEvent($productId, $sourceId, -$quantity, 'decrease');
            if ($item->isLowStock()) {
                $this->dispatchLowStockEvent($productId, $sourceId, $newQty);
            }
            return true;
        }
        return false;
    }
    protected function dispatchStockChangeEvent(int $productId, int $sourceId, float $quantityChange, string $type): void
    {
        $eventManager = ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);
        $eventManager->dispatch('WeShop_Inventory::stock_change', [
            'product_id' => $productId,
            'source_id' => $sourceId,
            'quantity_change' => $quantityChange,
            'type' => $type
        ]);
    }
    protected function dispatchLowStockEvent(int $productId, int $sourceId, float $currentQuantity): void
    {
        $eventManager = ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);
        $eventManager->dispatch('WeShop_Inventory::stock_low', [
            'product_id' => $productId,
            'source_id' => $sourceId,
            'current_quantity' => $currentQuantity
        ]);
    }
}
