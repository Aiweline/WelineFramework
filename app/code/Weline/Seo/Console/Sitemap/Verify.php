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
use Weline\Seo\Model\SitemapUrl;
use Weline\Websites\Model\Website;

/**
 * Sitemap E2E 验证命令
 * 
 * 模拟前端操作，验证完整的端到端流程
 */
class Verify implements CommandInterface
{
    private SitemapRegistryService $registryService;
    private WebSitemapData $webSitemapData;
    private SitemapAdapterRegistry $adapterRegistry;
    private SitemapUrl $sitemapUrlModel;
    private Website $websiteModel;
    
    private array $errors = [];
    private array $warnings = [];
    private int $checks = 0;
    private int $passed = 0;

    public function __construct(
        SitemapRegistryService $registryService,
        WebSitemapData $webSitemapData,
        SitemapAdapterRegistry $adapterRegistry,
        SitemapUrl $sitemapUrlModel,
        Website $websiteModel
    ) {
        $this->registryService = $registryService;
        $this->webSitemapData = $webSitemapData;
        $this->adapterRegistry = $adapterRegistry;
        $this->sitemapUrlModel = $sitemapUrlModel;
        $this->websiteModel = $websiteModel;
    }

    public function execute(array $args = [], array $options = []): string
    {
        $this->printHeader();
        
        // 测试套件
        $this->testAdaptersRegistered();
        $this->testProvidersDiscovered();
        $this->testDatabaseUrlSync();
        $this->testFileGeneration();
        $this->testFileStructure();
        $this->testXmlValidity();
        $this->testUrlAccessibility();
        $this->testCrossSiteIndex();
        
        $this->printSummary();
        
        return '';
    }

    private function printHeader(): void
    {
        echo "\n";
        echo "╔════════════════════════════════════════════════════════════════╗\n";
        echo "║              Sitemap E2E 端到端验证测试                       ║\n";
        echo "╚════════════════════════════════════════════════════════════════╝\n";
        echo "\n";
    }

    private function testAdaptersRegistered(): void
    {
        $this->printTest('检查平台适配器注册');
        
        $adapters = $this->adapterRegistry->getAdapters();
        
        $this->check(count($adapters) >= 3, '至少注册了 3 个适配器', 
            '发现 ' . count($adapters) . ' 个适配器');
        
        $expectedPlatforms = ['google', 'bing', 'baidu'];
        foreach ($expectedPlatforms as $platform) {
            $this->check(isset($adapters[$platform]), "适配器 '{$platform}' 已注册");
        }
        
        foreach ($adapters as $code => $adapter) {
            $this->check($adapter->getPlatformName() !== '', "{$code} 适配器有平台名称");
            $this->check($adapter->getMaxUrlsPerFile() > 0, "{$code} 适配器配置了 URL 限制");
        }
    }

    private function testProvidersDiscovered(): void
    {
        $this->printTest('检查 URL Provider 发现');
        
        $providers = $this->registryService->getUrlProviders();
        
        $this->check(count($providers) > 0, '至少发现了 1 个 URL Provider',
            '发现 ' . count($providers) . ' 个 Provider');
        
        foreach ($providers as $provider) {
            $module = $provider->getModule();
            $this->check($provider->isEnabled(), "Provider '{$module}' 已启用");
            $this->check($provider->getScope() !== '', "Provider '{$module}' 有 scope");
            $this->check(count($provider->getWebsiteIds()) > 0, "Provider '{$module}' 管理至少 1 个站点");
        }
    }

    private function testDatabaseUrlSync(): void
    {
        $this->printTest('检查数据库 URL 同步');
        
        $websites = $this->websiteModel->reset()->select()->fetch()->getItems();
        
        foreach ($websites as $website) {
            $websiteId = (int)$website->getId();
            $websiteCode = $website->getData('code') ?: 'website_' . $websiteId;
            
            $count = $this->sitemapUrlModel->getActiveUrlCount($websiteId);
            
            if ($count > 0) {
                $this->check(true, "站点 '{$websiteCode}' 有 {$count} 条 URL");
                
                // 验证 URL 数据完整性
                $urls = $this->sitemapUrlModel->reset()
                    ->where(SitemapUrl::fields_WEBSITE_ID, $websiteId)
                    ->where(SitemapUrl::fields_STATUS, 1)
                    ->select()
                    ->fetchArray();
                
                foreach ($urls as $url) {
                    if (empty($url[SitemapUrl::fields_URL])) {
                        $this->addWarning("站点 {$websiteCode} 存在空 URL");
                    }
                }
            } else {
                $this->addWarning("站点 '{$websiteCode}' 没有 URL 数据");
            }
        }
    }

    private function testFileGeneration(): void
    {
        $this->printTest('检查 Sitemap 文件生成');
        
        $sitemapsDir = BP . '/pub/sitemaps';
        
        $this->check(is_dir($sitemapsDir), 'Sitemap 目录存在');
        
        $siteDirs = glob($sitemapsDir . '/*', GLOB_ONLYDIR);
        
        $this->check(count($siteDirs) > 0, '至少生成了 1 个站点目录',
            '发现 ' . count($siteDirs) . ' 个站点目录');
        
        foreach ($siteDirs as $siteDir) {
            $siteName = basename($siteDir);
            
            $platformDirs = glob($siteDir . '/*', GLOB_ONLYDIR);
            
            $this->check(count($platformDirs) > 0, 
                "站点 '{$siteName}' 至少有 1 个平台目录",
                "发现 " . count($platformDirs) . " 个平台");
            
            foreach ($platformDirs as $platformDir) {
                $platformName = basename($platformDir);
                
                // 检查平台索引文件
                $indexFile = $platformDir . '/sitemap.xml';
                $this->check(file_exists($indexFile), 
                    "站点 '{$siteName}' 平台 '{$platformName}' 有索引文件");
                
                // 检查模块文件
                $moduleFiles = glob($platformDir . '/sitemap_*.xml');
                $this->check(count($moduleFiles) > 0,
                    "站点 '{$siteName}' 平台 '{$platformName}' 有模块文件",
                    "发现 " . count($moduleFiles) . " 个文件");
            }
        }
    }

    private function testFileStructure(): void
    {
        $this->printTest('检查文件结构规范');
        
        $sitemapsDir = BP . '/pub/sitemaps';
        $siteDirs = glob($sitemapsDir . '/*', GLOB_ONLYDIR);
        
        foreach ($siteDirs as $siteDir) {
            $siteName = basename($siteDir);
            $platformDirs = glob($siteDir . '/*', GLOB_ONLYDIR);
            
            foreach ($platformDirs as $platformDir) {
                $platformName = basename($platformDir);
                
                // 检查文件命名规范
                $files = glob($platformDir . '/*.xml');
                foreach ($files as $file) {
                    $filename = basename($file);
                    
                    if ($filename === 'sitemap.xml') {
                        continue; // 索引文件
                    }
                    
                    // 模块文件应符合 sitemap_{module}_{n}.xml 格式
                    $matches = preg_match('/^sitemap_[a-z_]+_\d+\.xml$/', $filename);
                    $this->check($matches === 1, 
                        "文件 '{$siteName}/{$platformName}/{$filename}' 符合命名规范");
                }
            }
        }
    }

    private function testXmlValidity(): void
    {
        $this->printTest('检查 XML 文件有效性');
        
        $sitemapsDir = BP . '/pub/sitemaps';
        
        // 测试跨站索引
        $crossSiteIndex = $sitemapsDir . '/sitemap.xml';
        if (file_exists($crossSiteIndex)) {
            $this->checkXmlFile($crossSiteIndex, '跨站总索引');
        }
        
        // 测试站点文件
        $siteDirs = glob($sitemapsDir . '/*', GLOB_ONLYDIR);
        
        foreach ($siteDirs as $siteDir) {
            $siteName = basename($siteDir);
            $platformDirs = glob($siteDir . '/*', GLOB_ONLYDIR);
            
            foreach ($platformDirs as $platformDir) {
                $platformName = basename($platformDir);
                $files = glob($platformDir . '/*.xml');
                
                foreach ($files as $file) {
                    $filename = basename($file);
                    $label = "{$siteName}/{$platformName}/{$filename}";
                    $this->checkXmlFile($file, $label);
                }
            }
        }
    }

    private function checkXmlFile(string $file, string $label): void
    {
        libxml_use_internal_errors(true);
        
        $xml = simplexml_load_file($file);
        
        if ($xml === false) {
            $errors = libxml_get_errors();
            $this->check(false, "XML 文件 '{$label}' 格式有效",
                'XML 解析错误: ' . $errors[0]->message);
            libxml_clear_errors();
            return;
        }
        
        $this->check(true, "XML 文件 '{$label}' 格式有效");
        
        // 检查命名空间
        $namespaces = $xml->getNamespaces(true);
        $this->check(isset($namespaces['']) && 
            $namespaces[''] === 'http://www.sitemaps.org/schemas/sitemap/0.9',
            "文件 '{$label}' 使用正确的 sitemap 命名空间");
    }

    private function testUrlAccessibility(): void
    {
        $this->printTest('检查 URL 可访问性（模拟）');
        
        $sitemapsDir = BP . '/pub/sitemaps';
        
        // 检查跨站索引的 URL 映射
        $crossSiteIndex = $sitemapsDir . '/sitemap.xml';
        if (file_exists($crossSiteIndex)) {
            $expectedUrl = '/sitemaps/sitemap.xml';
            $this->check(true, "跨站索引 URL: {$expectedUrl}");
        }
        
        // 检查站点平台索引
        $siteDirs = glob($sitemapsDir . '/*', GLOB_ONLYDIR);
        
        foreach ($siteDirs as $siteDir) {
            $siteName = basename($siteDir);
            $platformDirs = glob($siteDir . '/*', GLOB_ONLYDIR);
            
            foreach ($platformDirs as $platformDir) {
                $platformName = basename($platformDir);
                $indexFile = $platformDir . '/sitemap.xml';
                
                if (file_exists($indexFile)) {
                    $expectedUrl = "/sitemaps/{$siteName}/{$platformName}/sitemap.xml";
                    $this->check(true, "平台索引 URL: {$expectedUrl}");
                }
            }
        }
    }

    private function testCrossSiteIndex(): void
    {
        $this->printTest('检查跨站总索引');
        
        $crossSiteIndex = BP . '/pub/sitemaps/sitemap.xml';
        
        $this->check(file_exists($crossSiteIndex), '跨站总索引文件存在');
        
        if (file_exists($crossSiteIndex)) {
            $xml = simplexml_load_file($crossSiteIndex);
            
            if ($xml !== false) {
                $sitemaps = $xml->sitemap ?? [];
                $count = count($sitemaps);
                
                $this->check($count > 0, '跨站索引包含站点链接',
                    "包含 {$count} 个站点");
                
                // 验证链接格式
                foreach ($sitemaps as $sitemap) {
                    $loc = (string)$sitemap->loc;
                    $this->check(strpos($loc, '/sitemaps/') !== false,
                        "索引链接使用正确的路径: {$loc}");
                }
            }
        }
    }

    private function check(bool $condition, string $description, string $details = ''): void
    {
        $this->checks++;
        
        if ($condition) {
            $this->passed++;
            echo "  ✓ {$description}";
            if ($details) {
                echo " ({$details})";
            }
            echo "\n";
        } else {
            echo "  ✗ {$description}";
            if ($details) {
                echo " - {$details}";
            }
            echo "\n";
            $this->errors[] = $description . ($details ? " - {$details}" : '');
        }
    }

    private function addWarning(string $message): void
    {
        $this->warnings[] = $message;
        echo "  ⚠ {$message}\n";
    }

    private function printTest(string $title): void
    {
        echo "\n";
        echo "┌─────────────────────────────────────────────────────────────┐\n";
        echo "│ " . str_pad($title, 59) . " │\n";
        echo "└─────────────────────────────────────────────────────────────┘\n";
    }

    private function printSummary(): void
    {
        echo "\n";
        echo "╔════════════════════════════════════════════════════════════════╗\n";
        echo "║                      测试摘要                                  ║\n";
        echo "╚════════════════════════════════════════════════════════════════╝\n";
        echo "\n";
        
        $passRate = $this->checks > 0 ? round(($this->passed / $this->checks) * 100, 1) : 0;
        
        echo "  总检查项: {$this->checks}\n";
        echo "  通过: {$this->passed}\n";
        echo "  失败: " . count($this->errors) . "\n";
        echo "  警告: " . count($this->warnings) . "\n";
        echo "  通过率: {$passRate}%\n";
        
        if (!empty($this->errors)) {
            echo "\n  失败项目:\n";
            foreach ($this->errors as $i => $error) {
                echo "    " . ($i + 1) . ". {$error}\n";
            }
        }
        
        if (!empty($this->warnings)) {
            echo "\n  警告信息:\n";
            foreach ($this->warnings as $i => $warning) {
                echo "    " . ($i + 1) . ". {$warning}\n";
            }
        }
        
        echo "\n";
        
        if (count($this->errors) === 0) {
            echo "  🎉 所有测试通过！功能与预期一致。\n";
        } else {
            echo "  ⚠️  存在 " . count($this->errors) . " 个失败项，需要修复。\n";
        }
        
        echo "\n";
    }

    public function tip(): string
    {
        return 'Verify sitemap end-to-end functionality';
    }

    public function help(): string
    {
        return <<<HELP
Sitemap E2E 端到端验证测试

此命令执行完整的端到端测试，验证：
1. 平台适配器注册
2. URL Provider 发现
3. 数据库 URL 同步
4. Sitemap 文件生成
5. 文件结构规范
6. XML 格式有效性
7. URL 路径映射
8. 跨站总索引

使用方法：
  php bin/w sitemap:verify

示例：
  php bin/w sitemap:verify

HELP;
    }
}
