<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\ProductCardRenderer;

/**
 * wave9-9p：列表批渲染 + fragment key 分桶（eager/lazy），禁平行 static。
 */
final class ProductCardBatchRenderContractTest extends TestCase
{
    public function testProjectFromOffersIsPublicBatchEntry(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ProductCardRenderer.php'
        );
        self::assertStringContainsString('public static function projectFromOffers(', $src);
        self::assertStringContainsString('public static function projectFromIds(', $src);
        self::assertStringContainsString('public static function projectFromProducts(', $src);
        self::assertStringContainsString('public static function projectHtmlByProductId(', $src);
        self::assertStringContainsString('renderCachedBody', $src);
        self::assertStringContainsString('product-card-shelf.phtml', $src);
        self::assertFileExists(
            dirname(__DIR__, 3) . '/view/templates/frontend/partials/product-card-shelf.phtml'
        );
        self::assertStringContainsString('StorefrontProductCardFragmentCache', $src);
        self::assertStringContainsString("'batch' => true", $src);
        self::assertStringContainsString('hydrateReviewAggregates', $src);
        self::assertStringContainsString('aggregatesForExternalUuids', $src);
        self::assertStringNotContainsString('static $cardHtml', $src);
    }

    public function testVideoCarouselPassesIdsOnlyToProductCardTaglib(): void
    {
        // Product/Test/Unit/Service → Weline/Theme/...
        $path = dirname(__DIR__, 4) . '/Theme/view/theme/frontend/widgets/video/video-carousel/default.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('<w:product:card ids="relatedProductIds"', $source);
        self::assertStringNotContainsString('StorefrontProductWidgetCatalog', $source);
        self::assertStringNotContainsString('projectHtmlByProductId', $source);
        self::assertStringNotContainsString('cardsByIds', $source);
    }

    public function testBucketCardIndexCollapsesEagerAndLazyPositions(): void
    {
        $method = new \ReflectionMethod(ProductCardRenderer::class, 'bucketCardIndexForFragmentReuse');
        $method->setAccessible(true);

        $eagerA = $method->invoke(null, ['id' => 1, 'card_index' => 0]);
        $eagerB = $method->invoke(null, ['id' => 1, 'card_index' => 7]);
        $lazyA = $method->invoke(null, ['id' => 1, 'card_index' => 8]);
        $lazyB = $method->invoke(null, ['id' => 1, 'card_index' => 39]);

        self::assertSame(0, (int)$eagerA['card_index']);
        self::assertSame(0, (int)$eagerB['card_index']);
        self::assertSame(8, (int)$lazyA['card_index']);
        self::assertSame(8, (int)$lazyB['card_index']);
        self::assertSame(
            ProductCardRenderer::imageLoadingAttributes($eagerA),
            ProductCardRenderer::imageLoadingAttributes($eagerB)
        );
        self::assertSame(
            ProductCardRenderer::imageLoadingAttributes($lazyA),
            ProductCardRenderer::imageLoadingAttributes($lazyB)
        );
        self::assertNotSame(
            ProductCardRenderer::imageLoadingAttributes($eagerA),
            ProductCardRenderer::imageLoadingAttributes($lazyA)
        );
    }

    public function testListingTemplatesUseBatchProjectionNotPerCardTaglib(): void
    {
        $catalog = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/catalog/index.phtml'
        );
        $category = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/category/index.phtml'
        );

        foreach ([$catalog, $category] as $tpl) {
            self::assertStringContainsString('ProductCardRenderer::projectFromOffers', $tpl);
            self::assertStringNotContainsString('<w:product:card', $tpl);
            self::assertStringNotContainsString('fromStorefrontOffer(', $tpl);
        }
    }

    /**
     * 简单网格：声明式 ids 批渲（Taglib → projectFromIds），禁止模板内 projectFromProducts。
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function recommendationTaglibBatchSurfaces(): iterable
    {
        $productRoot = dirname(__DIR__, 3);
        $themeRoot = dirname(__DIR__, 4) . '/Theme';
        yield 'product-related' => [$productRoot . '/view/templates/frontend/widgets/related-products.phtml', 'ids="products"'];
        yield 'product-recommended' => [$productRoot . '/view/templates/frontend/widgets/recommended-products.phtml', 'ids="products"'];
        yield 'product-you-may-like' => [$productRoot . '/view/templates/frontend/widgets/you-may-like.phtml', 'ids="products"'];
        yield 'theme-related' => [$themeRoot . '/view/theme/frontend/widgets/product/related-products/default.phtml', 'ids="batchProducts"'];
        yield 'theme-up-sell' => [$themeRoot . '/view/theme/frontend/widgets/product/up-sell/default.phtml', 'ids="batchProducts"'];
    }

    /**
     * @dataProvider recommendationTaglibBatchSurfaces
     */
    public function testRecommendationSurfacesUseIdsProductCardTaglib(string $path, string $idsAttr): void
    {
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('<w:product:card ' . $idsAttr, $source);
        self::assertStringNotContainsString('ProductCardRenderer::projectFromProducts', $source);
        self::assertDoesNotMatchRegularExpression(
            '/foreach\s*\([^)]+\)\s*\{[^}]*<w:product:card\s+product=/s',
            $source
        );
    }

    /**
     * 按 id 分桶接线（轮播/交叉销售等）：仍用 projectHtmlByProductId。
     *
     * @return iterable<string, array{0: string}>
     */
    public static function keyedBatchSurfaces(): iterable
    {
        $productRoot = dirname(__DIR__, 3);
        $themeRoot = dirname(__DIR__, 4) . '/Theme';
        yield 'product-best-sellers' => [$productRoot . '/view/templates/frontend/best-sellers/index.phtml'];
        yield 'theme-cross-sell' => [$themeRoot . '/view/theme/frontend/widgets/product/cross-sell/default.phtml'];
        yield 'theme-carousel' => [$themeRoot . '/view/theme/frontend/widgets/carousel/product-carousel/default.phtml'];
    }

    /**
     * @dataProvider keyedBatchSurfaces
     */
    public function testKeyedSurfacesUseProjectHtmlByProductIdNotPerCardTaglib(string $path): void
    {
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('ProductCardRenderer::projectHtmlByProductId', $source);
        self::assertStringNotContainsString('<w:product:card', $source);
    }
}
