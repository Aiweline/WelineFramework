<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Context;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Test\TestCore;
use Weline\I18n\Api\Scope\PhraseScopeSource;
use Weline\I18n\Api\Scope\PhraseScopeValue;
use Weline\I18n\Api\Translation\TranslationResolverInterface;
use Weline\Theme\Helper\EnsurePublishedThemeStaticUrl;
use Weline\Theme\Helper\HeaderNavFragment;
use Weline\Theme\Helper\LegacyMediaUrl;
use Weline\Theme\Helper\PdpRecommendationLazyShell;
use Weline\Theme\Helper\ProductLabelMarkup;
use Weline\Theme\Helper\StorefrontHref;
use Weline\Theme\Helper\WidgetI18n;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\StorefrontHeaderNavFragmentCache;
use Weline\Theme\Service\StorefrontThemeCacheCoordinator;
use Weline\Theme\Service\ThemeDirectoryResolver;
use Weline\Theme\Service\ThemeStaticAssetPublisher;

/**
 * Aggregated contract suite (merged from 12 *ContractTest files).
 * Observe one overall pass/fail via testAllThemeHelperContractsPass().
 */
final class ThemeHelperContractsTest extends TestCase
{
    public function testAllThemeHelperContractsPass(): void
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
            'EnsurePublishedThemeStaticUrl::testEnsurePublishesHanfuBrandFaviconIntoPubStatic' => function () {
            $this->setUp_EnsurePublishedThemeStaticUrl();
            try {
                $this->assert_EnsurePublishedThemeStaticUrl_testEnsurePublishesHanfuBrandFaviconIntoPubStatic();
            } finally {
                $this->tearDown_EnsurePublishedThemeStaticUrl();
            }
        },
            'EnsurePublishedThemeStaticUrl::testEnsurePublishesHanfuApprovedLogoIntoPubStatic' => function () {
            $this->setUp_EnsurePublishedThemeStaticUrl();
            try {
                $this->assert_EnsurePublishedThemeStaticUrl_testEnsurePublishesHanfuApprovedLogoIntoPubStatic();
            } finally {
                $this->tearDown_EnsurePublishedThemeStaticUrl();
            }
        },
            'EnsurePublishedThemeStaticUrl::testEnsureReturnsEmptyWhenPathMissing' => function () {
            $this->setUp_EnsurePublishedThemeStaticUrl();
            try {
                $this->assert_EnsurePublishedThemeStaticUrl_testEnsureReturnsEmptyWhenPathMissing();
            } finally {
                $this->tearDown_EnsurePublishedThemeStaticUrl();
            }
        },
            'HeaderNavLabelPrefetch::testHeaderCommerceDataExposesPrefetchNavItemLabels' => function () {
            $this->assert_HeaderNavLabelPrefetch_testHeaderCommerceDataExposesPrefetchNavItemLabels();
        },
            'HeaderNavLabelPrefetch::testHeaderNavFragmentPrefetchesBeforeHorizontalAndSidebarRender' => function () {
            $this->assert_HeaderNavLabelPrefetch_testHeaderNavFragmentPrefetchesBeforeHorizontalAndSidebarRender();
        },
            'HeaderNavLabelPrefetch::testHeaderBarPrefetchesHotWordsAndSearchTypes' => function () {
            $this->assert_HeaderNavLabelPrefetch_testHeaderBarPrefetchesHotWordsAndSearchTypes();
        },
            'HeaderNavLabelPrefetch::testNavPartialsPrefetchItemsAtEntry' => function () {
            $this->assert_HeaderNavLabelPrefetch_testNavPartialsPrefetchItemsAtEntry();
        },
            'HeaderWaterfallRememberPolicy::testCategoryNavProjectionUsesHeaderNavigationPolicy' => function () {
            $this->assert_HeaderWaterfallRememberPolicy_testCategoryNavProjectionUsesHeaderNavigationPolicy();
        },
            'HeaderWaterfallRememberPolicy::testHorizontalStripUsesSameNavigationPolicyBag' => function () {
            $this->assert_HeaderWaterfallRememberPolicy_testHorizontalStripUsesSameNavigationPolicyBag();
        },
            'HeaderWaterfallRememberPolicy::testHeaderPartialDropsParallelStaticDefaultNavBag' => function () {
            $this->assert_HeaderWaterfallRememberPolicy_testHeaderPartialDropsParallelStaticDefaultNavBag();
        },
            'LegacyMediaUrlWebpFallback::testPreferExistingLocalRasterRewritesMissingJpgToWebp' => function () {
            $this->assert_LegacyMediaUrlWebpFallback_testPreferExistingLocalRasterRewritesMissingJpgToWebp();
        },
            'LegacyMediaUrlWebpFallback::testSanitizeLeavesNonMediaPathsUntouched' => function () {
            $this->assert_LegacyMediaUrlWebpFallback_testSanitizeLeavesNonMediaPathsUntouched();
        },
            'LegacyMediaUrlWebpFallback::testPreferExistingKeepsUrlWhenWebpMissing' => function () {
            $this->assert_LegacyMediaUrlWebpFallback_testPreferExistingKeepsUrlWhenWebpMissing();
        },
            'PdpRecommendationLazyShell::testConfigKeyAndSkeletonBounds' => function () {
            $this->assert_PdpRecommendationLazyShell_testConfigKeyAndSkeletonBounds();
        },
            'PdpRecommendationLazyShell::testPreviewNeverDefers' => function () {
            $this->assert_PdpRecommendationLazyShell_testPreviewNeverDefers();
        },
            'PdpRecommendationLazyShell::testProductLayoutRetainsSlotsAndPdpPhaseMarkers' => function () {
            $this->assert_PdpRecommendationLazyShell_testProductLayoutRetainsSlotsAndPdpPhaseMarkers();
        },
            'PdpRecommendationLazyShell::testYouMayLikeAndRecentlyViewedKeepRequiredAndLazyShellMarkers' => function () {
            $this->assert_PdpRecommendationLazyShell_testYouMayLikeAndRecentlyViewedKeepRequiredAndLazyShellMarkers();
        },
            'PdpRecommendationLazyShell::testHydrateScriptsUseWelineApiNotNativeFetch' => function () {
            $this->assert_PdpRecommendationLazyShell_testHydrateScriptsUseWelineApiNotNativeFetch();
        },
            'ProductLabelMarkup::testRenderBadgesEmitsStableSpansWithoutWidgetUid' => function () {
            $this->setUp_ProductLabelMarkup();
            $this->assert_ProductLabelMarkup_testRenderBadgesEmitsStableSpansWithoutWidgetUid();
        },
            'ProductLabelMarkup::testPhrasesAreRequestMemoized' => function () {
            $this->setUp_ProductLabelMarkup();
            $this->assert_ProductLabelMarkup_testPhrasesAreRequestMemoized();
        },
            'ProductLabelMarkup::testShelfPartialUsesProductLabelMarkup' => function () {
            $this->setUp_ProductLabelMarkup();
            $this->assert_ProductLabelMarkup_testShelfPartialUsesProductLabelMarkup();
        },
            'SearchTypeDropdownFragmentGate::testStableFragmentTemplateOmitsSelectionAndSupportsSlimLabels' => function () {
            $this->assert_SearchTypeDropdownFragmentGate_testStableFragmentTemplateOmitsSelectionAndSupportsSlimLabels();
        },
            'SearchTypeDropdownFragmentGate::testApplySelectionPaintsRequestLocalStateOntoStableHtml' => function () {
            $this->assert_SearchTypeDropdownFragmentGate_testApplySelectionPaintsRequestLocalStateOntoStableHtml();
        },
            'SearchTypeDropdownFragmentGate::testHeaderBarUsesFragmentHelperNotInlineRememberWithSelection' => function () {
            $this->assert_SearchTypeDropdownFragmentGate_testHeaderBarUsesFragmentHelperNotInlineRememberWithSelection();
        },
            'SearchTypeDropdownFragmentGate::testLanguageSwitcherNotTripledInDefaultHeader' => function () {
            $this->assert_SearchTypeDropdownFragmentGate_testLanguageSwitcherNotTripledInDefaultHeader();
        },
            'SiteBrandNonDefaultHost::testRequestLooksLikeNonDefaultStorefrontHostDoesNotTreatZeroIdAsMissing' => function () {
            $this->assert_SiteBrandNonDefaultHost_testRequestLooksLikeNonDefaultStorefrontHostDoesNotTreatZeroIdAsMissing();
        },
            'StorefrontHref::testPathRelativeLinksStayForDocumentBase' => function () {
            $this->assert_StorefrontHref_testPathRelativeLinksStayForDocumentBase();
        },
            'StorefrontHref::testFragmentHrefUsesFallbackPathWhenRequestPathMissing' => function () {
            $this->assert_StorefrontHref_testFragmentHrefUsesFallbackPathWhenRequestPathMissing();
        },
            'StorefrontHref::testFragmentHrefSourceCallsLocalizeBeforeAppend' => function () {
            $this->assert_StorefrontHref_testFragmentHrefSourceCallsLocalizeBeforeAppend();
        },
            'StorefrontHref::testExternalAbsoluteUrlsPassThrough' => function () {
            $this->assert_StorefrontHref_testExternalAbsoluteUrlsPassThrough();
        },
            'StorefrontHref::testAdBannerAndPromoUseSiteBlockLink' => function () {
            $this->assert_StorefrontHref_testAdBannerAndPromoUseSiteBlockLink();
        },
            'ThemeDataMetaListHotCache::testGetMetaListUsesStorefrontScopeHotCache' => function () {
            $this->assert_ThemeDataMetaListHotCache_testGetMetaListUsesStorefrontScopeHotCache();
        },
            'WidgetI18nHtml::testHtmlMethodExistsWithSegmentStrategy' => function () {
            $this->setUp_WidgetI18nHtml();
            try {
                $this->assert_WidgetI18nHtml_testHtmlMethodExistsWithSegmentStrategy();
            } finally {
                $this->tearDown_WidgetI18nHtml();
            }
        },
            'WidgetI18nHtml::testHtmlTranslatesChineseTextNodesAndKeepsMarkup' => function () {
            $this->setUp_WidgetI18nHtml();
            try {
                $this->assert_WidgetI18nHtml_testHtmlTranslatesChineseTextNodesAndKeepsMarkup();
            } finally {
                $this->tearDown_WidgetI18nHtml();
            }
        },
            'WidgetI18nWebsiteDefaultLocaleFallback::testResolveStorefrontLocaleFallsBackToWebsiteDefaultLanguage' => function () {
            $this->assert_WidgetI18nWebsiteDefaultLocaleFallback_testResolveStorefrontLocaleFallsBackToWebsiteDefaultLanguage();
        },
        ];
    }

private const FIXTURE_THEME_ID_EnsurePublishedThemeStaticUrl = 990874;

private const SOURCE_FAVICON_EnsurePublishedThemeStaticUrl = 'app/design/Weline/hanfu/frontend/assets/images/component-kit/brand/favicon-32.png';

private const SOURCE_LOGO_EnsurePublishedThemeStaticUrl = 'app/design/Weline/hanfu/frontend/assets/images/component-kit/brand/approved-logo-reference.png';

private const PUBLISHED_FAVICON_EnsurePublishedThemeStaticUrl = 'Weline/hanfu/Weline/Theme/view/theme/frontend/assets/images/component-kit/brand/favicon-32.png';

private const PUBLISHED_LOGO_EnsurePublishedThemeStaticUrl = 'Weline/hanfu/Weline/Theme/view/theme/frontend/assets/images/component-kit/brand/approved-logo-reference.png';

private ThemeStaticAssetPublisher $publisher_EnsurePublishedThemeStaticUrl;

private function setUp_EnsurePublishedThemeStaticUrl(): void
{
    parent::setUp();
    /** @var Request $request */
    $request = ObjectManager::getInstance(Request::class);
    $request->setServer('REQUEST_URI', '/test');
    $request->setGet('frontend_theme_id', 0);
    $request->setGet('backend_theme_id', 0);
    $request->setGet('editor_area', '');
    $request->setGet('shell', '');
    $request->setGet('preview_mode', '');
    $request->setGet('status', '');
    $request->setGet(PreviewTokenService::TOKEN_KEY, '');
    PreviewTokenService::resetRequestState();
    ObjectManager::getInstance(ThemeDirectoryResolver::class)->clearCache();
    $this->publisher_EnsurePublishedThemeStaticUrl = ObjectManager::getInstance(ThemeStaticAssetPublisher::class);
}

private function tearDown_EnsurePublishedThemeStaticUrl(): void
{
    $this->removePublished_EnsurePublishedThemeStaticUrl(self::PUBLISHED_FAVICON_EnsurePublishedThemeStaticUrl);
    $this->removePublished_EnsurePublishedThemeStaticUrl(self::PUBLISHED_LOGO_EnsurePublishedThemeStaticUrl);
    parent::tearDown();
}

private function assert_EnsurePublishedThemeStaticUrl_testEnsurePublishesHanfuBrandFaviconIntoPubStatic(): void
{
    $base = rtrim((string) BP, '\/') . DIRECTORY_SEPARATOR;
    $source = $base . str_replace('/', DIRECTORY_SEPARATOR, self::SOURCE_FAVICON_EnsurePublishedThemeStaticUrl);
    if (!is_file($source)) {
        self::markTestSkipped('hanfu design brand favicon missing: ' . self::SOURCE_FAVICON_EnsurePublishedThemeStaticUrl);
    }
    $this->removePublished_EnsurePublishedThemeStaticUrl(self::PUBLISHED_FAVICON_EnsurePublishedThemeStaticUrl);
    $theme = $this->buildHanfuTheme_EnsurePublishedThemeStaticUrl();
    $requestPath = '/static/Weline/hanfu/Weline/Theme/view/theme/frontend/assets/images/component-kit/brand/favicon-32.png';
    $published = $this->publisher_EnsurePublishedThemeStaticUrl->publishForRequestPath($requestPath, $theme);
    self::assertSame('/pub/static/' . self::PUBLISHED_FAVICON_EnsurePublishedThemeStaticUrl, $published);
    $url = $requestPath . '?v=contract';
    self::assertSame($url, EnsurePublishedThemeStaticUrl::ensure($url));
    $disk = $base . 'pub' . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::PUBLISHED_FAVICON_EnsurePublishedThemeStaticUrl);
    self::assertFileExists($disk);
    self::assertSame(file_get_contents($source), file_get_contents($disk));
}

private function assert_EnsurePublishedThemeStaticUrl_testEnsurePublishesHanfuApprovedLogoIntoPubStatic(): void
{
    $base = rtrim((string) BP, '\/') . DIRECTORY_SEPARATOR;
    $source = $base . str_replace('/', DIRECTORY_SEPARATOR, self::SOURCE_LOGO_EnsurePublishedThemeStaticUrl);
    if (!is_file($source)) {
        self::markTestSkipped('hanfu design approved logo missing: ' . self::SOURCE_LOGO_EnsurePublishedThemeStaticUrl);
    }
    $this->removePublished_EnsurePublishedThemeStaticUrl(self::PUBLISHED_LOGO_EnsurePublishedThemeStaticUrl);
    $theme = $this->buildHanfuTheme_EnsurePublishedThemeStaticUrl();
    $requestPath = '/static/Weline/hanfu/Weline/Theme/view/theme/frontend/assets/images/component-kit/brand/approved-logo-reference.png';
    $published = $this->publisher_EnsurePublishedThemeStaticUrl->publishForRequestPath($requestPath, $theme);
    self::assertSame('/pub/static/' . self::PUBLISHED_LOGO_EnsurePublishedThemeStaticUrl, $published);
    self::assertSame($requestPath, EnsurePublishedThemeStaticUrl::ensure($requestPath));
    $disk = $base . 'pub' . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::PUBLISHED_LOGO_EnsurePublishedThemeStaticUrl);
    self::assertFileExists($disk);
    self::assertSame(file_get_contents($source), file_get_contents($disk));
}

private function assert_EnsurePublishedThemeStaticUrl_testEnsureReturnsEmptyWhenPathMissing(): void
{
    $missing = '/static/Weline/hanfu/Weline/Theme/view/theme/frontend/assets/images/component-kit/brand/__missing-favicon__.png';
    self::assertSame('', EnsurePublishedThemeStaticUrl::ensure($missing));
}

private function buildHanfuTheme_EnsurePublishedThemeStaticUrl(): WelineTheme
{
    /** @var WelineTheme $theme */
    $theme = clone ObjectManager::getInstance(WelineTheme::class);
    $theme->clearData()->clearQuery();
    $theme->setData(WelineTheme::schema_fields_ID, self::FIXTURE_THEME_ID_EnsurePublishedThemeStaticUrl);
    $theme->setData(WelineTheme::schema_fields_NAME, 'hanfu');
    $theme->setData(WelineTheme::schema_fields_PATH, 'Weline/hanfu');
    $hotCache = ObjectManager::getInstance(StorefrontScopeHotCache::class);
    foreach (['frontend', 'backend'] as $fixtureArea) {
        $hotCache->forgetPolicy(StorefrontThemeCacheCoordinator::themeAreaDirectoriesPolicy(), self::FIXTURE_THEME_ID_EnsurePublishedThemeStaticUrl . '|' . $fixtureArea);
    }
    return $theme;
}

private function removePublished_EnsurePublishedThemeStaticUrl(string $relativeUnderPubStatic): void
{
    $staticRoot = realpath(rtrim((string) BP, '\/') . DIRECTORY_SEPARATOR . 'pub' . DIRECTORY_SEPARATOR . 'static');
    if ($staticRoot === false) {
        return;
    }
    $resolved = $staticRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeUnderPubStatic);
    if (is_file($resolved)) {
        @unlink($resolved);
    }
    $dir = dirname($resolved);
    while (str_starts_with($dir, $staticRoot . DIRECTORY_SEPARATOR)) {
        if (!@rmdir($dir)) {
            break;
        }
        $dir = dirname($dir);
    }
}

private function assert_HeaderNavLabelPrefetch_testHeaderCommerceDataExposesPrefetchNavItemLabels(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Helper/HeaderCommerceData.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('function prefetchNavItemLabels', $src);
    self::assertStringContainsString('WidgetI18n::prefetchLabels($sources)', $src);
}

private function assert_HeaderNavLabelPrefetch_testHeaderNavFragmentPrefetchesBeforeHorizontalAndSidebarRender(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Helper/HeaderNavFragment.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('HeaderCommerceData::prefetchNavItemLabels($items)', $src);
    $h = strpos($src, 'function fetchCategoriesHorizontalNav');
    $s = strpos($src, 'function fetchCategoriesSidebarNav');
    $prefH = strpos($src, 'HeaderCommerceData::prefetchNavItemLabels($items)', $h ?: 0);
    $prefS = strpos($src, 'HeaderCommerceData::prefetchNavItemLabels($items)', $s ?: 0);
    self::assertNotFalse($h);
    self::assertNotFalse($s);
    self::assertNotFalse($prefH);
    self::assertNotFalse($prefS);
    self::assertGreaterThan($h, $prefH);
    self::assertGreaterThan($s, $prefS);
}

private function assert_HeaderNavLabelPrefetch_testHeaderBarPrefetchesHotWordsAndSearchTypes(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/view/theme/frontend/partials/search/header-bar.phtml';
    $src = (string) file_get_contents($path);
    $prefetch = strpos($src, 'WidgetI18n::prefetchLabels($hotLabelSources)');
    $typesPrefetch = strpos($src, 'HeaderCommerceData::prefetchSearchTypeLabels($searchTypes)');
    $hotLoop = strpos($src, 'foreach ($hotWords as $word)');
    self::assertNotFalse($prefetch);
    self::assertNotFalse($typesPrefetch);
    self::assertNotFalse($hotLoop);
    self::assertLessThan($hotLoop, $prefetch);
}

private function assert_HeaderNavLabelPrefetch_testNavPartialsPrefetchItemsAtEntry(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/view/theme/frontend/partials/header';
    foreach (['categories-horizontal-nav.phtml', 'categories-sidebar-nav.phtml'] as $file) {
        $src = (string) file_get_contents($base . '/' . $file);
        self::assertStringContainsString('HeaderCommerceData::prefetchNavItemLabels($items)', $src, $file);
    }
}

private function assert_HeaderWaterfallRememberPolicy_testCategoryNavProjectionUsesHeaderNavigationPolicy(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Helper/HeaderCommerceData.php');
    self::assertStringContainsString('headerNavigationPolicy()', $src);
    self::assertStringContainsString('theme.header.category_nav.v2.', $src);
    self::assertStringContainsString('rememberPolicy', $src);
    self::assertStringContainsString('resolveCategoryNavItemsUncached', $src);
    self::assertStringContainsString('requestOriginSegment', $src);
    $policy = StorefrontThemeCacheCoordinator::headerNavigationPolicy();
    self::assertSame('theme.header_navigation', $policy->resource);
    self::assertSame(StorefrontHeaderNavFragmentCache::cachePool(), $policy->pool);
    self::assertSame('channel', $policy->scope);
    self::assertContains('catalog', $policy->dependencies);
    self::assertContains('theme', $policy->dependencies);
}

private function assert_HeaderWaterfallRememberPolicy_testHorizontalStripUsesSameNavigationPolicyBag(): void
{
    $cacheSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/StorefrontHeaderNavFragmentCache.php');
    $helperSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Helper/HeaderNavFragment.php');
    $widgetSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/view/theme/frontend/widgets/navigation/category-menu/default.phtml');
    self::assertStringContainsString('rememberCategoriesHorizontalNav', $cacheSrc);
    self::assertStringContainsString('prefetchCategoryNavFragments', $cacheSrc);
    self::assertStringContainsString('horizontalNavLogicalKey', $cacheSrc);
    self::assertStringContainsString('theme.header.horizontal_nav.v2.', $cacheSrc);
    self::assertStringContainsString('prefetchCategoryNavFragments', $helperSrc);
    self::assertStringContainsString('fetchCategoriesHorizontalNav', $helperSrc);
    self::assertStringContainsString('fetchCategoriesHorizontalNav', $widgetSrc);
    self::assertStringNotContainsString("fetch('Weline_Theme::theme/frontend/partials/header/categories-horizontal-nav.phtml'", $widgetSrc);
}

private function assert_HeaderWaterfallRememberPolicy_testHeaderPartialDropsParallelStaticDefaultNavBag(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/view/theme/frontend/partials/header/default.phtml');
    self::assertStringContainsString('HeaderDefaultNavItems', $src);
    self::assertStringNotContainsString('static $defaultNavItems', $src);
}

private function assert_LegacyMediaUrlWebpFallback_testPreferExistingLocalRasterRewritesMissingJpgToWebp(): void
{
    $webpRel = 'catalog/hanfu/1688/factory-huazhaoji-cx/824286376488/detail-03-c2e91b039ebb.webp';
    $webpAbs = rtrim((string) \PUB, '/\\') . '/media/' . $webpRel;
    self::assertFileExists($webpAbs, 'fixture webp from buyer-show catalog must exist');
    $out = LegacyMediaUrl::preferExistingLocalRaster('/media/catalog/hanfu/1688/factory-huazhaoji-cx/824286376488/detail-03-c2e91b039ebb.jpg');
    self::assertSame('/media/catalog/hanfu/1688/factory-huazhaoji-cx/824286376488/detail-03-c2e91b039ebb.webp', $out);
}

private function assert_LegacyMediaUrlWebpFallback_testSanitizeLeavesNonMediaPathsUntouched(): void
{
    $url = '/static/Weline/hanfu/demo.png';
    self::assertSame($url, LegacyMediaUrl::sanitize($url));
}

private function assert_LegacyMediaUrlWebpFallback_testPreferExistingKeepsUrlWhenWebpMissing(): void
{
    $url = '/media/catalog/hanfu/__no_such_dir__/missing-look.jpg';
    self::assertSame($url, LegacyMediaUrl::preferExistingLocalRaster($url));
}

private function assert_PdpRecommendationLazyShell_testConfigKeyAndSkeletonBounds(): void
{
    self::assertSame('lazy_load', PdpRecommendationLazyShell::CONFIG_KEY);
    self::assertSame(1, PdpRecommendationLazyShell::skeletonCount(0, 8));
    self::assertSame(8, PdpRecommendationLazyShell::skeletonCount(24, 8));
    self::assertSame(4, PdpRecommendationLazyShell::skeletonCount(4, 8));
}

private function assert_PdpRecommendationLazyShell_testPreviewNeverDefers(): void
{
    self::assertFalse(PdpRecommendationLazyShell::shouldDeferCards(['preview_mode' => true, 'lazy_load' => true]));
    self::assertFalse(PdpRecommendationLazyShell::shouldDeferCards(['editor_mode' => true, 'lazy_load' => true]));
}

private function assert_PdpRecommendationLazyShell_testProductLayoutRetainsSlotsAndPdpPhaseMarkers(): void
{
    $layout = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Product/view/theme/frontend/layouts/product/default.phtml';
    self::assertFileExists($layout);
    $source = (string) file_get_contents($layout);
    self::assertStringContainsString('id="product-you-may-like"', $source);
    self::assertStringContainsString('id="product-recently-viewed"', $source);
    self::assertStringContainsString('id="product-cross-sell"', $source);
    self::assertStringContainsString('data-pdp-budget-phase="pdp.main"', $source);
    self::assertStringContainsString('data-pdp-budget-phase="pdp.related_stack"', $source);
    self::assertStringContainsString('data-pdp-budget-phase="pdp.personalization"', $source);
    // Same-module Product layout may embed you-may-like; cross-module RecentlyViewed stays hook/injection.
    self::assertMatchesRegularExpression('/<w:widget[^>]*name="you-may-like"/i', $source);
    self::assertDoesNotMatchRegularExpression('/<w:widget[^>]*name="recently-viewed"/i', $source);
}

private function assert_PdpRecommendationLazyShell_testYouMayLikeAndRecentlyViewedKeepRequiredAndLazyShellMarkers(): void
{
    $yml = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Product/view/templates/frontend/widgets/you-may-like.phtml';
    $rv = '/Users/weline/Project/Official/框架/app/code/Weline' . '/RecentlyViewed/view/templates/frontend/widgets/recently-viewed.phtml';
    self::assertFileExists($yml);
    self::assertFileExists($rv);
    $ymlSource = (string) file_get_contents($yml);
    self::assertStringContainsString('data-pdp-lazy-shell="1"', $ymlSource);
    self::assertStringContainsString('data-weline-hydrate="1"', $ymlSource);
    self::assertStringContainsString('data-hydrate-operation="youMayLikeCards"', $ymlSource);
    self::assertStringContainsString('w-skeleton', $ymlSource);
    self::assertStringContainsString('data-size="card"', $ymlSource);
    self::assertStringNotContainsString('fetch(', $ymlSource);
    $rvSource = (string) file_get_contents($rv);
    self::assertStringContainsString('data-pdp-lazy-shell="1"', $rvSource);
    self::assertStringContainsString('data-hydrate-operation="recentlyViewedCards"', $rvSource);
    self::assertStringContainsString('w-skeleton', $rvSource);
    self::assertStringContainsString('data-size="card"', $rvSource);
    self::assertStringNotContainsString('fetch(', $rvSource);
}

private function assert_PdpRecommendationLazyShell_testHydrateScriptsUseWelineApiNotNativeFetch(): void
{
    $ymlJs = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Product/view/statics/js/widgets/you-may-like.js';
    $rvJs = '/Users/weline/Project/Official/框架/app/code/Weline' . '/RecentlyViewed/view/statics/js/widgets/recently-viewed.js';
    self::assertFileExists($ymlJs);
    self::assertFileExists($rvJs);
    $yml = (string) file_get_contents($ymlJs);
    self::assertStringContainsString('Weline.Api', $yml);
    self::assertStringContainsString('data-weline-hydrate', $yml);
    self::assertStringContainsString('scheduleHydrate', $yml);
    self::assertStringContainsString('isTransientHydrateError', $yml);
    self::assertStringContainsString('data-pdp-hydrating', $yml);
    self::assertStringContainsString('maxAttempts', $yml);
    self::assertStringNotContainsString('XMLHttpRequest', $yml);
    self::assertDoesNotMatchRegularExpression('/\bfetch\s*\(/', $yml);
    $rv = (string) file_get_contents($rvJs);
    self::assertStringContainsString('Weline.Api', $rv);
    self::assertStringContainsString('recentlyViewedCards', $rv);
    self::assertStringContainsString('scheduleHydrate', $rv);
    self::assertStringContainsString('isTransientHydrateError', $rv);
    self::assertStringContainsString('data-pdp-hydrating', $rv);
    self::assertDoesNotMatchRegularExpression('/\bfetch\s*\(/', $rv);
    $revealJs = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Product/view/statics/js/widgets/product-detail-reveal.js';
    self::assertFileExists($revealJs);
    $reveal = (string) file_get_contents($revealJs);
    self::assertStringContainsString('observeDescImages', $reveal);
    self::assertStringContainsString('data-pdp-desc-lazy', $reveal);
    self::assertStringContainsString('hydrateDescImage', $reveal);
}

private function setUp_ProductLabelMarkup(): void
{
    parent::setUp();
    RequestContext::init();
}

private function assert_ProductLabelMarkup_testRenderBadgesEmitsStableSpansWithoutWidgetUid(): void
{
    $html = ProductLabelMarkup::renderBadges(['is_new' => true, 'is_sale' => true, 'sale_text' => '-20%'], 'wpc-badges');
    self::assertStringContainsString('class="w-product-labels wpc-badges"', $html);
    self::assertStringContainsString('data-kind="new"', $html);
    self::assertStringContainsString('data-kind="sale"', $html);
    self::assertStringContainsString('-20%', $html);
    self::assertStringNotContainsString('data-uid=', $html);
    self::assertStringNotContainsString('wc-theme_component_product_label', $html);
}

private function assert_ProductLabelMarkup_testPhrasesAreRequestMemoized(): void
{
    $a = ProductLabelMarkup::phrases();
    $b = ProductLabelMarkup::phrases();
    self::assertSame($a, $b);
    self::assertArrayHasKey('hot', $a);
    self::assertArrayHasKey('ship', $a);
}

private function assert_ProductLabelMarkup_testShelfPartialUsesProductLabelMarkup(): void
{
    // Theme/test/Unit/Helper → ../../../../Product/...
    $productShelf = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Product/view/templates/frontend/partials/product-card-shelf.phtml';
    $productCard = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Product/view/templates/frontend/partials/product-card.phtml';
    $grid = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/theme/frontend/partials/product/grid.phtml';
    self::assertFileExists($productShelf);
    $src = (string) file_get_contents($productShelf);
    self::assertStringContainsString('ProductLabelMarkup::renderBadges', $src);
    self::assertStringContainsString('ProductCardShopperActionMarkup::render', $src);
    self::assertStringContainsString('ProductCardAddToCartParams::renderSlot', $src);
    $addToCartHelper = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/Helper/ProductCardAddToCartParams.php');
    self::assertStringContainsString('FiberOutputBuffer::hasActiveCapture()', $addToCartHelper);
    self::assertStringContainsString('RenderContext::REQUIRE_STRING_HOOK', $addToCartHelper);
    self::assertStringNotContainsString("fetch('Weline_Theme::theme/frontend/partials/product/labels.phtml'", $src);
    self::assertStringNotContainsString("fetch('Weline_Theme::theme/frontend/partials/product/shopper-actions.phtml'", $src);
    self::assertStringNotContainsString("fetch('Weline_Theme::theme/frontend/partials/product/add-to-cart.phtml'", $src);
    self::assertStringContainsString('currency_symbol', $src);

    $cardSrc = (string) file_get_contents($productCard);
    self::assertStringContainsString('ProductLabelMarkup::renderBadges', $cardSrc);
    self::assertStringContainsString('ProductCardShopperActionMarkup::render', $cardSrc);
    self::assertStringContainsString('ProductCardAddToCartParams::renderSlot', $cardSrc);
    self::assertStringNotContainsString("fetch('Weline_Theme::theme/frontend/partials/product/", $cardSrc);

    $gridSrc = (string) file_get_contents($grid);
    self::assertStringContainsString('ProductLabelMarkup::renderBadges', $gridSrc);
    self::assertStringContainsString('ProductCardShopperActionMarkup::render', $gridSrc);
    self::assertStringContainsString('ProductCardAddToCartParams::renderSlot', $gridSrc);
    self::assertStringNotContainsString("fetch('Weline_Theme::theme/frontend/partials/product/", $gridSrc);
}

private function assert_SearchTypeDropdownFragmentGate_testStableFragmentTemplateOmitsSelectionAndSupportsSlimLabels(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/view/theme/frontend/partials/search/type-dropdown.phtml';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('stable_fragment', $src);
    self::assertStringContainsString('data-search-tree-stable', $src);
    self::assertStringContainsString('data-display-label', $src);
    // Slim: only emit data-display-label when different from data-label.
    self::assertStringContainsString('displayLabel !== $label', $src);
    // P11 sprite retained — do not re-split SVG.
    self::assertStringContainsString('localSprite', $src);
    self::assertStringContainsString('renderUse', $src);
    self::assertStringNotContainsString('<select', $src);
}

private function assert_SearchTypeDropdownFragmentGate_testApplySelectionPaintsRequestLocalStateOntoStableHtml(): void
{
    $stable = <<<'HTML'
    <svg class="w-icon-local-sprite"></svg>
    <div class="search-type-dropdown" data-search-tree-stable="1">
    <input type="hidden" name="type" value="all" class="search-type-input">
    <input type="hidden" name="category_id" value="" class="search-category-id-input">
    <button type="button" class="search-type-trigger"><span class="search-type-label search-category-label">全部</span></button>
    <div class="w-menu" data-w-menu-panel>
    <button type="button" class="w-menu__item search-type-option" role="option" data-search-type-option data-value="all" data-label="全部" data-category-id="" aria-selected="false"><span class="search-type-option-label">全部</span></button>
    <div class="search-type-node has-children" data-search-type-node data-value="product" data-state="closed">
    <button type="button" class="w-menu__item search-type-option--branch" data-search-type-branch data-value="product" data-label="商品" aria-haspopup="true" aria-expanded="false"><span class="search-type-option-label">商品</span></button>
    <div class="search-type-submenu search-type-menu" data-search-type-submenu role="group" hidden>
    <button type="button" class="w-menu__item search-type-option" role="option" data-search-type-option data-value="product" data-label="全部商品" data-display-label="商品" data-category-id="" aria-selected="false"><span class="search-type-option-label">全部商品</span></button>
    <button type="button" class="w-menu__item search-type-option" role="option" data-search-type-option data-value="product" data-label="健身" data-display-label="商品:健身" data-category-id="42" aria-selected="false"><span class="search-type-option-label">健身</span></button>
    </div>
    </div>
    </div>
    </div>
    HTML;
    $painted = HeaderNavFragment::applySearchTypeDropdownSelection($stable, 'product', 42);
    self::assertStringContainsString('name="type" value="product"', $painted);
    self::assertStringContainsString('name="category_id" value="42"', $painted);
    self::assertStringContainsString('search-type-label search-category-label">商品:健身</span>', $painted);
    self::assertStringContainsString('data-category-id="42"', $painted);
    self::assertStringContainsString('is-active', $painted);
    self::assertStringContainsString('aria-selected="true"', $painted);
    self::assertStringContainsString('data-state="open"', $painted);
    // Stable bag itself must not bake selection — apply happens after.
    self::assertStringContainsString('stable_fragment', (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Helper/HeaderNavFragment.php'));
}

private function assert_SearchTypeDropdownFragmentGate_testHeaderBarUsesFragmentHelperNotInlineRememberWithSelection(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/view/theme/frontend/partials/search/header-bar.phtml');
    self::assertStringContainsString('HeaderNavFragment::fetchSearchTypeDropdown', $src);
    self::assertStringNotContainsString('rememberSearchTypeDropdown(', $src);
}

private function assert_SearchTypeDropdownFragmentGate_testLanguageSwitcherNotTripledInDefaultHeader(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/view/theme/frontend/partials/header/default.phtml');
    $count = \substr_count($src, 'header-language-switcher');
    // One hook slot (+ class / accept list) — must not restore three full language lists.
    self::assertLessThanOrEqual(3, $count);
    self::assertStringNotContainsString('<w:language', $src);
}

private function assert_SiteBrandNonDefaultHost_testRequestLooksLikeNonDefaultStorefrontHostDoesNotTreatZeroIdAsMissing(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Helper/SiteBrand.php');
    self::assertStringContainsString('requestLooksLikeNonDefaultStorefrontHost', $src);
    self::assertStringContainsString('Website::ID_DEFAULT is 0', $src);
    self::assertStringContainsString('instanceof \Weline\Websites\Model\Website', $src);
    self::assertStringNotContainsString('!(int)$default->getId()', $src);
    self::assertStringContainsString('shouldUseBackendBrandIdentity', $src);
    self::assertStringContainsString('requestLooksLikeNonDefaultStorefrontHost()', $src);
}

private function assert_StorefrontHref_testPathRelativeLinksStayForDocumentBase(): void
{
    self::assertSame('promotion/deals', StorefrontHref::localize('promotion/deals'));
    self::assertSame('about/us', StorefrontHref::localize('about/us'));
}

private function assert_StorefrontHref_testFragmentHrefUsesFallbackPathWhenRequestPathMissing(): void
{
    // localize() may no-op in CLI (no frontend URL builder); path+#id shape must remain.
    $href = StorefrontHref::fragmentHref('shipping-s5', '/policy/shipping');
    self::assertStringEndsWith('#shipping-s5', $href);
    self::assertStringContainsString('policy/shipping', $href);
    $href2 = StorefrontHref::fragmentHref('#shipping-s1', '/policy/shipping');
    self::assertStringEndsWith('#shipping-s1', $href2);
    self::assertStringContainsString('policy/shipping', $href2);
    self::assertSame('#', StorefrontHref::fragmentHref('', '/policy/shipping'));
}

private function assert_StorefrontHref_testFragmentHrefSourceCallsLocalizeBeforeAppend(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Helper/StorefrontHref.php');
    self::assertStringContainsString('self::localize(self::currentDocumentPath($fallbackPath))', $src);
}

private function assert_StorefrontHref_testExternalAbsoluteUrlsPassThrough(): void
{
    self::assertSame('https://example.com/promotion/deals', StorefrontHref::localize('https://example.com/promotion/deals'));
}

private function assert_StorefrontHref_testAdBannerAndPromoUseSiteBlockLink(): void
{
    $ad = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/view/theme/frontend/widgets/banner/ad-banner/default.phtml';
    $promo = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/view/theme/frontend/widgets/banner/promo-banner/default.phtml';
    self::assertFileExists($ad);
    self::assertFileExists($promo);
    $adSrc = (string) file_get_contents($ad);
    $promoSrc = (string) file_get_contents($promo);
    self::assertStringContainsString('SiteBlockConfig::link', $adSrc);
    self::assertStringContainsString('SiteBlockConfig::link', $promoSrc);
    self::assertStringNotContainsString("LegacyMediaUrl::sanitize(\$this->getData('link'", $adSrc);
    self::assertStringNotContainsString("LegacyMediaUrl::sanitize(\$this->getData('link'", $promoSrc);
}

private function assert_ThemeDataMetaListHotCache_testGetMetaListUsesStorefrontScopeHotCache(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Helper/ThemeData.php');
    self::assertStringContainsString("resource: 'theme.meta_list'", $src);
    self::assertStringContainsString("pool: 'theme'", $src);
    self::assertStringContainsString('global/storefront/theme', $src);
    self::assertStringContainsString('rememberForRequest', $src);
    self::assertStringContainsString('rememberPolicy', $src);
    self::assertMatchesRegularExpression('/function getMetaList[\s\S]{0,1200}StorefrontScopeHotCache/', $src);
}

private ?object $originalResolver_WidgetI18nHtml = null;

private WidgetI18nHtmlMapResolver $resolver_WidgetI18nHtml;

private function setUp_WidgetI18nHtml(): void
{
    parent::setUp();
    if (Context::hasCurrent()) {
        Context::leave();
    }
    Context::enter(new Context());
    RequestContext::init();
    RequestContext::setWelineUserLang('en_US');
    $instances = ObjectManager::getInstances();
    $this->originalResolver_WidgetI18nHtml = $instances[TranslationResolverInterface::class] ?? null;
    $this->resolver_WidgetI18nHtml = new WidgetI18nHtmlMapResolver(['1.1 本网站不对因以下原因导致的服务中断或数据丢失承担责任：' => '1.1 This website is not responsible for service interruption or data loss caused by the following reasons:', '（1）不可抗力因素，如自然灾害、战争、罢工、政府行为等；' => '(1) Force majeure such as natural disasters, war, strikes, or government actions;']);
    ObjectManager::setInstance(TranslationResolverInterface::class, $this->resolver_WidgetI18nHtml);
}

private function tearDown_WidgetI18nHtml(): void
{
    ObjectManager::removeInstance(TranslationResolverInterface::class);
    if ($this->originalResolver_WidgetI18nHtml !== null) {
        ObjectManager::setInstance(TranslationResolverInterface::class, $this->originalResolver_WidgetI18nHtml);
    }
    RequestContext::cleanup();
    if (Context::hasCurrent()) {
        Context::leave();
    }
    parent::tearDown();
}

private function assert_WidgetI18nHtml_testHtmlMethodExistsWithSegmentStrategy(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Helper/WidgetI18n.php');
    self::assertStringContainsString('public static function html(', $src);
    self::assertStringContainsString('preg_replace_callback', $src);
    self::assertStringContainsString('\p{Han}', $src);
}

private function assert_WidgetI18nHtml_testHtmlTranslatesChineseTextNodesAndKeepsMarkup(): void
{
    $html = '<p>1.1 本网站不对因以下原因导致的服务中断或数据丢失承担责任：</p>' . '<ul><li>（1）不可抗力因素，如自然灾害、战争、罢工、政府行为等；</li></ul>';
    $out = WidgetI18n::html($html);
    self::assertStringContainsString('<p>', $out);
    self::assertStringContainsString('<li>', $out);
    self::assertStringContainsString('1.1 This website is not responsible for service interruption or data loss caused by the following reasons:', $out);
    self::assertStringContainsString('(1) Force majeure such as natural disasters, war, strikes, or government actions;', $out);
    self::assertStringNotContainsString('本网站不对因以下原因', $out);
}

private function assert_WidgetI18nWebsiteDefaultLocaleFallback_testResolveStorefrontLocaleFallsBackToWebsiteDefaultLanguage(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Helper/WidgetI18n.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('State::resolveWebsiteDefaultLanguage()', $source);
    self::assertStringContainsString('无 path / RequestContext 时回落到网站默认语', $source);
    // 仍保留最终安全底，但必须先尝试网站默认语
    self::assertLessThan(strpos($source, "return 'zh_Hans_CN';"), strrpos($source, 'resolveWebsiteDefaultLanguage'), 'website default lookup must precede hard-coded zh_Hans_CN fallback');
}
}

final class WidgetI18nHtmlMapResolver implements TranslationResolverInterface
{
    /** @param array<string, string> $map */
    public function __construct(private array $map)
    {
    }
    public function translate(string $source, string $localeCode, array $preferredModules = []): string
    {
        return $this->map[$source] ?? $source;
    }
    public function reset(): void
    {
    }
    public function translateForScope(string $source, ScopeIdentity $identity, string $localeCode, array $preferredModules = [], ?array $localeFallbackChain = null): PhraseScopeValue
    {
        $text = $this->translate($source, $localeCode, $preferredModules);
        return new PhraseScopeValue(text: $text, source: PhraseScopeSource::fromDefault($localeCode), requestedScope: $identity, requestedLocale: $localeCode, fallbackStorageScopes: [], localeFallbackChain: $localeFallbackChain ?? []);
    }
}
