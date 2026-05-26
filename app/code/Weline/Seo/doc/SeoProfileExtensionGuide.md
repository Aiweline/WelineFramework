# SEO/GEO Profile Extension Guide

This guide is the module contract for extending page-level SEO, structured data,
sitemap metadata, and GEO feed facts through `Weline_Seo`.

## Extension Points

Modules should use these extension points:

1. `SeoProfileProvider`
   - Path: `extends/module/Weline_Seo/SeoProfileProvider`
   - Interface: `Weline\Seo\Interface\SeoProfileProviderInterface`
   - Use for page type, robots, canonical, schema facts, sitemap metadata, and GEO metadata.

2. `SitemapUrlProvider`
   - Path: `extends/module/Weline_Seo/SitemapUrlProvider`
   - Interface: `Weline\Seo\Interface\SitemapUrlProviderInterface`
   - Use for discoverable URLs and sitemap payloads such as image/video/news/hreflang metadata.

`SeoProfileProvider` is the only supported page-level SEO/GEO entry point. Put
module-specific entity enrichment and schema facts there, and return JSON-LD
nodes through `schema_nodes`.

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
    'schema_nodes' => [],
    'sitemap' => [],
    'geo' => [],
]
```

List-style keys `schema_nodes`, `item_list`, and `faqs` are appended to existing
context. Other keys override or enrich existing context recursively.

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

Do not print `application/ld+json` directly from templates. Put facts on the
template/controller context, then let `Weline_Seo` render the final graph:

```php
$this->setData('seo', [
    'page_type' => 'blog_post',
    'canonical_url' => $canonicalUrl,
]);
$this->setData('article', $articleFacts);
```

If a module needs sitemap support for the same page type, add a matching
`SitemapUrlProvider` and reuse the same facts in its metadata payload.

## Validation

Use `Weline\Seo\Service\Profile\SeoProfileValidationService` in tests to catch
common mistakes such as indexable noindex pages in sitemap/GEO payloads, missing
news sitemap fields, missing product names, and collection pages without item lists.
