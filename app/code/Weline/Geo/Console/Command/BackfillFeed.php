<?php

declare(strict_types=1);

namespace Weline\Geo\Console\Command;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Geo\Service\FeedBackfillService;

class BackfillFeed implements CommandInterface
{
    public function execute(array $args = [], array $data = []): mixed
    {
        $limit = (int)($args[0] ?? 5000);
        if ($limit <= 0) {
            $limit = 5000;
        }

        try {
            /** @var FeedBackfillService $service */
            $service = ObjectManager::getInstance(FeedBackfillService::class);
            $stats = $service->backfill($limit);
            echo "GEO feed backfill done\n";
            echo 'feeds: ' . json_encode($stats['feeds'], JSON_UNESCAPED_UNICODE) . "\n";
            echo 'products: ' . $stats['products'] . "\n";
            echo 'articles: ' . $stats['articles'] . "\n";
            echo 'blog_categories: ' . $stats['blog_categories'] . "\n";
            echo 'catalog_categories: ' . $stats['catalog_categories'] . "\n";

            return true;
        } catch (\Throwable $e) {
            echo '错误: ' . $e->getMessage() . "\n";

            return false;
        }
    }

    public function tip(): string
    {
        return '回填 GEO Feed 条目（产品/文章/分类）';
    }

    public function help(): array|string
    {
        return '用法: php bin/m command:backfill-feed [limit]';
    }
}
