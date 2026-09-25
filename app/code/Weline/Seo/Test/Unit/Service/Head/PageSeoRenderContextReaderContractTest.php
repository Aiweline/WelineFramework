<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Head;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\Head\PageSeoContextResolver;

/**
 * N4: SeoHead site name prefers RenderContext bag before WebsiteData.
 */
final class PageSeoRenderContextReaderContractTest extends TestCase
{
    public function testResolveWebsiteSiteNamePrefersRenderContext(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Service/Head/PageSeoContextResolver.php',
        );
        $fn = \strpos($src, 'function resolveWebsiteSiteName');
        self::assertNotFalse($fn);
        $slice = \substr($src, $fn, 2800);
        self::assertStringContainsString('StorefrontRenderContextBag::websiteLocalName', $slice);
        self::assertStringContainsString('StorefrontRenderContextReader', $slice);
        $bagPos = \strpos($slice, 'StorefrontRenderContextBag::websiteLocalName');
        $dataPos = \strpos($slice, 'WebsiteData::getName');
        self::assertNotFalse($bagPos);
        self::assertNotFalse($dataPos);
        self::assertLessThan($dataPos, $bagPos);
        self::assertTrue(class_exists(PageSeoContextResolver::class));
    }
}
