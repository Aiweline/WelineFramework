<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Model\SeoAccount;
use Weline\Seo\Model\SeoWebsiteAccount;
use Weline\Seo\Service\SearchEngineAdapterRegistry;
use Weline\Seo\Service\SitemapRegistryService;
use Weline\Seo\Service\WebSitemapData;
use Weline\Websites\Model\Website;

/**
 * SEO Sitemap 提交任务
 *
 * 新架构：
 * 1. 调用所有 Provider 收集 URL 数据
 * 2. 为所有站点生成 sitemap 文件
 * 3. 检查站点账户绑定
 * 4. 只提交已绑定的站点
 * 5. 未绑定的发送消息通知
 */
class SitemapSubmit implements CronTaskInterface
{
    public function name(): string
    {
        return 'SEO Sitemap提交任务';
    }

    public function execute_name(): string
    {
        return 'seo_sitemap_submit';
    }

    public function tip(): string
    {
        return '收集URL数据、生成sitemap文件、向已绑定账户的站点提交Sitemap';
    }

    public function cron_time(): string
    {
        // 每天凌晨3点
        return '0 3 * * *';
    }

    public function execute(): string
    {
        try {
            /** @var SitemapRegistryService $sitemapRegistry */
            $sitemapRegistry = ObjectManager::getInstance(SitemapRegistryService::class);
            /** @var WebSitemapData $webSitemapData */
            $webSitemapData = ObjectManager::getInstance(WebSitemapData::class);
            /** @var SeoWebsiteAccount $seoWebsiteAccountModel */
            $seoWebsiteAccountModel = ObjectManager::getInstance(SeoWebsiteAccount::class);
            /** @var SeoAccount $accountModel */
            $accountModel = ObjectManager::getInstance(SeoAccount::class);
            /** @var SearchEngineAdapterRegistry $adapterRegistry */
            $adapterRegistry = ObjectManager::getInstance(SearchEngineAdapterRegistry::class);
            /** @var Website $websiteModel */
            $websiteModel = ObjectManager::getInstance(Website::class);
            /** @var EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(EventsManager::class);

            $stats = [
                'collected_websites' => 0,
                'generated_files' => 0,
                'submitted' => 0,
                'errors' => 0,
                'unbound_websites' => [],
            ];

            // ========== 步骤1：调用所有 Provider 收集 URL 数据 ==========
            $providers = $sitemapRegistry->getUrlProviders();
            
            foreach ($providers as $provider) {
                if (!$provider->isEnabled()) {
                    continue;
                }

                try {
                    $websiteIds = $provider->getWebsiteIds();
                    foreach ($websiteIds as $websiteId) {
                        // Provider 内部会调用 syncUrls 同步到数据库
                        $provider->getUrlsForWebsite($websiteId);
                        $stats['collected_websites']++;
                    }
                } catch (\Exception $e) {
                    error_log(sprintf(
                        '[Weline_Seo] SitemapSubmit provider error: %s - %s',
                        $provider->getModule(),
                        $e->getMessage()
                    ));
                    $stats['errors']++;
                }
            }

            // ========== 步骤2：为所有站点生成 sitemap 文件 ==========
            $websites = $websiteModel->reset()->select()->fetchArray();

            foreach ($websites as $website) {
                $websiteId = (int)($website['website_id'] ?? 0);
                if ($websiteId <= 0) {
                    continue;
                }

                try {
                    $result = $webSitemapData->generateSitemapFiles($websiteId);
                    $stats['generated_files'] += count($result['files']);

                    // 检查是否绑定 SEO 账户
                    $binding = $seoWebsiteAccountModel->getByWebsiteId($websiteId);
                    if (!$binding || !$binding->isAutoSubmitEnabled()) {
                        $stats['unbound_websites'][] = $website['name'] ?? "站点ID: {$websiteId}";
                    }
                } catch (\Exception $e) {
                    error_log(sprintf(
                        '[Weline_Seo] SitemapSubmit generate error: website_id=%d - %s',
                        $websiteId,
                        $e->getMessage()
                    ));
                    $stats['errors']++;
                }
            }

            // ========== 步骤3：提交 sitemap URL 到搜索引擎（只提交已绑定的）==========
            $bindings = $seoWebsiteAccountModel->getAutoSubmitBindings();

            foreach ($bindings as $binding) {
                $websiteId = (int)($binding[SeoWebsiteAccount::fields_WEBSITE_ID] ?? 0);
                $accountId = (int)($binding[SeoWebsiteAccount::fields_ACCOUNT_ID] ?? 0);

                if ($websiteId <= 0 || $accountId <= 0) {
                    continue;
                }

                try {
                    // 获取账户信息
                    $account = $accountModel->reset()->load($accountId);
                    if (!$account->getId() || !$account->isActive()) {
                        continue;
                    }

                    $providerCode = $account->getData(SeoAccount::fields_PROVIDER);
                    $adapter = $adapterRegistry->getAdapter($providerCode);
                    if ($adapter === null) {
                        $stats['errors']++;
                        continue;
                    }

                    // 获取 sitemap URL
                    $sitemapUrl = $webSitemapData->getMainSitemapUrl($websiteId);
                    if (empty($sitemapUrl)) {
                        continue;
                    }

                    // 提交到搜索引擎
                    $config = $account->getConfigArray();
                    $result = $adapter->submitSitemap($sitemapUrl, [
                        'account' => [
                            'id' => $accountId,
                            'provider' => $providerCode,
                        ],
                        'config' => $config,
                    ]);

                    if ($result['success'] ?? false) {
                        $stats['submitted']++;
                    } else {
                        $stats['errors']++;
                    }
                } catch (\Exception $e) {
                    error_log(sprintf(
                        '[Weline_Seo] SitemapSubmit submit error: website_id=%d, account_id=%d - %s',
                        $websiteId,
                        $accountId,
                        $e->getMessage()
                    ));
                    $stats['errors']++;
                }
            }

            // ========== 步骤4：发送消息通知未绑定的站点 ==========
            if (!empty($stats['unbound_websites'])) {
                try {
                    $eventsManager->dispatch('Weline_Admin::msg', [
                        'title' => __('Sitemap 提交提示'),
                        'content' => __('以下站点未绑定 SEO 账户，无法自动提交 sitemap：') . "\n\n" 
                            . implode("\n", $stats['unbound_websites']) . "\n\n" 
                            . __('请前往"SEO管理 > Sitemap管理"或"站点管理"绑定 SEO 账户。'),
                        'type' => 'warning',
                        'level' => 'warning',
                    ]);
                } catch (\Exception $e) {
                    // 消息发送失败不影响主流程
                    error_log('[Weline_Seo] SitemapSubmit message error: ' . $e->getMessage());
                }
            }

            return sprintf(
                'Sitemap任务完成：收集 %d 个站点，生成 %d 个文件，提交 %d 个，未绑定 %d 个，错误 %d 个',
                $stats['collected_websites'],
                $stats['generated_files'],
                $stats['submitted'],
                count($stats['unbound_websites']),
                $stats['errors']
            );
        } catch (\Throwable $e) {
            return 'Sitemap提交任务执行失败：' . $e->getMessage();
        }
    }

    public function timeout(): int
    {
        return 300; // 5分钟，处理大量站点可能需要更多时间
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return $minute;
    }
}
