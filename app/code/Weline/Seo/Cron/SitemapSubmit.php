<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Cron;

use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Service\SeoWebsiteAccountBindingService;
use Weline\Seo\Service\SeoWebsiteDirectory;
use Weline\Seo\Service\SitemapUrlSyncService;
use Weline\Seo\Service\WebSitemapData;

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
            /** @var SitemapUrlSyncService $syncService */
            $syncService = ObjectManager::getInstance(SitemapUrlSyncService::class);
            /** @var WebSitemapData $webSitemapData */
            $webSitemapData = ObjectManager::getInstance(WebSitemapData::class);
            /** @var SeoWebsiteDirectory $websiteDirectory */
            $websiteDirectory = ObjectManager::getInstance(SeoWebsiteDirectory::class);
            /** @var SeoWebsiteAccountBindingService $bindingService */
            $bindingService = ObjectManager::getInstance(SeoWebsiteAccountBindingService::class);

            $stats = [
                'collected_websites' => 0,
                'synced_urls' => 0,
                'disabled_urls' => 0,
                'generated_files' => 0,
                'submitted' => 0,
                'errors' => 0,
                'unbound_websites' => [],
            ];

            // ========== 步骤1：调用所有 Provider 同步 URL 数据到数据库 ==========
            $syncStats = $syncService->syncAll(true);
            $stats['collected_websites'] = count($syncStats['changed_websites'] ?? []);
            $stats['synced_urls'] = (int)($syncStats['inserted'] ?? 0)
                + (int)($syncStats['updated'] ?? 0)
                + (int)($syncStats['unchanged'] ?? 0);
            $stats['disabled_urls'] = (int)($syncStats['disabled'] ?? 0);
            $stats['errors'] += (int)($syncStats['errors'] ?? 0);

            foreach ((array)($syncStats['error_messages'] ?? []) as $message) {
                w_log_error('[Weline_Seo] SitemapSubmit provider sync error: ' . $message);
            }

            // ========== 步骤2：为所有站点生成 sitemap 文件 ==========
            $websites = $websiteDirectory->listWebsites();

            foreach ($websites as $website) {
                $websiteId = (int)($website['website_id'] ?? 0);
                if ($websiteId <= 0) {
                    continue;
                }

                try {
                    $result = $webSitemapData->generateSitemapFiles($websiteId);
                    $stats['generated_files'] += ($result['total_files'] ?? 0);

                    $hasAutoSubmit = $bindingService->getSitemapSubmitAccounts($websiteId) !== [];
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
            foreach ($websites as $website) {
                $websiteId = (int)($website['website_id'] ?? 0);
                if ($websiteId <= 0) {
                    continue;
                }
                foreach ($bindingService->getSitemapSubmitAccounts($websiteId) as $bindingInfo) {
                    $accountId = (int)($bindingInfo['account_id'] ?? 0);
                    try {
                        $platformCode = (string)($bindingInfo['platform_code'] ?? '');
                        if (empty($platformCode)) {
                            $stats['errors']++;
                            continue;
                        }

                        $adapter = $bindingInfo['adapter'] ?? null;

                        // 获取该平台的 sitemap 索引 URL
                        $sitemapUrl = $webSitemapData->getPlatformSitemapUrl($websiteId, $platformCode);
                        if (empty($sitemapUrl)) {
                            continue;
                        }

                        // 提交到搜索引擎（直接传递账户配置数组，适配器内部解析所需字段）
                        $accountConfig = (array)($bindingInfo['account_config'] ?? []);
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
                        ['source_module' => 'Weline_Seo', 'icon' => 'ri-file-list-line']
                    );
                } catch (\Exception $e) {
                    w_log_error('[Weline_Seo] SitemapSubmit message error: ' . $e->getMessage());
                }
            }

            return sprintf(
                'Sitemap任务完成：同步 %d 条URL，禁用 %d 条，变更 %d 个站点，生成 %d 个文件，提交 %d 个，未绑定 %d 个，错误 %d 个',
                $stats['synced_urls'],
                $stats['disabled_urls'],
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
