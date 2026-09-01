<?php

declare(strict_types=1);

namespace Weline\Search\Cron;

use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Search\Service\SearchProviderIndexService;
use Weline\Websites\Model\Website;

/**
 * 定时重建 Search Provider 内容索引（Blog 等非 Product 类型）。
 * 前台搜索只读索引，不回退源表直查。
 */
class ProviderIndexRebuild implements CronTaskInterface
{
    public function name(): string
    {
        return (string)__('Search Provider 索引重建');
    }

    public function execute_name(): string
    {
        return 'search_provider_index_rebuild';
    }

    public function tip(): string
    {
        return (string)__('定时重建 Blog 等 Provider 搜索索引，保证前台只读索引');
    }

    public function cron_time(): string
    {
        // 每 15 分钟
        return '*/15 * * * *';
    }

    public function execute(): string
    {
        /** @var SearchProviderIndexService $service */
        $service = ObjectManager::getInstance(SearchProviderIndexService::class);
        $websiteIds = $this->websiteIds();
        $total = 0;
        $websites = 0;
        foreach ($websiteIds as $websiteId) {
            $count = $service->rebuildAll($websiteId);
            $total += $count;
            $websites++;
        }

        return (string)__('Provider 索引定时重建完成：站点 %{1} 个，文档 %{2} 条', [
            (string)$websites,
            (string)$total,
        ]);
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return max(60, $minute);
    }

    /** @return list<int> */
    private function websiteIds(): array
    {
        $ids = [0];
        try {
            /** @var Website $website */
            $website = ObjectManager::getInstance(Website::class);
            $rows = $website->clearData()->reset()->select()->fetchArray();
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $id = max(0, (int)($row[Website::schema_fields_ID] ?? 0));
                    if (!in_array($id, $ids, true)) {
                        $ids[] = $id;
                    }
                }
            }
        } catch (\Throwable) {
        }

        sort($ids);

        return $ids;
    }
}
