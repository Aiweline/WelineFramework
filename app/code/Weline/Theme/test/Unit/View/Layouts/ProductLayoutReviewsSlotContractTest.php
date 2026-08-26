<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View\Layouts;

use PHPUnit\Framework\TestCase;

final class ProductLayoutReviewsSlotContractTest extends TestCase
{
    public function testProductLayoutProvidesReviewsContainerWithoutHardcodedWidget(): void
    {
        $path = dirname(__DIR__, 4) . '/view/theme/frontend/layouts/product/default.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('id="product-reviews"', $source);
        self::assertStringContainsString('accept="layout-product-reviews,product-reviews,review,reviews"', $source);
        self::assertStringContainsString('showReviews', $source);
        self::assertStringContainsString('product-detail-layout__reviews', $source);
        self::assertStringContainsString('$coerceBool', $source);
        self::assertStringContainsString("\$showReviews = \$coerceBool(\$meta['showReviews']", $source);
        self::assertDoesNotMatchRegularExpression('/<w:widget[^>]*(product-reviews|name="product-reviews")/i', $source);
    }

    public function testProductLayoutProvidesMainInfoSlotWithoutHardcodedWidget(): void
    {
        $path = dirname(__DIR__, 4) . '/view/theme/frontend/layouts/product/default.phtml';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('id="product-main"', $source);
        self::assertStringContainsString('accept="layout-product-main,product-gallery,product-info,product-options,add-to-cart,product-detail"', $source);
        self::assertStringContainsString('product-detail-layout__preview-mock', $source);
        self::assertStringNotContainsString('condition="contentTemplate"', $source);
        self::assertStringNotContainsString('$contentTemplate', $source);
        self::assertDoesNotMatchRegularExpression('/<w:widget[^>]*(product-info|name="product-info")/i', $source);
    }
}
