<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Controller\Frontend;

use PHPUnit\Framework\TestCase;

final class ProductDetailTemplateContractTest extends TestCase
{
    public function testDetailControllerOwnsTheNativeProductDetailLayout(): void
    {
        $controller = (string)file_get_contents(
            BP . 'app/code/Weline/Product/Controller/Frontend/Detail.php',
        );

        self::assertStringContainsString("\$this->layoutType = 'product_detail'", $controller);
        self::assertStringContainsString('publishedOffer($productId)', $controller);
        self::assertStringContainsString("\$this->getUrl('products')", $controller);
        self::assertStringNotContainsString('WeShop\\Product', $controller);
        self::assertStringNotContainsString('PageBuilder', $controller);
    }

    public function testDetailTemplateUsesPublishedOfferIdentityThroughCartQueryBin(): void
    {
        $template = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/frontend/catalog/detail.phtml',
        );

        self::assertStringContainsString('data-testid="storefront-product-detail"', $template);
        self::assertStringContainsString("Weline.Api.resource('cart')", $template);
        self::assertStringContainsString('issueGuestToken', $template);
        self::assertStringContainsString('addV2', $template);
        self::assertStringContainsString("\$this->getUrl('products')", $template);
        self::assertStringNotContainsString('<dd>', $template);
        self::assertStringContainsString('<strong>', $template);
        self::assertStringNotContainsString('scope:', $template);
        self::assertStringNotContainsString('website_id:', $template);
        self::assertStringNotContainsString('store_code:', $template);
        self::assertStringNotContainsString('fetch(', $template);
        self::assertStringNotContainsString('axios', $template);
    }

}
