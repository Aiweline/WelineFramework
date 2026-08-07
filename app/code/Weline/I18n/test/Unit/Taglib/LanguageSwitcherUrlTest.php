<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\I18n\Taglib\LanguageSwitcher;

final class LanguageSwitcherUrlTest extends TestCase
{
    public function testBuildLanguageHrefOmitsDefaultCurrencyOnFrontend(): void
    {
        self::assertSame(
            '/en_US/product/view?id=652',
            $this->buildLanguageHref('/product/view', '?id=652', 'en_US', 'CNY')
        );
    }

    public function testBuildLanguageHrefStripsDefaultCurrencyFromExistingPath(): void
    {
        // default locale (zh_Hans_CN) is also omitted by LocalizedUrlBuilder
        self::assertSame(
            '/product/frontend/product/view?id=652',
            $this->buildLanguageHref('/product/CNY/en_US/frontend/product/view', '?id=652', 'zh_Hans_CN', 'CNY')
        );
    }

    public function testBuildLanguageHrefOmitsDefaultCurrencyWhenSwitchingLocale(): void
    {
        self::assertSame(
            '/ru_RU/about',
            $this->buildLanguageHref('/CNY/bn_IN/about', '', 'ru_RU', 'CNY')
        );
    }

    public function testBuildLanguageHrefPreservesNonDefaultCurrency(): void
    {
        // Default currency is CNY; USD must stay until the currency switcher changes it.
        // Default locale (zh_Hans_CN) is omitted by LocalizedUrlBuilder.
        self::assertSame(
            '/USD/about',
            $this->buildLanguageHref('/USD/ru_RU/about', '', 'zh_Hans_CN', 'CNY')
        );
        self::assertSame(
            '/USD/en_US/about',
            $this->buildLanguageHref('/USD/ru_RU/about', '', 'en_US', 'CNY')
        );
    }

    public function testBuildLanguageHrefKeepsExplicitBackendPrefix(): void
    {
        self::assertSame(
            '/adminKey/en_US/dashboard',
            $this->buildLanguageHref('/adminKey/dashboard', '', 'en_US', 'CNY', 'adminKey')
        );
    }

    public function testBuildLanguageHrefRestoresBackendPrefixWhenRequestPathWasStripped(): void
    {
        self::assertSame(
            '/adminKey/en_US/admin/dashboard',
            $this->buildLanguageHref('/admin/dashboard', '', 'en_US', 'CNY', 'adminKey')
        );
    }

    public function testBuildLanguageHrefKeepsWebsiteMountAsFixedBaseOutsideLocaleSplit(): void
    {
        $previous = $_SERVER['WELINE_WEBSITE_URL'] ?? null;
        $_SERVER['WELINE_WEBSITE_URL'] = 'https://pre.example.test/aisite_accept_ok';
        try {
            self::assertSame(
                '/aisite_accept_ok/hi_IN/about',
                $this->buildLanguageHref('/about', '', 'hi_IN', 'CNY')
            );
            self::assertSame(
                '/aisite_accept_ok/hi_IN/about',
                $this->buildLanguageHref('/aisite_accept_ok/about', '', 'hi_IN', 'CNY')
            );
            self::assertSame(
                '/aisite_accept_ok/hi_IN/about',
                $this->buildLanguageHref('/hi_IN/aisite_accept_ok/about', '', 'hi_IN', 'CNY')
            );
            self::assertNotSame(
                '/hi_IN/aisite_accept_ok/about',
                $this->buildLanguageHref('/about', '', 'hi_IN', 'CNY')
            );
        } finally {
            if ($previous === null) {
                unset($_SERVER['WELINE_WEBSITE_URL']);
            } else {
                $_SERVER['WELINE_WEBSITE_URL'] = $previous;
            }
        }
    }

    private function buildLanguageHref(
        string $path,
        string $search,
        string $targetLang,
        string $fallbackCurrency = 'CNY',
        string $preferredPrefix = ''
    ): string {
        $method = new ReflectionMethod(LanguageSwitcher::class, 'buildLanguageHref');
        $method->setAccessible(true);

        return (string)$method->invoke(null, $path, $search, $targetLang, $fallbackCurrency, $preferredPrefix);
    }
}
