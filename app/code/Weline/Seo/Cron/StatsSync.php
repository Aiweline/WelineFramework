<?php

declare(strict_types=1);

namespace Weline\Seo\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Model\SeoAccount;
use Weline\Seo\Model\SeoWebsiteAccount;
use Weline\Seo\Model\SeoWebsiteStats;
use Weline\Seo\Service\SitemapAdapterRegistry;
use Weline\Websites\Model\Website;

/**
 * SEO 统计数据同步任务
 *
 * 定期从各搜索引擎平台获取统计数据：
 * - 索引量/收录量
 * - 点击量、展示量
 * - CTR、平均排名
 * - 错误和警告数
 */
class StatsSync implements CronTaskInterface
{
    public function name(): string
    {
        return 'SEO统计数据同步';
    }

    public function execute_name(): string
    {
        return 'seo_stats_sync';
    }

    public function tip(): string
    {
        return '从各搜索引擎平台获取站点的索引量、点击量、展示量等统计数据';
    }

    public function cron_time(): string
    {
        // 每天凌晨 6 点执行
        return '0 6 * * *';
    }

    public function execute(): string
    {
        try {
            /** @var SitemapAdapterRegistry $adapterRegistry */
            $adapterRegistry = ObjectManager::getInstance(SitemapAdapterRegistry::class);
            
            /** @var SeoWebsiteAccount $websiteAccountModel */
            $websiteAccountModel = ObjectManager::getInstance(SeoWebsiteAccount::class);
            
            /** @var SeoAccount $seoAccountModel */
            $seoAccountModel = ObjectManager::getInstance(SeoAccount::class);
            
            /** @var Website $websiteModel */
            $websiteModel = ObjectManager::getInstance(Website::class);
            
            /** @var SeoWebsiteStats $statsModel */
            $statsModel = ObjectManager::getInstance(SeoWebsiteStats::class);
            
            // 获取所有站点-账户绑定关系
            $allBindings = $websiteAccountModel->reset()->select()->fetchArray();
            
            if (empty($allBindings)) {
                return '没有站点-账户绑定关系，跳过统计同步';
            }
            
            $syncedCount = 0;
            $errorCount = 0;
            $skippedCount = 0;
            $messages = [];
            
            foreach ($allBindings as $binding) {
                $websiteId = (int)($binding[SeoWebsiteAccount::schema_fields_WEBSITE_ID] ?? 0);
                $accountId = (int)($binding[SeoWebsiteAccount::schema_fields_ACCOUNT_ID] ?? 0);
                
                if ($websiteId <= 0 || $accountId <= 0) {
                    continue;
                }
                
                // 加载账户信息
                $account = $seoAccountModel->reset()->load($accountId);
                if (!$account->getId() || !$account->isActive()) {
                    $skippedCount++;
                    continue;
                }
                
                // 获取平台代码
                $platform = $account->getPlatform();
                if (empty($platform)) {
                    $skippedCount++;
                    continue;
                }
                
                // 获取适配器
                $adapter = $adapterRegistry->getAdapter($platform);
                if (!$adapter || !$adapter->supportsStats()) {
                    $skippedCount++;
                    continue;
                }
                
                // 加载站点信息
                $website = $websiteModel->reset()->load($websiteId);
                if (!$website->getId()) {
                    $skippedCount++;
                    continue;
                }
                
                $siteUrl = $website->getData('url') ?: '';
                if (empty($siteUrl)) {
                    $skippedCount++;
                    continue;
                }
                
                // 获取账户配置
                $accountConfig = [
                    'config' => $account->getConfigArray(),
                ];
                
                // 调用适配器获取统计数据
                try {
                    $result = $adapter->getStats($siteUrl, $accountConfig);
                    
                    if ($result['success'] && !empty($result['data'])) {
                        // 保存统计数据
                        $statsRecord = $statsModel->reset();
                        $statsRecord->getOrCreateTodayStats($websiteId, $accountId, $platform);
                        $statsRecord->updateStats($result['data']);
                        
                        $syncedCount++;
                        $messages[] = sprintf(
                            '[%s] %s (%s): 索引 %d, 点击 %d, 展示 %d',
                            $platform,
                            $website->getData('name'),
                            $account->getData('name'),
                            $result['data']['indexed_pages'] ?? 0,
                            $result['data']['clicks'] ?? 0,
                            $result['data']['impressions'] ?? 0
                        );
                    } else {
                        $errorCount++;
                        $messages[] = sprintf(
                            '[%s] %s: 获取失败 - %s',
                            $platform,
                            $website->getData('name'),
                            $result['message'] ?? '未知错误'
                        );
                    }
                } catch (\Throwable $e) {
                    $errorCount++;
                    $messages[] = sprintf(
                        '[%s] %s: 异常 - %s',
                        $platform,
                        $website->getData('name'),
                        $e->getMessage()
                    );
                }
            }
            
            $summary = sprintf(
                'SEO 统计同步完成：成功 %d，失败 %d，跳过 %d',
                $syncedCount,
                $errorCount,
                $skippedCount
            );
            
            if (!empty($messages)) {
                $summary .= "\n" . implode("\n", array_slice($messages, 0, 20)); // 最多显示 20 条
            }
            
            return $summary;
            
        } catch (\Throwable $e) {
            return 'SEO 统计同步失败：' . $e->getMessage();
        }
    }

    public function timeout(): int
    {
        return 600; // 10 分钟，API 调用可能需要较长时间
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return $minute;
    }
}
