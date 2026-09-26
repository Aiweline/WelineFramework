<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Theme Address Taglib/JS 级联字段须输出 w-field notch DOM。
 */
final class AddressOutlinedFieldContractTest extends TestCase
{
    public function testAddressTaglibPostalDetailUseWField(): void
    {
        $path = dirname(__DIR__, 2) . '/Taglib/Address.php';
        $src = (string)file_get_contents($path);
        self::assertNotSame('', $src);
        self::assertStringContainsString('class="w-field w-address__postal"', $src);
        self::assertStringContainsString('class="w-field w-address__detail"', $src);
        self::assertStringContainsString('<label class="w-field__label">', $src);
        self::assertStringNotContainsString('w-address__label', $src);
    }

    public function testAddressTaglibPrefetchesLabelSourcesBeforeTranslate(): void
    {
        $path = dirname(__DIR__, 2) . '/Taglib/Address.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('Parser::prefetchWords($labelSources', $src);
        self::assertStringContainsString('WidgetI18n::storefrontLocale()', $src);
        self::assertStringContainsString('WidgetI18n::prefetchLabels($labelSources)', $src);
        self::assertStringContainsString("'省份'", $src);
        self::assertStringContainsString("'城市'", $src);
        self::assertStringContainsString("'区县'", $src);
    }

    public function testAddressJsCascadeItemsUseWFieldNotch(): void
    {
        $path = dirname(__DIR__, 2) . '/view/statics/js/address.js';
        $src = (string)file_get_contents($path);
        self::assertNotSame('', $src);
        self::assertStringContainsString('class="w-field w-address__item"', $src);
        self::assertStringContainsString('<label class="w-field__label">', $src);
        self::assertStringContainsString('class="w-input w-address__input"', $src);
        self::assertStringNotContainsString("w-address__label{display:block;margin:0 0", $src);
        self::assertStringContainsString('border-radius:var(--weline-radius-md,var(--weline-theme-radius-md,8px))', $src);
    }

    public function testThemeCssAddressControlMatchesOutlinedInputRadius(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/assets/css/theme.css';
        $src = (string)file_get_contents($path);
        self::assertNotSame('', $src);
        self::assertStringContainsString('.w-address__item.w-field', $src);
        self::assertStringContainsString('border-radius: var(--weline-radius-md, var(--weline-theme-radius-md, 8px));', $src);
        self::assertStringNotContainsString(
            ".w-address__label {
    display: block;
    margin: 0 0 var(--weline-layout-spacing-xs);",
            $src
        );
    }
}
