<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Product\Service\StorefrontProductWidgetCatalog;

final class StorefrontProductWidgetCatalogRelatedCardsTest extends TestCase
{
    public function testProductInfoRegistrationPinsDefaultInjectionSlot(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Product/widget.php';
        self::assertFileExists($path);
        /** @var array<string, mixed> $widgets */
        $widgets = include $path;
        self::assertIsArray($widgets);
        self::assertArrayHasKey('product-info', $widgets);
        $widget = $widgets['product-info'];
        self::assertSame('product-main', $widget['slot'] ?? null);
        self::assertTrue((bool)($widget['is_container'] ?? false));
        self::assertArrayHasKey('product-purchase-actions', $widget['slots'] ?? []);
        self::assertSame('Weline_Product::templates/frontend/widgets/product-info.phtml', $widget['template'] ?? null);
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('product-main', $injection['slot'] ?? null);
        self::assertSame('product', $injection['layout_type'] ?? null);
        self::assertTrue((bool)($injection['required'] ?? false));
        self::assertTrue((bool)(($injection['config']['show_brand'] ?? false)));
        self::assertTrue((bool)(($injection['config']['show_supplier'] ?? false)));
        self::assertArrayHasKey('show_brand', $widget['params'] ?? []);
        self::assertArrayHasKey('show_supplier', $widget['params'] ?? []);
        self::assertTrue((bool)(($widget['params']['show_brand']['default'] ?? false)));
        self::assertTrue((bool)(($widget['params']['show_supplier']['default'] ?? false)));

        $tpl = dirname(__DIR__, 3) . '/view/templates/frontend/widgets/product-info.phtml';
        self::assertFileExists($tpl);
        $source = (string)file_get_contents($tpl);
        self::assertStringContainsString('data-testid="storefront-product-detail"', $source);
        self::assertStringContainsString('data-testid="storefront-product-detail-unavailable"', $source);
        self::assertStringContainsString('data-testid="product-brand"', $source);
        self::assertStringContainsString('data-testid="product-supplier"', $source);
        self::assertStringContainsString('@param show_brand', $source);
        self::assertStringContainsString('@param show_supplier', $source);
        self::assertStringContainsString("getParam('editor_mode'", $source);
        self::assertStringContainsString('StorefrontOfferResolver::resolve', $source);
        self::assertStringContainsString('@widget.default_injections', $source);
        self::assertStringContainsString('product-main', $source);
        self::assertStringContainsString('id="product-purchase-actions"', $source);
        self::assertStringNotContainsString("Weline.Api.resource('cart')", $source);
    }

    public function testRelatedCardsMethodExcludesCurrentProductInSource(): void
    {
        $method = new ReflectionMethod(StorefrontProductWidgetCatalog::class, 'relatedCards');
        self::assertTrue($method->isPublic());
        self::assertSame(2, $method->getNumberOfParameters());

        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontProductWidgetCatalog.php'
        );
        self::assertStringContainsString('function relatedCards(int $excludeProductId = 0, int $limit = 4)', $source);
        self::assertStringContainsString('if ($excludeProductId > 0 && $productId === $excludeProductId)', $source);
    }

    public function testCardPoolsUseSummaryCatalogProjection(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontProductWidgetCatalog.php'
        );

        self::assertStringContainsString('$this->catalog->publishedOffers($limit * 3, false)', $source);
        self::assertStringContainsString('shouldUseListingProjection', $source);
        self::assertStringContainsString('$this->catalog->publishedOffers($limit * 3, true)', $source);
        self::assertStringContainsString('$this->catalog->publishedOffers(max($limit * 3, 48), false)', $source);
        self::assertStringContainsString(
            '$this->catalog->publishedOffersForProductIds(' . "\n"
                . '                \\array_keys($createdAtByProductId),' . "\n"
                . '                \\max($limit * 3, 48),' . "\n"
                . '                false,',
            $source,
        );
        self::assertStringContainsString('$this->catalog->publishedOffers(max($limit * 4, 16), false)', $source);
        self::assertStringContainsString(
            '$this->catalog->publishedOffersForProductIds([$seedProductId], 4, false)',
            $source,
        );
    }

    public function testNewArrivalCardsMethodFiltersByCreatedAtInSource(): void
    {
        $method = new ReflectionMethod(StorefrontProductWidgetCatalog::class, 'newArrivalCards');
        self::assertTrue($method->isPublic());
        self::assertSame(2, $method->getNumberOfParameters());

        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontProductWidgetCatalog.php'
        );
        self::assertStringContainsString('function newArrivalCards(int $limit = 8, int $days = 30)', $source);
        self::assertStringContainsString('Product::schema_fields_CREATED_AT', $source);
        self::assertStringContainsString('publishedOffersForProductIds', $source);
        self::assertStringContainsString('New-arrivals: prefer HF-* then fill non-HF published offers', $source);
        self::assertStringContainsString('$fallbackProductId', $source);
        self::assertStringContainsString('Day-window empty or no sellable offers: stable catalog fallback', $source);
        self::assertStringContainsString('$this->cards($limit)', $source);
    }

    public function testWidgetRegistrationPinsDefaultInjectionSlot(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Product/widget.php';
        self::assertFileExists($path);
        /** @var array<string, mixed> $widgets */
        $widgets = include $path;
        self::assertIsArray($widgets);
        self::assertArrayHasKey('related-products', $widgets);
        $widget = $widgets['related-products'];
        self::assertSame('product-related-products', $widget['slot'] ?? null);
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('product-related-products', $injection['slot'] ?? null);
        self::assertSame('product', $injection['layout_type'] ?? null);
    }

    public function testCrossSellRegistrationPinsDefaultInjectionSlot(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Product/widget.php';
        /** @var array<string, mixed> $widgets */
        $widgets = include $path;
        self::assertArrayHasKey('cross-sell', $widgets);
        $widget = $widgets['cross-sell'];
        self::assertSame('product-cross-sell', $widget['slot'] ?? null);
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('product-cross-sell', $injection['slot'] ?? null);
        self::assertSame('product', $injection['layout_type'] ?? null);
        $cartInjection = null;
        foreach ($widget['default_injections'] as $row) {
            if (($row['layout_type'] ?? '') === 'cart') {
                $cartInjection = $row;
                break;
            }
        }
        self::assertNotNull($cartInjection);
        self::assertSame('cart-recommendations', $cartInjection['slot'] ?? null);
        self::assertSame('Weline_Product::templates/frontend/widgets/cross-sell.phtml', $widget['template'] ?? null);

        $tpl = dirname(__DIR__, 3) . '/view/templates/frontend/widgets/cross-sell.phtml';
        self::assertFileExists($tpl);
        $source = (string)file_get_contents($tpl);
        self::assertStringContainsString('data-testid="storefront-cross-sell"', $source);
        self::assertStringContainsString('bundleCards(', $source);
        self::assertStringContainsString('Weline_Product::css/widgets/cross-sell.css', $source);
    }

    public function testBundleCardsMethodExistsInCatalogSource(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontProductWidgetCatalog.php'
        );
        self::assertStringContainsString('function bundleCards(int $seedProductId = 0, int $companionLimit = 3)', $source);
    }

    public function testWidgetTemplateUsesExternalAssetsAndTestId(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/frontend/widgets/related-products.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('data-testid="storefront-related-products"', $source);
        self::assertStringContainsString('Weline_Product::css/widgets/related-products.css', $source);
        self::assertStringContainsString('data-weline-load="relatedProducts"', $source);
        self::assertStringContainsString('<w:product:card', $source);
        self::assertStringContainsString('class="wpr-card"', $source);
        self::assertStringContainsString('class="wpr-header"', $source);
        self::assertStringNotContainsString('class="wpc-header"', $source);
        self::assertStringNotContainsString('ProductCardRenderer::render', $source);
        self::assertStringNotContainsString('<script>', $source);
        self::assertStringContainsString('relatedCards(', $source);

        $modulesPath = dirname(__DIR__, 3) . '/view/statics/frontend/weline.modules.js';
        self::assertFileExists($modulesPath);
        $modulesSource = (string)file_get_contents($modulesPath);
        self::assertStringContainsString('relatedProducts:', $modulesSource);
        self::assertStringContainsString(
            'Weline_Product::js/widgets/related-products.js',
            $modulesSource,
        );
    }

    public function testRecommendedProductsRegistrationAndTemplateContract(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Product/widget.php';
        /** @var array<string, mixed> $widgets */
        $widgets = include $path;
        self::assertArrayHasKey('recommended-products', $widgets);
        $widget = $widgets['recommended-products'];
        self::assertSame('category-recommendations', $widget['slot'] ?? null);
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('category-recommendations', $injection['slot'] ?? null);
        self::assertSame('category', $injection['layout_type'] ?? null);
        $listInjection = $widget['default_injections'][1] ?? [];
        self::assertSame('list-recommendations', $listInjection['slot'] ?? null);
        self::assertSame('product_list', $listInjection['layout_type'] ?? null);
        self::assertSame('Weline_Product::templates/frontend/widgets/recommended-products.phtml', $widget['template'] ?? null);

        $tpl = dirname(__DIR__, 3) . '/view/templates/frontend/widgets/recommended-products.phtml';
        self::assertFileExists($tpl);
        $source = (string)file_get_contents($tpl);
        self::assertStringContainsString('data-testid="storefront-recommended-products"', $source);
        self::assertStringContainsString('->cards($limit)', $source);
        self::assertStringContainsString('Url::getPrefix()', $source);
        self::assertStringNotContainsString('$this->getUrl(ltrim($route', $source);
        self::assertStringContainsString('<w:product:card', $source);
        self::assertStringContainsString('class="wpr-card"', $source);
        self::assertStringContainsString('class="wpr-header"', $source);
        self::assertStringNotContainsString('class="wpc-header"', $source);
        self::assertStringContainsString('wishlist-pixel="true"', $source);
        self::assertStringNotContainsString('ProductCardAddToCartParams::fetchDictionary', $source);
        self::assertStringNotContainsString('Weline_Theme::theme/frontend/partials/product/add-to-cart.phtml', $source);
        self::assertStringContainsString('Weline_Product::css/widgets/recommended-products.css', $source);
        self::assertStringContainsString('data-weline-load="recommendedProducts"', $source);
        self::assertFileExists(dirname(__DIR__, 3) . '/view/statics/css/widgets/recommended-products.css');
        self::assertFileExists(dirname(__DIR__, 3) . '/view/statics/js/widgets/recommended-products.js');
        $modulesSrc = (string)file_get_contents(dirname(__DIR__, 3) . '/view/statics/frontend/weline.modules.js');
        self::assertStringContainsString('recommendedProducts', $modulesSrc);
        self::assertStringContainsString('Weline_Product::js/widgets/recommended-products.js', $modulesSrc);

        $css = (string)file_get_contents(dirname(__DIR__, 3) . '/view/statics/css/widgets/recommended-products.css');
        self::assertStringContainsString('var(--weline-layout-content-max-width', $css);
        self::assertStringContainsString('.weline-product-card.wpr-card', $css);
        self::assertStringContainsString('.wpr-header', $css);
        self::assertStringNotContainsString('.weline-product-recommended .wpc-cta', $css);
        self::assertStringNotContainsString('.weline-product-recommended .wpc-title', $css);
        self::assertStringNotContainsString('.weline-product-recommended .wpc-media', $css);

        $catalogSrc = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/StorefrontProductWidgetCatalog.php');
        self::assertStringContainsString("'/product/'", $catalogSrc);

        $notFoundInjection = null;
        foreach ($widget['default_injections'] as $row) {
            if (($row['layout_type'] ?? '') === 'not_found') {
                $notFoundInjection = $row;
                break;
            }
        }
        self::assertNotNull($notFoundInjection);
        self::assertSame('not-found-recommendations', $notFoundInjection['slot'] ?? null);
    }
}
