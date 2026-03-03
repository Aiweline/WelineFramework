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
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Model\SeoAccount;
use Weline\Seo\Model\SeoWebsiteAccount;
use Weline\Seo\Service\SitemapAdapterRegistry;
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
            /** @var SitemapAdapterRegistry $sitemapAdapterRegistry */
            $sitemapAdapterRegistry = ObjectManager::getInstance(SitemapAdapterRegistry::class);
            /** @var Website $websiteModel */
            $websiteModel = ObjectManager::getInstance(Website::class);

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
                    w_log_error(sprintf(
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
                    $stats['generated_files'] += ($result['total_files'] ?? 0);

                    // 检查是否绑定 SEO 账户（getByWebsiteId 返回数组）
                    $bindings = $seoWebsiteAccountModel->getByWebsiteId($websiteId);
                    $hasAutoSubmit = false;
                    if (!empty($bindings)) {
                        foreach ($bindings as $bindingRow) {
                            if ((int)($bindingRow[SeoWebsiteAccount::fields_IS_AUTO_SUBMIT] ?? 0) === 1) {
                                $hasAutoSubmit = true;
                                break;
                            }
                        }
                    }
                    if (!$hasAutoSubmit) {
                        $stats['unbound_websites'][] = $website['name'] ?? "站点ID: {$websiteId}";
                    }
                } catch (\Exception $e) {
                    w_log_error(sprintf(
                        '[Weline_Seo] SitemapSubmit generate error: website_id=%d - %s',
                        $websiteId,
                        $e->getMessage()
                    ));
                    $stats['errors']++;
                }
            }

            // ========== 步骤3：提交 sitemap URL 到搜索引擎（只提交已绑定的）==========
            $autoBindings = $seoWebsiteAccountModel->getAutoSubmitBindings();
            
            // 按 website_id 分组，避免同一站点重复提交
            $websiteBindingsMap = [];
            foreach ($autoBindings as $binding) {
                $wId = (int)($binding[SeoWebsiteAccount::fields_WEBSITE_ID] ?? 0);
                $aId = (int)($binding[SeoWebsiteAccount::fields_ACCOUNT_ID] ?? 0);
                if ($wId > 0 && $aId > 0) {
                    $websiteBindingsMap[$wId][] = $binding;
                }
            }

            foreach ($websiteBindingsMap as $websiteId => $websiteBindings) {
                foreach ($websiteBindings as $binding) {
                    $accountId = (int)($binding[SeoWebsiteAccount::fields_ACCOUNT_ID] ?? 0);

                    try {
                        // 获取账户信息
                        $account = $accountModel->reset()->load($accountId);
                        if (!$account->getId() || !$account->isActive()) {
                            continue;
                        }

                        // 通过账户的 platform 字段获取平台适配器
                        $platformCode = $account->getPlatform();
                        
                        // 如果 platform 字段为空，从 provider 推断（向后兼容）
                        if (empty($platformCode)) {
                            $providerCode = $account->getData(SeoAccount::fields_PROVIDER);
                            $platformCode = $sitemapAdapterRegistry->extractPlatformFromProvider($providerCode);
                        }
                        
                        if (empty($platformCode)) {
                            $stats['errors']++;
                            continue;
                        }

                        $adapter = $sitemapAdapterRegistry->getAdapter($platformCode);
                        if ($adapter === null || !$adapter->supportsAutoSubmit()) {
                            $stats['errors']++;
                            continue;
                        }

                        // 获取该平台的 sitemap 索引 URL
                        $sitemapUrl = $webSitemapData->getPlatformSitemapUrl($websiteId, $platformCode);
                        if (empty($sitemapUrl)) {
                            continue;
                        }

                        // 提交到搜索引擎（直接传递账户配置数组，适配器内部解析所需字段）
                        $accountConfig = $account->getConfigArray();
                        $result = $adapter->submitSitemap($sitemapUrl, $accountConfig);

                        if ($result['success'] ?? false) {
                            $stats['submitted']++;
                        } else {
                            $stats['errors']++;
                        }
                    } catch (\Exception $e) {
                        w_log_error(sprintf(
                            '[Weline_Seo] SitemapSubmit submit error: website_id=%d, account_id=%d - %s',
                            $websiteId,
                            $accountId,
                            $e->getMessage()
                        ));
                        $stats['errors']++;
                    }
                }
            }

            // ========== 步骤4：发送消息通知未绑定的站点 ==========
            if (!empty($stats['unbound_websites'])) {
                try {
                    w_msg(
                        'seo_sitemap',
                        'warning',
                        __('Sitemap 提交提示'),
                        __('以下站点未绑定 SEO 账户，无法自动提交 sitemap：') . "\n\n" 
                            . implode("\n", $stats['unbound_websites']) . "\n\n" 
                            . __('请前往"SEO管理 > Sitemap管理"或"站点管理"绑定 SEO 账户。'),
                        ['source_module' => 'Weline_Seo', 'icon' => 'ri-sitemap-line']
                    );
                } catch (\Exception $e) {
                    w_log_error('[Weline_Seo] SitemapSubmit message error: ' . $e->getMessage());
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
