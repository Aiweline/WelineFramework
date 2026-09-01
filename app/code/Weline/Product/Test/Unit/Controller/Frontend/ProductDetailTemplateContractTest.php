<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Controller\Frontend;

use PHPUnit\Framework\TestCase;

final class ProductDetailTemplateContractTest extends TestCase
{
    public function testDetailTemplateRendersPublishedDescriptionAndSpecifications(): void
    {
        $template = BP . 'app/code/Weline/Product/view/templates/frontend/widgets/product-info.phtml';
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
        self::assertStringContainsString('publishedOffersForProduct($productId)', $controller);
        self::assertStringContainsString('publishedOffersBySlug($slug)', $controller);
        self::assertStringContainsString("\$this->getUrl('products')", $controller);
        self::assertStringContainsString('detail-shell.phtml', $controller);
        self::assertStringNotContainsString('product_detail', $controller);
        self::assertStringNotContainsString('WeShop\\Product', $controller);
        self::assertStringNotContainsString('PageBuilder', $controller);
    }


    public function testDetailTemplateUsesPublishedOfferIdentityThroughCartQueryBin(): void
    {
        $template = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/frontend/widgets/product-info.phtml',
        );

        self::assertStringContainsString('data-testid="storefront-product-detail"', $template);
        self::assertStringContainsString('id="product-purchase-actions"', $template);
        self::assertStringContainsString('product-purchase-actions', $template);
        self::assertStringContainsString('@param show_brand', $template);
        self::assertStringContainsString('@param show_supplier', $template);
        self::assertStringContainsString('data-testid="product-brand"', $template);
        self::assertStringContainsString('data-testid="product-supplier"', $template);
        self::assertStringContainsString('@url{\'products\'}', $template);
        self::assertStringContainsString('@widget.code {product-info}', $template);
        self::assertStringContainsString('product-main', $template);
        self::assertStringContainsString('data-variant-live-url', $template);
        self::assertStringContainsString('refreshLiveAvailability', $template);
        self::assertStringNotContainsString('scope:', $template);
        self::assertStringNotContainsString('website_id:', $template);
        self::assertStringNotContainsString('store_code:', $template);
        self::assertStringNotContainsString("Weline.Api.resource('cart')", $template);
        self::assertStringNotContainsString('axios', $template);
    }

    public function testConfigurableDetailRequiresAnExplicitOfferIdentity(): void
    {
        $template = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/frontend/widgets/product-info.phtml',
        );
        $controller = (string)file_get_contents(
            BP . 'app/code/Weline/Product/Controller/Frontend/Detail.php',
        );
        $service = (string)file_get_contents(
            BP . 'app/code/Weline/Product/Service/StorefrontCatalogViewService.php',
        );

        self::assertStringContainsString('data-testid="product-offer-selector"', $template);
        self::assertStringContainsString('name="offer"', $template);
        self::assertStringContainsString('请选择规格', $template);
        self::assertStringContainsString("getParam('offer', '')", $controller);
        self::assertStringContainsString('StorefrontVariantSelectionService', $controller);
        self::assertStringContainsString("'variant_catalog'", $controller);
        self::assertStringContainsString('data-variant-interactive="1"', $template);
        self::assertStringContainsString('data-variant-option="1"', $template);
        self::assertStringContainsString('is-out-of-stock', $template);
        self::assertStringContainsString('isOptionSellable', $template);
        self::assertStringContainsString('isOfferSellable', $template);
        self::assertStringContainsString('resolveSelectionToInStock', $template);
        self::assertStringContainsString('resolveSelectionGallery', $template);
        self::assertStringContainsString('gallery_by_color', $template);
        self::assertStringContainsString('resolveOptionGallery', $template);
        self::assertStringContainsString('全局 EAV 选项图板仅表示', (string)file_get_contents(
            BP . 'app/code/Weline/Product/Service/StorefrontVariantAxisResolver.php',
        ));
        self::assertStringContainsString('clearVariantOptionSwatchImages', (string)file_get_contents(
            BP . 'app/code/Weline/Product/Service/ProductCatalogEavBootstrap.php',
        ));
        self::assertStringContainsString('data-variant-live-url', $template);
        self::assertStringContainsString('refreshLiveAvailability', $template);
        self::assertStringContainsString('mergeLiveOffers', $template);
        self::assertStringContainsString("\$displayOffer['global_offer_uuid'] = '';", $controller);
        self::assertStringContainsString('publishedOffersForProduct', $service);
        self::assertStringContainsString('publishedOffersBySlug', $service);
        self::assertStringContainsString('livePublishedOffersForProduct', $service);
        self::assertStringContainsString('attachPrimarySupplier', $service);
        self::assertStringContainsString('supplier_name', $service);
        self::assertStringContainsString('VariantAvailability', (string)file_get_contents(
            BP . 'app/code/Weline/Product/Controller/Frontend/Api/VariantAvailability.php',
        ));
        self::assertStringNotContainsString('axios', $template);
    }

}
