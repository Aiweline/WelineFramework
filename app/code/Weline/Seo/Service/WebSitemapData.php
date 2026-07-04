<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Service;

use Weline\Seo\Model\SitemapUrl;
use Weline\Seo\Interface\SitemapPlatformAdapterInterface;

/**
 * Sitemap 数据服务
 *
 * 协调器模式：负责协调站点、SEO账户、平台适配器之间的关系
 * 
 * 核心职责：
 * 1. 站点 → SEO账户绑定关系管理
 * 2. SEO账户 → 平台适配器映射
 * 3. 调用适配器生成 sitemap
 * 
 * 遵循 SOLID 原则：
 * - 单一职责：本服务只负责协调，不处理具体平台逻辑
 * - 开闭原则：添加新平台只需添加适配器，无需修改本服务
 * - 依赖倒置：依赖适配器接口，而非具体实现
 *
 * 目录结构：
 * pub/sitemaps/{website_code}/
 * ├── google/sitemap.xml    ← Google 适配器生成
 * ├── bing/sitemap.xml      ← Bing 适配器生成
 * └── baidu/sitemap.xml     ← 百度适配器生成
 *
 * @package Weline_Seo
 */
class WebSitemapData
{
    /**
     * Sitemap 存储目录
     */
    public const SITEMAP_DIR = 'pub/sitemaps';

    private SitemapUrl $sitemapUrlModel;
    private SitemapAdapterRegistry $adapterRegistry;
    private SeoWebsiteDirectory $websiteDirectory;
    private SeoWebsiteAccountBindingService $bindingService;

    public function __construct(
        SitemapUrl $sitemapUrlModel,
        SitemapAdapterRegistry $adapterRegistry,
        SeoWebsiteDirectory $websiteDirectory,
        SeoWebsiteAccountBindingService $bindingService
    ) {
        $this->sitemapUrlModel = $sitemapUrlModel;
        $this->adapterRegistry = $adapterRegistry;
        $this->websiteDirectory = $websiteDirectory;
        $this->bindingService = $bindingService;
    }

    /**
     * 获取站点绑定的平台适配器列表
     *
     * @param int $websiteId
     * @return SitemapPlatformAdapterInterface[] [platform_code => adapter, ...]
     */
    public function getWebsiteAdapters(int $websiteId): array
    {
        return $this->bindingService->getWebsiteAdapters($websiteId);
    }

    /**
     * 获取站点绑定的 SEO 账户和平台信息
     *
     * @param int $websiteId
     * @return array [['account' => SeoAccount, 'platform' => string, 'adapter' => adapter], ...]
     */
    public function getWebsiteAccountsWithPlatforms(int $websiteId): array
    {
        return $this->bindingService->getWebsiteAccountsWithPlatforms($websiteId, false);
    }

    /**
     * 为站点生成所有平台的 sitemap 文件
     *
     * @param int $websiteId
     * @return array
     */
    public function generateSitemapFiles(int $websiteId): array
    {
        // 获取站点信息
        $website = $this->websiteDirectory->getWebsiteById($websiteId) ?? [];
        $websiteCode = (string)($website['code'] ?? ('website_' . $websiteId));
        $baseUrl = rtrim((string)($website['url'] ?? ''), '/');
        
        // 按模块分组获取 URL
        $groupedUrls = $this->sitemapUrlModel->getActiveUrlsByWebsiteGrouped($websiteId);
        
        if (empty($groupedUrls)) {
            return [
                'platforms' => [],
                'total_urls' => 0,
                'website_code' => $websiteCode,
                'error' => 'no_urls',
                'message' => __('该站点没有 URL 数据，请先点击"同步所有 Provider"按钮生成 URL'),
            ];
        }
        
        // 获取站点绑定的适配器
        $adapters = $this->getWebsiteAdapters($websiteId);
        
        if (empty($adapters)) {
            // 计算总 URL 数
            $totalUrls = 0;
            foreach ($groupedUrls as $urls) {
                $totalUrls += count($urls);
            }
            
            return [
                'platforms' => [],
                'total_urls' => $totalUrls,
                'website_code' => $websiteCode,
                'error' => 'no_seo_account',
                'message' => __('该站点未绑定 SEO 账户，已有 %{1} 条 URL 数据，但无法生成 sitemap 文件。请前往"站点管理"或"SEO管理 > 账户管理"绑定 SEO 账户。', $totalUrls),
            ];
        }
        
        $platformResults = [];
        $totalUrls = 0;
        $totalFiles = 0;
        
        // 计算总 URL 数
        foreach ($groupedUrls as $urls) {
            $totalUrls += count($urls);
        }
        
        // 调用各适配器生成 sitemap
        foreach ($adapters as $platformCode => $adapter) {
            $result = $adapter->generateSitemapFiles(
                $websiteId,
                $websiteCode,
                $baseUrl,
                $groupedUrls
            );
            $platformResults[$platformCode] = $result;
            
            // 累计生成的文件数
            $totalFiles += ($result['total_files'] ?? 0);
        }
        
        return [
            'platforms' => $platformResults,
            'total_urls' => $totalUrls,
            'total_files' => $totalFiles,
            'website_code' => $websiteCode,
            'platform_count' => count($adapters),
        ];
    }

    /**
     * 提交站点的 sitemap 到所有绑定的平台
     *
     * @param int $websiteId
     * @return array 各平台提交结果
     */
    public function submitSitemaps(int $websiteId): array
    {
        $results = [];
        
        $website = $this->websiteDirectory->getWebsiteById($websiteId) ?? [];
        $websiteCode = (string)($website['code'] ?? ('website_' . $websiteId));
        $baseUrl = rtrim((string)($website['url'] ?? ''), '/');
        
        // 获取账户和平台信息
        $accountsInfo = $this->bindingService->getSitemapSubmitAccounts($websiteId);
        
        foreach ($accountsInfo as $info) {
            $account = (array)($info['account'] ?? []);
            $adapter = $info['adapter'];
            $platformCode = $info['platform_code'];
            
            if (!$adapter || !$adapter->supportsAutoSubmit()) {
                $results[$platformCode] = [
                    'success' => false,
                    'message' => __('平台 %{1} 不支持自动提交', $platformCode),
                ];
                continue;
            }
            
            // 获取平台索引 URL
            $sitemapUrl = $adapter->getIndexUrl($baseUrl, $websiteCode);
            
            // 获取账户配置
            $accountConfig = (array)($info['account_config'] ?? []);
            
            // 调用适配器提交
            $results[$platformCode] = $adapter->submitSitemap($sitemapUrl, $accountConfig);
        }
        
        return $results;
    }

    /**
     * 获取站点的主 sitemap URL
     */
    public function getMainSitemapUrl(int $websiteId): ?string
    {
        $website = $this->websiteDirectory->getWebsiteById($websiteId) ?? [];
        $websiteCode = (string)($website['code'] ?? ('website_' . $websiteId));
        $baseUrl = rtrim((string)($website['url'] ?? ''), '/');
        
        $adapters = $this->getWebsiteAdapters($websiteId);
        
        if (!empty($adapters)) {
            $firstAdapter = reset($adapters);
            return $firstAdapter->getIndexUrl($baseUrl, $websiteCode);
        }
        
        return null;
    }

    /**
     * 获取站点特定平台的 sitemap URL
     */
    public function getPlatformSitemapUrl(int $websiteId, string $platform): ?string
    {
        $adapter = $this->adapterRegistry->getAdapter($platform);
        if (!$adapter) {
            return null;
        }
        
        $website = $this->websiteDirectory->getWebsiteById($websiteId) ?? [];
        $websiteCode = (string)($website['code'] ?? ('website_' . $websiteId));
        $baseUrl = rtrim((string)($website['url'] ?? ''), '/');
        
        return $adapter->getIndexUrl($baseUrl, $websiteCode);
    }

    /**
     * 获取站点的所有活跃 URL
     */
    public function getActiveUrls(int $websiteId): array
    {
        return $this->sitemapUrlModel->getActiveUrls($websiteId);
    }

    /**
     * 获取站点活跃 URL 数量
     */
    public function getActiveUrlCount(int $websiteId): int
    {
        return $this->sitemapUrlModel->getActiveUrlCount($websiteId);
    }

    /**
     * 获取站点的 sitemap 文件列表
     */
    public function getSitemapFileList(int $websiteId): array
    {
        $website = $this->websiteDirectory->getWebsiteById($websiteId) ?? [];
        $websiteCode = (string)($website['code'] ?? ('website_' . $websiteId));
        $siteDir = BP . '/' . self::SITEMAP_DIR . '/' . $websiteCode;

        if (!is_dir($siteDir)) {
            return [];
        }

        $result = ['platforms' => []];

        $platformDirs = glob($siteDir . '/*', GLOB_ONLYDIR);
        foreach ($platformDirs as $platformDir) {
            $platformCode = basename($platformDir);
            $adapter = $this->adapterRegistry->getAdapter($platformCode);
            
            $platformData = [
                'platform_code' => $platformCode,
                'platform_name' => $adapter ? $adapter->getPlatformName() : ucfirst($platformCode),
                'platform_color' => $adapter ? $adapter->getPlatformColor() : '#6c757d',
                'index' => null,
                'modules' => [],
            ];

            // 检查平台索引
            $indexPath = $platformDir . '/sitemap.xml';
            if (file_exists($indexPath)) {
                $platformData['index'] = [
                    'filename' => 'sitemap.xml',
                    'path' => $indexPath,
                    'size' => filesize($indexPath),
                    'modified' => filemtime($indexPath),
                ];
            }

            // 扫描模块文件
            $sitemapFiles = glob($platformDir . '/sitemap_*.xml');
            $moduleGroups = [];
            
            foreach ($sitemapFiles as $file) {
                $filename = basename($file);
                if (preg_match('/^sitemap_([a-z]+)_(\d+)\.xml$/', $filename, $matches)) {
                    $moduleName = $matches[1];
                    if (!isset($moduleGroups[$moduleName])) {
                        $moduleGroups[$moduleName] = [];
                    }
                    $moduleGroups[$moduleName][] = [
                        'filename' => $filename,
                        'path' => $file,
                        'size' => filesize($file),
                        'modified' => filemtime($file),
                    ];
                }
            }
            
            $platformData['modules'] = $moduleGroups;
            $result['platforms'][$platformCode] = $platformData;
        }

        return $result;
    }

    /**
     * 删除站点的 sitemap 文件
     */
    public function deleteSitemapFiles(int $websiteId): void
    {
        $website = $this->websiteDirectory->getWebsiteById($websiteId) ?? [];
        $websiteCode = (string)($website['code'] ?? ('website_' . $websiteId));
        $siteDir = BP . '/' . self::SITEMAP_DIR . '/' . $websiteCode;

        if (is_dir($siteDir)) {
            $this->recursiveDelete($siteDir);
        }
    }

    /**
     * 递归删除目录
     */
    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * 获取所有站点的 sitemap 统计
     */
    public function getAllWebsiteStats(): array
    {
        $websites = $this->websiteDirectory->listWebsites();
        $stats = [];

        foreach ($websites as $website) {
            $websiteId = (int)($website['website_id'] ?? 0);
            $adapters = $this->getWebsiteAdapters($websiteId);
            $scopeStats = $this->sitemapUrlModel->getScopeStats($websiteId);

            $platformNames = [];
            foreach ($adapters as $code => $adapter) {
                $platformNames[$code] = $adapter->getPlatformName();
            }

            $stats[] = [
                'website_id' => $websiteId,
                'website_name' => $website['name'] ?? '',
                'website_code' => $website['code'] ?? '',
                'url_count' => $this->getActiveUrlCount($websiteId),
                'scope_stats' => $scopeStats,
                'platforms' => $platformNames,
                'platform_count' => count($adapters),
            ];
        }

        return $stats;
    }

    /**
     * 获取适配器注册中心
     */
    public function getAdapterRegistry(): SitemapAdapterRegistry
    {
        return $this->adapterRegistry;
    }

    /**
     * 格式化文件大小
     */
    public function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
