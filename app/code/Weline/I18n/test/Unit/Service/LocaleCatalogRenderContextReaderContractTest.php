<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Service\LocaleCatalogScopeResolver;
use Weline\I18n\Taglib\LanguageSwitcher;

/**
 * N4: LanguageSwitcher / LocaleCatalogScopeResolver prefer
 * storefront.render_context.v1 Reader — no parallel locale bag.
 */
final class LocaleCatalogRenderContextReaderContractTest extends TestCase
{
    public function testResolverPrefersRenderContextLocaleCatalog(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/LocaleCatalogScopeResolver.php',
        );
        self::assertStringContainsString('StorefrontRenderContextReader', $src);
        self::assertStringContainsString('localeCatalog', $src);
        self::assertStringContainsString('websiteTableSnapshot', $src);
        self::assertTrue(class_exists(LocaleCatalogScopeResolver::class));
    }

    public function testLanguageSwitcherDefaultsPreferReader(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Taglib/LanguageSwitcher.php',
        );
        self::assertStringContainsString('StorefrontRenderContextReader', $src);
        $defaultLang = \strpos($src, 'function defaultLanguage');
        self::assertNotFalse($defaultLang);
        $slice = \substr($src, $defaultLang, 2800);
        $readerPos = \strpos($slice, 'StorefrontRenderContextReader');
        $websitePos = \strpos($slice, 'WebsiteData::getDefaultLanguage');
        self::assertNotFalse($readerPos);
        self::assertNotFalse($websitePos);
        self::assertLessThan($websitePos, $readerPos);

        $filterPos = \strpos($src, 'function filterFrontendLanguages');
        self::assertNotFalse($filterPos);
        $filterSlice = \substr($src, $filterPos, 1600);
        self::assertStringContainsString('StorefrontRenderContextReader', $filterSlice);
        self::assertTrue(class_exists(LanguageSwitcher::class));
    }
}
