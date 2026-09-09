<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Controller\Frontend;

use PHPUnit\Framework\TestCase;

final class BlogCategorySeoTitleContractTest extends TestCase
{
    public function testCategoryLeafTitleUsesSerpFriendlyTemplate(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 4) . '/Controller/Frontend/Category.php');
        self::assertStringContainsString('分类博客 · 穿搭与选购', $src);
        self::assertStringContainsString('blog_page_heading', $src);
        self::assertStringContainsString('汉服博客分类 · 穿搭灵感与选购指南', $src);
        self::assertStringContainsString("mb_strlen((string)\$seo['description']) < 50", $src);
    }
}
