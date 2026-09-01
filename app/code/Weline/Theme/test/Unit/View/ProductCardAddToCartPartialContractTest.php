<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class ProductCardAddToCartPartialContractTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function cardSurfacesUsingSharedPartial(): iterable
    {
        yield 'card-partial' => ['view/theme/frontend/partials/product/card.phtml'];
        yield 'grid-partial' => ['view/theme/frontend/partials/product/grid.phtml'];
        yield 'bestsellers' => ['view/theme/frontend/widgets/product/bestsellers/default.phtml'];
        yield 'featured-products' => ['view/theme/frontend/widgets/product/featured-products/default.phtml'];
        yield 'new-arrivals' => ['view/theme/frontend/widgets/product/new-arrivals/default.phtml'];
        yield 'related-products' => ['view/theme/frontend/widgets/product/related-products/default.phtml'];
    }

    /**
     * @dataProvider cardSurfacesUsingSharedPartial
     */
    public function testProductCardSurfaceUsesCartHookPartial(string $relativePath): void
    {
        $path = dirname(__DIR__, 3) . '/' . $relativePath;
        self::assertFileExists($path);
        $content = (string)file_get_contents($path);
        self::assertStringContainsString(
            'theme/frontend/partials/product/add-to-cart.phtml',
            $content,
        );
        self::assertStringContainsString('ProductCardAddToCartParams::fetchDictionary', $content);
        self::assertStringNotContainsString('data-action="add-to-cart"', $content);
    }
}
