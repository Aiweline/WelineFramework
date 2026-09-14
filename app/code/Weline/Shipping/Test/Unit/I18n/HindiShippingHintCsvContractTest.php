<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\I18n;

use PHPUnit\Framework\TestCase;

final class HindiShippingHintCsvContractTest extends TestCase
{
    public function testHindiCsvTranslatesCheckoutFreightHint(): void
    {
        $path = dirname(__DIR__, 3) . '/i18n/hi_IN.csv';
        self::assertFileExists($path);
        $hi = (string)file_get_contents($path);
        self::assertStringContainsString('运费以结算页为准,"शिपिंग शुल्क चेकआउट पर तय होगा"', $hi);
        self::assertStringContainsString('重货,"भारी माल"', $hi);
        self::assertDoesNotMatchRegularExpression(
            '/^本商品使用重货配送方案，运费以结算页为准,本商品使用重货配送方案/m',
            $hi,
        );
        self::assertMatchesRegularExpression('/\\p{Devanagari}/u', $hi);
    }

    public function testPreviewHintReturnsSourceKeysForStorefrontWidgetI18n(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3)
            . '/extends/module/Weline_Product/StorefrontShippingProfileCatalogProvider/ShippingServiceProfileCatalogProvider.php',
        );
        self::assertStringContainsString("'note' => '运费以结算页为准'", $src);
        self::assertStringNotContainsString("__('运费以结算页为准')", $src);
    }
}
