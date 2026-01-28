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

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;
use WeShop\Product\Model\Product;

class SourceItem extends Model
{
    public const table = 'weshop_inventory_source_item';
    public const primary_key = 'source_item_id';
    public const indexer = 'inventory_source_item_indexer';
    public array $_unit_primary_keys = ['source_item_id'];
    public array $_index_sort_keys = ['source_id', 'product_id', 'sku', 'status', 'quantity'];
    
    public const fields_ID = 'source_item_id';
    public const fields_SOURCE_ID = 'source_id';
    public const fields_PRODUCT_ID = 'product_id';
    public const fields_SKU = 'sku';
    public const fields_QUANTITY = 'quantity';
    public const fields_STATUS = 'status';  // 1=有货，0=缺货
    public const fields_LOW_STOCK_THRESHOLD = 'low_stock_threshold';

    // 库存状态常量
    public const STATUS_IN_STOCK = 1;
    public const STATUS_OUT_OF_STOCK = 0;

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // TODO: Implement upgrade() method.
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('产品库存项表（产品-库存源-数量）')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                11,
                'primary key auto_increment',
                '库存项ID'
            )
            ->addColumn(
                self::fields_SOURCE_ID,
                TableInterface::column_type_INTEGER,
                11,
                'not null',
                '库存源ID'
            )
            ->addColumn(
                self::fields_PRODUCT_ID,
                TableInterface::column_type_INTEGER,
                11,
                'not null',
                '产品ID'
            )
            ->addColumn(
                self::fields_SKU,
                TableInterface::column_type_VARCHAR,
                60,
                'not null',
                '产品SKU'
            )
            ->addColumn(
                self::fields_QUANTITY,
                TableInterface::column_type_FLOAT,
                0,
                'default 0',
                '库存数量'
            )
            ->addColumn(
                self::fields_STATUS,
                TableInterface::column_type_INTEGER,
                1,
                'default 1',
                '库存状态：1=有货，0=缺货'
            )
            ->addColumn(
                self::fields_LOW_STOCK_THRESHOLD,
                TableInterface::column_type_INTEGER,
                11,
                'default 0',
                '低库存阈值'
            )
            ->addIndex(
                TableInterface::index_type_UNIQUE,
                'idx_unique_source_product',
                [self::fields_SOURCE_ID, self::fields_PRODUCT_ID],
                '库存源-产品唯一索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_source_id',
                self::fields_SOURCE_ID,
                '库存源ID索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_product_id',
                self::fields_PRODUCT_ID,
                '产品ID索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_sku',
                self::fields_SKU,
                'SKU索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_status',
                self::fields_STATUS,
                '状态索引'
            )
            ->create();
    }

    // Getters and Setters
    public function getSourceId(): int
    {
        return (int)$this->getData(self::fields_SOURCE_ID);
    }

    public function setSourceId(int $sourceId): static
    {
        return $this->setData(self::fields_SOURCE_ID, $sourceId);
    }

    public function getProductId(): int
    {
        return (int)$this->getData(self::fields_PRODUCT_ID);
    }

    public function setProductId(int $productId): static
    {
        return $this->setData(self::fields_PRODUCT_ID, $productId);
    }

    public function getSku(): string
    {
        return (string)$this->getData(self::fields_SKU);
    }

    public function setSku(string $sku): static
    {
        return $this->setData(self::fields_SKU, $sku);
    }

    public function getQuantity(): float
    {
        return (float)$this->getData(self::fields_QUANTITY);
    }

    public function setQuantity(float $quantity): static
    {
        return $this->setData(self::fields_QUANTITY, $quantity);
    }

    public function getStatus(): int
    {
        return (int)$this->getData(self::fields_STATUS);
    }

    public function setStatus(int $status): static
    {
        return $this->setData(self::fields_STATUS, $status);
    }

    public function isInStock(): bool
    {
        return $this->getStatus() === self::STATUS_IN_STOCK && $this->getQuantity() > 0;
    }

    public function getLowStockThreshold(): int
    {
        return (int)$this->getData(self::fields_LOW_STOCK_THRESHOLD);
    }

    public function setLowStockThreshold(int $threshold): static
    {
        return $this->setData(self::fields_LOW_STOCK_THRESHOLD, $threshold);
    }

    public function isLowStock(): bool
    {
        $threshold = $this->getLowStockThreshold();
        return $threshold > 0 && $this->getQuantity() <= $threshold;
    }

    /**
     * 获取产品在所有库存源的总库存
     * @param int $productId
     * @return float
     */
    public function getTotalQuantityByProductId(int $productId): float
    {
        $result = $this->reset()
            ->where(self::fields_PRODUCT_ID, $productId)
            ->where(self::fields_STATUS, self::STATUS_IN_STOCK)
            ->fields('SUM(' . self::fields_QUANTITY . ') as total_qty')
            ->find()
            ->fetch();
        return (float)($result['total_qty'] ?? 0);
    }

    /**
     * 获取产品在指定库存源的库存
     * @param int $productId
     * @param int $sourceId
     * @return static|null
     */
    public function getByProductAndSource(int $productId, int $sourceId): ?static
    {
        $item = $this->reset()
            ->where(self::fields_PRODUCT_ID, $productId)
            ->where(self::fields_SOURCE_ID, $sourceId)
            ->find()
            ->fetch();
        return $item->getId() ? $item : null;
    }

    /**
     * 获取产品在所有库存源的库存列表
     * @param int $productId
     * @return array
     */
    public function getItemsByProductId(int $productId): array
    {
        return $this->reset()
            ->where(self::fields_PRODUCT_ID, $productId)
            ->joinModel(Source::class, 'source', 'main_table.source_id = source.source_id')
            ->select()
            ->fetchArray();
    }

    /**
     * 增加库存
     * @param int $productId
     * @param int $sourceId
     * @param float $quantity
     * @return bool
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
            
            // 触发库存变更事件
            $this->dispatchStockChangeEvent($productId, $sourceId, $quantity, 'increase');
            return true;
        }
        return false;
    }

    /**
     * 减少库存
     * @param int $productId
     * @param int $sourceId
     * @param float $quantity
     * @return bool
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
            
            // 触发库存变更事件
            $this->dispatchStockChangeEvent($productId, $sourceId, -$quantity, 'decrease');
            
            // 检查是否低库存
            if ($item->isLowStock()) {
                $this->dispatchLowStockEvent($productId, $sourceId, $newQty);
            }
            
            return true;
        }
        return false;
    }

    /**
     * 触发库存变更事件
     */
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

    /**
     * 触发低库存事件
     */
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

