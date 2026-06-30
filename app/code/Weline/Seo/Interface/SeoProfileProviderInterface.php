<?php

declare(strict_types=1);

namespace Weline\Seo\Interface;

interface SeoProfileProviderInterface
{
    /**
     * Return normalized SEO/GEO profile data for the current page context.
     *
     * Supported top-level keys are intentionally broad so modules can add page
     * type data without changing the framework contract on every schema update:
     * - page_type, robots, canonical_url, title, description, image
     * - schema_nodes: additional JSON-LD graph nodes
     * - item_list: list page entries
     * - article: article/news structured facts
     * - faqs: normalized FAQ facts for FAQPage schema
     * - qa_list: Q&A listing facts for QAPage schema
     * - sitemap: sitemap extension payload
     * - geo: GEO/feed metadata
     *
     * Runtime context also includes `_slot` and `_options` when invoked from the
     * `<w:seo>` tag. Providers should use the context to decide whether they
     * support the current page and return structured facts only, not HTML.
     *
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function provideSeoProfile($template, array $context): array;
}
