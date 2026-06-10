# Weline_Seo 模块扩展文档

## 概述

Weline_Seo 模块提供了 SEO Feed 提供扩展点，允许其他模块向 SEO 模块上报 SEO 信息，实现自动化的 SEO 数据收集和管理。

## 快速开始

### 1. 创建扩展目录

在您的模块中创建以下目录结构：

```
app/code/YourModule/
└── extends/
    └── module/
        └── Weline_Seo/
            └── FeedProvider/
                └── YourFeedProvider.php
```

### 2. 实现 Feed 提供接口

创建实现 `FeedProviderInterface` 接口的类：

```php
<?php
declare(strict_types=1);

namespace YourModule\Extends\Weline_Seo\FeedProvider;

use Weline\Seo\Interface\FeedProviderInterface;
use Weline\Seo\Model\Feed\Item;

class YourFeedProvider implements FeedProviderInterface
{
    public function getCode(): string
    {
        return 'your_module_feed';
    }

    public function getName(): string
    {
        return '您的模块 Feed';
    }

    public function getDescription(): string
    {
        return '提供产品/内容的 SEO 信息';
    }

    public function getFeedItems(): array
    {
        $items = [];
        
        // 获取需要上报的数据
        $entities = $this->getEntities();
        
        foreach ($entities as $entity) {
            $items[] = $this->createFeedItem($entity);
        }
        
        return $items;
    }

    public function getFeedType(): string
    {
        return 'product'; // 或其他类型：article, page, category 等
    }

    public function getUpdateFrequency(): string
    {
        return 'daily'; // daily, weekly, monthly
    }

    private function getEntities(): array
    {
        // 获取您模块中的实体数据（产品、文章等）
        return [];
    }

    private function createFeedItem($entity): Item
    {
        $item = new Item();
        $item->setUrl($entity->getUrl());
        $item->setTitle($entity->getTitle());
        $item->setDescription($entity->getDescription());
        $item->setImage($entity->getImage());
        $item->setUpdatedAt($entity->getUpdatedAt());
        
        // 设置 SEO 元数据
        $item->setMetaTitle($entity->getMetaTitle());
        $item->setMetaDescription($entity->getMetaDescription());
        $item->setMetaKeywords($entity->getMetaKeywords());
        $item->setCanonicalUrl($entity->getCanonicalUrl());
        
        return $item;
    }
}
```

### 3. 注册模块

确保您的模块已正确注册，并且依赖 `Weline_Seo` 模块。

### 4. 运行升级

运行以下命令来扫描和注册 Feed 提供：

```bash
php bin/w setup:upgrade
```

## 详细说明

### FeedProvider 扩展点

**路径**: `extends/module/Weline_Seo/FeedProvider`

**接口**: `Weline\Seo\Interface\FeedProviderInterface`

**用途**: SEO Feed 提供扩展点，允许其他模块向 SEO 模块上报 SEO 信息。系统会自动收集这些信息并生成 sitemap、RSS feed 等。

**要求**:
- 必须实现 `FeedProviderInterface` 接口
- 必须实现所有接口方法
- 允许多个实现

#### 接口方法说明

- **getCode()**: 返回 Feed 提供代码（唯一标识）
- **getName()**: 返回 Feed 提供显示名称
- **getDescription()**: 返回 Feed 提供描述
- **getFeedItems()**: 返回 Feed 项目数组，每个项目包含 URL、标题、描述等 SEO 信息
- **getFeedType()**: 返回 Feed 类型（product, article, page, category 等）
- **getUpdateFrequency()**: 返回更新频率（daily, weekly, monthly）

## Feed 项目结构

### Item 对象属性

```php
$item = new Item();

// 必需字段
$item->setUrl('https://example.com/product/123');  // 页面URL
$item->setTitle('产品标题');                       // 页面标题
$item->setUpdatedAt('2026-01-08 12:00:00');       // 更新时间

// 可选字段
$item->setDescription('产品描述');                 // 描述
$item->setImage('https://example.com/image.jpg'); // 图片URL
$item->setMetaTitle('SEO标题');                   // SEO标题
$item->setMetaDescription('SEO描述');             // SEO描述
$item->setMetaKeywords('关键词1,关键词2');         // SEO关键词
$item->setCanonicalUrl('https://example.com/product/123'); // 规范URL
$item->setPriority(0.8);                          // 优先级 (0.0-1.0)
$item->setChangeFrequency('weekly');              // 更新频率
```

## Feed 类型

支持以下 Feed 类型：

- **product**: 产品信息
- **article**: 文章/博客
- **page**: 页面
- **category**: 分类页面
- **custom**: 自定义类型

## 更新频率

支持以下更新频率：

- **daily**: 每天更新
- **weekly**: 每周更新
- **monthly**: 每月更新
- **always**: 总是更新（实时）
- **never**: 不更新

## 使用场景

### 1. 产品模块上报产品信息

```php
class ProductFeedProvider implements FeedProviderInterface
{
    public function getFeedItems(): array
    {
        $products = $this->productModel->getActiveProducts();
        $items = [];
        
        foreach ($products as $product) {
            $item = new Item();
            $item->setUrl($product->getUrl());
            $item->setTitle($product->getName());
            $item->setDescription($product->getShortDescription());
            $item->setImage($product->getImageUrl());
            $item->setMetaTitle($product->getMetaTitle());
            $item->setMetaDescription($product->getMetaDescription());
            $item->setUpdatedAt($product->getUpdatedAt());
            
            $items[] = $item;
        }
        
        return $items;
    }

    public function getFeedType(): string
    {
        return 'product';
    }
}
```

### 2. 博客模块上报文章信息

```php
class ArticleFeedProvider implements FeedProviderInterface
{
    public function getFeedItems(): array
    {
        $articles = $this->articleModel->getPublishedArticles();
        $items = [];
        
        foreach ($articles as $article) {
            $item = new Item();
            $item->setUrl($article->getUrl());
            $item->setTitle($article->getTitle());
            $item->setDescription($article->getExcerpt());
            $item->setImage($article->getFeaturedImage());
            $item->setMetaTitle($article->getMetaTitle());
            $item->setMetaKeywords($article->getTags());
            $item->setUpdatedAt($article->getPublishedAt());
            
            $items[] = $item;
        }
        
        return $items;
    }

    public function getFeedType(): string
    {
        return 'article';
    }
}
```

### 3. 分类页面上报

```php
class CategoryFeedProvider implements FeedProviderInterface
{
    public function getFeedItems(): array
    {
        $categories = $this->categoryModel->getAllCategories();
        $items = [];
        
        foreach ($categories as $category) {
            $item = new Item();
            $item->setUrl($category->getUrl());
            $item->setTitle($category->getName());
            $item->setDescription($category->getDescription());
            $item->setMetaTitle($category->getMetaTitle());
            $item->setPriority(0.7);
            $item->setChangeFrequency('weekly');
            
            $items[] = $item;
        }
        
        return $items;
    }

    public function getFeedType(): string
    {
        return 'category';
    }
}
```

## 最佳实践

1. **数据完整性**: 确保提供完整的 SEO 信息，包括标题、描述、URL 等
2. **性能优化**: 对于大量数据，考虑分批处理或使用缓存
3. **更新频率**: 根据内容实际更新频率设置合理的更新频率
4. **URL 规范**: 使用规范的 URL 格式，避免重复内容
5. **图片优化**: 提供高质量的图片 URL，支持 HTTPS
6. **元数据质量**: 确保元数据（标题、描述、关键词）质量高且相关

## 常见问题

### Q: Feed 数据何时被收集？

A: 系统会在以下情况自动收集：
- 系统升级时
- 手动触发 SEO 数据更新时
- 定期任务执行时（根据更新频率）

### Q: 如何处理大量数据？

A: 可以：
1. 分批处理数据
2. 使用缓存减少查询
3. 只上报活跃/公开的数据
4. 实现增量更新

### Q: Feed 数据如何生成 sitemap？

A: SEO 模块会自动收集所有 Feed 提供的数据，并生成统一的 sitemap.xml 文件。

### Q: 如何更新已存在的 Feed 数据？

A: 修改 `getFeedItems()` 方法返回的数据，系统会自动更新。可以通过 `setUpdatedAt()` 方法标记更新时间。

## 相关文档

- 扩展点总览：`doc/扩展规约说明.md`
- 页面 SEO 与 JSON-LD 结构层：`doc/SEO结构化数据说明.md`
- 模块文档目录：`doc/设计文档.md`
