<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Controller\Frontend;

use PHPUnit\Framework\TestCase;

final class ProductStorefrontTemplateContractTest extends TestCase
{
    public function testProductControllerSelectsTheProductListThemeLayout(): void
    {
        $controller = (string)file_get_contents(
            BP . 'app/code/Weline/Product/Controller/Frontend/Catalog.php',
        );

        self::assertStringContainsString('$this->layoutType = \'product_list\'', $controller);
        self::assertStringContainsString("setGet('page_type', 'products')", $controller);
    }

    public function testProductControllerDoesNotReachIntoCartConcreteServices(): void
    {
        $controller = (string)file_get_contents(
            BP . 'app/code/Weline/Product/Controller/Frontend/Catalog.php',
        );

        self::assertStringNotContainsString('Weline\\Cart', $controller);
        self::assertStringNotContainsString('ObjectManager', $controller);
        self::assertStringNotContainsString('Cookie::', $controller);
    }

    public function testBrowserObtainsGuestCartTokenThroughThePublishedCartApi(): void
    {
        $template = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/frontend/catalog/index.phtml',
        );

        self::assertStringContainsString("Weline.Api.resource('cart')", $template);
        self::assertStringContainsString("issueGuestToken", $template);
        self::assertStringContainsString("const guestTokenStorageKey = 'weline.cart.guest_token'", $template);
        self::assertStringContainsString('window.sessionStorage.getItem(guestTokenStorageKey)', $template);
        self::assertStringContainsString('window.sessionStorage.setItem(guestTokenStorageKey, guestToken)', $template);
    }

    public function testCartLinkKeepsTheActiveCurrencyAndLocaleRoute(): void
    {
        $template = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/frontend/catalog/index.phtml',
        );

        self::assertStringContainsString('$this->getUrl(\'cart\')', $template);
        self::assertStringNotContainsString('href="/cart"', $template);
    }

    public function testCatalogCardsLinkToTheLocaleAwareProductDetailRoute(): void
    {
        $template = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/frontend/catalog/index.phtml',
        );

        self::assertStringContainsString(
            "\$this->getUrl('product/' . \$productId)",
            $template,
        );
        self::assertStringContainsString('class="product-storefront__media-link"', $template);
        self::assertStringContainsString('class="product-storefront__title-link"', $template);
        self::assertStringNotContainsString('href="/product/', $template);
    }

    public function testBrowserCartMutationDoesNotSubmitAClientOwnedScope(): void
    {
        $template = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/frontend/catalog/index.phtml',
        );

        self::assertStringNotContainsString('scope:', $template);
        self::assertStringNotContainsString('website_id:', $template);
        self::assertStringNotContainsString('store_code:', $template);
    }
}
