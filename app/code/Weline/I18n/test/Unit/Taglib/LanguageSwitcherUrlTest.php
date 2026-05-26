<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\I18n\Taglib\LanguageSwitcher;

final class LanguageSwitcherUrlTest extends TestCase
{
    public function testBuildLanguageHrefDoesNotTreatFirstRouteSegmentAsPrefix(): void
    {
        self::assertSame(
            '/CNY/en_US/product/view?id=652',
            $this->buildLanguageHref('/product/view', '?id=652', 'en_US', 'CNY')
        );
    }

    public function testBuildLanguageHrefMovesExistingLocaleBeforeRouteWithoutPrefixGuessing(): void
    {
        self::assertSame(
            '/CNY/zh_Hans_CN/product/frontend/product/view?id=652',
            $this->buildLanguageHref('/product/CNY/en_US/frontend/product/view', '?id=652', 'zh_Hans_CN', 'CNY')
        );
    }

    public function testBuildLanguageHrefKeepsExplicitBackendPrefix(): void
    {
        self::assertSame(
            '/adminKey/CNY/en_US/dashboard',
            $this->buildLanguageHref('/adminKey/dashboard', '', 'en_US', 'CNY', 'adminKey')
        );
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
