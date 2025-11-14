# Weline_Cdn 模块扩展文档

## 概述

Weline_Cdn 模块提供了CDN缓存预热URL提供者扩展点，允许其他模块通过 WarmupProvider 扩展点来提供需要预热的URL列表。本文档详细说明如何使用 WarmupProvider 扩展点。

## 快速开始

### 创建 WarmupProvider

1. 在您的模块中创建扩展目录：`extends/module/Weline_Cdn/`
2. 创建PHP文件（文件名可自定义，如 `ProductUrls.php`）
3. 实现 `execute()` 静态方法，返回URL数组

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

## 支持和贡献

如有问题或建议，请通过以下方式联系：

- 邮箱: aiweline@qq.com
- 论坛: https://bbs.aiweline.com
- 文档: 查看模块的 doc/ 目录

