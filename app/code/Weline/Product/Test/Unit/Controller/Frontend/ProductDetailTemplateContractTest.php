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
        ]) extends \Weline\Framework\View\Template {
            /** @param array<string, mixed> $data */
            public function __construct(private readonly array $data)
            {
            }

            public function getData(string $key = '', $index = null): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function getUrl(string $path, array $params = [], bool $merge_query = false): string
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
        self::assertStringContainsString('data-testid="product-specifications-clamp"', $html);
        self::assertStringContainsString('data-testid="product-specifications-more"', $html);
        self::assertStringContainsString('product-native-detail__spec-clamp', $html);
        self::assertStringContainsString('product-native-detail__spec-grid', $html);
        self::assertStringContainsString('bindSpecificationsClamp', $html);
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
        self::assertStringContainsString("\$seoProduct['storefront_offers'] = \$offers", $controller);
        self::assertStringContainsString("\$this->assign('product', \$seoProduct)", $controller);
        self::assertStringContainsString('$seoProfile = [', $controller);
        self::assertStringContainsString('getCurrentUrl([], true)', $controller);
        self::assertStringContainsString("\$seoProfile['canonical_url'] = \$canonicalUrl", $controller);
        self::assertStringContainsString("\$this->assign('seo', \$seoProfile)", $controller);
        self::assertStringContainsString("\$this->assign('meta_title', \$seoTitle)", $controller);
        self::assertStringContainsString("\$this->assign('meta_description', \$seoDescription)", $controller);
        self::assertStringContainsString("\$this->assign('meta_keywords', \$seoKeywords)", $controller);
        self::assertStringContainsString('publishedOffersForProduct($productId)', $controller);
        self::assertStringContainsString('publishedOffersBySlug($slug)', $controller);
        self::assertStringContainsString('carryResolvedIdentity', $controller);
        self::assertStringContainsString("\$this->getUrl('products')", $controller);
        self::assertStringContainsString('detail-shell.phtml', $controller);
        self::assertStringNotContainsString('product_detail', $controller);
        self::assertStringNotContainsString('WeShop\\Product', $controller);
        self::assertStringNotContainsString('PageBuilder', $controller);
    }


    public function testDetailTemplateRendersOnlyProjectedDescriptionHtml(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 4) . '/view/templates/frontend/widgets/product-info.phtml',
        );
        $detailCss = (string)file_get_contents(
            dirname(__DIR__, 4) . '/view/statics/css/widgets/product-native-detail.css',
        );
        $variantJs = (string)file_get_contents(
            dirname(__DIR__, 4) . '/view/statics/js/widgets/widget-product-info-0.js',
        );

        self::assertStringContainsString('$descriptionHtml', $template);
        self::assertStringContainsString('ensureDescriptionImageAlts', $template);
        self::assertStringContainsString('normalizeDescriptionLayout', $template);
        self::assertStringContainsString('data-testid="product-description-body"', $template);
        self::assertStringContainsString('object-fit: contain', $detailCss);
        self::assertStringNotContainsString('aspect-ratio: 3 / 4', $detailCss);
        self::assertLessThan(
            strpos($template, 'data-testid="product-description"'),
            strpos($template, 'data-testid="product-specifications"'),
            '技术细节区块须排在关于该商品/详情之前',
        );
        self::assertStringContainsString('data-testid="product-specifications"', $template);
        self::assertStringContainsString('data-testid="product-specifications-clamp"', $template);
        self::assertStringContainsString('product-native-detail__spec-grid', $template);
        self::assertStringContainsString('container-name: product-specs', $detailCss);
        self::assertStringContainsString("__('展示更多')", $template);
        self::assertStringContainsString("__('收起')", $template);
        self::assertStringContainsString('data-label-less', $template);
        self::assertStringContainsString('setCollapsed', $variantJs);
        self::assertStringContainsString('specification[\'label\']', $template);
        self::assertStringContainsString('--product-spec-clamp-max', $detailCss);
        self::assertStringContainsString('mask-image', $detailCss);
        self::assertStringNotContainsString('linear-gradient(to bottom, rgba(255, 255, 255, 0), #fff', $detailCss);
        self::assertStringContainsString(
            '$descriptionHtml !== \'\' ? $descriptionHtml : nl2br($escape($description), false)',
            $template,
        );
        self::assertStringContainsString('.product-native-detail__description-body img', $detailCss);
        self::assertMatchesRegularExpression(
            '/\.product-native-detail__description,\s*\n\s*\.product-native-detail__specifications\s*\{[^}]*max-width:\s*100%;/s',
            $detailCss,
            '关于该商品描述区须与主列同宽，禁止 64rem 二次收窄',
        );
        self::assertStringNotContainsString('max-width: min(100%, 64rem)', $detailCss);
        self::assertStringContainsString('.weline-detail-text--size-chart', $detailCss);
        self::assertStringContainsString('.weline-detail-text__columns', $detailCss);
        self::assertStringContainsString('.product-native-detail__qty-label', $detailCss);
        self::assertMatchesRegularExpression(
            '/\.product-native-detail__qty-label\s*\{[^}]*white-space:\s*nowrap/s',
            $detailCss,
        );
        self::assertStringContainsString('.product-native-detail__qty-select', $detailCss);
        self::assertMatchesRegularExpression(
            '/\.product-native-detail__qty-select\s*\{(?:[^{}]|\{[^}]*\})*inline-size:\s*auto/s',
            $detailCss,
        );
    }

    public function testDetailTemplateExposesAfterGalleryHookUnderMediaColumn(): void
    {
        $template = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/frontend/widgets/product-info.phtml',
        );

        self::assertStringContainsString('product-native-detail__media-column', $template);
        self::assertStringContainsString(
            "getHook('Weline_Product::frontend::product::detail::after-gallery')",
            $template,
        );
        self::assertLessThan(
            (int) strpos($template, "getHook('Weline_Product::frontend::product::detail::after-gallery')"),
            (int) strpos($template, 'data-testid="product-gallery"'),
            'after-gallery 须紧随图库之后',
        );
        self::assertLessThan(
            (int) strpos($template, 'product-native-detail__buy-column'),
            (int) strpos($template, "getHook('Weline_Product::frontend::product::detail::after-gallery')"),
            'after-gallery 须在购买列之前',
        );
    }

    public function testDetailTemplateUsesPublishedOfferIdentityThroughCartQueryBin(): void
    {
        $template = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/frontend/widgets/product-info.phtml',
        );
        $variantJs = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/statics/js/widgets/widget-product-info-0.js',
        );

        self::assertStringContainsString('data-testid="storefront-product-detail"', $template);
        self::assertStringContainsString('data-testid="product-gallery"', $template);
        self::assertStringContainsString('data-testid="product-gallery-video"', $template);
        self::assertStringContainsString('data-gallery-type', $template);
        self::assertStringContainsString('renderGalleryVideo', $variantJs);
        self::assertStringContainsString('productVideos', $variantJs);
        self::assertStringContainsString('data-image-zoom="1"', $template);
        self::assertStringContainsString('product-native-detail__thumbs', $template);
        self::assertStringContainsString('product-native-detail__zoom-lens', $template);
        self::assertStringContainsString('data-testid="product-image-zoom-result"', $template);
        self::assertStringContainsString('bindImageZoom', $variantJs);
        self::assertStringContainsString('syncImageZoomSource', $variantJs);
        self::assertStringContainsString('id="product-purchase-actions"', $template);
        self::assertStringContainsString('product-purchase-actions', $template);
        self::assertStringContainsString('product-native-detail__actions', $template);
        self::assertStringContainsString('min-inline-size: 0', (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/statics/css/widgets/product-native-detail.css',
        ));
        self::assertStringContainsString('@param show_brand', $template);
        self::assertStringContainsString('@param show_supplier', $template);
        self::assertStringContainsString('data-testid="product-brand"', $template);
        self::assertStringContainsString('data-testid="product-supplier"', $template);
        self::assertStringContainsString('@url{\'products\'}', $template);
        self::assertStringContainsString('@widget.code {product-info}', $template);
        self::assertStringContainsString('product-main', $template);
        self::assertStringContainsString('data-variant-live-url', $template);
        self::assertStringContainsString('refreshLiveAvailability', $variantJs);
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
        $variantJs = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/statics/js/widgets/widget-product-info-0.js',
        );
        $controller = (string)file_get_contents(
            BP . 'app/code/Weline/Product/Controller/Frontend/Detail.php',
        );
        $shell = (string)file_get_contents(
            BP . 'app/code/Weline/Product/Service/StorefrontPdpVariantShell.php',
        );
        $service = (string)file_get_contents(
            BP . 'app/code/Weline/Product/Service/StorefrontCatalogViewService.php',
        );

        self::assertStringContainsString('data-testid="product-offer-selector"', $template);
        self::assertStringContainsString('name="offer"', $template);
        self::assertStringContainsString('请选择规格', $template);
        self::assertStringContainsString('StorefrontPdpVariantShell', $controller);
        self::assertStringContainsString('buildNeutral', $controller);
        self::assertStringContainsString('pathOnlyUrl', $controller);
        self::assertStringContainsString("'variant_catalog'", $controller);
        self::assertStringNotContainsString('canonicalizeAxisQuery', $controller);
        self::assertStringNotContainsString('resolveSelectedOffer', $controller);
        self::assertStringContainsString('data-variant-interactive="1"', $template);
        self::assertStringContainsString('data-variant-option="1"', $template);
        self::assertStringContainsString('publicCodeFor', $variantJs);
        self::assertStringContainsString('canonicalValueFor', $variantJs);
        self::assertStringContainsString('Path-only PDP', $variantJs);
        self::assertStringContainsString('data-testid="product-sku"', $template);
        self::assertStringContainsString('data-product-sku="1"', $template);
        self::assertStringContainsString("root.querySelector('[data-product-sku]')", $variantJs);
        self::assertStringContainsString("exactOffer.sku || ''", $variantJs);
        self::assertStringContainsString('is-out-of-stock', $variantJs);
        self::assertStringContainsString('isOptionSellable', $variantJs);
        self::assertStringContainsString('isOfferSellable', $variantJs);
        self::assertStringContainsString('resolveSelectionToInStock', $variantJs);
        self::assertStringContainsString('resolveSelectionGallery', $variantJs);
        self::assertStringContainsString('catalog.base_images', $variantJs);
        self::assertStringContainsString('collectSpecGalleryItems', $variantJs);
        self::assertStringContainsString('collectProductGalleryImages', $variantJs);
        self::assertStringContainsString('pageProductImages', $variantJs);
        self::assertStringContainsString("kind: 'product'", $variantJs);
        self::assertStringContainsString('onGalleryThumbActivate', $variantJs);
        self::assertStringContainsString('galleryAxis', $variantJs);
        self::assertStringContainsString('galleryValue', $variantJs);
        self::assertStringContainsString('product-native-detail__thumb-spec-badge', $variantJs);
        self::assertStringContainsString('galleryFocusAxis', $variantJs);
        self::assertStringContainsString('never promote these to', $variantJs);
        self::assertStringContainsString('compactCatalogMedia', $shell);
        self::assertStringContainsString('gallery_by_color', $variantJs);
        self::assertStringContainsString('resolveOptionGallery', $variantJs);
        self::assertStringContainsString('全局 EAV 选项图板仅表示', (string)file_get_contents(
            BP . 'app/code/Weline/Product/Service/StorefrontVariantAxisResolver.php',
        ));
        self::assertStringContainsString('clearVariantOptionSwatchImages', (string)file_get_contents(
            BP . 'app/code/Weline/Product/Service/ProductCatalogEavBootstrap.php',
        ));
        self::assertStringContainsString('data-variant-live-url', $template);
        self::assertStringContainsString('refreshLiveAvailability', $variantJs);
        self::assertStringContainsString('mergeLiveOffers', $variantJs);
        self::assertStringContainsString('Availability API returns raw catalog unit_price_minor', $variantJs);
        self::assertStringContainsString('liveHasDealFields', $variantJs);
        self::assertStringContainsString("node.hidden = !priced.hasDeal;", $variantJs);
        self::assertStringContainsString("\$display['global_offer_uuid'] = '';", $shell);
        self::assertStringContainsString('publishedOffersForProduct', $service);
        self::assertStringContainsString('publishedOffersBySlug', $service);
        self::assertStringContainsString('livePublishedOffersForProduct', $service);
        self::assertStringContainsString('resolveCatalogOffers', $service);
        self::assertStringNotContainsString('$this->snapshots->resolve(', $service);
        self::assertStringContainsString('attachPrimarySupplier', $service);
        self::assertStringContainsString('supplier_name', $service);
        self::assertStringContainsString('VariantAvailability', (string)file_get_contents(
            BP . 'app/code/Weline/Product/Controller/Frontend/Api/VariantAvailability.php',
        ));
        self::assertStringNotContainsString('axios', $template);
    }

}
