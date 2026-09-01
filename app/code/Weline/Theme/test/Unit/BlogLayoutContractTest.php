<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class BlogLayoutContractTest extends TestCase
{
    public function testBlogDetailLayoutUsesAmazonArticleShell(): void
    {
        $layout = dirname(__DIR__, 2) . '/view/theme/frontend/layouts/blog/default.phtml';
        self::assertFileExists($layout);
        $source = (string)file_get_contents($layout);
        self::assertStringContainsString('data-layout="blog"', $source);
        self::assertStringContainsString('blog-layout__panel', $source);
        self::assertStringContainsString('contentTemplate', $source);
        self::assertStringContainsString('amazon-blog-article', $source);
        self::assertStringContainsString('#eaeded', $source);
        self::assertStringContainsString('--weline-layout-content-max-width', $source);
        self::assertStringContainsString('blog-reviews', $source);
        self::assertStringContainsString('showReviews', $source);
    }

    public function testBlogCategoryLayoutUsesSidebarAndCardGrid(): void
    {
        $layout = dirname(__DIR__, 2) . '/view/theme/frontend/layouts/blog_category/default.phtml';
        self::assertFileExists($layout);
        $source = (string)file_get_contents($layout);
        self::assertStringContainsString('data-layout="blog_category"', $source);
        self::assertStringContainsString('blog-category-layout__body--with-sidebar', $source);
        self::assertStringContainsString('category-filter.phtml', $source);
        self::assertStringContainsString('amazon-blog-listing__grid', $source);
        self::assertStringContainsString('contentTemplate', $source);
        self::assertStringContainsString('--weline-layout-content-max-width', $source);
    }
}
