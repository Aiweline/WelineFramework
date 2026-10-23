<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Controller\Frontend;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\ProductCardRenderer;

/**
 * Aggregated contract suite (merged from 8 *ContractTest files).
 * Observe one overall pass/fail via testAllProductFrontendContractsPass().
 */
final class ProductFrontendContractsTest extends TestCase
{
    public function testAllProductFrontendContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'BestSellersPage::testRouterMapsBestSellersAliases' => function () {
            $this->assert_BestSellersPage_testRouterMapsBestSellersAliases();
        },
            'BestSellersPage::testControllerSelectsBestSellersLayout' => function () {
            $this->assert_BestSellersPage_testControllerSelectsBestSellersLayout();
        },
            'BestSellersPage::testModuleContributesBestSellersLayout' => function () {
            $this->assert_BestSellersPage_testModuleContributesBestSellersLayout();
        },
            'BestSellersPage::testBestSellersHeroWidgetIsRegisteredAndConfigurable' => function () {
            $this->assert_BestSellersPage_testBestSellersHeroWidgetIsRegisteredAndConfigurable();
        },
            'BestSellersPage::testBestSellersTemplateUsesUnifiedProductCard' => function () {
            $this->assert_BestSellersPage_testBestSellersTemplateUsesUnifiedProductCard();
        },
            'BestSellersPage::testControllerPublishesBestSellerCountForHeroWidget' => function () {
            $this->assert_BestSellersPage_testControllerPublishesBestSellerCountForHeroWidget();
        },
            'BestSellersPage::testWidgetCatalogExposesBestSellerCards' => function () {
            $this->assert_BestSellersPage_testWidgetCatalogExposesBestSellerCards();
        },
            'CatalogCategoriesLanding::testCatalogBranchesCategoriesSurfaceToLandingTemplate' => function () {
            $this->assert_CatalogCategoriesLanding_testCatalogBranchesCategoriesSurfaceToLandingTemplate();
        },
            'CatalogCategoriesLanding::testCategoryViewServiceExposesRootLanding' => function () {
            $this->assert_CatalogCategoriesLanding_testCategoryViewServiceExposesRootLanding();
        },
            'CategoryCatalogExtraFpc::testCategoryDeclaresNestedPathFpc' => function () {
            $this->assert_CategoryCatalogExtraFpc_testCategoryDeclaresNestedPathFpc();
        },
            'CategoryCatalogExtraFpc::testCatalogDeclaresListingFpc' => function () {
            $this->assert_CategoryCatalogExtraFpc_testCatalogDeclaresListingFpc();
        },
            'ProductCatalogTemplate::testCatalogListingUsesUnifiedProductCardTag' => function () {
            $this->assert_ProductCatalogTemplate_testCatalogListingUsesUnifiedProductCardTag();
        },
            'ProductCatalogTemplate::testFromStorefrontOfferMapsQuoteOnlyWithoutZeroPrice' => function () {
            $this->assert_ProductCatalogTemplate_testFromStorefrontOfferMapsQuoteOnlyWithoutZeroPrice();
        },
            'ProductCatalogTemplate::testBuyboxHidesGenericStockMessageAgainstSourceAndTranslatedCandidates' => function () {
            $this->assert_ProductCatalogTemplate_testBuyboxHidesGenericStockMessageAgainstSourceAndTranslatedCandidates();
        },
            'ProductCategoryTemplate::testCategoryControllerAppliesListingFilterAndKeepsUnfilteredOffers' => function () {
            $this->assert_ProductCategoryTemplate_testCategoryControllerAppliesListingFilterAndKeepsUnfilteredOffers();
        },
            'ProductCategoryTemplate::testCategoryTemplateExposesToolbarAndGridContracts' => function () {
            $this->assert_ProductCategoryTemplate_testCategoryTemplateExposesToolbarAndGridContracts();
        },
            'ProductCategoryTemplate::testCategoryLayoutLeavesFiltersSlotForFiltersModuleInjection' => function () {
            $this->assert_ProductCategoryTemplate_testCategoryLayoutLeavesFiltersSlotForFiltersModuleInjection();
        },
            'ProductDetailTemplate::testDetailTemplateRendersPublishedDescriptionAndSpecifications' => function () {
            $this->assert_ProductDetailTemplate_testDetailTemplateRendersPublishedDescriptionAndSpecifications();
        },
            'ProductDetailTemplate::testDetailControllerOwnsTheNativeProductDetailLayout' => function () {
            $this->assert_ProductDetailTemplate_testDetailControllerOwnsTheNativeProductDetailLayout();
        },
            'ProductDetailTemplate::testDetailTemplateRendersOnlyProjectedDescriptionHtml' => function () {
            $this->assert_ProductDetailTemplate_testDetailTemplateRendersOnlyProjectedDescriptionHtml();
        },
            'ProductDetailTemplate::testDetailTemplateExposesAfterGalleryHookUnderMediaColumn' => function () {
            $this->assert_ProductDetailTemplate_testDetailTemplateExposesAfterGalleryHookUnderMediaColumn();
        },
            'ProductDetailTemplate::testDetailTemplateUsesPublishedOfferIdentityThroughCartQueryBin' => function () {
            $this->assert_ProductDetailTemplate_testDetailTemplateUsesPublishedOfferIdentityThroughCartQueryBin();
        },
            'ProductDetailTemplate::testConfigurableDetailRequiresAnExplicitOfferIdentity' => function () {
            $this->assert_ProductDetailTemplate_testConfigurableDetailRequiresAnExplicitOfferIdentity();
        },
            'ProductDetailTemplate::testDetailShimFetchesProductInfoThroughTaglib' => function () {
            $this->assert_ProductDetailTemplate_testDetailShimFetchesProductInfoThroughTaglib();
        },
            'ProductDownloadSurface::testPaidObserverIsSynchronousCriticalAndReadsOrderUuid' => function () {
            $this->assert_ProductDownloadSurface_testPaidObserverIsSynchronousCriticalAndReadsOrderUuid();
        },
            'ProductDownloadSurface::testDownloadRouteAndControllerAcceptOnlyEntitlementUuid' => function () {
            $this->assert_ProductDownloadSurface_testDownloadRouteAndControllerAcceptOnlyEntitlementUuid();
        },
            'ProductDownloadSurface::testCustomerQueryIsUncachedAndEntitlementGrantUsesFrozenOrderMetadata' => function () {
            $this->assert_ProductDownloadSurface_testCustomerQueryIsUncachedAndEntitlementGrantUsesFrozenOrderMetadata();
        },
            'ProductStorefrontTemplate::testProductControllerSelectsTheProductListThemeLayout' => function () {
            $this->assert_ProductStorefrontTemplate_testProductControllerSelectsTheProductListThemeLayout();
        },
            'ProductStorefrontTemplate::testCatalogAndCategoryTemplatesExposeListingPager' => function () {
            $this->assert_ProductStorefrontTemplate_testCatalogAndCategoryTemplatesExposeListingPager();
        },
            'ProductStorefrontTemplate::testProductControllerDoesNotReachIntoCartConcreteServices' => function () {
            $this->assert_ProductStorefrontTemplate_testProductControllerDoesNotReachIntoCartConcreteServices();
        },
            'ProductStorefrontTemplate::testCatalogDelegatesCartMutationToUnifiedProductCard' => function () {
            $this->assert_ProductStorefrontTemplate_testCatalogDelegatesCartMutationToUnifiedProductCard();
        },
            'ProductStorefrontTemplate::testCartLinkKeepsTheActiveCurrencyAndLocaleRoute' => function () {
            $this->assert_ProductStorefrontTemplate_testCartLinkKeepsTheActiveCurrencyAndLocaleRoute();
        },
            'ProductStorefrontTemplate::testCatalogCardsUseUnifiedProductCardTag' => function () {
            $this->assert_ProductStorefrontTemplate_testCatalogCardsUseUnifiedProductCardTag();
        },
            'ProductStorefrontTemplate::testProductListLayoutLeavesFiltersSlotForFiltersModuleInjection' => function () {
            $this->assert_ProductStorefrontTemplate_testProductListLayoutLeavesFiltersSlotForFiltersModuleInjection();
        },
            'ProductStorefrontTemplate::testCatalogControllerDispatchesStorefrontOffersFilterEventViaListingShell' => function () {
            $this->assert_ProductStorefrontTemplate_testCatalogControllerDispatchesStorefrontOffersFilterEventViaListingShell();
        },
            'ProductStorefrontTemplate::testProductEventSpecDeclaresStorefrontOffersFilter' => function () {
            $this->assert_ProductStorefrontTemplate_testProductEventSpecDeclaresStorefrontOffersFilter();
        },
            'ProductStorefrontTemplate::testBrowserCartMutationDoesNotSubmitAClientOwnedScope' => function () {
            $this->assert_ProductStorefrontTemplate_testBrowserCartMutationDoesNotSubmitAClientOwnedScope();
        },
        ];
    }

private function assert_BestSellersPage_testRouterMapsBestSellersAliases(): void
{
    $router = (string) file_get_contents(BP . 'app/code/Weline/Product/Controller/Router.php');
    self::assertStringContainsString("BEST_SELLERS_ROUTE = 'weline_product/frontend/best-sellers'", $router);
    self::assertStringContainsString("'best-sellers', 'bestsellers', 'best_sellers'", $router);
}

private function assert_BestSellersPage_testControllerSelectsBestSellersLayout(): void
{
    $controller = (string) file_get_contents(BP . 'app/code/Weline/Product/Controller/Frontend/BestSellers.php');
    self::assertStringContainsString("\$this->layoutType = 'best_sellers'", $controller);
    self::assertStringNotContainsString("theme_public_route", $controller);
    self::assertStringContainsString('bestSellerCards', $controller);
    self::assertStringContainsString("Weline_Product::templates/frontend/best-sellers/index.phtml", $controller);
}

private function assert_BestSellersPage_testModuleContributesBestSellersLayout(): void
{
    $layout = (string) file_get_contents(BP . 'app/code/Weline/Product/view/theme/frontend/layouts/best_sellers/default.phtml');
    self::assertStringContainsString('data-layout="best_sellers"', $layout);
    self::assertStringContainsString('data-testid="storefront-best-sellers-layout"', $layout);
    self::assertStringContainsString('best-sellers-hero', $layout);
    self::assertStringContainsString('exclusive="true"', $layout);
    self::assertStringContainsString('best-sellers-main', $layout);
    self::assertStringContainsString('<w:widget type="banner" name="best-sellers-hero"', $layout);
    self::assertStringNotContainsString('accept="layout-best-sellers-content,layout-default-content,content,product-list,product-grid,bestsellers,product-carousel,best-sellers-hero,banner"', $layout);
    self::assertStringContainsString('@meta.name', $layout);
}

private function assert_BestSellersPage_testBestSellersHeroWidgetIsRegisteredAndConfigurable(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/extends/module/Weline_Widget/Weline_Product/widget.php';
    $tpl = 'Weline_Product::templates/frontend/widgets/best-sellers-hero.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/widgets/best-sellers-hero.phtml');
    self::assertStringContainsString('@widget.code {best-sellers-hero}', $src);
    self::assertStringContainsString('@widget.placement {layout}', $src);
    self::assertStringContainsString('@param background_image {', $src);
    self::assertStringContainsString('type="media_image"', $src);
}

private function assert_BestSellersPage_testBestSellersTemplateUsesUnifiedProductCard(): void
{
    $template = (string) file_get_contents(BP . 'app/code/Weline/Product/view/templates/frontend/best-sellers/index.phtml');
    self::assertStringContainsString('data-testid="storefront-best-sellers"', $template);
    self::assertStringContainsString('storefront-best-sellers-grid', $template);
    self::assertStringContainsString('storefront-best-sellers-card', $template);
    self::assertStringContainsString('best-sellers-page__rank', $template);
    self::assertStringContainsString('ProductCardRenderer::projectHtmlByProductId', $template);
    self::assertStringNotContainsString('<w:product:card', $template);
    self::assertStringNotContainsString('best-sellers-hero.phtml', $template);
    self::assertStringContainsString('ProductCardRenderer::emitStylesheetLinkOnce()', $template);
    self::assertStringContainsString('weline-product-card-shelf', $template);
    self::assertStringContainsString('ProductCardUrl::splitForTaglib', $template);
    self::assertStringContainsString("'show_sku' => true", $template);
    self::assertStringNotContainsString('best-sellers-page__hero', $template);
    self::assertStringNotContainsString('storefront-best-sellers-podium', $template);
    self::assertStringNotContainsString('ProductCardAddToCartParams::fetchDictionary', $template);
    self::assertStringNotContainsString('add-to-cart.phtml', $template);
}

private function assert_BestSellersPage_testControllerPublishesBestSellerCountForHeroWidget(): void
{
    $controller = (string) file_get_contents(BP . 'app/code/Weline/Product/Controller/Frontend/BestSellers.php');
    self::assertStringContainsString("setData('storefront_best_sellers_count'", $controller);
}

private function assert_BestSellersPage_testWidgetCatalogExposesBestSellerCards(): void
{
    $service = (string) file_get_contents(BP . 'app/code/Weline/Product/Service/StorefrontProductWidgetCatalog.php');
    self::assertStringContainsString('function bestSellerCards(int $limit = 24)', $service);
    self::assertStringContainsString("'sales_count'", $service);
    self::assertStringContainsString("'rank'", $service);
}

private function assert_CatalogCategoriesLanding_testCatalogBranchesCategoriesSurfaceToLandingTemplate(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Product';
    $controller = (string) file_get_contents($root . '/Controller/Frontend/Catalog.php');
    $template = (string) file_get_contents($root . '/view/templates/frontend/catalog/categories.phtml');
    self::assertStringContainsString("=== 'categories'", $controller);
    self::assertStringContainsString('renderCategoriesLanding', $controller);
    self::assertStringContainsString('resolveRootLanding', $controller);
    self::assertStringContainsString("Weline_Product::templates/frontend/catalog/categories.phtml", $controller);
    self::assertStringContainsString("assign('showFilters', false)", $controller);
    self::assertStringNotContainsString('publishedListingCandidates', $this->methodBody_CatalogCategoriesLanding($controller, 'renderCategoriesLanding'));
    self::assertStringContainsString('data-testid="storefront-categories-hub"', $template);
    self::assertStringContainsString('data-testid="storefront-categories-hub-grid"', $template);
    self::assertStringContainsString("@url{'products'}", $template);
    self::assertStringContainsString('align-items: start', $template);
    self::assertStringNotContainsString('height: 100%', $template);
    self::assertStringNotContainsString('storefront-product-catalog', $template);
    self::assertStringNotContainsString('ProductCardRenderer', $template);
    $layout = (string) file_get_contents($root . '/view/theme/frontend/layouts/category/default.phtml');
    self::assertStringContainsString('category-layout__container--no-filters', $layout);
    self::assertStringContainsString('grid-template-columns: minmax(0, 1fr)', $layout);
    self::assertStringContainsString('.category-layout__container:has(> .category-layout__sidebar)', $layout);
}

private function assert_CatalogCategoriesLanding_testCategoryViewServiceExposesRootLanding(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontCategoryViewService.php');
    self::assertStringContainsString('function resolveRootLanding(', $source);
    self::assertStringContainsString('StorefrontCategoryPublicFilter::shouldHideFromCustomers', $source);
}

private function methodBody_CatalogCategoriesLanding(string $source, string $method): string
{
    $needle = 'function ' . $method . '(';
    $start = strpos($source, $needle);
    self::assertNotFalse($start, $method . ' missing');
    $brace = strpos($source, '{', $start);
    self::assertNotFalse($brace);
    $depth = 0;
    $len = strlen($source);
    for ($i = $brace; $i < $len; $i++) {
        $ch = $source[$i];
        if ($ch === '{') {
            $depth++;
        } elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $brace, $i - $brace + 1);
            }
        }
    }
    self::fail('unclosed method ' . $method);
    return '';
}

private function assert_CategoryCatalogExtraFpc_testCategoryDeclaresNestedPathFpc(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Controller/Frontend/Category.php');
    self::assertMatchesRegularExpression('/@Extra\s+type=fpc\b/', $src);
    self::assertStringContainsString('enabled=true', $src);
    self::assertStringContainsString('ttl=31536000', $src);
    self::assertStringContainsString('website/default/catalog', $src);
    // 多级分类 path（/category/sets、/category/a/b）须用 **（* 仅单段）
    self::assertStringContainsString('/category/**', $src);
    self::assertStringContainsString(
        'query_keys=' . \Weline\Product\Service\StorefrontListingFpcQueryKeys::csv(),
        $src,
    );
}

private function assert_CategoryCatalogExtraFpc_testCatalogDeclaresListingFpc(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Controller/Frontend/Catalog.php');
    self::assertMatchesRegularExpression('/@Extra\s+type=fpc\b/', $src);
    self::assertStringContainsString('website/default/catalog', $src);
    self::assertStringContainsString('/products', $src);
    self::assertStringContainsString('/category', $src);
    self::assertStringContainsString('/categories', $src);
}

private function assert_ProductCatalogTemplate_testCatalogListingUsesUnifiedProductCardTag(): void
{
    $source = (string) file_get_contents(BP . 'app/code/Weline/Product/view/templates/frontend/catalog/index.phtml');
    self::assertStringContainsString('ProductCardRenderer::projectFromOffers', $source);
    self::assertStringContainsString("'show_sku' => true", $source);
    self::assertStringContainsString('weline-product-card-shelf', $source);
    self::assertStringContainsString("'class' => 'wpc-listing-card'", $source);
    self::assertStringNotContainsString('<w:product:card', $source);
    self::assertStringNotContainsString('product-storefront__card product-card', $source);
    self::assertStringNotContainsString('ProductCardAddToCartParams::fetchDictionaryFromOffer', $source);
}

private function assert_ProductCatalogTemplate_testFromStorefrontOfferMapsQuoteOnlyWithoutZeroPrice(): void
{
    $product = ProductCardRenderer::fromStorefrontOffer(['product_id' => 48, 'provider_code' => 'product', 'global_offer_uuid' => 'offer-48', 'name' => 'STN X3 YB300R', 'sku' => 'STN-X3-YB300R', 'image' => '/media/stn-x3.jpg', 'currency' => 'USD', 'unit_price_minor' => 0, 'stock' => 0, 'sellable' => false, 'message' => '商品库存不足', 'quote_only' => true]);
    self::assertSame(48, (int) $product['id']);
    self::assertTrue(!empty($product['quote_only']));
    self::assertSame(0.0, (float) $product['price']);
    self::assertSame('USD', $product['currency']);
}

private function assert_ProductCatalogTemplate_testBuyboxHidesGenericStockMessageAgainstSourceAndTranslatedCandidates(): void
{
    $source = (string) file_get_contents(BP . 'app/code/Weline/Product/view/templates/frontend/widgets/product-info.phtml');
    self::assertStringContainsString('$genericStockCandidates', $source);
    self::assertStringContainsString("'商品库存不足'", $source);
    self::assertStringContainsString("'Out of stock'", $source);
    self::assertStringContainsString('!in_array($offerMessage, $genericStockCandidates, true)', $source);
    self::assertStringNotContainsString('$offerMessage !== $genericStockMessage', $source);
}

private function assert_ProductCategoryTemplate_testCategoryControllerAppliesListingFilterAndKeepsUnfilteredOffers(): void
{
    $controller = (string) file_get_contents(BP . 'app/code/Weline/Product/Controller/Frontend/Category.php');
    self::assertStringContainsString('StorefrontListingShell', $controller);
    self::assertStringContainsString('projectNeutral(', $controller);
    self::assertStringContainsString('projectFiltered(', $controller);
    self::assertStringContainsString('StorefrontListingFpcQueryKeys', $controller);
    self::assertStringContainsString("assign('storefront_offers_unfiltered'", $controller);
    self::assertStringContainsString("assign('storefront_listing_sort_options'", $controller);
    self::assertStringContainsString("assign('storefront_category_siblings'", $controller);
    self::assertStringContainsString("assign('storefront_category_tree'", $controller);
    self::assertStringContainsString("assign('storefront_category_active_path_ids'", $controller);
    self::assertStringContainsString("setGet('path', \$routePath)", $controller);
    self::assertStringContainsString('$this->layoutType = \'category\'', $controller);
    self::assertStringContainsString("\$productIds = \$page['product_ids'];", $controller);
    self::assertStringContainsString('$productIds === []', $controller);
    self::assertStringContainsString('publishedOffersForProductIds($productIds, 120, $bindListingQuery)', $controller);
    self::assertStringNotContainsString('getQueryParams()', $controller);
    self::assertStringNotContainsString('ObjectManager', $controller);
}

private function assert_ProductCategoryTemplate_testCategoryTemplateExposesToolbarAndGridContracts(): void
{
    $template = (string) file_get_contents(BP . 'app/code/Weline/Product/view/templates/frontend/category/index.phtml');
    self::assertStringContainsString('data-testid="storefront-category-toolbar"', $template);
    self::assertStringContainsString('data-testid="storefront-category-sort"', $template);
    self::assertStringContainsString('data-listing-sort', $template);
    self::assertStringContainsString('data-storefront-listing="1"', $template);
    self::assertStringNotContainsString('window.location.href', $template);
    // Heavy-locale packs only prefetch template literals; dynamic __($var) stays Chinese.
    self::assertStringContainsString("__('默认排序')", $template);
    self::assertStringContainsString("__('价格从低到高')", $template);
    self::assertStringContainsString("__('价格从高到低')", $template);
    self::assertStringContainsString("__('名称 A-Z')", $template);
    self::assertStringContainsString('data-testid="storefront-category-grid"', $template);
    self::assertStringContainsString('amz-plp__results-bar', $template);
    // Category name is plain text — no decorative curly quotes around the query span.
    self::assertStringContainsString('amz-plp__results-query', $template);
    self::assertStringContainsString('$escape($categoryName)', $template);
    self::assertStringNotContainsString("__('“%{1}”'", $template);
    self::assertStringNotContainsString("__('「%{1}」'", $template);
    self::assertStringContainsString('ProductCardRenderer::projectFromOffers', $template);
    self::assertStringContainsString("'show_sku' => true", $template);
    self::assertStringContainsString('weline-product-card-shelf', $template);
    self::assertStringContainsString('storefront_category_breadcrumbs', $template);
    self::assertStringNotContainsString('<w:product:card', $template);
    self::assertStringNotContainsString('amz-card product-card', $template);
    self::assertStringNotContainsString('ProductCardAddToCartParams::fetchDictionaryFromOffer', $template);
    self::assertStringNotContainsString("button.textContent = '", $template);
    self::assertStringNotContainsString('ObjectManager', $template);
}

private function assert_ProductCategoryTemplate_testCategoryLayoutLeavesFiltersSlotForFiltersModuleInjection(): void
{
    $layout = (string) file_get_contents(BP . 'app/code/Weline/Product/view/theme/frontend/layouts/category/default.phtml');
    self::assertStringContainsString('id="category-filters"', $layout);
    self::assertStringContainsString('data-placeholder="category-filters"', $layout);
    self::assertStringContainsString('由 Filters 部件默认注入', $layout);
    self::assertStringContainsString('$isEditorPreview', $layout);
    self::assertStringContainsString('if ($isEditorPreview)', $layout);
    self::assertStringNotContainsString("getHook('Weline_Theme::frontend::layouts::category::filters-sidebar'", $layout);
    self::assertStringNotContainsString('<w:widget type="sidebar" name="category-filters"/>', $layout);
    self::assertFileDoesNotExist(BP . 'app/code/Weline/Product/view/hooks/Weline_Theme/frontend/layouts/category/filters-sidebar.phtml');
}

private function assert_ProductDetailTemplate_testDetailTemplateRendersPublishedDescriptionAndSpecifications(): void
{
    $template = BP . 'app/code/Weline/Product/view/templates/frontend/widgets/product-info.phtml';
    $view = new class(['storefront_offer' => ['product_id' => 9, 'provider_code' => 'product', 'global_offer_uuid' => 'offer-9', 'name' => 'ZTOT Z7-L YBS300 PRO', 'sku' => 'ZTOT-Z7L-YBS300PRO', 'image' => '/media/primary.jpg', 'images' => ['/media/primary.jpg', '/media/secondary.jpg'], 'currency' => 'USD', 'unit_price_minor' => 233700, 'stock' => 999, 'sellable' => true, 'message' => '', 'short_description' => 'Wholesale off-road motorcycle for dealer buyers.', 'description' => 'Full dealer product description.', 'specifications' => [['code' => 'engine', 'value' => 'LONCIN YBS300 PRO'], ['code' => 'displacement', 'value' => '294.9 ml']]]]) extends \Weline\Framework\View\Template
    {
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
            return (string) ob_get_clean();
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

private function assert_ProductDetailTemplate_testDetailShimFetchesProductInfoThroughTaglib(): void
{
    $shim = (string) file_get_contents(BP . 'app/code/Weline/Product/view/templates/frontend/catalog/detail.phtml');
    self::assertStringContainsString(
        "fetch('Weline_Product::templates/frontend/widgets/product-info.phtml')",
        $shim,
        'detail.phtml shim must Template::fetch product-info so Taglib expands @url',
    );
    self::assertStringNotContainsString(
        'include __DIR__',
        $shim,
        'raw __DIR__ include of product-info bypasses Taglib (T_DIR → source dir) and leaks @url into HTML',
    );

    // Optional local design overlay (often gitignored): must compile module bytes, never raw-include.
    $hanfuOverlay = BP . 'app/design/Weline/hanfu/Weline_Product/templates/frontend/widgets/product-info.phtml';
    if (is_file($hanfuOverlay)) {
        $overlay = (string) file_get_contents($hanfuOverlay);
        self::assertStringContainsString('fetchSourceHtml(', $overlay);
        self::assertStringNotContainsString('include $moduleTpl', $overlay);
    }
}

private function assert_ProductDetailTemplate_testDetailControllerOwnsTheNativeProductDetailLayout(): void
{
    $controller = (string) file_get_contents(BP . 'app/code/Weline/Product/Controller/Frontend/Detail.php');
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
    self::assertStringContainsString('renderUnavailableProduct', $controller);
    self::assertStringContainsString('unavailable.phtml', $controller);
    self::assertStringContainsString("setHeader('Cache-Control', 'private, no-store, max-age=0, must-revalidate')", $controller);
    self::assertStringContainsString("data-testid=\"storefront-product-unavailable\"", (string) file_get_contents(BP . 'app/code/Weline/Product/view/templates/frontend/catalog/unavailable.phtml'));
    self::assertStringNotContainsString("return (string)\$this->redirect(\$this->getUrl('products'));", $controller);
    self::assertStringContainsString('detail.phtml', $controller);
    self::assertStringNotContainsString('detail-shell', $controller);
    self::assertStringNotContainsString('product_detail', $controller);
    self::assertStringNotContainsString('WeShop\Product', $controller);
    self::assertStringNotContainsString('PageBuilder', $controller);
}

private function assert_ProductDetailTemplate_testDetailTemplateRendersOnlyProjectedDescriptionHtml(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/widgets/product-info.phtml');
    $detailCss = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/statics/css/widgets/product-native-detail.css');
    $variantJs = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/statics/js/widgets/widget-product-info-0.js');
    self::assertStringContainsString('$descriptionHtml', $template);
    self::assertStringContainsString('ensureDescriptionImageAlts', $template);
    self::assertStringContainsString('normalizeDescriptionLayout', $template);
    self::assertStringContainsString('data-testid="product-description-body"', $template);
    self::assertStringContainsString('object-fit: contain', $detailCss);
    self::assertStringNotContainsString('aspect-ratio: 3 / 4', $detailCss);
    self::assertLessThan(strpos($template, 'data-testid="product-description"'), strpos($template, 'data-testid="product-specifications"'), '技术细节区块须排在关于该商品/详情之前');
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
    self::assertStringContainsString('$descriptionHtml !== \'\' ? $descriptionHtml : nl2br($escape($description), false)', $template);
    self::assertStringContainsString('.product-native-detail__description-body img', $detailCss);
    self::assertMatchesRegularExpression('/\.product-native-detail__description,\s*\n\s*\.product-native-detail__specifications\s*\{[^}]*max-width:\s*100%;/s', $detailCss, '关于该商品描述区须与主列同宽，禁止 64rem 二次收窄');
    self::assertStringNotContainsString('max-width: min(100%, 64rem)', $detailCss);
    self::assertStringContainsString('.weline-detail-text--size-chart', $detailCss);
    self::assertStringContainsString('.weline-detail-text__columns', $detailCss);
    self::assertStringContainsString('.product-native-detail__qty-label', $detailCss);
    self::assertMatchesRegularExpression('/\.product-native-detail__qty-label\s*\{[^}]*white-space:\s*nowrap/s', $detailCss);
    self::assertStringContainsString('.product-native-detail__qty-select', $detailCss);
    self::assertMatchesRegularExpression('/\.product-native-detail__qty-select\s*\{(?:[^{}]|\{[^}]*\})*inline-size:\s*auto/s', $detailCss);
}

private function assert_ProductDetailTemplate_testDetailTemplateExposesAfterGalleryHookUnderMediaColumn(): void
{
    $template = (string) file_get_contents(BP . 'app/code/Weline/Product/view/templates/frontend/widgets/product-info.phtml');
    self::assertStringContainsString('product-native-detail__media-column', $template);
    self::assertStringContainsString("getHook('Weline_Product::frontend::product::detail::after-gallery')", $template);
    self::assertLessThan((int) strpos($template, "getHook('Weline_Product::frontend::product::detail::after-gallery')"), (int) strpos($template, 'data-testid="product-gallery"'), 'after-gallery 须紧随图库之后');
    self::assertLessThan((int) strpos($template, 'product-native-detail__buy-column'), (int) strpos($template, "getHook('Weline_Product::frontend::product::detail::after-gallery')"), 'after-gallery 须在购买列之前');
}

private function assert_ProductDetailTemplate_testDetailTemplateUsesPublishedOfferIdentityThroughCartQueryBin(): void
{
    $template = (string) file_get_contents(BP . 'app/code/Weline/Product/view/templates/frontend/widgets/product-info.phtml');
    $variantJs = (string) file_get_contents(BP . 'app/code/Weline/Product/view/statics/js/widgets/widget-product-info-0.js');
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
    self::assertStringContainsString('min-inline-size: 0', (string) file_get_contents(BP . 'app/code/Weline/Product/view/statics/css/widgets/product-native-detail.css'));
    self::assertStringContainsString('@param show_brand', $template);
    self::assertStringContainsString('@param show_supplier', $template);
    self::assertStringContainsString('data-testid="product-brand"', $template);
    self::assertStringContainsString('data-testid="product-supplier"', $template);
    self::assertStringContainsString('@url{\'products\'}', $template);
    self::assertStringContainsString('@widget.code {product-info}', $template);
    self::assertStringContainsString('product-main', $template);
    self::assertStringContainsString('data-variant-live="1"', $template);
    self::assertStringNotContainsString('data-variant-live-url', $template);
    self::assertStringContainsString('refreshLiveAvailability', $variantJs);
    self::assertStringContainsString('productApi.getVariantAvailability(', $variantJs);
    self::assertStringNotContainsString('fetch(liveUrl', $variantJs);
    self::assertStringNotContainsString('scope:', $template);
    self::assertStringNotContainsString('website_id:', $template);
    self::assertStringNotContainsString('store_code:', $template);
    self::assertStringNotContainsString("Weline.Api.resource('cart')", $template);
    self::assertStringNotContainsString('axios', $template);
}

private function assert_ProductDetailTemplate_testConfigurableDetailRequiresAnExplicitOfferIdentity(): void
{
    $template = (string) file_get_contents(BP . 'app/code/Weline/Product/view/templates/frontend/widgets/product-info.phtml');
    $variantJs = (string) file_get_contents(BP . 'app/code/Weline/Product/view/statics/js/widgets/widget-product-info-0.js');
    $controller = (string) file_get_contents(BP . 'app/code/Weline/Product/Controller/Frontend/Detail.php');
    $shell = (string) file_get_contents(BP . 'app/code/Weline/Product/Service/StorefrontPdpVariantShell.php');
    $service = (string) file_get_contents(BP . 'app/code/Weline/Product/Service/StorefrontCatalogViewService.php');
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
    self::assertStringContainsString('全局 EAV 选项图板仅表示', (string) file_get_contents(BP . 'app/code/Weline/Product/Service/StorefrontVariantAxisResolver.php'));
    self::assertStringContainsString('clearVariantOptionSwatchImages', (string) file_get_contents(BP . 'app/code/Weline/Product/Service/ProductCatalogEavBootstrap.php'));
    self::assertStringContainsString('data-variant-live="1"', $template);
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
    $queryProvider = (string) file_get_contents(BP . 'app/code/Weline/Product/extends/module/Weline_Framework/Query/ProductQueryProvider.php');
    self::assertStringContainsString("'name' => 'getVariantAvailability'", $queryProvider);
    self::assertStringContainsString('StorefrontVariantAvailabilityService::class', $queryProvider);
    self::assertFileDoesNotExist(BP . 'app/code/Weline/Product/Controller/Frontend/Api/VariantAvailability.php');
    self::assertStringNotContainsString('axios', $template);
}

private function assert_ProductDownloadSurface_testPaidObserverIsSynchronousCriticalAndReadsOrderUuid(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Product';
    $event = (string) file_get_contents($root . '/etc/event.xml');
    $observer = (string) file_get_contents($root . '/Observer/GrantDownloadEntitlementsOnOrderPaid.php');
    self::assertStringContainsString('Weline_Order::order_paid', $event);
    self::assertStringContainsString('delivery="sync"', $event);
    self::assertStringContainsString('failure="critical"', $event);
    self::assertStringContainsString("getData('order_uuid')", $observer);
}

private function assert_ProductDownloadSurface_testDownloadRouteAndControllerAcceptOnlyEntitlementUuid(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Product';
    $router = (string) file_get_contents($root . '/Controller/Router.php');
    $controller = (string) file_get_contents($root . '/Controller/Frontend/Download.php');
    self::assertStringContainsString('product-download/', $router);
    self::assertStringContainsString('input.query.entitlement_uuid', $router);
    self::assertStringContainsString("getParam('entitlement_uuid'", $controller);
    self::assertStringNotContainsString("getParam('asset_id'", $controller);
}

private function assert_ProductDownloadSurface_testCustomerQueryIsUncachedAndEntitlementGrantUsesFrozenOrderMetadata(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Product';
    $query = (string) file_get_contents($root . '/extends/module/Weline_Framework/Query/ProductDownloadQueryProvider.php');
    $service = (string) file_get_contents($root . '/Service/ProductDownloadEntitlementService.php');
    self::assertStringContainsString("'auth' => 'customer'", $query);
    self::assertStringContainsString("'cache_ttl' => 0", $query);
    self::assertStringContainsString('OrderFacadeInterface', $service);
    self::assertStringContainsString("['fulfillment_metadata']['digital_download']", $service);
    self::assertStringContainsString('StorageUrlOptions::KIND_TEMPORARY', $service);
    self::assertStringContainsString("additional('FOR UPDATE')", $service);
}

private function assert_ProductStorefrontTemplate_testProductControllerSelectsTheProductListThemeLayout(): void
{
    $controller = (string) file_get_contents(BP . 'app/code/Weline/Product/Controller/Frontend/Catalog.php');
    self::assertStringContainsString("\$this->layoutType = \$surface['layout_type']", $controller);
    self::assertStringContainsString("setGet('page_type', \$surface['page_type'])", $controller);
    self::assertStringNotContainsString('theme_public_route', $controller);
    self::assertStringContainsString("assign('showToolbar', false)", $controller);
    self::assertStringContainsString('StorefrontListingShell', $controller);
    self::assertStringContainsString('projectNeutral(', $controller);
    self::assertStringContainsString('projectFiltered(', $controller);
    self::assertStringContainsString('StorefrontListingFpcQueryKeys', $controller);
    self::assertStringContainsString("assign('storefront_offers_unfiltered'", $controller);
    self::assertStringContainsString("assign('storefront_listing_sort_options'", $controller);
    self::assertStringContainsString("assign('storefront_listing_page_options'", $controller);
}

private function assert_ProductStorefrontTemplate_testCatalogAndCategoryTemplatesExposeListingPager(): void
{
    $catalog = (string) file_get_contents(BP . 'app/code/Weline/Product/view/templates/frontend/catalog/index.phtml');
    $category = (string) file_get_contents(BP . 'app/code/Weline/Product/view/templates/frontend/category/index.phtml');
    self::assertStringContainsString('data-testid="storefront-products-pager"', $catalog);
    self::assertStringContainsString('data-testid="storefront-category-pager"', $category);
    self::assertStringContainsString('data-listing-sort', $catalog);
    self::assertStringContainsString('data-listing-page', $catalog);
    self::assertStringNotContainsString('window.location.href', $catalog);
    self::assertStringNotContainsString('window.location.href', $category);
    // Heavy-locale packs only prefetch template literals; dynamic __($var) stays Chinese.
    self::assertStringContainsString("__('默认排序')", $catalog);
    self::assertStringContainsString("__('价格从低到高')", $catalog);
    self::assertStringContainsString("__('价格从高到低')", $catalog);
    self::assertStringContainsString("__('名称 A-Z')", $catalog);
    self::assertStringContainsString("__('默认排序')", $category);
    self::assertStringContainsString('storefront_listing_page_options', $catalog);
    self::assertStringContainsString('storefront_listing_page_options', $category);
    self::assertStringContainsString('is-ellipsis', $catalog);
    self::assertStringContainsString('is-ellipsis', $category);
}

private function assert_ProductStorefrontTemplate_testProductControllerDoesNotReachIntoCartConcreteServices(): void
{
    $controller = (string) file_get_contents(BP . 'app/code/Weline/Product/Controller/Frontend/Catalog.php');
    self::assertStringNotContainsString('Weline\Cart', $controller);
    self::assertStringNotContainsString('ObjectManager', $controller);
    self::assertStringNotContainsString('Cookie::', $controller);
}

private function assert_ProductStorefrontTemplate_testCatalogDelegatesCartMutationToUnifiedProductCard(): void
{
    $template = (string) file_get_contents(BP . 'app/code/Weline/Product/view/templates/frontend/catalog/index.phtml');
    self::assertStringContainsString('ProductCardRenderer::projectFromOffers', $template);
    self::assertStringContainsString("'show_add_to_cart' => true", $template);
}

private function assert_ProductStorefrontTemplate_testCartLinkKeepsTheActiveCurrencyAndLocaleRoute(): void
{
    $template = (string) file_get_contents(BP . 'app/code/Weline/Product/view/templates/frontend/catalog/index.phtml');
    self::assertStringContainsString('href="@url{\'cart\'}"', $template);
    self::assertStringNotContainsString('href="/cart"', $template);
}

private function assert_ProductStorefrontTemplate_testCatalogCardsUseUnifiedProductCardTag(): void
{
    $template = (string) file_get_contents(BP . 'app/code/Weline/Product/view/templates/frontend/catalog/index.phtml');
    self::assertStringContainsString('ProductCardRenderer::projectFromOffers', $template);
    self::assertStringContainsString("'show_sku' => true", $template);
    self::assertStringContainsString('weline-product-card-shelf', $template);
    self::assertStringNotContainsString('<w:product:card', $template);
    self::assertStringNotContainsString('class="product-storefront__card-hit"', $template);
    self::assertStringNotContainsString('product-storefront__card product-card', $template);
}

private function assert_ProductStorefrontTemplate_testProductListLayoutLeavesFiltersSlotForFiltersModuleInjection(): void
{
    $layout = (string) file_get_contents(BP . 'app/code/Weline/Product/view/theme/frontend/layouts/products/default.phtml');
    self::assertStringContainsString('id="list-filters"', $layout);
    self::assertStringContainsString('data-placeholder="list-filters"', $layout);
    self::assertStringContainsString('由 Filters 部件默认注入', $layout);
    // Storefront solidify fallback must not paint declaration copy for customers.
    self::assertStringContainsString('$isEditorPreview', $layout);
    self::assertStringContainsString('if ($isEditorPreview)', $layout);
    self::assertStringNotContainsString("getHook('Weline_Theme::frontend::layouts::products::filters-sidebar'", $layout);
    self::assertStringContainsString('grid-template-columns: 240px minmax(0, 1fr)', $layout);
    self::assertStringContainsString('width: min(100%, var(--weline-layout-content-max-width));', $layout);
    // No widget override: keep controller content / live catalog fallback (editor clears meta.content).
    self::assertStringContainsString('<elseif condition="content"/>', $layout);
    self::assertStringContainsString('Weline_Product::templates/frontend/catalog/index.phtml', $layout);
    self::assertStringContainsString('publishedListingCandidates', $layout);
    self::assertFileDoesNotExist(BP . 'app/code/Weline/Product/view/hooks/Weline_Theme/frontend/layouts/products/filters-sidebar.phtml');
}

private function assert_ProductStorefrontTemplate_testCatalogControllerDispatchesStorefrontOffersFilterEventViaListingShell(): void
{
    $controller = (string) file_get_contents(BP . 'app/code/Weline/Product/Controller/Frontend/Catalog.php');
    $shell = (string) file_get_contents(BP . 'app/code/Weline/Product/Service/StorefrontListingShell.php');
    self::assertStringContainsString('StorefrontListingShell', $controller);
    self::assertStringContainsString('Weline_Product::storefront_offers_filter', $shell);
    self::assertStringContainsString('EventsManager', $shell);
    self::assertStringNotContainsString('ObjectManager', $controller);
}

private function assert_ProductStorefrontTemplate_testProductEventSpecDeclaresStorefrontOffersFilter(): void
{
    $spec = require BP . 'app/code/Weline/Product/event.php';
    self::assertIsArray($spec);
    self::assertArrayHasKey('Weline_Product::storefront_offers_filter', $spec);
}

private function assert_ProductStorefrontTemplate_testBrowserCartMutationDoesNotSubmitAClientOwnedScope(): void
{
    $template = (string) file_get_contents(BP . 'app/code/Weline/Product/view/templates/frontend/catalog/index.phtml');
    self::assertStringNotContainsString('scope:', $template);
    self::assertStringNotContainsString('website_id:', $template);
    self::assertStringNotContainsString('store_code:', $template);
}
}
