<?php

declare(strict_types=1);

namespace WeShop\Search\Observer;

use WeShop\Search\Service\SearchIndexer;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

class CategorySaveAfter implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $categoryId = (int) ($data['category_id'] ?? 0);
        if ($categoryId <= 0) {
            return;
        }

        try {
            /** @var SearchIndexer $indexer */
            $indexer = ObjectManager::getInstance(SearchIndexer::class);
            $indexer->indexEntity('category', $categoryId);

            $productIds = w_query('product', 'getProductIdsByCategoryId', ['category_id' => $categoryId]);
            if (is_array($productIds)) {
                foreach ($productIds as $productId) {
                    $productId = (int) $productId;
                    if ($productId <= 0) {
                        continue;
                    }

                    $indexer->indexEntity('product', $productId);
                }
            }
        } catch (\Throwable $throwable) {
            w_log_error('分类保存后同步搜索索引失败: ' . $throwable->getMessage());
        }
    }
}
