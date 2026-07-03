<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Console\Sitemap;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Service\SitemapRegistryService;
use Weline\Seo\Service\WebSitemapData;
use Weline\Seo\Service\SitemapAdapterRegistry;
use Weline\Seo\Service\SeoWebsiteDirectory;
use Weline\Seo\Model\SitemapUrl;

/**
 * Sitemap 测试命令
 * 
 * 用于测试完整的 Sitemap 生成流程：
 * 1. Provider 同步 URL 到数据库
 * 2. 平台适配器从数据库读取并生成分组文件
 */
class Test implements CommandInterface
{
    private SitemapRegistryService $registryService;
    private WebSitemapData $webSitemapData;
    private SitemapAdapterRegistry $adapterRegistry;
    private SitemapUrl $sitemapUrlModel;
    private SeoWebsiteDirectory $websiteDirectory;

    public function __construct(
        SitemapRegistryService $registryService,
        WebSitemapData $webSitemapData,
        SitemapAdapterRegistry $adapterRegistry,
        SitemapUrl $sitemapUrlModel,
        SeoWebsiteDirectory $websiteDirectory
    ) {
        $this->registryService = $registryService;
        $this->webSitemapData = $webSitemapData;
        $this->adapterRegistry = $adapterRegistry;
        $this->sitemapUrlModel = $sitemapUrlModel;
        $this->websiteDirectory = $websiteDirectory;
    }

    public function execute(array $args = [], array $options = []): string
    {
        $this->printHeader();
        
        // 步骤 1: 检查适配器
        $this->checkAdapters();
        
        // 步骤 2: 检查 URL Provider
        $this->checkProviders();
        
        // 步骤 3: 同步 URL 到数据库
        $this->syncUrls();
        
        // 步骤 4: 生成 Sitemap 文件
        $this->generateSitemaps();
        
        // 步骤 5: 检查生成结果
        $this->checkResults();
        
        $this->printFooter();
        
        return '';
    }

    private function printHeader(): void
    {
        echo "\n";
        echo "╔════════════════════════════════════════════════════════════════╗\n";
        echo "║          Sitemap 生成流程完整测试                              ║\n";
        echo "╚════════════════════════════════════════════════════════════════╝\n";
        echo "\n";
    }

    private function printFooter(): void
    {
        echo "\n";
        echo "╔════════════════════════════════════════════════════════════════╗\n";
        echo "║                    测试完成                                    ║\n";
        echo "╚════════════════════════════════════════════════════════════════╝\n";
        echo "\n";
    }

    private function printSection(string $title): void
    {
        echo "\n";
        echo "┌─────────────────────────────────────────────────────────────┐\n";
        echo "│ " . str_pad($title, 59) . " │\n";
        echo "└─────────────────────────────────────────────────────────────┘\n";
    }

    private function checkAdapters(): void
    {
        $this->printSection('步骤 1: 检查平台适配器');
        
        $adapters = $this->adapterRegistry->getAdapters();
        echo "  ✓ 已注册 " . count($adapters) . " 个适配器:\n";
        
        foreach ($adapters as $code => $adapter) {
            $name = $adapter->getPlatformName();
            $color = $adapter->getPlatformColor();
            $maxUrls = number_format($adapter->getMaxUrlsPerFile());
            $maxSize = $this->formatBytes($adapter->getMaxFileSizeBytes());
            
            echo "    • {$name} ({$code})\n";
            echo "      - 限制: {$maxUrls} URLs / {$maxSize}\n";
        }
    }

    private function checkProviders(): void
    {
        $this->printSection('步骤 2: 检查 URL Provider');
        
        $providers = $this->registryService->getUrlProviders();
        echo "  ✓ 发现 " . count($providers) . " 个 URL Provider:\n";
        
        foreach ($providers as $provider) {
            $module = $provider->getModule();
            $scope = $provider->getScope();
            $enabled = $provider->isEnabled() ? '启用' : '禁用';
            
            echo "    • {$module} ({$scope}) - {$enabled}\n";
            echo "      {$provider->getDescription()}\n";
        }
    }

    private function syncUrls(): void
    {
        $this->printSection('步骤 3: 同步 URL 到数据库');
        
        $providers = $this->registryService->getUrlProviders();
        
        if (empty($providers)) {
            echo "  ⚠ 没有可用的 URL Provider\n";
            return;
        }
        
        foreach ($providers as $provider) {
            if (!$provider->isEnabled()) {
                continue;
            }
            
            $module = $provider->getModule();
            echo "\n  处理: {$module}\n";
            
            $websiteIds = $provider->getWebsiteIds();
            foreach ($websiteIds as $websiteId) {
                echo "    - 站点 {$websiteId}: ";
                
                try {
                    $result = $provider->syncUrls($websiteId);
                    echo "✓ ";
                    echo "新增 {$result['inserted']}, ";
                    echo "更新 {$result['updated']}, ";
                    echo "删除 {$result['deleted']}, ";
                    echo "总计 {$result['total']} 条\n";
                } catch (\Throwable $e) {
                    echo "✗ 失败: {$e->getMessage()}\n";
                }
            }
        }
        
        // 显示数据库统计
        echo "\n  数据库统计:\n";
        $websites = $this->websiteDirectory->listWebsites();
        foreach ($websites as $website) {
            $websiteId = (int)($website['website_id'] ?? 0);
            $count = $this->sitemapUrlModel->getActiveUrlCount($websiteId);
            $websiteCode = (string)($website['code'] ?? ('website_' . $websiteId));
            echo "    • {$websiteCode}: {$count} 条 URL\n";
        }
    }

    private function generateSitemaps(): void
    {
        $this->printSection('步骤 4: 生成 Sitemap 文件');
        
        $websites = $this->websiteDirectory->listWebsites();
        
        foreach ($websites as $website) {
            $websiteId = (int)($website['website_id'] ?? 0);
            $websiteCode = (string)($website['code'] ?? ('website_' . $websiteId));
            
            echo "\n  站点: {$websiteCode}\n";
            
            try {
                $result = $this->webSitemapData->generateSitemapFiles($websiteId);
                
                if (empty($result['platforms'])) {
                    echo "    ⚠ 没有生成任何文件（可能没有 URL 数据或未绑定平台）\n";
                    continue;
                }
                
                echo "    ✓ 生成成功:\n";
                echo "      - 总 URL: {$result['total_urls']}\n";
                echo "      - 平台数: {$result['platform_count']}\n";
                
                foreach ($result['platforms'] as $platformCode => $platformData) {
                    echo "\n      平台: {$platformData['platform_name']} ({$platformCode})\n";
                    echo "        - 文件数: {$platformData['total_files']}\n";
                    echo "        - URL 数: {$platformData['total_urls']}\n";
                    
                    if (!empty($platformData['modules'])) {
                        echo "        - 模块:\n";
                        foreach ($platformData['modules'] as $module => $moduleData) {
                            echo "          * {$module}: {$moduleData['file_count']} 个文件\n";
                        }
                    }
                }
            } catch (\Throwable $e) {
                echo "    ✗ 生成失败: {$e->getMessage()}\n";
                echo "      文件: {$e->getFile()}:{$e->getLine()}\n";
            }
        }
    }

    private function checkResults(): void
    {
        $this->printSection('步骤 5: 检查文件系统');
        
        $sitemapsDir = BP . '/pub/sitemaps';
        
        if (!is_dir($sitemapsDir)) {
            echo "  ✗ Sitemap 目录不存在: {$sitemapsDir}\n";
            return;
        }
        
        // 检查各站点
        $siteDirs = glob($sitemapsDir . '/*', GLOB_ONLYDIR);
        
        if (empty($siteDirs)) {
            echo "\n  ⚠ 没有站点目录\n";
            return;
        }
        
        foreach ($siteDirs as $siteDir) {
            $siteName = basename($siteDir);
            echo "\n  站点: {$siteName}/\n";
            
            $platformDirs = glob($siteDir . '/*', GLOB_ONLYDIR);
            
            if (empty($platformDirs)) {
                echo "    ⚠ 没有平台目录\n";
                continue;
            }
            
            foreach ($platformDirs as $platformDir) {
                $platformName = basename($platformDir);
                $files = glob($platformDir . '/*.xml');
                
                echo "    • {$platformName}/ (" . count($files) . " 个文件)\n";
                
                foreach ($files as $file) {
                    $filename = basename($file);
                    $size = $this->formatBytes(filesize($file));
                    echo "      - {$filename} ({$size})\n";
                }
            }
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    public function tip(): string
    {
        return 'Test sitemap generation workflow';
    }

    public function help(): string
    {
        return <<<HELP
测试 Sitemap 生成完整流程

此命令会执行以下步骤：
1. 检查平台适配器（Google, Bing, 百度）
2. 检查 URL Provider
3. 同步 URL 数据到数据库
4. 生成平台分组的 Sitemap 文件
5. 验证生成结果

使用方法：
  php bin/w sitemap:test

示例：
  php bin/w sitemap:test

HELP;
    }
}
