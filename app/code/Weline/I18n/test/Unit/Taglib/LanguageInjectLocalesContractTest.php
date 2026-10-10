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
        // Storefront SSR is panel-lazy (no data-lang options). Assert grouping order directly.
        $languages = [
            'zh_Hans_CN' => ['code' => 'zh_Hans_CN', 'country_code' => 'CN', 'country_name' => 'China'],
            'ru_RU' => ['code' => 'ru_RU', 'country_code' => 'RU', 'country_name' => 'Russia'],
            'bn_IN' => ['code' => 'bn_IN', 'country_code' => 'IN', 'country_name' => 'India'],
            'as_IN' => ['code' => 'as_IN', 'country_code' => 'IN', 'country_name' => 'India'],
        ];
        $method = new \ReflectionMethod(LanguageSwitcher::class, 'groupLanguagesByCountry');
        $method->setAccessible(true);
        /** @var list<array{country_code:string,country_name:string,languages:array<string,array<string,mixed>>}> $groups */
        $groups = $method->invoke(null, $languages, 'zh_Hans_CN', 'bn_IN');

        $order = [];
        foreach ($groups as $group) {
            foreach (\array_keys($group['languages'] ?? []) as $code) {
                $order[] = (string)$code;
            }
        }
        self::assertSame(
            ['bn_IN', 'as_IN', 'zh_Hans_CN', 'ru_RU'],
            $order,
            'current Indian country group must sort before China/Russia; got: ' . \implode(',', $order)
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
        // Storefront panel is lazy; authoritative inject order lands on supported-locales.
        self::assertMatchesRegularExpression(
            '/data-i18n-supported-locales="[^"]*as_IN[^"]*"/',
            $html
        );
        self::assertStringContainsString('en_US', (string)(\preg_match('/data-i18n-supported-locales="([^"]*)"/', $html, $m) ? $m[1] : ''));
        self::assertStringContainsString('bn_IN', (string)(\preg_match('/data-i18n-supported-locales="([^"]*)"/', $html, $m) ? $m[1] : ''));
        self::assertStringContainsString('data-i18n-current-locale="as_IN"', $html);
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

    public function testInstalledAllowlistResolveDoesNotUpgradeToGlobalCatalogInSource(): void
    {
        $src = (string)\file_get_contents(
            BP . '/app/code/Weline/I18n/Taglib/LanguageSelect.php'
        );
        $switcherSrc = (string)\file_get_contents(
            BP . '/app/code/Weline/I18n/Taglib/LanguageSwitcher.php'
        );
        self::assertStringNotContainsString(
            "\$catalog === 'installed' ? 'global'",
            $src,
            'installed+allowlist must not call getLanguageItems(global)'
        );
        self::assertStringContainsString(
            'buildInstalledLanguageItemsForCodes',
            $src,
            'website/inject allowlist must resolve only those codes from Locals'
        );
        self::assertStringContainsString(
            'countryNamesFromDbForCodes',
            $src,
            'allowlist path must load country labels from i18n_countries_locale_name'
        );
        self::assertStringContainsString(
            "->where(Locals::schema_fields_CODE, \$normalized, 'IN')",
            $src,
            'Locals subset query must use where(field, codes, IN) ORM order'
        );
        self::assertStringNotContainsString(
            "getLanguageItems(\$displayLocale, 'installed');\n        } else {",
            $src,
            'allowlist path must not materialize full installed catalog then filter'
        );
        self::assertStringNotContainsString(
            'LanguageSelect::getLanguageItems($displayLocale)',
            $switcherSrc,
            'groupLanguagesByCountry must not reload full LanguageSelect catalog'
        );
        $builderStart = \strpos($src, 'function buildInstalledLanguageItemsForCodes');
        $builderEnd = \strpos($src, 'function pickLocalNameForTarget', (int)$builderStart);
        self::assertNotFalse($builderStart);
        self::assertNotFalse($builderEnd);
        $builderBody = \substr($src, (int)$builderStart, (int)$builderEnd - (int)$builderStart);
        self::assertStringNotContainsString(
            '->getLocaleName(',
            $builderBody,
            'allowlist builder must not call I18n/Symfony locale name APIs'
        );
        self::assertStringNotContainsString(
            'Locales::getName',
            $builderBody,
            'allowlist builder must not call Symfony Locales::getName'
        );
    }

    public function testRepeatedLanguageSwitcherRenderKeepsInstanceIdsUniqueAndAriaLinked(): void
    {
        LanguageSwitcher::clearProcessCaches();
        $attributes = [
            'allowed_values' => ['zh_Hans_CN', 'en_US'],
            'current' => 'zh_Hans_CN',
            'navigation' => 'emit',
        ];
        $first = LanguageSwitcher::render($attributes);
        $second = LanguageSwitcher::render($attributes);

        preg_match('/data-i18n-switcher-id="([^"]+)"/', $first, $firstMatch);
        preg_match('/data-i18n-switcher-id="([^"]+)"/', $second, $secondMatch);
        $firstId = (string)($firstMatch[1] ?? '');
        $secondId = (string)($secondMatch[1] ?? '');

        self::assertNotSame('', $firstId);
        self::assertNotSame('', $secondId);
        self::assertNotSame($firstId, $secondId);
        foreach ([[$first, $firstId], [$second, $secondId]] as [$html, $id]) {
            self::assertStringContainsString('id="' . $id . '-toggle"', $html);
            self::assertStringContainsString('aria-controls="' . $id . '-panel"', $html);
            self::assertStringContainsString('id="' . $id . '-panel"', $html);
            self::assertStringContainsString('aria-labelledby="' . $id . '-toggle"', $html);
        }
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
