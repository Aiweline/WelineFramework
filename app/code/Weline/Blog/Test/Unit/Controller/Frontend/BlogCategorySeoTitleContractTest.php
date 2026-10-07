<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Controller\Frontend;

use PHPUnit\Framework\TestCase;

final class BlogCategorySeoTitleContractTest extends TestCase
{
    public function testCategoryLeafTitleUsesNeutralBlogTemplates(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 4) . '/Controller/Frontend/Category.php');
        self::assertStringContainsString('分类 · 博客文章', $src);
        self::assertStringContainsString('blog_page_heading', $src);
        self::assertStringContainsString("WidgetI18n::label('博客分类')", $src);
        self::assertStringNotContainsString('汉服博客', $src);
        self::assertStringContainsString("mb_strlen((string)\$seo['description']) < 50", $src);
    }
}
