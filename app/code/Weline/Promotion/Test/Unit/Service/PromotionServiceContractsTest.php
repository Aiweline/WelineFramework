<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\DateTime\Timezone;

/**
 * Aggregated contract suite (merged from 14 *ContractTest files).
 * Observe one overall pass/fail via testAllPromotionServiceContractsPass().
 */
final class PromotionServiceContractsTest extends TestCase
{
    public function testAllPromotionServiceContractsPass(): void
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
            'EnsureDefaultThemesExistingMatch::testEnsureDefaultThemesVerifiesWebsiteAndSlugBeforeSkip' => function () {
            $this->assert_EnsureDefaultThemesExistingMatch_testEnsureDefaultThemesVerifiesWebsiteAndSlugBeforeSkip();
        },
            'PromotionActivityThemeResourceChange::testSaveThemePublishesResourceChangeAndClearsLocalFpc' => function () {
            $this->assert_PromotionActivityThemeResourceChange_testSaveThemePublishesResourceChangeAndClearsLocalFpc();
        },
            'PromotionStorefrontActiveDealResolver::testResolverExposesActiveThemeDealApi' => function () {
            $this->assert_PromotionStorefrontActiveDealResolver_testResolverExposesActiveThemeDealApi();
        },
            'PromotionStorefrontActiveDealResolver::testThemeServiceExposesStorefrontActiveThemeList' => function () {
            $this->assert_PromotionStorefrontActiveDealResolver_testThemeServiceExposesStorefrontActiveThemeList();
        },
            'PromotionStorefrontLaunchReadiness::testStorefrontShelfUsesRealCatalogProductsWithoutAcceptancePlaceholders' => function () {
            $this->assert_PromotionStorefrontLaunchReadiness_testStorefrontShelfUsesRealCatalogProductsWithoutAcceptancePlaceholders();
        },
            'PromotionStorefrontLaunchReadiness::testBuiltInThemeCopyResolvesTheSpecificLocaleBeforeBaseRowFallback' => function () {
            $this->assert_PromotionStorefrontLaunchReadiness_testBuiltInThemeCopyResolvesTheSpecificLocaleBeforeBaseRowFallback();
        },
            'PromotionStorefrontLaunchReadiness::testStorefrontPromotionCopyShipsEnglishAndArabicDictionaries' => function () {
            $this->assert_PromotionStorefrontLaunchReadiness_testStorefrontPromotionCopyShipsEnglishAndArabicDictionaries();
        },
            'PromotionStorefrontLaunchReadiness::testPromotionTemplateDoesNotNestAMainLandmarkInsideTheThemeLayout' => function () {
            $this->assert_PromotionStorefrontLaunchReadiness_testPromotionTemplateDoesNotNestAMainLandmarkInsideTheThemeLayout();
        },
            'PromotionStorefrontLaunchReadiness::testPromotionHeroMirrorsItsImageFocusForRtlLocales' => function () {
            $this->assert_PromotionStorefrontLaunchReadiness_testPromotionHeroMirrorsItsImageFocusForRtlLocales();
        },
            'PromotionStorefrontPageServiceAssemblerPricing::testShelfPricingTrustsAssemblerAndBlocksFilterEmptyCatalogFallback' => function () {
            $this->assert_PromotionStorefrontPageServiceAssemblerPricing_testShelfPricingTrustsAssemblerAndBlocksFilterEmptyCatalogFallback();
        },
            'PromotionStorefrontPageServiceHubDealFilter::testHubBuildUsesActiveThemeSelectionsAndDropsUnmarkedCatalogRows' => function () {
            $this->assert_PromotionStorefrontPageServiceHubDealFilter_testHubBuildUsesActiveThemeSelectionsAndDropsUnmarkedCatalogRows();
        },
            'PromotionStorefrontPageServiceOfferDeepLink::testNormalizeItemsDeepLinksPdpWithStorefrontOfferDetailQuery' => function () {
            $this->assert_PromotionStorefrontPageServiceOfferDeepLink_testNormalizeItemsDeepLinksPdpWithStorefrontOfferDetailQuery();
        },
            'PromotionStorefrontPageServiceProductDedupe::testLoadProductsByIdsDedupesCatalogOffersAndDoesNotUseDefaultTwelveLimit' => function () {
            $this->assert_PromotionStorefrontPageServiceProductDedupe_testLoadProductsByIdsDedupesCatalogOffersAndDoesNotUseDefaultTwelveLimit();
        },
            'PromotionStorefrontPageServiceProductDedupe::testPromotionShelfUsesCatalogSummaryProjection' => function () {
            $this->assert_PromotionStorefrontPageServiceProductDedupe_testPromotionShelfUsesCatalogSummaryProjection();
        },
            'PromotionStorefrontPageServiceTitle::testBuildReadsPageTitleFromThemePagePayload' => function () {
            $this->assert_PromotionStorefrontPageServiceTitle_testBuildReadsPageTitleFromThemePagePayload();
        },
            'PromotionStorefrontScope::testStorefrontPageServiceUsesScopeResolverAndNavTabsForUrls' => function () {
            $this->assert_PromotionStorefrontScope_testStorefrontPageServiceUsesScopeResolverAndNavTabsForUrls();
        },
            'PromotionStorefrontScope::testThemeServiceFiltersByScopeMatcherOnStorefront' => function () {
            $this->assert_PromotionStorefrontScope_testThemeServiceFiltersByScopeMatcherOnStorefront();
        },
            'PromotionStorefrontUrlBuilder::testThemeServiceBuildsStorefrontUrlsViaFrameworkFrontendUrl' => function () {
            $this->assert_PromotionStorefrontUrlBuilder_testThemeServiceBuildsStorefrontUrlsViaFrameworkFrontendUrl();
        },
            'PromotionStorefrontUrlBuilder::testPageServiceDelegatesListAndCampaignUrlsToThemeStorefrontUrl' => function () {
            $this->assert_PromotionStorefrontUrlBuilder_testPageServiceDelegatesListAndCampaignUrlsToThemeStorefrontUrl();
        },
            'PromotionStorefrontUrlBuilder::testFrontendTemplateFallbacksUseGetFrontendUrl' => function () {
            $this->assert_PromotionStorefrontUrlBuilder_testFrontendTemplateFallbacksUseGetFrontendUrl();
        },
            'PromotionStorefrontUrlBuilder::testPriceAdjustmentProviderUsesThemeStorefrontUrlFallback' => function () {
            $this->assert_PromotionStorefrontUrlBuilder_testPriceAdjustmentProviderUsesThemeStorefrontUrlFallback();
        },
            'PromotionThemeDealDiscountSyncProvider::testSyncServiceConsumesMarketingProviderAndDoesNotTouchRuleModel' => function () {
            $this->assert_PromotionThemeDealDiscountSyncProvider_testSyncServiceConsumesMarketingProviderAndDoesNotTouchRuleModel();
        },
            'PromotionThemeScheduleWindow::testOutsideUtcWindowRejectedByHelper' => function () {
            $this->assert_PromotionThemeScheduleWindow_testOutsideUtcWindowRejectedByHelper();
        },
            'PromotionThemeScheduleWindow::testActiveDealResolverSourceGuardsWindow' => function () {
            $this->assert_PromotionThemeScheduleWindow_testActiveDealResolverSourceGuardsWindow();
        },
            'StorefrontCampaignLabelI18n::testResolveCampaignDisplayLabelTranslatesHanViaUnderscore' => function () {
            $this->assert_StorefrontCampaignLabelI18n_testResolveCampaignDisplayLabelTranslatesHanViaUnderscore();
        },
            'StorefrontCampaignLabelI18n::testHindiCsvCoversDealsChrome' => function () {
            $this->assert_StorefrontCampaignLabelI18n_testHindiCsvCoversDealsChrome();
        },
        ];
    }

private function assert_EnsureDefaultThemesExistingMatch_testEnsureDefaultThemesVerifiesWebsiteAndSlugBeforeSkip(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Service/PromotionActivityThemeService.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('function ensureDefaultThemes', $src);
    self::assertStringContainsString('existingMatches', $src);
    self::assertStringContainsString('schema_fields_WEBSITE_ID) === (int)$websiteId', $src);
    self::assertStringContainsString('schema_fields_PAGE_SLUG))) === strtolower(trim((string)$row[\'page_slug\'])', $src);
    self::assertStringContainsString("'product_ids' => []", $src);
}

private function assert_PromotionActivityThemeResourceChange_testSaveThemePublishesResourceChangeAndClearsLocalFpc(): void
{
    $service = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Service/PromotionActivityThemeService.php');
    $publisher = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Service/PromotionActivityThemeResourceChangePublisher.php');
    $invalidator = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Service/PromotionStorefrontCacheInvalidator.php');
    $doc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/doc/event/resource_changed.md');
    $readme = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/doc/README.md');
    self::assertStringContainsString('promotion_activity_theme', $publisher);
    self::assertStringContainsString('w_changed', $publisher);
    self::assertStringContainsString('/promotion', $publisher);
    self::assertStringContainsString('publishUpsert', $service);
    self::assertStringContainsString('afterCommit', $service);
    self::assertStringContainsString('WriteIntentTransactionCoordinatorInterface', $service);
    self::assertStringContainsString('clearForTheme', $invalidator);
    self::assertStringContainsString('FullPageCacheCoordinator', $invalidator);
    self::assertStringContainsString('w_changed', $doc);
    self::assertStringContainsString('PromotionStorefrontCacheInvalidator', $doc);
    self::assertStringContainsString('resource_changed.md', $readme);
    self::assertStringNotContainsString('Weline_Cdn::clear', $publisher);
    self::assertStringNotContainsString('Weline_Cdn::clear', $service);
}

private function assert_PromotionStorefrontActiveDealResolver_testResolverExposesActiveThemeDealApi(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Service/PromotionStorefrontActiveDealResolver.php';
    self::assertFileExists($path);
    $content = (string) file_get_contents($path);
    $resolve = new \ReflectionMethod(\Weline\Promotion\Service\PromotionStorefrontActiveDealResolver::class, 'resolveForProduct');
    self::assertSame(1, $resolve->getNumberOfRequiredParameters());
    self::assertTrue($resolve->getParameters()[1]->allowsNull());
    self::assertStringContainsString('function applyToCatalogPrice(float $catalogPrice', $content);
    self::assertStringContainsString('listActiveThemesForStorefront', $content);
    self::assertStringContainsString('applyDealToPrice', $content);
}

private function assert_PromotionStorefrontActiveDealResolver_testThemeServiceExposesStorefrontActiveThemeList(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Service/PromotionActivityThemeService.php';
    self::assertFileExists($path);
    $content = (string) file_get_contents($path);
    self::assertStringContainsString('function listActiveThemesForStorefront', $content);
    self::assertStringContainsString('function listHubStorefrontProductIds', $content);
}

private function assert_PromotionStorefrontLaunchReadiness_testStorefrontShelfUsesRealCatalogProductsWithoutAcceptancePlaceholders(): void
{
    $servicePath = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Service/PromotionStorefrontPageService.php';
    $templatePath = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/view/templates/frontend/promotion/index.phtml';
    self::assertFileExists($servicePath);
    self::assertFileExists($templatePath);
    $service = (string) file_get_contents($servicePath);
    $template = (string) file_get_contents($templatePath);
    self::assertStringContainsString('$this->resolveCatalog()', $service);
    self::assertStringContainsString('class_exists(StorefrontCatalogViewService::class)', $service);
    self::assertStringContainsString('ObjectManager::getInstance(StorefrontCatalogViewService::class)', $service);
    self::assertStringContainsString('$catalog->publishedOfferSummaries(', $service);
    self::assertStringContainsString("\$item['slug']", $service);
    self::assertStringContainsString("\$item['unit_price_minor']", $service);
    self::assertStringContainsString('StorefrontOfferDetailQuery::params', $service);
    self::assertStringContainsString('http_build_query($detailQuery)', $service);
    self::assertStringNotContainsString('stubProducts(', $service);
    self::assertStringNotContainsString('示例商品 A', $service);
    self::assertStringNotContainsString('data:image/svg+xml', $template);
    self::assertStringNotContainsString('<span class="price-current">¥', $template);
}

private function assert_PromotionStorefrontLaunchReadiness_testBuiltInThemeCopyResolvesTheSpecificLocaleBeforeBaseRowFallback(): void
{
    $servicePath = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Service/PromotionActivityThemeService.php';
    self::assertFileExists($servicePath);
    $service = (string) file_get_contents($servicePath);
    self::assertStringContainsString('Cookie::getLangLocal() ?: Cookie::getLang()', $service);
    self::assertStringContainsString('private function isBuiltInThemeSlug(string $pageSlug): bool', $service);
    self::assertStringContainsString("['deals', 'sale', 'weekend', 'wedding']", $service);
    self::assertStringContainsString('private function isCopyCompatibleWithLocale(string $value, string $locale): bool', $service);
    self::assertStringContainsString("preg_match('/\\p{Han}/u'", $service);
    self::assertStringContainsString("preg_match('/\\p{Arabic}/u'", $service);
}

private function assert_PromotionStorefrontLaunchReadiness_testStorefrontPromotionCopyShipsEnglishAndArabicDictionaries(): void
{
    $modulePath = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion';
    $englishPath = $modulePath . '/i18n/en_US.csv';
    $arabicPath = $modulePath . '/i18n/ar_SA.csv';
    self::assertFileExists($englishPath);
    self::assertFileExists($arabicPath);
    $english = (string) file_get_contents($englishPath);
    $arabic = (string) file_get_contents($arabicPath);
    foreach (['活动首页', '今日特价', '今日特价专场', '看今日特价', '节令主题', '出游常服', '婚嫁礼服', '浏览活动商品', '浏览活动商品，进入商品详情、购物车与结账路径。本页不展示虚假折扣，只承接真实可售商品。', '匹配商品', '活动入口', '活动商品', '查看商品', '围绕踏青、市集与日常出游，陈列常服套装与轻便搭配，只展示真实可售商品，不虚构折扣。', '围绕婚礼、订婚与敬酒仪式，陈列嫁衣与礼服套装，只展示真实成交价，不虚构折扣。'] as $key) {
        self::assertMatchesRegularExpression('/^' . preg_quote($key, '/') . ',(?!' . preg_quote($key, '/') . '$).+/m', $english);
        self::assertMatchesRegularExpression('/^' . preg_quote($key, '/') . ',(?!' . preg_quote($key, '/') . '$).+/m', $arabic);
    }
    self::assertDoesNotMatchRegularExpression('/^匹配商品,匹配商品$/m', $english);
    self::assertMatchesRegularExpression('/^匹配商品,"Matching products"$/m', $english);
}

private function assert_PromotionStorefrontLaunchReadiness_testPromotionTemplateDoesNotNestAMainLandmarkInsideTheThemeLayout(): void
{
    $templatePath = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/view/templates/frontend/promotion/index.phtml';
    self::assertFileExists($templatePath);
    $template = (string) file_get_contents($templatePath);
    self::assertStringContainsString('<div class="w-promotion-page weline-product-card-shelf"', $template);
    self::assertStringNotContainsString('<main class="w-promotion-page weline-product-card-shelf"', $template);
    self::assertStringContainsString('<w:product:card ids="batchProducts"', $template);
    self::assertStringNotContainsString('ProductCardRenderer::projectFromProducts', $template);
    self::assertStringContainsString('ProductCardRenderer::emitStylesheetLinkOnce()', $template);
    self::assertStringNotContainsString('amazon-product-card.css', $template);
}

private function assert_PromotionStorefrontLaunchReadiness_testPromotionHeroMirrorsItsImageFocusForRtlLocales(): void
{
    $cssPath = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/view/statics/css/promotion-hub.css';
    self::assertFileExists($cssPath);
    $css = (string) file_get_contents($cssPath);
    self::assertStringContainsString('--promo-hero-gradient-angle: 90deg;', $css);
    self::assertStringContainsString('--promo-hero-focus-x: 72%;', $css);
    self::assertStringContainsString('[dir="rtl"] .w-promotion-page', $css);
    self::assertStringContainsString('--promo-hero-gradient-angle: 270deg;', $css);
    self::assertStringContainsString('--promo-hero-focus-x: 28%;', $css);
    self::assertStringContainsString('linear-gradient(var(--promo-hero-gradient-angle)', $css);
}

private function assert_PromotionStorefrontPageServiceAssemblerPricing_testShelfPricingTrustsAssemblerAndBlocksFilterEmptyCatalogFallback(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Service/PromotionStorefrontPageService.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('product_pick_mode', $src);
    self::assertStringContainsString('PICK_MODE_FILTER', $src);
    self::assertStringContainsString('Assembler is authoritative with PDP/cart', $src);
    self::assertStringContainsString("catalog_price_minor'] ?? \$item['unit_price_minor'", $src);
    self::assertStringContainsString("'has_deal' => false", $src);
    self::assertStringContainsString('keepDealMarkedItemsOnly', $src);
    self::assertStringContainsString('listHubStorefrontProductIds', $src);
    self::assertStringNotContainsString('Shelf themes may list price-band products while selection is empty; keep page deal + campaign name.', $src);
}

private function assert_PromotionStorefrontPageServiceHubDealFilter_testHubBuildUsesActiveThemeSelectionsAndDropsUnmarkedCatalogRows(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Service/PromotionStorefrontPageService.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('listHubStorefrontProductIds', $src);
    self::assertStringContainsString('keepDealMarkedItemsOnly', $src);
    self::assertStringContainsString('// Hub never shelves a generic catalog slice; only active-theme selections.', $src);
    self::assertStringContainsString('// Activity homepage must not show catalog rows without a live deal badge.', $src);
    self::assertStringContainsString("\$pageType === 'index'", $src);
    self::assertStringContainsString('empty($item[\'has_deal\'])', $src);
}

private function assert_PromotionStorefrontPageServiceOfferDeepLink_testNormalizeItemsDeepLinksPdpWithStorefrontOfferDetailQuery(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Service/PromotionStorefrontPageService.php';
    self::assertFileExists($path);
    $content = (string) file_get_contents($path);
    self::assertStringContainsString('use Weline\Product\Helper\StorefrontOfferDetailQuery;', $content);
    self::assertStringContainsString('class_exists(StorefrontOfferDetailQuery::class)', $content);
    self::assertStringContainsString('StorefrontOfferDetailQuery::params($item)', $content);
    self::assertStringContainsString('http_build_query($detailQuery)', $content);
    self::assertStringContainsString("\$url .= (str_contains(\$url, '?') ? '&' : '?') . http_build_query(\$detailQuery);", $content);
}

private function assert_PromotionStorefrontPageServiceProductDedupe_testLoadProductsByIdsDedupesCatalogOffersAndDoesNotUseDefaultTwelveLimit(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Service/PromotionStorefrontPageService.php';
    self::assertFileExists($path);
    $content = (string) file_get_contents($path);
    self::assertStringNotContainsString('publishedOffersForProductIds($productIds, max(12, count($productIds)))', $content);
    self::assertStringContainsString('isset($byProductId[$productId])', $content);
    self::assertStringContainsString('foreach ($orderedIds as $productId)', $content);
    self::assertStringContainsString('// Explicit theme selection must not fall back to a generic catalog page.', $content);
    self::assertStringContainsString('return $this->loadProductsByIds($productIds);', $content);
}

private function assert_PromotionStorefrontPageServiceProductDedupe_testPromotionShelfUsesCatalogSummaryProjection(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Service/PromotionStorefrontPageService.php';
    self::assertFileExists($path);
    $content = (string) file_get_contents($path);
    self::assertStringContainsString('$catalog->publishedOfferSummaries(48)', $content);
    self::assertMatchesRegularExpression('/publishedOffersForProductIds\(\s*\$orderedIds,\s*max\(count\(\$orderedIds\) \* 8, count\(\$orderedIds\)\),\s*false,\s*\)/s', $content);
}

private function assert_PromotionStorefrontPageServiceTitle_testBuildReadsPageTitleFromThemePagePayload(): void
{
    $themeService = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Service/PromotionActivityThemeService.php';
    $storefront = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Service/PromotionStorefrontPageService.php';
    self::assertFileExists($themeService);
    self::assertFileExists($storefront);
    $themeContent = (string) file_get_contents($themeService);
    $storefrontContent = (string) file_get_contents($storefront);
    self::assertStringContainsString("'page_title' => (string)\$copy['page_title']", $themeContent);
    self::assertStringContainsString("\$themePage['page_title'] ?? \$themePage['title']", $storefrontContent);
    self::assertStringContainsString("'sale' => (string)__('节令主题陈列')", $storefrontContent);
}

private function assert_PromotionStorefrontScope_testStorefrontPageServiceUsesScopeResolverAndNavTabsForUrls(): void
{
    $storefront = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Service/PromotionStorefrontPageService.php';
    $scopeResolver = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Service/PromotionScopeResolver.php';
    $frontendTemplate = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/view/templates/frontend/promotion/index.phtml';
    self::assertFileExists($storefront);
    self::assertFileExists($scopeResolver);
    self::assertFileExists($frontendTemplate);
    $storefrontContent = (string) file_get_contents($storefront);
    $scopeContent = (string) file_get_contents($scopeResolver);
    $templateContent = (string) file_get_contents($frontendTemplate);
    self::assertStringContainsString('PromotionScopeResolver', $storefrontContent);
    self::assertStringContainsString('$this->scopeResolver->resolve()', $storefrontContent);
    self::assertStringContainsString('slugUrlsFromNavTabs', $storefrontContent);
    self::assertStringContainsString("'store_code' =>", $storefrontContent);
    self::assertStringContainsString('CartScopeResolverInterface', $scopeContent);
    self::assertStringContainsString('resolveTrustedScope', $scopeContent);
    self::assertStringNotContainsString('今日精选', $templateContent);
    self::assertStringNotContainsString('主题陈列', $templateContent);
}

private function assert_PromotionStorefrontScope_testThemeServiceFiltersByScopeMatcherOnStorefront(): void
{
    $themeService = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Service/PromotionActivityThemeService.php';
    $content = (string) file_get_contents($themeService);
    self::assertStringContainsString('PromotionActivityThemeScopeMatcher::matches', $content);
    self::assertStringContainsString('PromotionActivityThemeScopeMatcher::dedupeByPageSlug', $content);
    self::assertStringContainsString('$this->scopeResolver->resolve()', $content);
    self::assertStringContainsString("'nav_label' => '今日特价'", $content);
    self::assertStringNotContainsString("'nav_label' => '今日精选'", $content);
    self::assertStringContainsString('migrateLegacyDealsFeaturedBranding', $content);
    self::assertStringContainsString("__('今日特价')", $content);
    self::assertStringContainsString('findActiveDealOverlaps', (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Service/PromotionThemeProductService.php'));
    self::assertStringContainsString('listEligibleDealsForProduct', (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Service/PromotionStorefrontActiveDealResolver.php'));
    self::assertStringContainsString('force_overlap', (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Service/PromotionActivityThemeService.php'));
    self::assertStringContainsString('needs_overlap_confirm', (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/view/templates/backend/promotion/theme/form.phtml'));
}

private function assert_PromotionStorefrontUrlBuilder_testThemeServiceBuildsStorefrontUrlsViaFrameworkFrontendUrl(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Service/PromotionActivityThemeService.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('use Weline\Framework\Http\Url;', $src);
    self::assertStringContainsString('private readonly Url $url', $src);
    self::assertStringContainsString('public function storefrontUrl(string $pageSlug = \'\'): string', $src);
    self::assertStringContainsString("getFrontendUrl('promotion')", $src);
    self::assertStringContainsString("getFrontendUrl('promotion/' . rawurlencode(\$pageSlug))", $src);
    self::assertStringContainsString("'url' => \$this->storefrontUrl()", $src);
    self::assertStringContainsString("'url' => \$this->storefrontUrl(\$slug)", $src);
    self::assertStringContainsString("'action_url' => \$this->storefrontUrl(\$slug)", $src);
    self::assertStringContainsString("'campaign_url' => \$slug !== '' ? \$this->storefrontUrl(\$slug) : ''", $src);
    self::assertStringContainsString("'storefront_url' => \$this->storefrontUrl(", $src);
    self::assertStringNotContainsString("'url' => '/promotion'", $src);
    self::assertStringNotContainsString("'/promotion/' . rawurlencode(\$slug)", $src);
}

private function assert_PromotionStorefrontUrlBuilder_testPageServiceDelegatesListAndCampaignUrlsToThemeStorefrontUrl(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Service/PromotionStorefrontPageService.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('$this->storefrontUrl($pageType)', $src);
    self::assertStringContainsString("'list_url' => \$this->storefrontUrl()", $src);
    self::assertStringContainsString('return $this->themeService->storefrontUrl($pageSlug);', $src);
    self::assertStringNotContainsString("'list_url' => '/promotion'", $src);
    self::assertStringNotContainsString("'/promotion/' . rawurlencode(\$pageType)", $src);
}

private function assert_PromotionStorefrontUrlBuilder_testFrontendTemplateFallbacksUseGetFrontendUrl(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/view/templates/frontend/promotion/index.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString("getFrontendUrl('promotion')", $src);
    self::assertStringContainsString("getFrontendUrl('promotion/deals')", $src);
    self::assertStringContainsString("getFrontendUrl('promotion/sale')", $src);
    self::assertStringNotContainsString("?? '/promotion'", $src);
    self::assertStringNotContainsString("?? '/promotion/deals'", $src);
    self::assertStringNotContainsString("?? '/promotion/sale'", $src);
}

private function assert_PromotionStorefrontUrlBuilder_testPriceAdjustmentProviderUsesThemeStorefrontUrlFallback(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/extends/module/Weline_Product/StorefrontPriceAdjustmentProvider/PromotionThemeDealPriceAdjustmentProvider.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('$this->themeService->storefrontUrl($pageSlug)', $src);
    self::assertStringNotContainsString("'/promotion/' . rawurlencode(\$pageSlug)", $src);
}

private function assert_PromotionThemeDealDiscountSyncProvider_testSyncServiceConsumesMarketingProviderAndDoesNotTouchRuleModel(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Service/PromotionThemeDealDiscountSyncService.php';
    self::assertFileExists($path);
    $content = (string) file_get_contents($path);
    self::assertStringNotContainsString('Weline\Marketing\Model\Rule\Rule', $content);
    self::assertStringContainsString('ExternalDealDiscountProviderInterface', $content);
    self::assertStringContainsString('RuntimeProviderResolver', $content);
    self::assertStringContainsString('provider->upsert(new ExternalDealDiscountRequest(', $content);
    self::assertStringContainsString("SOURCE_TYPE = 'promotion_activity_theme'", $content);
}

private function assert_PromotionThemeScheduleWindow_testOutsideUtcWindowRejectedByHelper(): void
{
    self::assertFalse(Timezone::isWithinUtcWindow('2099-01-01 00:00:00', '2099-12-31 00:00:00', '2026-01-01 00:00:00'));
    self::assertTrue(Timezone::isWithinUtcWindow('2020-01-01 00:00:00', '2099-12-31 00:00:00', Timezone::utcNowSql()));
}

private function assert_PromotionThemeScheduleWindow_testActiveDealResolverSourceGuardsWindow(): void
{
    $src = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Service/PromotionStorefrontActiveDealResolver.php');
    self::assertIsString($src);
    self::assertStringContainsString('isWithinUtcWindow', $src);
    self::assertStringContainsString('starts_at', $src);
}

private function assert_StorefrontCampaignLabelI18n_testResolveCampaignDisplayLabelTranslatesHanViaUnderscore(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Service/PromotionActivityThemeService.php');
    self::assertStringContainsString('translateStorefrontCampaignLabel', $src);
    self::assertStringContainsString('WidgetI18n::label', $src);
}

private function assert_StorefrontCampaignLabelI18n_testHindiCsvCoversDealsChrome(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/i18n/hi_IN.csv';
    self::assertFileExists($path);
    $hi = (string) file_get_contents($path);
    self::assertStringContainsString('今日特价,"आज के विशेष"', $hi);
    self::assertMatchesRegularExpression('/\p{Devanagari}/u', $hi);
}
}
