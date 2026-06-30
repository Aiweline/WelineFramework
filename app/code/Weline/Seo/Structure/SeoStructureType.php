<?php

declare(strict_types=1);

namespace Weline\Seo\Structure;

/**
 * SEO 结构化数据类型常量。
 */
final class SeoStructureType
{
    public const FAQ = 'faq';
    public const QA = 'qa';
    public const PRODUCT = 'product';
    public const ARTICLE = 'article';
    public const ITEM_LIST = 'item_list';
    public const REVIEW = 'review';
    public const BREADCRUMB = 'breadcrumb';
    public const ORGANIZATION = 'organization';

    private function __construct()
    {
    }
}
