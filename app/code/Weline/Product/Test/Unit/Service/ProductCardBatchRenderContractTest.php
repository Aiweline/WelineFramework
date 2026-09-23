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
        self::assertStringContainsString('renderCachedBody', $src);
        self::assertStringContainsString('StorefrontProductCardFragmentCache', $src);
        self::assertStringContainsString("'batch' => true", $src);
        self::assertStringNotContainsString('static $cardHtml', $src);
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
}
