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

    public function testProductLayoutRelatedRecommendationsDefaultOffWithoutBestsellersFallback(): void
    {
        $path = dirname(__DIR__, 4) . '/view/theme/frontend/layouts/product/default.phtml';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('showRelatedProducts {default=false', $source);
        self::assertStringContainsString('$showRelatedProducts = $coerceBool($meta[\'showRelatedProducts\']', $source);
        self::assertMatchesRegularExpression('/\$showRelatedProducts = \$coerceBool\([^\n]+, false\);/', $source);
        self::assertStringContainsString('id="product-related-products"', $source);
        self::assertStringContainsString('condition="meta.showRelatedProducts"', $source);
        self::assertDoesNotMatchRegularExpression('/bestsellers<else\/>[\s\S]*name="bestsellers"/', $source);
    }

    public function testProductLayoutAlwaysShowsYouMayLikeAndRecentlyViewedSlotsOutsideRelatedGate(): void
    {
        $path = dirname(__DIR__, 4) . '/view/theme/frontend/layouts/product/default.phtml';
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('id="product-you-may-like"', $source);
        self::assertStringContainsString('accept="you-may-like,product-carousel"', $source);
        self::assertStringContainsString('Weline_Theme::frontend::layouts::product::you-may-like', $source);
        self::assertStringContainsString('id="product-recently-viewed"', $source);
        self::assertStringContainsString('product-detail-layout__personalization', $source);
        self::assertStringContainsString('.product-detail-layout__personalization-container', $source);
        self::assertStringContainsString('--weline-layout-content-max-width', $source);
        self::assertDoesNotMatchRegularExpression(
            '/<w:widget[^>]*(you-may-like|recently-viewed)/i',
            $source,
            'Layout must not hardcode you-may-like or recently-viewed widgets.',
        );

        self::assertMatchesRegularExpression(
            '/product-detail-layout__personalization[\s\S]*id="product-you-may-like"[\s\S]*id="product-recently-viewed"/',
            $source,
        );

        // Gated related stack must not still own recently-viewed (avoid duplicate slot ids).
        if (preg_match(
            '/condition="meta\.showRelatedProducts"[\s\S]*?<\/if>/',
            $source,
            $gateMatch
        ) === 1) {
            self::assertStringNotContainsString(
                'id="product-recently-viewed"',
                $gateMatch[0],
                'product-recently-viewed must live outside showRelatedProducts gate.',
            );
            self::assertStringNotContainsString(
                'id="product-you-may-like"',
                $gateMatch[0],
                'product-you-may-like must live outside showRelatedProducts gate.',
            );
        }
    }
}
