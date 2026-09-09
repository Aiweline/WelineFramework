<?php

declare(strict_types=1);

namespace Weline\Cms\Test\Unit\Extends\Module\Weline_Seo;

use PHPUnit\Framework\TestCase;

final class CmsPageProviderBlogExcludeContractTest extends TestCase
{
    public function testProviderSkipsBlogPathGroupWhenBlogModuleEnabled(): void
    {
        $path = dirname(__DIR__, 5) . '/extends/module/Weline_Seo/SitemapUrlProvider/CmsPageProvider.php';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('shouldSkipOwnedPathGroup', $source);
        self::assertStringContainsString("=== 'blog'", $source);
        self::assertStringContainsString('Weline_Blog', $source);
        self::assertStringContainsString("=== 'help'", $source);
        self::assertStringContainsString('Weline_Help', $source);
    }
}
