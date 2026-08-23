<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Controller\Frontend;

use PHPUnit\Framework\TestCase;

final class ProductDetailTemplateContractTest extends TestCase
{
    public function testDetailTemplateRendersPublishedDescriptionAndSpecifications(): void
    {
        $template = BP . 'app/code/Weline/Product/view/templates/frontend/catalog/detail.phtml';
        $view = new class([
            'storefront_offer' => [
                'product_id' => 9,
                'provider_code' => 'product',
                'global_offer_uuid' => 'offer-9',
                'name' => 'ZTOT Z7-L YBS300 PRO',
                'sku' => 'ZTOT-Z7L-YBS300PRO',
                'image' => '/media/primary.jpg',
                'images' => ['/media/primary.jpg', '/media/secondary.jpg'],
                'currency' => 'USD',
                'unit_price_minor' => 233700,
                'stock' => 999,
                'sellable' => true,
                'message' => '',
                'short_description' => 'Wholesale off-road motorcycle for dealer buyers.',
                'description' => 'Full dealer product description.',
                'specifications' => [
                    ['code' => 'engine', 'value' => 'LONCIN YBS300 PRO'],
                    ['code' => 'displacement', 'value' => '294.9 ml'],
                ],
            ],
        ]) {
            /** @param array<string, mixed> $data */
            public function __construct(private readonly array $data)
            {
            }

            public function getData(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function getUrl(string $path): string
            {
                return '/USD/' . ltrim($path, '/');
            }

            public function render(string $template): string
            {
                ob_start();
                include $template;
                return (string)ob_get_clean();
            }
        };

        $html = $view->render($template);

        self::assertStringContainsString('Wholesale off-road motorcycle for dealer buyers.', $html);
        self::assertStringContainsString('Full dealer product description.', $html);
        self::assertStringContainsString('data-testid="product-specifications"', $html);
        self::assertStringContainsString('LONCIN YBS300 PRO', $html);
        self::assertStringContainsString('294.9 ml', $html);
        self::assertStringContainsString('/media/secondary.jpg', $html);
        self::assertStringNotContainsString('<dd>', $html);
    }

    public function testDetailControllerOwnsTheNativeProductDetailLayout(): void
    {
        $controller = (string)file_get_contents(
            BP . 'app/code/Weline/Product/Controller/Frontend/Detail.php',
        );

        self::assertStringContainsString("\$this->layoutType = 'product'", $controller);
        self::assertStringContainsString("setGet('page_type', 'product')", $controller);
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
        self::assertStringContainsString('<strong>', $template);
        self::assertStringNotContainsString('scope:', $template);
        self::assertStringNotContainsString('website_id:', $template);
        self::assertStringNotContainsString('store_code:', $template);
        self::assertStringNotContainsString('fetch(', $template);
        self::assertStringNotContainsString('axios', $template);
    }

}
