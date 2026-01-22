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
use Weline\Seo\Service\SearchEngineAdapterRegistry;
use Weline\Seo\Service\SitemapRegistryService;

/**
 * SEO Sitemap 提交任务
 *
 * 按账户和 scope 定时向搜索引擎提交 sitemap URL。
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
        return '按账户和scope定时向搜索引擎提交Sitemap URL';
    }

    public function cron_time(): string
    {
        // 每天凌晨3点
        return '0 3 * * *';
    }

    public function execute(): string
    {
        try {
            /** @var SeoAccount $accountModel */
            $accountModel = ObjectManager::getInstance(SeoAccount::class);
            /** @var SearchEngineAdapterRegistry $adapterRegistry */
            $adapterRegistry = ObjectManager::getInstance(SearchEngineAdapterRegistry::class);
            /** @var SitemapRegistryService $sitemapRegistry */
            $sitemapRegistry = ObjectManager::getInstance(SitemapRegistryService::class);

            $accounts = $accountModel->reset()
                ->where(SeoAccount::fields_IS_ACTIVE, SeoAccount::STATUS_ACTIVE)
                ->where(SeoAccount::fields_ENABLE_CRON_SITEMAP, 1)
                ->select()
                ->fetchArray();

            if (empty($accounts)) {
                return '没有启用Sitemap定时提交的SEO账户';
            }

            $submitCount = 0;
            $errorCount = 0;

            foreach ($accounts as $account) {
                $providerCode = (string)($account[SeoAccount::fields_PROVIDER] ?? '');
                $accountId = (int)($account[SeoAccount::fields_ACCOUNT_ID] ?? 0);
                $scope = (string)($account[SeoAccount::fields_SCOPE] ?? '');
                $module = (string)($account[SeoAccount::fields_MODULE] ?? '');

                if ($providerCode === '' || $accountId <= 0) {
                    continue;
                }

                $adapter = $adapterRegistry->getAdapter($providerCode);
                if ($adapter === null) {
                    $errorCount++;
                    continue;
                }

                $accountObj = $accountModel->reset()->load($accountId);
                if (!$accountObj->getId() || !$accountObj->isActive()) {
                    $errorCount++;
                    continue;
                }

                $config = $accountObj->getConfigArray();

                // 通过 extends 注册的 SitemapProvider 衍生类生成 sitemap URL
                $providers = $sitemapRegistry->getProvidersByScopeModule($scope, $module);
                $sitemaps = [];
                foreach ($providers as $sp) {
                    $generated = $sp->generateSitemaps();
                    if (is_array($generated)) {
                        $sitemaps = array_merge($sitemaps, $generated);
                    }
                }

                // 如果没有衍生类提供，则回退使用账户配置中的 sitemaps
                if (empty($sitemaps)) {
                    if (isset($config['sitemaps']) && is_array($config['sitemaps'])) {
                        $sitemaps = $config['sitemaps'];
                    } elseif (isset($config['sitemap'])) {
                        $sitemaps = [(string)$config['sitemap']];
                    }
                }

                if (empty($sitemaps)) {
                    continue;
                }

                foreach ($sitemaps as $sitemapUrl) {
                    $result = $adapter->submitSitemap((string)$sitemapUrl, [
                        'scope' => $scope,
                        'module' => $module,
                        'account' => [
                            'id' => $accountId,
                            'provider' => $providerCode,
                        ],
                        'config' => $config,
                    ]);

                    if ($result['success'] ?? false) {
                        $submitCount++;
                    } else {
                        $errorCount++;
                    }
                }
            }

            return sprintf(
                'Sitemap提交任务完成：成功 %d 个，失败 %d 个',
                $submitCount,
                $errorCount
            );
        } catch (\Throwable $e) {
            return 'Sitemap提交任务执行失败：' . $e->getMessage();
        }
    }

    public function timeout(): int
    {
        return 60;
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return $minute;
    }
}

