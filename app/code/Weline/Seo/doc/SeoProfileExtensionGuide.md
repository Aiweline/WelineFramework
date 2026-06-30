# SEO/GEO Profile Extension Guide

> 中文说明见 [SEO结构化数据说明.md](SEO结构化数据说明.md)。模块文档索引见 [设计文档.md](设计文档.md)。

This guide is the module contract for extending `<w:seo>` tag rendering,
page-level SEO, structured data, sitemap metadata, and GEO feed facts through
`Weline_Seo`.

## Extension Points

Modules should use these extension points:

1. `SeoProfileProvider`
   - Path: `extends/module/Weline_Seo/SeoProfileProvider`
   - Interface: `Weline\Seo\Interface\SeoProfileProviderInterface`
   - Use for page type, robots, canonical, schema facts, sitemap metadata, and GEO metadata.
   - It is called by `<w:seo>` with the current template context. Providers return structured facts only.

2. `SeoSlotProvider`
   - Path: `extends/module/Weline_Seo/SeoSlotProvider`
   - Interface: `Weline\Seo\Interface\SeoSlotProviderInterface`
   - Use for custom `<w:seo slot="..."/>` regions in body, footer, or module-defined slots.
   - Providers return structured slot payloads such as `blocks` and `schema_nodes`, not raw HTML.

3. `SitemapUrlProvider`
   - Path: `extends/module/Weline_Seo/SitemapUrlProvider`
   - Interface: `Weline\Seo\Interface\SitemapUrlProviderInterface`
   - Use for discoverable URLs and sitemap payloads such as image/video/news/hreflang metadata.

`SeoProfileProvider` is the only supported page-level SEO/GEO entry point. It
receives `_slot` and `_options` inside `$context` when invoked by `<w:seo>`.
Put module-specific entity enrichment and schema facts there. Do not assemble
HTML or hand-write JSON-LD in providers; return structure data and let SEO render.

## Profile Shape

A profile provider returns an array that is merged into the resolved page context.
Common keys:

```php
[
    'page_type' => 'product|category|blog_post|news_article|faq|qa|review_page|web_page',
    'title' => 'Page title',
    'description' => 'Search result summary',
    'canonical_url' => 'https://example.com/current-page',
    'robots' => 'index,follow',
    'image' => 'https://example.com/image.jpg',
    'article' => [],
    'product' => [],
    'item_list' => [],
    'faqs' => [],
    'qa_list' => [],
    'schema_nodes' => [],
    'sitemap' => [],
    'geo' => [],
]
```

List-style keys `schema_nodes`, `item_list`, `faqs`, and `qa_list` are appended to existing
context. Other keys override or enrich existing context recursively.

## Custom Slot Shape

`<w:seo>` is not limited to head templates. It may appear in body, footer, or a
custom module slot:

```html
<w:seo slot="blog-footer"/>
```

Custom slots are handled by `SeoSlotProvider`:

```php
[
    'blocks' => [
        [
            'type' => 'related_posts',
            'title' => 'Related',
            'items' => [
                ['name' => 'Related Post', 'url' => 'https://example.com/post'],
            ],
        ],
    ],
    'schema_nodes' => [
        [
            '@type' => 'WebPage',
            '@id' => 'https://example.com/post#webpage',
            'name' => 'Related Post',
        ],
    ],
]
```

SEO renders the returned structure. Slot providers must decide whether the
current page and slot are supported by reading `$template`, `$context`, `$slot`,
and `$options`.

## Structure Layer

`Weline_Seo` exposes a structure layer under `Weline\Seo\Structure` so modules can reuse
normalized facts and let the framework render JSON-LD graph nodes.

### Built-in types

| Type | Context key | Normalizer base | NodeBuilder base | Built-in renderer |
|------|-------------|-----------------|------------------|-------------------|
| FAQ | `faqs` | `Faq\AbstractFaqStructureNormalizer` | `Faq\AbstractFaqStructureNodeBuilder` | `FaqStructureNodeBuilder` |
| QA | `qa_list` | `Qa\AbstractQaStructureNormalizer` | `Qa\AbstractQaStructureNodeBuilder` | `QaStructureNodeBuilder` |
| Product | `product` | `Product\AbstractProductStructureNormalizer` | `Product\AbstractProductStructureNodeBuilder` | `HeadRenderer` (for now) |
| Article | `article` | `Article\AbstractArticleStructureNormalizer` | `Article\AbstractArticleStructureNodeBuilder` | `HeadRenderer` (for now) |
| ItemList | `item_list` | `ItemList\AbstractItemListStructureNormalizer` | `ItemList\AbstractItemListStructureNodeBuilder` | `HeadRenderer` (for now) |
| Review | `reviews` | `Review\AbstractReviewStructureNormalizer` | `Review\AbstractReviewStructureNodeBuilder` | module `schema_nodes` |
| Breadcrumb | `breadcrumbs` | `Breadcrumb\AbstractBreadcrumbStructureNormalizer` | `Breadcrumb\AbstractBreadcrumbStructureNodeBuilder` | `HeadRenderer` (for now) |
| Organization | `organization` | `Organization\AbstractOrganizationStructureNormalizer` | `Organization\AbstractOrganizationStructureNodeBuilder` | `HeadRenderer` (for now) |

Shared contracts:

- `SeoStructureNodeBuilderInterface`
- `AbstractSeoStructureNodeBuilder`
- `AbstractSeoStructureNormalizer`
- `SeoStructureContextKeys`
- `SeoStructureType`

### Register a custom structure builder

Place a class under `extends/module/Weline_Seo/SeoStructureNodeBuilder` and implement
`SeoStructureNodeBuilderInterface`, or extend the matching `Abstract*StructureNodeBuilder`.

```php
<?php

declare(strict_types=1);

namespace Vendor\Module\Extends\Module\Weline_Seo\SeoStructureNodeBuilder;

use Weline\Seo\Structure\Review\AbstractReviewStructureNodeBuilder;

class CustomReviewStructureNodeBuilder extends AbstractReviewStructureNodeBuilder
{
  protected function buildFactNodes(array $context, string $url): array
  {
      // return Review / AggregateRating nodes
      return [];
  }
}
```

`SeoStructureRegistry` loads built-in FAQ/QA builders first, then all extended builders.

### FAQ / QA rules

Current built-in structure builders:

- `FaqStructureNormalizer` + `FaqStructureNodeBuilder` for `FAQPage`
- `QaStructureNodeBuilder` for `QAPage`

Rules:

1. Put FAQ facts in `faqs` using the normalized shape below. Do not hand-write `FAQPage`
   JSON-LD in templates or providers.
2. Put Q&A listing facts in `qa_list`. The framework renders `QAPage` from that list.
3. Use `Weline_Seo::integration::head_context_resolve` when facts come from theme widgets or
   other cross-cutting sources.

FAQ normalized shape:

```php
[
    ['question' => '问题', 'answer' => '答案'],
]
```

Accepted aliases before normalization:

- question: `question`, `q`, `title`, `name`
- answer: `answer`, `a`, `text`, `content`, nested `acceptedAnswer.text`

Example controller integration:

```php
$this->setData('faqs', [
    ['question' => __('如何下单？'), 'answer' => __('在商品页加入购物车后结账。')],
]);
$this->setData('seo', [
    'page_type' => 'faq',
    'title' => __('常见问题'),
]);
```

## Minimal Provider

```php
<?php

declare(strict_types=1);

namespace Vendor\Module\Extends\Module\Weline_Seo\SeoProfileProvider;

use Weline\Seo\Interface\SeoProfileProviderInterface;

class LandingPageSeoProfileProvider implements SeoProfileProviderInterface
{
    public function provideSeoProfile($template, array $context): array
    {
        $landing = is_object($template) && method_exists($template, 'getData')
            ? $template->getData('landing_page')
            : null;

        if (!$landing) {
            return [];
        }

        return [
            'page_type' => 'landing_page',
            'title' => (string)$landing->getData('meta_title'),
            'description' => (string)$landing->getData('meta_description'),
            'canonical_url' => (string)$landing->getData('url'),
            'robots' => 'index,follow',
            'image' => (string)$landing->getData('image'),
            'schema_nodes' => [
                [
                    '@type' => 'WebPage',
                    '@id' => (string)$landing->getData('url') . '#landing',
                    'name' => (string)$landing->getData('title'),
                ],
            ],
            'sitemap' => [
                'include' => true,
                'images' => [
                    ['loc' => (string)$landing->getData('image')],
                ],
            ],
            'geo' => [
                'include' => true,
                'type' => 'landing_page',
                'summary' => (string)$landing->getData('summary'),
            ],
        ];
    }
}
```

## Page Type Rules

- Product pages should expose product facts in `product`, not only custom schema nodes.
- Collection pages should expose `item_list` so `CollectionPage` and `ItemList` stay consistent.
- Blog/news pages should expose `article`; news pages must also include `sitemap.news`.
- FAQ/QA pages should expose `faqs` or a `QAPage` node in `schema_nodes`.
- Cart, checkout, login, account, preview, backend, API, and low-value search/filter pages should use `robots => noindex,follow` and set `sitemap.include` / `geo.include` to `false`.

## Template Guidance

Do not print `application/ld+json` directly from templates or providers. Put
facts on the template/controller context or return them from `SeoProfileProvider`,
then let `Weline_Seo` render the final graph:

```php
$this->setData('seo', [
    'page_type' => 'blog_post',
    'canonical_url' => $canonicalUrl,
]);
$this->setData('article', $articleFacts);
```

If a module needs sitemap support for the same page type, add a matching
`SitemapUrlProvider` and reuse the same facts in its metadata payload. Sitemap
providers are not called by `<w:seo>`; they are used by sitemap/cron flows.

## Validation

Use `Weline\Seo\Service\Profile\SeoProfileValidationService` in tests to catch
common mistakes such as indexable noindex pages in sitemap/GEO payloads, missing
news sitemap fields, missing product names, and collection pages without item lists.
