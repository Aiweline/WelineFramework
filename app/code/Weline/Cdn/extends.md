# Weline_Cdn 模块扩展文档

## 概述

Weline_Cdn 模块提供了两个用途不同的扩展点：

1. **WarmupProvider** - CDN 缓存预热 URL 提供者，使用现有 WarmupProvider 收集链路。
2. **Edge Cache Adapter** - 缓存清理、规则管理和攻击模式适配器，必须通过模块清单的编译型 `cache.edge_adapter.*` Provider Registry 注册。

> 两者不共享发现机制。下文 WarmupProvider 的 `extends/module/Weline_Cdn/` 约定不适用于 Adapter。

## 快速开始

### 创建 WarmupProvider

1. 在您的模块中创建扩展目录：`extends/module/Weline_Cdn/`
2. 创建PHP文件（文件名可自定义，如 `ProductUrls.php`）
3. 实现 `execute()` 静态方法，返回URL数组

### 创建 Adapter（CDN 适配器）

1. 实现 `Weline\Framework\Cache\Contract\EdgeCacheAdapterInterface`（推荐）或兼容契约 `Weline\Cdn\Api\AdapterInterface`。
2. 在所属模块 `etc/module.php` 的 `provides` 中注册 `cache.edge_adapter.<priority>.<code>`。
3. 执行 `php bin/w framework:compile`；已运行 WLS 时再执行 `php bin/w server:reload`。

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

## WarmupProvider 注册信息（Adapter 不适用）

以下旧 Extends 注册表只用于本文前半部分的 WarmupProvider，不能用于注册 Edge Cache Adapter：

1. **命令行方式**: 使用 `extends:scan` 命令扫描
2. **管理界面**: 在后台管理中查看扩展管理
3. **直接查看**: 检查 `generated/extends.php` 文件

## 相关文档

- [Weline_Cdn 完整文档](doc/README.md)
- [CDN预热URL投递事件](doc/event/CDN预热URL投递.md)
- [CDN模块计划文档](计划.md)

---

## Adapter 扩展点（Edge Cache Adapter）

### 权威注册源

Adapter 的唯一权威注册源是所属模块的 `etc/module.php` 中的 `provides`：

```php
'provides' => [
    'cache.edge_adapter.300.your_adapter' => \Vendor\Module\Api\Cache\YourAdapter::class,
],
```

capability 格式固定为 `cache.edge_adapter.<priority>.<code>`：

- `priority` 使 Provider key 具有稳定顺序；同一 capability 不能由多个模块重复提供。
- `code` 应与实现的 `getAdapterCode()` 返回值一致，并在实例范围内唯一。
- 实现类可放在所属模块的任意 PSR 自动加载路径；不依赖特定 Adapter 目录。
- 新适配器优先实现 Framework 中立契约 `Weline\Framework\Cache\Contract\EdgeCacheAdapterInterface`，避免为了贡献适配器反向依赖 Cdn 内部实现。
- 已明确依赖 `Weline_Cdn` 的兼容集成可实现 `Weline\Cdn\Api\AdapterInterface`，并在模块清单声明依赖。

`extends/module/Weline_Cdn/Adapter/`、`generated/extends.php` 与 `setup:upgrade` 不再是 Adapter 注册入口。

### 公开契约

`EdgeCacheAdapterInterface` 和兼容契约 `AdapterInterface` 包含以下能力：

| 方法 | 说明 |
|---|---|
| `getAdapterCode()/getAdapterName()/getDescription()/getVersion()` | 适配器标识和展示信息 |
| `purgeEverything()` | 清理全部缓存 |
| `purgeUrls()/purgeHosts()/purgeTags()/purgeCacheKeys()` | 按 URL、Host、Tag 或 Cache Key 清理 |
| `getRules()/putRules()` | 读取或发布缓存规则 |
| `ensureZone()` | 解析或创建 Zone |
| `enableAttackMode()/disableAttackMode()/supportsAttackMode()` | 攻击防护模式 |
| `getRealIpHeaderKeys()` | 发布供 Framework 解析的 CDN 真实 IP Header keys |

所有方法签名以当前接口源码为准。不支持攻击模式的适配器应返回 `supportsAttackMode() === false`，不应伪造成功结果。

### 当前注册示例

Cloudflare 由 `Weline_Cdn/etc/module.php` 提供：

```php
'provides' => [
    'cache.edge_adapter.100.cloudflare' => \Weline\Cdn\Adapter\Cloudflare::class,
],
```

WLS Memory 由 `Weline_Server/etc/module.php` 提供：

```php
'provides' => [
    'cache.edge_adapter.200.wls_memory' => \Weline\Server\Api\Cache\WlsMemory::class,
],
```

`Weline\Server\Api\Cache\WlsMemory` 只实现 Framework 契约。Cdn 在加载时通过 `EdgeCacheAdapterBridge` 适配到历史 `AdapterInterface`，因此 Server 不需要引用 Cdn 模块内部类。

### 编译与运行时生效

新增、删除或修改 Provider 后执行：

```bash
php bin/w framework:compile
```

编译结果写入 `generated/framework/modules.php`；该文件是只读编译产物，禁止手工编辑。如果 WLS 已运行，继续执行：

```bash
php bin/w server:reload
```

Adapter Provider 清单在单个进程中不可变。`AdapterResolver::getAllAdapters(true)` 只会重建适配器实例，不会重读模块清单；必须通过编译和进程重载发布新清单。

请求和 WLS 热路径只读取编译结果，不执行 Adapter 目录 glob、Extends Registry 查找、PHP 源码解析、正则提取或 mtime 轮询。

### 加载流程

```text
etc/module.php provides
→ php bin/w framework:compile
→ generated/framework/modules.php
→ ServiceProviderRegistry::implementationsWithPrefix('cache.edge_adapter.')
→ ObjectManager 构建实现
→ EdgeCacheAdapterBridge（仅 Framework 契约实现）
→ AdapterResolver 按 getAdapterCode() 建立 O(1) 索引
```

### 常见问题

#### Q: 如何确认适配器已注册？

A: 执行 `php bin/w framework:compile` 并确认命令成功。可以只读方式检查 `generated/framework/modules.php` 中的 `cache.edge_adapter.*`，但不得手改该文件。运行中 WLS 还需重载后才会使用新注册表。

#### Q: 适配器失败会怎样？

A: 契约操作应返回包含 `success` 和 `message` 的结构化结果。加载失败或返回无效类型的 Provider 会被记录并忽略；业务操作的失败策略以调用方为准。

#### Q: 本地缓存没有外部 Zone 怎么办？

A: 可以像 WLS Memory 一样返回稳定的虚拟 Zone，但其他不支持的能力应明确返回失败或通过 capability 方法表达，不要静默伪造成功。

---

## 支持和贡献

如有问题或建议，请通过以下方式联系：

- 邮箱: aiweline@qq.com
- 论坛: https://bbs.aiweline.com
- 文档: 查看模块的 doc/ 目录
