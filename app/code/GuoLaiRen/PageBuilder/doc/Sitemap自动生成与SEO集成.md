# PageBuilder Sitemap 自动生成与 SEO 集成

## 概述

PageBuilder 模块集成了 Weline_Seo 模块的 Sitemap 扩展机制，支持自动生成和提交 Sitemap。通过这一机制，所有已发布的页面会自动包含在站点的 sitemap.xml 中，并可通过 SEO 模块自动提交到搜索引擎。

## 功能特性

- **自动生成**：按站点生成 sitemap.xml，包含所有已发布页面
- **自动提交**：通过 SEO 模块的定时任务自动提交到搜索引擎
- **多站点支持**：每个站点独立生成 sitemap
- **智能优先级**：根据页面类型自动设置 priority 和 changefreq
- **URL 推送**：页面发布时自动请求 URL 推送

## 文件结构

```
GuoLaiRen/PageBuilder/
├── extends/
│   └── module/
│       └── Weline_Seo/
│           └── SitemapProvider/
│               └── PageBuilderSitemapProvider.php    # Sitemap 提供者
├── Service/
│   └── SitemapService.php                            # Sitemap 生成服务
├── Model/
│   └── Page.php                                      # 页面模型（含 save_after 钩子）
└── doc/
    └── Sitemap自动生成与SEO集成.md                    # 本文档
```

## 工作原理

### 1. Sitemap 生成流程

```
┌──────────────────────────────────────────────────────────────────┐
│                    Sitemap 生成流程                               │
├──────────────────────────────────────────────────────────────────┤
│                                                                  │
│   ┌─────────────┐      ┌─────────────┐      ┌─────────────┐     │
│   │ SEO 定时任务 │ ──→ │ Registry    │ ──→ │ PageBuilder │     │
│   │ 或手动触发   │      │ Service     │      │ Provider    │     │
│   └─────────────┘      └─────────────┘      └──────┬──────┘     │
│                                                     │            │
│                                                     ▼            │
│                                              ┌─────────────┐     │
│                                              │ Sitemap     │     │
│                                              │ Service     │     │
│                                              └──────┬──────┘     │
│                                                     │            │
│        ┌────────────────────────────────────────────┼────────┐   │
│        ▼                        ▼                   ▼        │   │
│  ┌──────────┐          ┌──────────────┐      ┌──────────┐   │   │
│  │ 站点 1   │          │ 站点 2       │      │ 站点 N   │   │   │
│  │ sitemap  │          │ sitemap      │      │ sitemap  │   │   │
│  └──────────┘          └──────────────┘      └──────────┘   │   │
│        │                        │                   │        │   │
│        └────────────────────────┼───────────────────┘        │   │
│                                 ▼                             │   │
│                          ┌─────────────┐                      │   │
│                          │ pub/sitemaps│                      │   │
│                          │ /{code}/    │                      │   │
│                          │ sitemap.xml │                      │   │
│                          └─────────────┘                      │   │
└──────────────────────────────────────────────────────────────────┘
```

### 2. URL 推送流程

当页面保存并发布时，`Page` 模型的 `save_after` 钩子会自动调用 SEO 模块的 URL 推送服务：

```php
// Page.php save_after 钩子
public function save_after(): void
{
    parent::save_after();
    
    // 仅当页面为已发布状态时，提交 URL
    if ($this->getData(self::fields_STATUS) !== self::STATUS_PUBLISHED) {
        return;
    }
    
    // 调用 SEO 模块的 URL 推送服务
    $urlSubmitService = ObjectManager::getInstance(UrlSubmitService::class);
    $urlSubmitService->requestSubmit(
        $this->getFullUrl(),
        'page_builder',
        'GuoLaiRen_PageBuilder',
        ['subject_type' => 'page', 'subject_id' => $this->getId()]
    );
}
```

## 页面优先级配置

根据页面类型自动设置 Sitemap 的 priority 和 changefreq：

| 页面类型 | Priority | Changefreq |
|---------|----------|------------|
| 首页 (home_page) | 1.0 | daily |
| 关于/联系 | 0.8 | monthly |
| 博客列表 | 0.7 | daily |
| 博客文章 | 0.6 | weekly |
| 隐私政策/服务条款 | 0.3 | monthly |
| 退款/配送/Cookie政策 | 0.2 | monthly |
| 其他页面 | 0.5 | monthly |

## 生成的文件

### 目录结构

```
pub/
└── sitemaps/
    ├── website_code_1/
    │   └── sitemap.xml
    ├── website_code_2/
    │   └── sitemap.xml
    └── ...
```

### Sitemap 格式示例

```xml
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc>https://example.com/</loc>
    <lastmod>2026-01-29</lastmod>
    <changefreq>daily</changefreq>
    <priority>1.0</priority>
  </url>
  <url>
    <loc>https://example.com/about</loc>
    <lastmod>2026-01-20</lastmod>
    <changefreq>monthly</changefreq>
    <priority>0.8</priority>
  </url>
</urlset>
```

## 使用方式

### 1. 自动生成（推荐）

SEO 模块的定时任务每天凌晨 3 点自动执行：

1. 扫描所有注册的 SitemapProvider
2. 调用 PageBuilder 的 Provider 生成 sitemap
3. 根据 SEO 账户配置提交到搜索引擎

### 2. 手动生成

访问后台 **SEO管理 > Sitemap管理**：

1. 在 **Sitemap 生成器** 区域找到 `GuoLaiRen_PageBuilder`
2. 点击 **生成** 按钮
3. 或点击 **调用所有生成器** 一次性生成所有模块的 sitemap

### 3. 程序化调用

```php
use GuoLaiRen\PageBuilder\Service\SitemapService;
use Weline\Framework\Manager\ObjectManager;

$sitemapService = ObjectManager::getInstance(SitemapService::class);

// 为指定站点生成
$url = $sitemapService->generateForWebsite($websiteId);

// 为所有站点生成
$urls = $sitemapService->generateForAllWebsites();

// 获取站点的 Sitemap URL
$url = $sitemapService->getSitemapUrl($websiteId);

// 获取站点的 Sitemap 文件路径
$path = $sitemapService->getSitemapPath($websiteId);
```

## SEO 账户配置

要启用自动提交到搜索引擎，需在 **SEO管理 > 账户管理** 中配置：

| 配置项 | 值 |
|-------|-----|
| Scope | page_builder |
| Module | GuoLaiRen_PageBuilder |
| 启用定时Sitemap提交 | 是 |
| Provider | google_indexing_api（或其他适配器） |

## API 参考

### SitemapService

| 方法 | 说明 | 返回值 |
|------|------|--------|
| `generateForWebsite(int $websiteId)` | 为指定站点生成 Sitemap | `string\|null` URL |
| `generateForAllWebsites()` | 为所有站点生成 Sitemap | `string[]` URL 数组 |
| `getSitemapUrl(int $websiteId)` | 获取站点的 Sitemap URL | `string\|null` |
| `getSitemapPath(int $websiteId)` | 获取站点的 Sitemap 文件路径 | `string\|null` |

### PageBuilderSitemapProvider

| 方法 | 说明 | 返回值 |
|------|------|--------|
| `getScope()` | 返回 'page_builder' | `string` |
| `getModule()` | 返回 'GuoLaiRen_PageBuilder' | `string` |
| `generateSitemaps()` | 生成所有站点的 Sitemap | `string[]` URL 数组 |
| `getDescription()` | 返回描述信息 | `string` |

## 故障排查

### Sitemap 未生成

1. 检查站点是否有已发布的页面
2. 检查 `pub/sitemaps` 目录写入权限
3. 查看错误日志：`var/log/system.log`

### URL 未提交

1. 检查 SEO 账户配置是否正确
2. 检查 scope 和 module 是否匹配
3. 检查适配器（如 Google Indexing API）凭据

### Provider 未被发现

1. 确认文件路径正确：`extends/module/Weline_Seo/SitemapProvider/`
2. 运行 `php bin/m deploy:mode:set` 刷新扩展缓存
3. 检查类是否正确实现 `SitemapProviderInterface`

## 相关文档

- [Weline_Seo Sitemap 扩展开发指南](../../../Weline/Seo/doc/Sitemap扩展开发指南.md)
- [Weline_Seo Sitemap 衍生与 Website 集成说明](../../../Weline/Seo/doc/Sitemap衍生与Website集成说明.md)
