<?php

declare(strict_types=1);

namespace Weline\Visitor\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * PDP commerce events (quick_buy / add_to_cart / …) must resolve price via the
 * shared storefront major-price helper that reads data-offer-price-minor.
 */
final class PixelStorefrontPriceContractTest extends TestCase
{
    public function testProductMetaReadsOfferPriceMinorAndCurrency(): void
    {
        $root = \dirname(__DIR__, 3);
        $pixel = (string) \file_get_contents($root . '/view/statics/js/pixel.js');
        $phtml = (string) \file_get_contents($root . '/view/taglib/js/pixel.phtml');
        $bootstrap = (string) \file_get_contents($root . '/Service/PixelBootstrapHtmlService.php');
        $pdp = (string) \file_get_contents(
            \dirname($root) . '/Product/view/templates/frontend/widgets/product-info.phtml'
        );

        foreach ([$pixel, $phtml] as $src) {
            self::assertStringContainsString('function __readStorefrontPriceMajor', $src);
            self::assertStringContainsString('data-offer-price-minor', $src);
            self::assertStringContainsString('data-catalog-price-minor', $src);
            self::assertStringContainsString('.product-native-detail__price', $src);
            self::assertStringContainsString('currency: currency', $src);
            self::assertStringContainsString('price = __readStorefrontPriceMajor(element)', $src);
        }

        self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '20260923-r2d-param2'", $bootstrap);

        self::assertStringContainsString('data-offer-price-minor', $pdp);
        self::assertStringContainsString('data-price=', $pdp);
        self::assertStringContainsString('data-pixel-value=', $pdp);
        self::assertStringContainsString('data-pixel-currency=', $pdp);
        self::assertStringContainsString("root.setAttribute('data-price', offerMajor)", $pdp);
        self::assertStringContainsString("root.setAttribute('data-pixel-currency', code)", $pdp);
    }
}
