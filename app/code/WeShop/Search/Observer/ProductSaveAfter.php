<?php

declare(strict_types=1);

namespace WeShop\Search\Observer;

use WeShop\Search\Service\ProductIndexer;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * 产品保存后观察者
 * 自动更新搜索索引
 */
class ProductSaveAfter implements ObserverInterface
{
    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $productId = (int)($data['product_id'] ?? 0);
        
        if ($productId <= 0) {
            return;
        }
        
        try {
            /** @var ProductIndexer $indexer */
            $indexer = ObjectManager::getInstance(ProductIndexer::class);
            $indexer->indexProduct($productId);
        } catch (\Exception $e) {
            error_log("产品保存后更新搜索索引失败: " . $e->getMessage());
        }
    }
}
