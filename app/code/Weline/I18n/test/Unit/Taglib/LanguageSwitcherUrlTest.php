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

    public function testBuildLanguageHrefUnderLivePreviewOmitsSiteMountAndCarriesPreview(): void
    {
        if (!\class_exists(\Weline\Theme\Service\ThemeLivePreviewPathMount::class)) {
            self::markTestSkipped('Weline_Theme not available');
        }

        $token = 'pv_abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG';
        $previousUrl = $_SERVER['WELINE_WEBSITE_URL'] ?? null;
        $previousOrigin = $_SERVER['WELINE_ORIGIN_REQUEST_URI'] ?? null;
        $_SERVER['WELINE_WEBSITE_URL'] = 'https://p05113ef3.test.weline.com/~site/grocery';
        $_SERVER['WELINE_ORIGIN_REQUEST_URI'] = '/~preview/' . $token . '/CNY/terms';
        try {
            \Weline\Framework\Runtime\RequestContext::set(
                \Weline\Theme\Service\ThemeLivePreviewPathMount::REQUEST_CONTEXT_TOKEN_KEY,
                $token
            );
            $href = $this->buildLanguageHref(
                '/~preview/' . $token . '/CNY/terms',
                '',
                'zh_Hans_CN',
                'CNY'
            );
            self::assertStringContainsString('/~preview/' . $token, $href);
            self::assertStringContainsString('/terms', $href);
            self::assertStringNotContainsString('/~site/', $href);
            self::assertStringNotContainsString('/~site/grocery/~preview/', $href);
            self::assertMatchesRegularExpression(
                '#^/~preview/' . \preg_quote($token, '#') . '(/|$)#',
                \explode('?', $href, 2)[0]
            );
        } finally {
            try {
                \Weline\Framework\Runtime\RequestContext::remove(
                    \Weline\Theme\Service\ThemeLivePreviewPathMount::REQUEST_CONTEXT_TOKEN_KEY
                );
            } catch (\Throwable) {
            }
            if ($previousUrl === null) {
                unset($_SERVER['WELINE_WEBSITE_URL']);
            } else {
                $_SERVER['WELINE_WEBSITE_URL'] = $previousUrl;
            }
            if ($previousOrigin === null) {
                unset($_SERVER['WELINE_ORIGIN_REQUEST_URI']);
            } else {
                $_SERVER['WELINE_ORIGIN_REQUEST_URI'] = $previousOrigin;
            }
        }
    }

    public function testDefaultSiteMountIgnoresStickyGroceryCookie(): void
    {
        $previousUrl = $_SERVER['WELINE_WEBSITE_URL'] ?? null;
        $previousCookie = null;
        if (\function_exists('w_env_cookie')) {
            $previousCookie = \w_env_cookie('WELINE_WEBSITE_URL', null);
        }
        $_SERVER['WELINE_WEBSITE_URL'] = 'https://p05113ef3.test.weline.com/';
        if (\function_exists('w_env_set')) {
            \w_env_set('cookie.WELINE_WEBSITE_URL', 'https://p05113ef3.test.weline.com/~site/grocery');
        }
        try {
            self::assertSame('', $this->resolveWebsiteMountPath());
            $href = $this->buildLanguageHref('/', '', 'en_US', 'CNY');
            self::assertStringNotContainsString('/~site/', $href);
            self::assertStringNotContainsString('grocery', $href);
            self::assertStringContainsString('en_US', $href);
        } finally {
            if ($previousUrl === null) {
                unset($_SERVER['WELINE_WEBSITE_URL']);
            } else {
                $_SERVER['WELINE_WEBSITE_URL'] = $previousUrl;
            }
            if (\function_exists('w_env_set')) {
                \w_env_set('cookie.WELINE_WEBSITE_URL', $previousCookie);
            }
        }
    }

    public function testGrocerySiteMountStillEmittedFromRequestBoundUrl(): void
    {
        $previousUrl = $_SERVER['WELINE_WEBSITE_URL'] ?? null;
        $_SERVER['WELINE_WEBSITE_URL'] = 'https://p05113ef3.test.weline.com/~site/grocery';
        try {
            self::assertSame('~site/grocery', $this->resolveWebsiteMountPath());
            $href = $this->buildLanguageHref('/', '', 'en_US', 'CNY');
            self::assertStringContainsString('/~site/grocery', $href);
        } finally {
            if ($previousUrl === null) {
                unset($_SERVER['WELINE_WEBSITE_URL']);
            } else {
                $_SERVER['WELINE_WEBSITE_URL'] = $previousUrl;
            }
        }
    }

    private function resolveWebsiteMountPath(): string
    {
        $method = new ReflectionMethod(LanguageSwitcher::class, 'resolveWebsiteMountPath');
        $method->setAccessible(true);

        return (string)$method->invoke(null, null);
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
