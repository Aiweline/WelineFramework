<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\CachePolicy;
use Weline\I18n\Taglib\LanguageSwitcher;

final class LanguageSwitcherCatalogHotCacheContractTest extends TestCase
{
    public function testCatalogUsesStorefrontScopeHotCacheWithoutPathBoundHtml(): void
    {
        $taglib = (string)file_get_contents(dirname(__DIR__, 3) . '/Taglib/LanguageSwitcher.php');

        self::assertSame('i18n.language_switcher.catalog', LanguageSwitcher::CATALOG_CACHE_RESOURCE);
        self::assertInstanceOf(CachePolicy::class, LanguageSwitcher::catalogCachePolicy());
        self::assertSame('i18n.language_switcher.catalog', LanguageSwitcher::catalogCachePolicy()->resource);
        self::assertSame('i18n', LanguageSwitcher::catalogCachePolicy()->pool);
        self::assertSame('website', LanguageSwitcher::catalogCachePolicy()->scope);
        self::assertContains('global/i18n', LanguageSwitcher::catalogCachePolicy()->dependencies);

        self::assertStringContainsString('StorefrontScopeHotCache', $taglib);
        self::assertStringContainsString('rememberForRequest', $taglib);
        self::assertStringContainsString('rememberPolicy', $taglib);
        self::assertStringContainsString('CATALOG_CACHE_RESOURCE', $taglib);
        self::assertStringContainsString('catalogCachePolicy()', $taglib);
        self::assertStringContainsString('buildLanguagesFromCodes', $taglib);

        // Path-bound HTML shell must stay request-local — never fold URL into catalog HotCache.
        self::assertStringContainsString('buildHtmlCacheKey', $taglib);
        self::assertStringContainsString('$htmlCache', $taglib);
        self::assertStringNotContainsString("self::\$languageCache", $taglib);
        self::assertDoesNotMatchRegularExpression(
            '/rememberPolicy\([\s\S]{0,400}buildHtmlCacheKey|rememberPolicy\([\s\S]{0,400}\$htmlCache/',
            $taglib,
        );
    }
}
