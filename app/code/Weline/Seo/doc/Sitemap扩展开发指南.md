# Sitemap 扩展开发指南

## 概述

Weline_Seo 模块提供了灵活的 Sitemap 扩展机制，允许其他模块通过实现 `SitemapProviderInterface` 接口来注册自己的 Sitemap 生成器。SEO 模块的定时任务会自动发现并调用这些生成器，生成对应的 sitemap.xml 文件并提交到搜索引擎。

## 架构设计

```
┌─────────────────────────────────────────────────────────────────┐
│                        Weline_Seo 模块                           │
├─────────────────────────────────────────────────────────────────┤
│  SitemapProviderInterface          定义标准接口                   │
│  SitemapRegistryService           收集所有 Provider 实现          │
│  Cron/SitemapSubmit               定时任务，调用 Provider 生成     │
│  Controller/Backend/Sitemap       后台管理界面                    │
└─────────────────────────────────────────────────────────────────┘
                              ▲
                              │ extends 扩展点
                              │
    ┌─────────────────────────┼─────────────────────────┐
    │                         │                         │
┌───┴───────────────┐   ┌─────┴─────────────┐   ┌───────┴─────────┐
│ GuoLaiRen_PageBuilder │   │ Weline_Websites  │   │   其他模块...    │
│                       │   │                  │   │                 │
│ PageBuilderSitemap    │   │ WebsiteSitemap   │   │ XxxSitemap      │
│ Provider              │   │ Provider         │   │ Provider        │
└───────────────────────┘   └──────────────────┘   └─────────────────┘
```

## 核心接口

### SitemapProviderInterface

路径：`app/code/Weline/Seo/Interface/SitemapProviderInterface.php`

```php
<?php
namespace Weline\Seo\Interface;

interface SitemapProviderInterface
{
    /**
     * 返回该 Sitemap 提供者所属的 scope
     * 例如：page_builder、website、blog、product
     */
    public function getScope(): string;

    /**
     * 返回该 Sitemap 提供者所属的模块名称
     * 例如：Weline_Websites、GuoLaiRen_PageBuilder
     */
    public function getModule(): string;

    /**
     * 生成 Sitemap 并返回可访问的 URL 列表
     * 
     * @return string[] Sitemap URL 数组
     */
    public function generateSitemaps(): array;

    /**
     * 返回该提供者的描述信息，用于后台显示
     */
    public function getDescription(): string;
}
```

## 快速开始

### 步骤 1：创建 SitemapProvider 类

在你的模块中创建扩展目录和 Provider 类：

```
your_module/
├── extends/
│   └── module/
│       └── Weline_Seo/
│           └── SitemapProvider/
│               └── YourSitemapProvider.php
```

### 步骤 2：实现接口

```php
<?php
declare(strict_types=1);

namespace YourVendor\YourModule\Extends\Weline_Seo\SitemapProvider;

use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Interface\SitemapProviderInterface;
use Weline\Websites\Model\Website;

class YourSitemapProvider implements SitemapProviderInterface
{
    private const SITEMAP_DIR = 'pub/sitemaps';
    
    public function getScope(): string
    {
        return 'your_scope';  // 例如：blog、product、page
    }
    
    public function getModule(): string
    {
        return 'YourVendor_YourModule';
    }
    
    public function generateSitemaps(): array
    {
        $sitemapUrls = [];
        
        try {
            // 获取所有站点
            $websiteModel = ObjectManager::getInstance(Website::class);
            $websites = $websiteModel->select()->fetch()->getItems();
            
            foreach ($websites as $website) {
                $websiteId = (int)$website->getId();
                $url = $this->generateForWebsite($website);
                if ($url) {
                    $sitemapUrls[] = $url;
                }
            }
        } catch (\Throwable $e) {
            // 记录错误但不中断
            if (defined('DEV') && DEV) {
                error_log('YourSitemapProvider error: ' . $e->getMessage());
            }
        }
        
        return $sitemapUrls;
    }
    
    private function generateForWebsite($website): ?string
    {
        // 1. 收集该站点的 URL 列表
        $urls = $this->collectUrls($website);
        
        if (empty($urls)) {
            return null;
        }
        
        // 2. 生成 XML 内容
        $xml = $this->buildSitemapXml($urls);
        
        // 3. 保存文件
        $websiteCode = $website->getCode() ?: ('website_' . $website->getId());
        $dir = BP . DS . self::SITEMAP_DIR . DS . $websiteCode;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        $filePath = $dir . DS . 'sitemap.xml';
        file_put_contents($filePath, $xml);
        
        // 4. 返回可访问的 URL
        $baseUrl = rtrim($website->getUrl(), '/');
        return $baseUrl . '/sitemaps/' . $websiteCode . '/sitemap.xml';
    }
    
    private function collectUrls($website): array
    {
        // 实现你的 URL 收集逻辑
        // 返回格式：[['loc' => 'url', 'lastmod' => 'date', 'priority' => '0.8'], ...]
        return [];
    }
    
    private function buildSitemapXml(array $urls): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        
        foreach ($urls as $url) {
            $xml .= "  <url>\n";
            $xml .= "    <loc>" . htmlspecialchars($url['loc']) . "</loc>\n";
            if (isset($url['lastmod'])) {
                $xml .= "    <lastmod>{$url['lastmod']}</lastmod>\n";
            }
            if (isset($url['changefreq'])) {
                $xml .= "    <changefreq>{$url['changefreq']}</changefreq>\n";
            }
            if (isset($url['priority'])) {
                $xml .= "    <priority>{$url['priority']}</priority>\n";
            }
            $xml .= "  </url>\n";
        }
        
        $xml .= '</urlset>';
        return $xml;
    }
    
    public function getDescription(): string
    {
        return __('您的模块 Sitemap 生成器描述');
    }
}
```

### 步骤 3：自动发现

无需额外配置！SEO 模块会通过 `extends` 扩展点自动扫描并注册你的 Provider。

## 生成方式

### 1. 自动生成（定时任务）

SEO 模块提供定时任务 `Cron/SitemapSubmit`，每天凌晨 3 点自动执行：

1. 读取启用 `enable_cron_sitemap = 1` 的 SEO 账户
2. 根据账户的 `scope` 和 `module` 过滤对应的 Provider
3. 调用 `generateSitemaps()` 生成 Sitemap
4. 使用账户配置的适配器提交到搜索引擎

### 2. 手动生成（后台管理）

访问后台 **SEO管理 > Sitemap管理**：

- **手动生成**：按站点生成基础 sitemap 到 pub 目录
- **Sitemap 生成器**：调用指定模块的 Provider 生成
- **调用所有生成器**：一次性调用所有注册的 Provider

### 3. 程序化调用

```php
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Service\SitemapRegistryService;

// 获取所有 Provider
$registry = ObjectManager::getInstance(SitemapRegistryService::class);
$providers = $registry->getProviders();

// 按 scope/module 过滤
$filtered = $registry->getProvidersByScopeModule('page_builder', 'GuoLaiRen_PageBuilder');

// 调用生成
foreach ($providers as $provider) {
    $sitemapUrls = $provider->generateSitemaps();
    // 处理生成的 URL...
}
```

## 文件存储规范

### 目录结构

```
pub/
├── sitemap.xml                    # 主索引文件
├── sitemap_1.xml                  # 手动生成（按站点ID）
├── sitemap_2.xml
└── sitemaps/                      # 模块生成目录
    ├── website_code_1/
    │   └── sitemap.xml
    ├── website_code_2/
    │   └── sitemap.xml
    └── ...
```

### 命名规范

- 手动生成：`sitemap_{website_id}.xml` 或 `sitemap_{website_id}_{part}.xml`（分割时）
- 模块生成：`pub/sitemaps/{website_code}/sitemap.xml`
- 索引文件：`pub/sitemap.xml`

## SEO 账户配置

在 **SEO管理 > 账户管理** 中配置：

| 字段 | 说明 |
|------|------|
| scope | 对应 Provider 的 `getScope()` 返回值 |
| module | 对应 Provider 的 `getModule()` 返回值 |
| enable_cron_sitemap | 是否启用定时提交 |
| provider | 搜索引擎适配器（如 google_indexing_api） |

## 最佳实践

1. **单一职责**：每个 Provider 只负责一种类型的内容
2. **错误处理**：`generateSitemaps()` 应捕获异常，返回空数组而非抛出
3. **增量生成**：对于大量 URL，考虑分割成多个文件（每个最多 50000 条）
4. **缓存优化**：可以缓存生成结果，避免频繁 IO
5. **日志记录**：在开发模式下记录错误日志

## 相关文件

- `Interface/SitemapProviderInterface.php` - 接口定义
- `Service/SitemapRegistryService.php` - Provider 注册服务
- `Cron/SitemapSubmit.php` - 定时提交任务
- `Controller/Backend/Sitemap.php` - 后台管理控制器
- `doc/Sitemap衍生与Website集成说明.md` - 集成说明

## 示例模块

参考 PageBuilder 模块的实现：

- `GuoLaiRen/PageBuilder/extends/module/Weline_Seo/SitemapProvider/PageBuilderSitemapProvider.php`
- `GuoLaiRen/PageBuilder/Service/SitemapService.php`
