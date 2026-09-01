<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Extends\Module\Weline_Seo;

use PHPUnit\Framework\TestCase;

final class BlogSeoProfileProviderContractTest extends TestCase
{
    public function testProviderImplementsSeoProfileInterface(): void
    {
        $path = dirname(__DIR__, 5) . '/extends/module/Weline_Seo/SeoProfileProvider/BlogSeoProfileProvider.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('SeoProfileProviderInterface', $source);
        self::assertStringContainsString("'page_type' => 'blog_post'", (string)file_get_contents(dirname(__DIR__, 5) . '/Service/BlogSeoFactsBuilder.php'));
        self::assertStringContainsString("'page_type' => 'blog_list'", (string)file_get_contents(dirname(__DIR__, 5) . '/Service/BlogSeoFactsBuilder.php'));
    }
}
