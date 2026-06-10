<?php

declare(strict_types=1);

namespace Weline\Seo\Structure;

/**
 * 各 SEO 结构在页面 context / profile 中约定使用的主键。
 */
final class SeoStructureContextKeys
{
    public const FAQ = 'faqs';
    public const QA = 'qa_list';
    public const PRODUCT = 'product';
    public const ARTICLE = 'article';
    public const ITEM_LIST = 'item_list';
    public const REVIEW = 'reviews';
    public const BREADCRUMB = 'breadcrumbs';
    public const ORGANIZATION = 'organization';

    private function __construct()
    {
    }
}
