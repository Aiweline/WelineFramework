<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\Http\Request;
use Weline\I18n\Taglib\LanguageSwitcher;

final class LanguageSwitcherBackendAuthoritativeUrlTest extends TestCase
{
    public function testBackendOmitsDefaultCurrencyButKeepsDefaultLocaleAndQuery(): void
    {
        $backendKey = 'jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH';

        self::assertSame(
            '/' . $backendKey . '/en_US/cms/backend/page/edit?page_id=6&path_group=blog',
            $this->buildLanguageHref(
                '/' . $backendKey . '/ru_RU/cms/backend/page/edit',
                '?page_id=6&path_group=blog',
                'en_US',
                'CNY',
                $backendKey
            )
        );
    }

    public function testRequestQueryParametersAreUsedWhenWlsOmitsQueryStringServerValue(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getServer')->with('QUERY_STRING')->willReturn('');
        $request->method('getQuery')->willReturn([
            'page_id' => '6',
            'path_group' => 'blog',
        ]);

        self::assertSame(
            '?page_id=6&path_group=blog',
            $this->resolveCurrentSearch($request)
        );
    }

    private function resolveCurrentSearch(Request $request): string
    {
        $method = new ReflectionMethod(LanguageSwitcher::class, 'resolveCurrentSearch');
        $method->setAccessible(true);

        return (string)$method->invoke(null, $request);
    }

    private function buildLanguageHref(
        string $path,
        string $search,
        string $targetLang,
        string $fallbackCurrency,
        string $preferredPrefix
    ): string {
        $method = new ReflectionMethod(LanguageSwitcher::class, 'buildLanguageHref');
        $method->setAccessible(true);

        return (string)$method->invoke(
            null,
            $path,
            $search,
            $targetLang,
            $fallbackCurrency,
            $preferredPrefix
        );
    }
}
