<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\StorefrontLocale;

/**
 * Locale resolution for the storefront 404 special-FPC path.
 */
final class StorefrontLocaleTest extends TestCase
{
    public function testResolveLangPrefersPathSegment(): void
    {
        self::assertSame('en_US', StorefrontLocale::resolveLang('/en_US/products'));
        self::assertSame('en_US', StorefrontLocale::resolveLang('/en_US/products', 'lang=ja_JP'));
    }

    public function testResolveLangFallsBackToQuery(): void
    {
        self::assertSame('en_US', StorefrontLocale::resolveLang('/products', 'lang=en_US'));
        self::assertSame('en_US', StorefrontLocale::resolveLang('/', 'locale=en_US&foo=bar'));
    }

    public function testResolveLangFallsBackToDefault(): void
    {
        self::assertSame(StorefrontLocale::DEFAULT_LANG, StorefrontLocale::resolveLang('/products'));
        self::assertSame(StorefrontLocale::DEFAULT_LANG, StorefrontLocale::resolveLang('/ddsds'));
    }

    public function testNormalizeLangCode(): void
    {
        self::assertSame('en_US', StorefrontLocale::normalizeLangCode('en-us'));
        self::assertSame('zh_Hans_CN', StorefrontLocale::normalizeLangCode('zh-hans-CN'));
        self::assertSame('', StorefrontLocale::normalizeLangCode(''));
    }

    public function testLangFallbackChainIsUniqueAndOrdered(): void
    {
        $chain = StorefrontLocale::langFallbackChain('el_GR');
        self::assertSame('el_GR', $chain[0]);
        self::assertContains(StorefrontLocale::DEFAULT_LANG, $chain);
        self::assertContains('en_US', $chain);
        self::assertSame($chain, \array_values(\array_unique($chain)));
    }

    public function testResolveLangFromRequestUri(): void
    {
        self::assertSame('en_US', StorefrontLocale::resolveLangFromRequestUri('/en_US/anything?x=1'));
    }
}
