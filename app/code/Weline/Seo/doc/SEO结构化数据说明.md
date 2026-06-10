# SEO 结构化数据说明

本文说明 `Weline_Seo` 模块的页面级 SEO Profile、JSON-LD 结构层（Structure Layer）及扩展方式。英文契约见 [SeoProfileExtensionGuide.md](SeoProfileExtensionGuide.md)。

---

## 能力概览

`Weline_Seo` 在页面 `<head>` 中统一输出：

- Meta / Open Graph / 多语言 alternate
- `application/ld+json` 图（`@context` + `@graph`）
- 与 Sitemap、GEO Feed 共享的事实字段

**业务模块不要在模板里手写 JSON-LD。** 将事实写入控制器或模板的 context，由 `HeadRenderer` 与 `SeoStructureRegistry` 组装最终图。

---

## 扩展点一览

| 扩展点 | 路径 | 接口 | 用途 |
|--------|------|------|------|
| `SeoProfileProvider` | `extends/module/Weline_Seo/SeoProfileProvider` | `SeoProfileProviderInterface` | 页面类型、robots、canonical、结构事实、sitemap/geo 元数据 |
| `SitemapUrlProvider` | `extends/module/Weline_Seo/SitemapUrlProvider` | `SitemapUrlProviderInterface` | 可发现 URL 及 image/video/news/hreflang 等 sitemap 载荷 |
| `SeoStructureNodeBuilder` | `extends/module/Weline_Seo/SeoStructureNodeBuilder` | `SeoStructureNodeBuilderInterface` | 自定义 schema.org 图节点 Builder |
| `FeedProvider` | `extends/module/Weline_Seo/FeedProvider` | `FeedProviderInterface` | 主体级 SEO Feed 上报（与页面 Profile 互补） |

页面级 SEO/GEO 的**唯一推荐入口**是 `SeoProfileProvider`；模块特有实体 enrichment 与 `schema_nodes` 也应在此返回。

---

## Profile 字段约定

Provider 或控制器通过 `seo` / `setData()` 合并进页面 context。常用键：

```php
[
    'page_type' => 'product|category|blog_post|news_article|faq|qa|review_page|web_page',
    'title' => '页面标题',
    'description' => '搜索结果摘要',
    'canonical_url' => 'https://example.com/current-page',
    'robots' => 'index,follow',
    'image' => 'https://example.com/image.jpg',
    'article' => [],
    'product' => [],
    'item_list' => [],
    'faqs' => [],
    'qa_list' => [],
    'breadcrumbs' => [],
    'organization' => [],
    'reviews' => [],
    'schema_nodes' => [],
    'sitemap' => [],
    'geo' => [],
]
```

**合并规则：**

- 列表型键 `schema_nodes`、`item_list`、`faqs`、`qa_list`：**追加**到已有 context
- 其余键：**递归覆盖或 enrichment**

---

## 内置结构类型（8 种）

常量定义：`Weline\Seo\Structure\SeoStructureType`  
Context 主键：`Weline\Seo\Structure\SeoStructureContextKeys`

| 结构类型 | Context 键 | Schema.org 类型 | 内置渲染方 |
|----------|------------|-----------------|------------|
| FAQ | `faqs` | `FAQPage` | `FaqStructureNodeBuilder` |
| QA | `qa_list` | `QAPage` | `QaStructureNodeBuilder` |
| Product | `product` | `Product` / `ProductGroup` | `HeadRenderer` |
| Article | `article` | `BlogPosting` / `NewsArticle` | `HeadRenderer` |
| ItemList | `item_list` | `ItemList` + `CollectionPage` | `HeadRenderer` |
| Review | `reviews` | `Review` / `AggregateRating` | 模块 `schema_nodes` 或自定义 Builder |
| Breadcrumb | `breadcrumbs` | `BreadcrumbList` | `HeadRenderer` |
| Organization | `organization` | `Organization` / `LocalBusiness` | `HeadRenderer` |

每种类型在 `Weline\Seo\Structure\{Type}\` 下提供：

- `Abstract*StructureNormalizer` — 事实规范化
- `Abstract*StructureNodeBuilder` — 节点构建基类（供业务扩展）

共享契约：`SeoStructureNodeBuilderInterface`、`AbstractSeoStructureNodeBuilder`、`AbstractSeoStructureNormalizer`。

---

## 每页 JSON-LD 图组装顺序

`HeadRenderer::buildGraph()` 按下列顺序构建 `@graph`：

1. **Organization** / **LocalBusiness**（`organization` 含地址或电话时为 LocalBusiness）
2. **WebSite**（含可选 `SearchAction` 站内搜索）
3. **WebPage** 子类型（由 `page_type` 映射，见下节）
4. **BreadcrumbList**（有 `breadcrumbs` 时）
5. **Product**（`page_type === product` 且有 `product` 事实）
6. **Article**（文章类 `page_type` 且有 `article` 事实）
7. **ItemList**（有 `item_list` 时）
8. `SeoStructureRegistry` — 内置 FAQ/QA Builder + 扩展 `SeoStructureNodeBuilder`
9. `schema_nodes` — Provider 或业务自定义节点

---

## 页面类型（page_type）

### 常用值

```
product | category | blog_post | news_article | faq | qa | review_page | web_page
```

### 代码中识别的别名

| 场景 | 接受的 page_type |
|------|------------------|
| 文章 | `article`、`blog_post`、`post`、`news`、`news_article` |
| 集合/列表 | `category`、`collection`、`collection_page`、`blog_list`、`blog_category`、`searchable_landing` |
| FAQ | `faq`、`faq_page` |
| QA | `qa`、`qa_page` |
| 关于/联系 | `about`、`about_page`、`contact`、`contact_page` |
| 其他 | `landing_page`、`checkout`（通常 noindex）等 |

### WebPage 子类型映射

| page_type | JSON-LD `@type` |
|-----------|-----------------|
| `about` / `about_page` | `AboutPage` |
| `contact` / `contact_page` | `ContactPage` |
| `faq` / `faq_page` | `FAQPage` |
| `category` / `collection` / `blog_list` 等 | `CollectionPage` |
| 默认 | `WebPage` |

### 页面类型规则（摘要）

- **商品页**：必须提供 `product` 事实，不要只写自定义 schema
- **集合页**：提供 `item_list`，保持 `CollectionPage` 与 `ItemList` 一致
- **博客/新闻**：提供 `article`；新闻页还需 `sitemap.news` 字段
- **FAQ/QA**：提供 `faqs` 或 `qa_list`，勿在模板手写 `FAQPage` / `QAPage`
- **购物车、结账、登录、账户、预览、后台、API、低价值筛选页**：`robots => noindex,follow`，且 `sitemap.include` / `geo.include` 为 `false`

---

## FAQ / QA 使用说明

### FAQ（`faqs`）

规范化形态：

```php
[
    ['question' => '如何下单？', 'answer' => '在商品页加入购物车后结账。'],
]
```

规范化前接受的别名：

- 问题：`question`、`q`、`title`、`name`
- 答案：`answer`、`a`、`text`、`content`，或嵌套 `acceptedAnswer.text`

控制器示例：

```php
$this->setData('faqs', [
    ['question' => __('如何下单？'), 'answer' => __('在商品页加入购物车后结账。')],
]);
$this->setData('seo', [
    'page_type' => 'faq',
    'title' => __('常见问题'),
]);
```

### QA（`qa_list`）

列表项至少含 `question`（或 `title`）；`answer` 可选。框架渲染为 `QAPage`。

### 跨切面事实来源

若 FAQ 来自主题 Widget 等非控制器路径，可通过事件 `Weline_Seo::integration::head_context_resolve` 注入 context。

---

## 自定义结构 Builder

在业务模块创建：

```
extends/module/Weline_Seo/SeoStructureNodeBuilder/YourBuilder.php
```

实现 `SeoStructureNodeBuilderInterface`，或继承对应 `Abstract*StructureNodeBuilder`：

```php
<?php

declare(strict_types=1);

namespace Vendor\Module\Extends\Module\Weline_Seo\SeoStructureNodeBuilder;

use Weline\Seo\Structure\Review\AbstractReviewStructureNodeBuilder;

class CustomReviewStructureNodeBuilder extends AbstractReviewStructureNodeBuilder
{
    protected function buildFactNodes(array $context, string $url): array
    {
        // 返回 Review / AggregateRating 节点
        return [];
    }
}
```

`SeoStructureBuilderRegistry` 加载顺序：**内置 FAQ/QA Builder 优先**，其后为所有扩展 Builder。

---

## 模板与控制器集成

```php
$this->setData('seo', [
    'page_type' => 'blog_post',
    'canonical_url' => $canonicalUrl,
]);
$this->setData('article', $articleFacts);
```

同一页面类型若需进入 Sitemap，可额外实现 `SitemapUrlProvider`，复用相同事实构造元数据。

---

## 校验

单元测试中可使用 `Weline\Seo\Service\Profile\SeoProfileValidationService` 检查常见问题：

- noindex 页面是否误列入 sitemap / GEO
- 新闻 sitemap 必填字段
- 商品页缺少 `product.name`
- 集合页缺少 `item_list`
- FAQ 项问题/答案为空

---

## 相关文档

- [SeoProfileExtensionGuide.md](SeoProfileExtensionGuide.md) — 英文契约与 Provider 完整示例
- [扩展规约说明.md](扩展规约说明.md) — 全部扩展点索引
- [设计文档.md](设计文档.md) — 模块文档目录
- [Sitemap扩展开发指南.md](Sitemap扩展开发指南.md) — Sitemap URL 扩展
- [Sitemap架构设计.md](Sitemap架构设计.md) — Sitemap 生成架构
