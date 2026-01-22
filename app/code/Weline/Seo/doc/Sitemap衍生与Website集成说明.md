## Sitemap 衍生与 Website 集成说明

### 1. 角色划分

- **Seo 模块**：
  - 定义 `SitemapProviderInterface`（衍生接口），统一从各站点模块收集 Sitemap URL；
  - 在 `extends.php` 中定义 `SitemapProvider` 扩展点（`extends/module/Weline_Seo/SitemapProvider`），通过 `ExtendsData` 自动收集实现类；
  - 提供定时任务 `Cron\SitemapSubmit`，按账户、scope、module 定时提交 Sitemap 到搜索引擎；
  - 通过 `SeoAccount` + 适配器（如 `google_indexing_api`）完成真正的 API 调用。
- **Website / 其他站点模块**：
  - 在 `extends/module/Weline_Seo/SitemapProvider/` 目录下实现 `Weline\Seo\Interface\SitemapProviderInterface`；
  - 在实现类中一次性生成 Sitemap 文件（或更新），返回可访问的 Sitemap URL 列表；
  - 无需显式事件注册，只要放在约定的 extends 目录即可被 Seo 自动发现。

### 2. Sitemap 衍生接口

接口定义：`app/code/Weline/Seo/Interface/SitemapProviderInterface.php`：

```php
namespace Weline\Seo\Interface;

interface SitemapProviderInterface
{
    public function getScope(): string;       // 例如 website、page_builder、blog
    public function getModule(): string;      // 例如 Weline_Websites、GuoLaiRen_PageBuilder
    public function generateSitemaps(): array; // 返回 Sitemap URL 数组
    public function getDescription(): string;
}
```

所有站点模块只需要实现这个接口即可被 Seo 的定时任务发现和调用。

### 3. Seo 定时任务如何调用衍生类

`Cron\SitemapSubmit` 的核心流程（简化说明）：

1. 读取启用 `enable_cron_sitemap = 1` 的 `SeoAccount`（按 `scope/module/provider` 区分）；  
2. 通过 `SitemapRegistryService` 使用 `ExtendsData` 读取 `extends/module/Weline_Seo/SitemapProvider/` 下的所有实现类；
3. 对每个账户：
   - 过滤出 `getScope() === account.scope && getModule() === account.module` 的 provider；
   - 调用 `generateSitemaps()` 生成/更新 Sitemap 并返回 URL 列表；
   - 若衍生类未返回 URL，则回退读取账户 `config_json` 中的 `sitemaps` 或 `sitemap` 字段；
   - 使用账户 `provider` 对应的适配器（如 `GoogleIndexingApiAdapter`）调用 `submitSitemap()` 提交这些 URL。

### 4. Website 模块实现示例（代码示意）

假设你在 `Weline_Websites` 模块中实现一个 Sitemap 生成器：

```php
namespace Weline\Websites\Seo;

use Weline\Seo\Interface\SitemapProviderInterface;

class WebsiteSitemapProvider implements SitemapProviderInterface
{
    public function getScope(): string
    {
        return 'website';
    }

    public function getModule(): string
    {
        return 'Weline_Websites';
    }

    public function generateSitemaps(): array
    {
        // 这里生成（或更新）Sitemap文件，并返回可访问URL
        // 伪代码：生成 sitemap.xml 到 public 目录
        $baseUrl = 'https://example.com/';
        // ... 生成 sitemap.xml 逻辑 ...

        return [
            $baseUrl . 'sitemap.xml',
        ];
    }

    public function getDescription(): string
    {
        return '主站点 Sitemap 生成器';
    }
}
```

### 5. Website 模块注册衍生类（通过 extends 目录）

在 `Weline_Websites` 模块中，只需要在模块根目录下创建：  
`extends/module/Weline_Seo/SitemapProvider/WebsiteSitemapProvider.php`，并实现上面的接口即可。

Seo 的 `ExtendsScanner` 会在扫描 `extends.php` 后，自动将该目录下的类注册为 `Weline_Seo` 的 `SitemapProvider` 扩展，`SitemapRegistryService` 再通过 `ExtendsData` 统一读取这些实现。这样，在 Seo 的 `Cron\SitemapSubmit` 执行时，就会自动发现 `WebsiteSitemapProvider`，调用其 `generateSitemaps()` 生成 sitemap，并根据对应 `scope=website`、`module=Weline_Websites` 的 `SeoAccount` 配置，使用适配器（如 Google Indexing API）进行提交。

