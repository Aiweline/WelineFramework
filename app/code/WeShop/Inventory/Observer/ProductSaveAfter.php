<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归WeShop所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2024/01/15
 * 描述：产品保存后观察者 - 初始化库存记录
 */

namespace WeShop\Inventory\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Inventory\Model\Source;
use WeShop\Inventory\Model\SourceItem;
use WeShop\Product\Model\Product;

class ProductSaveAfter implements ObserverInterface
{
    private Source $source;
    private SourceItem $sourceItem;

    public function __construct(
        Source $source,
        SourceItem $sourceItem
    ) {
        $this->source = $source;
        $this->sourceItem = $sourceItem;
    }

    /**
     * 产品保存后执行 - 初始化默认库存源的库存记录
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        /** @var Product $product */
        $product = $data['product'] ?? null;
        $productId = $data['product_id'] ?? 0;

        if (!$product || !$productId) {
            return;
        }

        // 获取默认库存源
        $defaultSource = $this->source->reset()
            ->where(Source::schema_fields_CODE, 'default')
            ->find()
            ->fetch();

        if (!$defaultSource->getId()) {
            return;
        }

        $sourceId = (int)$defaultSource->getId();
        $productId = (int)$productId;

        // 检查是否已有库存记录
        $existingItem = $this->sourceItem->getByProductAndSource($productId, $sourceId);
        
        if (!$existingItem) {
            // 创建新的库存记录
            $stock = $product->getStock();
            $this->sourceItem->reset()
                ->clearData()
                ->setSourceId($sourceId)
                ->setProductId($productId)
                ->setSku($product->getSku())
                ->setQuantity((float)$stock)
                ->setStatus($stock > 0 ? SourceItem::STATUS_IN_STOCK : SourceItem::STATUS_OUT_OF_STOCK)
                ->setLowStockThreshold(5) // 默认低库存阈值
                ->save();
        } else {
            // 更新SKU（如果产品SKU变更）
            if ($existingItem->getSku() !== $product->getSku()) {
                $existingItem->setSku($product->getSku())->save();
            }
        }
    }
}

