<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Extends\Module\Weline_Search;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/bootstrap.php';

final class ProductSearchHitTemplateContractTest extends TestCase
{
    public function testProductSearchProviderDeclaresProductOwnedHitTemplate(): void
    {
        $provider = (string)file_get_contents(
            BP . 'app/code/Weline/Product/extends/module/Weline_Search/Searcher/ProductSearchProvider.php',
        );
        $hitTemplate = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/frontend/search/hit.phtml',
        );
        $offerCard = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/frontend/partials/storefront-offer-card.phtml',
        );

        self::assertStringContainsString(
            "return 'Weline_Product::templates/frontend/search/hit.phtml';",
            $provider,
        );
        self::assertStringContainsString('ProductSearchHitPresenter', $provider);
        self::assertStringContainsString('storefront-offer-card.phtml', $hitTemplate);
        self::assertStringContainsString('product-storefront__card', $offerCard);
        self::assertStringContainsString('data-testid="storefront-product-card"', $offerCard);
        self::assertStringContainsString('StorefrontOfferDetailQuery::params', $offerCard);
        self::assertStringContainsString('href="@url{$productUrl|$productUrlParams}"', $offerCard);
    }
}
