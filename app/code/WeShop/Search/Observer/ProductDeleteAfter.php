<?php

declare(strict_types=1);

namespace WeShop\Search\Observer;

use WeShop\Search\Service\ProductIndexer;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * 产品删除后观察者
 * 自动删除搜索索引
 */
class ProductDeleteAfter implements ObserverInterface
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
            $indexer->deleteProduct($productId);
        } catch (\Exception $e) {
            w_log_error("产品删除后清理搜索索引失败: " . $e->getMessage());
        }
    }
}
