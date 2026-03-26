<?php

declare(strict_types=1);

namespace WeShop\Search\Observer;

use WeShop\Search\Service\SearchIndexer;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

class CategoryDeleteAfter implements ObserverInterface
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
            $indexer->deleteEntity('category', $categoryId);
        } catch (\Throwable $throwable) {
            w_log_error('分类删除后清理搜索索引失败: ' . $throwable->getMessage());
        }
    }
}
