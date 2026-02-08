# Weline_Cdn 模块扩展文档

## 概述

Weline_Cdn 模块提供了两个扩展点，允许其他模块扩展 CDN 功能：

1. **WarmupProvider** - CDN 缓存预热 URL 提供者，用于收集需要预热的 URL 列表
2. **Adapter** - CDN 适配器，用于扩展缓存清理和规则管理功能（如本地内存缓存、Redis 缓存等）

## 快速开始

### 创建 WarmupProvider

1. 在您的模块中创建扩展目录：`extends/module/Weline_Cdn/`
2. 创建PHP文件（文件名可自定义，如 `ProductUrls.php`）
3. 实现 `execute()` 静态方法，返回URL数组

### 创建 Adapter（CDN 适配器）

1. 在您的模块中创建扩展目录：`extends/module/Weline_Cdn/Adapter/`
2. 创建 PHP 文件（如 `WlsMemory.php`）
3. 实现 `AdapterInterface` 接口的所有方法

## 详细说明

### WarmupProvider 扩展点

**路径**: `extends/module/Weline_Cdn`

**支持类型**: `module`（模块级）

**用途**: 提供CDN缓存预热所需的URL列表

**要求**:
- 文件必须位于 `extends/module/Weline_Cdn/` 目录下
- 必须实现 `WarmupProviderInterface` 接口
- 必须实现 `execute()` 静态方法
- `execute()` 方法必须返回数组格式的URL列表

### 模块级 WarmupProvider

**路径格式**: `extends/module/Weline_Cdn/{Provider文件名}.php`

**示例**:
- Provider文件: `extends/module/Weline_Cdn/ProductUrls.php`
- Provider文件: `extends/module/Weline_Cdn/ArticleUrls.php`

**适用场景**: 
- 为特定模块提供预热URL
- 根据业务逻辑动态生成预热URL列表

## 实现规范

### 基本结构

```php
<?php

declare(strict_types=1);

namespace Vendor\Module\Extends\Weline_Cdn;

use Weline\Cdn\Api\WarmupProviderInterface;

class YourWarmupProvider implements WarmupProviderInterface
{
    /**
     * 执行并返回预热URL列表
     * 
     * @return array URL数组，可以是简单字符串数组或详细数组
     */
    public static function execute(): array
    {
        return [
            'https://example.com/page1',
            'https://example.com/page2',
            // 或使用详细格式
            [
                'url' => 'https://example.com/page3',
                'site_id' => 1
            ]
        ];
    }
}
```

### 返回值格式

#### 简单格式（字符串数组）

```php
public static function execute(): array
{
    return [
        'https://example.com/page1',
        'https://example.com/page2',
        'https://example.com/page3'
    ];
}
```

#### 详细格式（关联数组）

```php
public static function execute(): array
{
    return [
        [
            'url' => 'https://example.com/page1',
            'site_id' => 1
        ],
        [
            'url' => 'https://example.com/page2',
            'site_id' => 2
        ]
    ];
}
```

### 命名空间规范

Provider类的命名空间应该遵循以下规则：

- 基础命名空间：`Vendor\Module\Extends\Weline_Cdn`
- 类名：与文件名相同（不含扩展名）
- 示例：文件 `ProductUrls.php` → 类名 `ProductUrls` → 完整命名空间 `Vendor\Module\Extends\Weline_Cdn\ProductUrls`

## 完整示例

### 示例1：产品URL提供者

```php
<?php

declare(strict_types=1);

namespace Weline\Eav\Extends\Weline_Cdn;

use Weline\Cdn\Api\WarmupProviderInterface;
use Weline\Eav\Model\Product;

class ProductUrls implements WarmupProviderInterface
{
    /**
     * 获取所有产品的URL
     * 
     * @return array
     */
    public static function execute(): array
    {
        $urls = [];
        $productModel = ObjectManager::getInstance(Product::class);
        $products = $productModel->select()->fetch();
        
        foreach ($products as $product) {
            $urls[] = '/product/' . $product->getId() . '.html';
        }
        
        return $urls;
    }
}
```

### 示例2：文章URL提供者

```php
<?php

declare(strict_types=1);

namespace Weline\Cms\Extends\Weline_Cdn;

use Weline\Cdn\Api\WarmupProviderInterface;
use Weline\Cms\Model\Article;
use Weline\Framework\Manager\ObjectManager;

class ArticleUrls implements WarmupProviderInterface
{
    /**
     * 获取热门文章的URL
     * 
     * @return array
     */
    public static function execute(): array
    {
        $urls = [];
        $articleModel = ObjectManager::getInstance(Article::class);
        $articles = $articleModel->select()
            ->where('status', 1)
            ->where('is_hot', 1)
            ->limit(100)
            ->fetch();
        
        foreach ($articles as $article) {
            $urls[] = [
                'url' => '/article/' . $article->getId() . '.html',
                'site_id' => $article->getSiteId()
            ];
        }
        
        return $urls;
    }
}
```

### 示例3：分类页面URL提供者

```php
<?php

declare(strict_types=1);

namespace Weline\Catalog\Extends\Weline_Cdn;

use Weline\Cdn\Api\WarmupProviderInterface;
use Weline\Catalog\Model\Category;
use Weline\Framework\Manager\ObjectManager;

class CategoryUrls implements WarmupProviderInterface
{
    /**
     * 获取所有分类页面的URL
     * 
     * @return array
     */
    public static function execute(): array
    {
        $urls = [];
        $categoryModel = ObjectManager::getInstance(Category::class);
        $categories = $categoryModel->select()->fetch();
        
        foreach ($categories as $category) {
            $urls[] = '/category/' . $category->getId() . '.html';
        }
        
        return $urls;
    }
}
```

## 最佳实践

### 1. 文件命名规范

- 使用描述性的文件名，如 `ProductUrls.php`、`ArticleUrls.php`
- 文件名应该反映Provider的功能
- 使用驼峰命名法（PascalCase）

### 2. 性能优化

- 避免在 `execute()` 方法中执行耗时操作
- 对于大量URL，考虑分批返回或使用生成器
- 缓存频繁访问的数据

### 3. 错误处理

- 在 `execute()` 方法中添加 try-catch 错误处理
- 确保方法始终返回数组（即使为空数组）
- 记录错误日志以便调试

### 4. URL格式

- 使用绝对URL（包含协议和域名）或相对路径
- 确保URL格式正确，避免无效URL
- 对于多站点系统，使用详细格式包含 `site_id`

### 5. 数据量控制

- 避免返回过多的URL（建议单次不超过1000个）
- 对于大量URL，考虑分多个Provider文件
- 优先返回重要的、访问频率高的URL

## 工作流程

1. **扫描阶段**: CDN模块的 `WarmupProviderScanner` 会扫描所有模块的 `extends/module/Weline_Cdn/` 目录
2. **加载阶段**: 自动加载找到的Provider类
3. **执行阶段**: 调用每个Provider的 `execute()` 方法收集URL
4. **合并阶段**: 将所有Provider返回的URL合并到一个数组中
5. **去重阶段**: 自动去除重复的URL
6. **投递阶段**: 通过 `Weline_Cdn::send_warmup` 事件投递URL列表
7. **执行阶段**: `WarmupRunner` 执行实际的预热请求

## 常见问题

### Q: 如何知道我的 Provider 是否被正确加载？

A: 系统会在定时任务执行时自动扫描并加载Provider。您可以在CDN预热管理界面查看收集到的URL数量。

### Q: Provider 执行失败会怎样？

A: 如果某个Provider执行失败，系统会记录错误日志，但不会影响其他Provider的执行。预热任务会继续处理其他Provider返回的URL。

### Q: 可以返回多少个URL？

A: 理论上没有限制，但建议单次返回不超过1000个URL。对于大量URL，建议分多个Provider文件或分批返回。

### Q: URL格式有什么要求？

A: URL可以是绝对URL（如 `https://example.com/page.html`）或相对路径（如 `/page.html`）。系统会根据配置自动转换为完整的URL。

### Q: 如何指定站点ID？

A: 使用详细格式返回URL，包含 `site_id` 字段：
```php
return [
    [
        'url' => '/page.html',
        'site_id' => 1
    ]
];
```

## 注册表信息

系统会在 `generated/extends.php` 文件中记录所有扩展信息，您可以通过以下方式查看：

1. **命令行方式**: 使用 `extends:scan` 命令扫描
2. **管理界面**: 在后台管理中查看扩展管理
3. **直接查看**: 检查 `generated/extends.php` 文件

## 相关文档

- [Weline_Cdn 完整文档](doc/README.md)
- [CDN预热URL投递事件](doc/event/CDN预热URL投递.md)
- [CDN模块计划文档](计划.md)

---

## Adapter 扩展点（CDN 适配器）

### 概述

Adapter 扩展点允许其他模块注册 CDN 适配器，以响应 CDN 模块的缓存清理和规则管理操作。例如，WLS（Weline Server）可以注册内存缓存适配器，当 CDN 模块调用 `purgeUrls()` 时，WLS 适配器会清理内存中的对应缓存。

### 路径

`extends/module/Weline_Cdn/Adapter/`

### 接口

必须实现 `Weline\Cdn\Api\AdapterInterface` 接口。

### 接口方法

| 方法 | 说明 |
|------|------|
| `getAdapterCode(): string` | 返回适配器唯一标识（如 `wls_memory`） |
| `getAdapterName(): string` | 返回适配器显示名称 |
| `getDescription(): string` | 返回适配器描述 |
| `getVersion(): string` | 返回适配器版本 |
| `purgeEverything(string $zoneId, array $credentials): array` | 清理所有缓存 |
| `purgeUrls(string $zoneId, array $urls, array $credentials): array` | 按 URL 清理缓存 |
| `purgeHosts(string $zoneId, array $hosts, array $credentials): array` | 按 Host 清理缓存 |
| `purgeTags(string $zoneId, array $tags, array $credentials): array` | 按 Tag 清理缓存 |
| `purgeCacheKeys(string $zoneId, array $keys, array $credentials): array` | 按 Cache Key 清理缓存 |
| `getRules(string $zoneId, array $credentials): array` | 获取缓存规则 |
| `putRules(string $zoneId, array $rules, array $credentials): array` | 推送缓存规则 |
| `ensureZone(string $domain, array $credentials): array` | 确保 Zone 存在 |

### 目录结构

```
app/code/YourVendor/YourModule/
└── extends/
    └── module/
        └── Weline_Cdn/
            └── Adapter/
                └── YourAdapter.php
```

### 命名空间规范

- 基础命名空间：`YourVendor\YourModule\Extends\Module\Weline_Cdn\Adapter`
- 类名：与文件名相同（不含扩展名）
- 示例：文件 `WlsMemory.php` → 类名 `WlsMemory` → 完整命名空间 `Weline\Server\Extends\Module\Weline_Cdn\Adapter\WlsMemory`

### 完整示例

```php
<?php

declare(strict_types=1);

namespace Weline\Server\Extends\Module\Weline_Cdn\Adapter;

use Weline\Cdn\Api\AdapterInterface;
use Weline\Server\Service\MemoryCacheService;

/**
 * WLS 内存缓存 CDN 适配器
 * 
 * 通过 extends 规约注册到 Weline_Cdn 模块
 */
class WlsMemory implements AdapterInterface
{
    public function getAdapterCode(): string
    {
        return 'wls_memory';
    }
    
    public function getAdapterName(): string
    {
        return __('WLS 内存缓存');
    }
    
    public function getDescription(): string
    {
        return __('Weline Server 本地内存全页缓存');
    }
    
    public function getVersion(): string
    {
        return '1.0.0';
    }
    
    public function purgeEverything(string $zoneId, array $credentials): array
    {
        MemoryCacheService::purgeAll();
        return ['success' => true, 'message' => __('WLS 内存缓存已清理')];
    }
    
    public function purgeUrls(string $zoneId, array $urls, array $credentials): array
    {
        $count = 0;
        foreach ($urls as $url) {
            if (MemoryCacheService::purgeByUrl($url)) {
                $count++;
            }
        }
        return ['success' => true, 'message' => __('已清理 %{count} 个 URL', ['count' => $count])];
    }
    
    public function purgeHosts(string $zoneId, array $hosts, array $credentials): array
    {
        $count = 0;
        foreach ($hosts as $host) {
            $count += MemoryCacheService::purgeByHost($host);
        }
        return ['success' => true, 'message' => __('已清理 %{count} 个 Host', ['count' => $count])];
    }
    
    public function purgeTags(string $zoneId, array $tags, array $credentials): array
    {
        $count = 0;
        foreach ($tags as $tag) {
            $count += MemoryCacheService::purgeByTag($tag);
        }
        return ['success' => true, 'message' => __('已清理 %{count} 个 Tag', ['count' => $count])];
    }
    
    public function purgeCacheKeys(string $zoneId, array $keys, array $credentials): array
    {
        $count = 0;
        foreach ($keys as $key) {
            if (MemoryCacheService::purgeByKey($key)) {
                $count++;
            }
        }
        return ['success' => true, 'message' => __('已清理 %{count} 个 Cache Key', ['count' => $count])];
    }
    
    public function getRules(string $zoneId, array $credentials): array
    {
        return MemoryCacheRuleManager::getInstance()->loadRules();
    }
    
    public function putRules(string $zoneId, array $rules, array $credentials): array
    {
        MemoryCacheRuleManager::getInstance()->updateRules($rules);
        return ['success' => true, 'message' => __('规则已更新')];
    }
    
    public function ensureZone(string $domain, array $credentials): array
    {
        // 本地内存缓存不需要 Zone 概念，直接返回虚拟 Zone
        return [
            'zone_id' => 'wls_local',
            'zone_name' => $domain
        ];
    }
}
```

### 适用场景

- **本地内存缓存**：WLS 内存缓存适配器，响应 CDN 模块的缓存清理请求
- **Redis 缓存**：Redis 缓存适配器，用于分布式缓存清理
- **其他 CDN 服务商**：接入其他 CDN 服务商（如阿里云 CDN、腾讯云 CDN 等）

### 工作流程

1. **注册阶段**：`setup:upgrade` 或框架启动时，`ExtendsScanner` 扫描模块的 `extends.php`，发现并注册适配器
2. **加载阶段**：`AdapterResolver` 通过 `ExtendsData::getExtendedBy('Weline_Cdn')` 获取所有已注册的适配器
3. **调用阶段**：当 CDN 模块需要清理缓存或更新规则时，调用所有适配器的对应方法

### 常见问题

#### Q: 如何知道我的适配器是否被正确注册？

A: 运行 `php bin/w s:up` 后，系统会扫描并注册适配器。您可以查看 `generated/extends.php` 文件确认注册信息。

#### Q: 适配器的 purge 方法失败会怎样？

A: 每个适配器的 purge 方法应返回包含 `success` 和 `message` 字段的数组。如果失败，CDN 模块会记录错误日志，但不会影响其他适配器的执行。

#### Q: 如何处理不支持的功能？

A: 对于不支持的功能（如本地缓存不支持 Zone 概念），可以返回一个表示成功的结果或抛出异常。建议返回成功结果以避免阻断其他适配器的执行。

---

## 支持和贡献

如有问题或建议，请通过以下方式联系：

- 邮箱: aiweline@qq.com
- 论坛: https://bbs.aiweline.com
- 文档: 查看模块的 doc/ 目录

