<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Taglib\LanguageSelect;
use Weline\I18n\Taglib\LanguageSwitcher;

final class LanguageInjectLocalesContractTest extends TestCase
{
    public function testDisplayNamePrefersNativeSelfNameOverEnglish(): void
    {
        $method = new \ReflectionMethod(LanguageSelect::class, 'buildDisplayName');
        $method->setAccessible(true);

        $display = (string)$method->invoke(
            null,
            'китайский (упрощенная, Китай)',
            'Chinese (Simplified, China)',
            '中文（简体，中国）',
            'zh_Hans_CN'
        );

        self::assertSame(
            'китайский (упрощенная, Китай) (中文（简体，中国）)',
            $display
        );
        self::assertStringNotContainsString('Chinese (Simplified, China)', $display);

        $sameLocale = (string)$method->invoke(
            null,
            'русский (Россия)',
            'Russian (Russia)',
            'русский (Россия)',
            'ru_RU'
        );
        self::assertSame('русский (Россия)', $sameLocale);
    }

    public function testLanguageSwitcherPinsCurrentCountryGroupFirst(): void
    {
        $html = LanguageSwitcher::render([
            'allowed_values' => ['zh_Hans_CN', 'ru_RU', 'bn_IN', 'as_IN'],
            'current' => 'bn_IN',
            'navigation' => 'emit',
        ]);

        self::assertStringContainsString('data-i18n-switcher', $html);

        \preg_match_all('/data-lang="([^"]+)"/', $html, $matches);
        $order = $matches[1] ?? [];
        self::assertSame(
            ['bn_IN', 'as_IN', 'zh_Hans_CN', 'ru_RU'],
            $order,
            'current Indian country group must render before China/Russia; got: ' . \implode(',', $order)
        );
    }

    public function testLanguageSwitcherInjectsAuthoritativeLocalesWithoutWebsite(): void
    {
        $html = LanguageSwitcher::render([
            'allowed_values' => ['as_IN', 'en_US', 'bn_IN'],
            'current' => 'as_IN',
            'navigation' => 'emit',
        ]);

        self::assertStringContainsString('data-i18n-switcher', $html);
        self::assertStringContainsString('data-i18n-navigation="emit"', $html);
        self::assertStringContainsString('data-lang="as_IN"', $html);
        self::assertStringContainsString('data-lang="en_US"', $html);
        self::assertStringContainsString('data-lang="bn_IN"', $html);
        self::assertMatchesRegularExpression('/data-lang="as_IN"[^>]*(?:active|aria-checked="true")/', $html);
    }

    public function testLanguageSelectResolveInjectsMissingCodesInCallerOrder(): void
    {
        $items = LanguageSelect::resolveLanguageItems(
            'zh_Hans_CN',
            'installed',
            ['as_IN', 'en_US', 'zz_QQ']
        );
        $codes = \array_values(\array_map(
            static fn(array $item): string => (string)($item['code'] ?? ''),
            $items
        ));

        self::assertSame(['as_IN', 'en_US', 'zz_QQ'], $codes);
        self::assertNotSame('', (string)($items[0]['display_name'] ?? $items[0]['name'] ?? ''));
        self::assertSame('zz_QQ', (string)($items[2]['name'] ?? ''));
    }

    public function testLanguageSwitcherAttrDocumentsLocalesCurrentNavigation(): void
    {
        $attrs = LanguageSwitcher::attr();
        self::assertArrayHasKey('locales', $attrs);
        self::assertArrayHasKey('current', $attrs);
        self::assertArrayHasKey('navigation', $attrs);

        $selectAttrs = LanguageSelect::attr();
        self::assertArrayHasKey('locales', $selectAttrs);
        self::assertArrayHasKey('catalog', $selectAttrs);
    }
}
