<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Contract: PDP sticky purchase dock proxies Cart/Checkout CTAs after scroll-away.
 */
final class ProductStickyPurchaseContractTest extends TestCase
{
    public function testProductInfoDeclaresStickyPurchaseDockAndModuleLoad(): void
    {
        $info = dirname(__DIR__, 3) . '/view/templates/frontend/widgets/product-info.phtml';
        $modules = dirname(__DIR__, 3) . '/view/statics/frontend/weline.modules.js';
        $js = dirname(__DIR__, 3) . '/view/statics/js/widgets/product-sticky-purchase.js';

        self::assertFileExists($info);
        self::assertFileExists($modules);
        self::assertFileExists($js);

        $infoSrc = (string) file_get_contents($info);
        $modulesSrc = (string) file_get_contents($modules);
        $jsSrc = (string) file_get_contents($js);

        self::assertStringContainsString('data-testid="product-sticky-purchase"', $infoSrc);
        self::assertStringContainsString('data-sticky-purchase="1"', $infoSrc);
        self::assertStringContainsString('data-weline-load="productStickyPurchase"', $infoSrc);
        self::assertStringContainsString('data-testid="product-sticky-add-to-cart"', $infoSrc);
        self::assertStringContainsString('data-testid="product-sticky-buy-now"', $infoSrc);
        self::assertStringContainsString('data-sticky-proxy="add"', $infoSrc);
        self::assertStringContainsString('data-sticky-proxy="buy-now"', $infoSrc);
        self::assertStringContainsString('data-testid="product-sticky-specs"', $infoSrc);
        self::assertStringContainsString('data-sticky-specs', $infoSrc);
        self::assertStringContainsString('data-testid="product-sticky-jump-variants"', $infoSrc);
        self::assertStringContainsString('data-sticky-jump-variants', $infoSrc);
        self::assertStringContainsString('data-testid="product-sticky-primary-image"', $infoSrc);
        self::assertStringContainsString('data-sticky-primary-image', $infoSrc);
        // Layout contract: media + title/price on the left; specs + jump on the right.
        $mediaPos = strpos($infoSrc, 'data-testid="product-sticky-media"');
        $metaPos = strpos($infoSrc, 'product-native-detail__sticky-purchase-meta');
        $specsPos = strpos($infoSrc, 'data-testid="product-sticky-specs"');
        $jumpPos = strpos($infoSrc, 'data-testid="product-sticky-jump-variants"');
        self::assertNotFalse($mediaPos);
        self::assertNotFalse($metaPos);
        self::assertNotFalse($specsPos);
        self::assertNotFalse($jumpPos);
        self::assertLessThan($metaPos, $mediaPos, 'primary media must precede title/price meta');
        self::assertLessThan($specsPos, $metaPos, 'title/price meta must precede specs');
        self::assertLessThan($jumpPos, $specsPos, 'specs must precede jump-to-variants');
        self::assertStringContainsString('margin-inline-start: auto', $infoSrc);
        self::assertStringContainsString('!$quickAdd && !$quoteOnly && !$isPreviewMode', $infoSrc);
        self::assertStringContainsString('product-native-detail__sticky-purchase', $infoSrc);
        self::assertStringContainsString('--weline-z-sticky-header', $infoSrc);
        self::assertStringContainsString('env(safe-area-inset-bottom', $infoSrc);
        self::assertStringContainsString('--weline-product-sticky-purchase-clearance', $infoSrc);
        self::assertStringContainsString('has-product-sticky-purchase', $infoSrc);

        self::assertStringContainsString('productStickyPurchase', $modulesSrc);
        self::assertStringContainsString('product-sticky-purchase.js', $modulesSrc);

        self::assertStringContainsString('IntersectionObserver', $jsSrc);
        self::assertStringContainsString('product-add-to-cart', $jsSrc);
        self::assertStringContainsString('product-buy-now', $jsSrc);
        self::assertStringContainsString('data-sticky-proxy', $jsSrc);
        self::assertStringContainsString('data-sticky-jump-variants', $jsSrc);
        self::assertStringContainsString('product-variant-axes', $jsSrc);
        self::assertStringContainsString('scrollIntoView', $jsSrc);
        self::assertStringContainsString('data-sticky-specs', $jsSrc);
        self::assertStringContainsString('data-sticky-primary-image', $jsSrc);
        self::assertStringContainsString('data-quick-add', $jsSrc);
        self::assertStringNotContainsString('Api.resource(', $jsSrc);
        self::assertStringNotContainsString('floating.attach', $jsSrc);
    }
}
