<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use ReflectionClass as ReflectionClass;
use ReflectionMethod as ReflectionMethod;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Url;
use Weline\Theme\Observer\NormalizeVisitorUriLivePreviewPath;
use Weline\Theme\Observer\WorkerBootstrapWarmup;
use Weline\Theme\Service\ThemeLivePreviewPathMount;

/**
 * Aggregated contract suite (merged from 4 *ContractTest files).
 * Observe one overall pass/fail via testAllThemeObserverContractsPass().
 */
final class ThemeObserverContractsTest extends TestCase
{
    public function testAllThemeObserverContractsPass(): void
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
            'HomepageShellSkipTemplateFetch::testFetchFileBeforeArmsSkipForHomepageLayoutType' => function () {
            $this->assert_HomepageShellSkipTemplateFetch_testFetchFileBeforeArmsSkipForHomepageLayoutType();
        },
            'HomepageShellSkipTemplateFetch::testFetchFileAfterSkipsControllerContentOnHomepageLayout' => function () {
            $this->assert_HomepageShellSkipTemplateFetch_testFetchFileAfterSkipsControllerContentOnHomepageLayout();
        },
            'HomepageShellSkipTemplateFetch::testPcControllerHonorsSkipTemplateFetch' => function () {
            $this->assert_HomepageShellSkipTemplateFetch_testPcControllerHonorsSkipTemplateFetch();
        },
            'HomepageShellSkipTemplateFetch::testHomepageShellTemplateRemovedFromIndexModule' => function () {
            $this->assert_HomepageShellSkipTemplateFetch_testHomepageShellTemplateRemovedFromIndexModule();
        },
            'HomepageShellSkipTemplateFetch::testDetailShellTemplateRemovedFromProductModule' => function () {
            $this->assert_HomepageShellSkipTemplateFetch_testDetailShellTemplateRemovedFromProductModule();
        },
            'HomepageShellSkipTemplateFetch::testHomepageBagFinalizerAndPreFinalizedSkipWired' => function () {
            $this->assert_HomepageShellSkipTemplateFetch_testHomepageBagFinalizerAndPreFinalizedSkipWired();
        },
            'NormalizeVisitorUriLivePreviewPath::testObserverRegisteredOnNormalizeVisitorUriEvent' => function () {
            $this->assert_NormalizeVisitorUriLivePreviewPath_testObserverRegisteredOnNormalizeVisitorUriEvent();
        },
            'NormalizeVisitorUriLivePreviewPath::testObserverPeelsPreviewMountForSiteProbeRemainder' => function () {
            $this->assert_NormalizeVisitorUriLivePreviewPath_testObserverPeelsPreviewMountForSiteProbeRemainder();
        },
            'NormalizeVisitorUriLivePreviewPath::testSharedUrlHelperReturnsRoutingRemainderViaEvent' => function () {
            $this->assert_NormalizeVisitorUriLivePreviewPath_testSharedUrlHelperReturnsRoutingRemainderViaEvent();
        },
            'NormalizeVisitorUriLivePreviewPath::testRehydrateHelperPrefixesSiteWhenTokenHasCode' => function () {
            $this->assert_NormalizeVisitorUriLivePreviewPath_testRehydrateHelperPrefixesSiteWhenTokenHasCode();
        },
            'NormalizeVisitorUriLivePreviewPath::testExtractWebsiteCodeFromTokenPayload' => function () {
            $this->assert_NormalizeVisitorUriLivePreviewPath_testExtractWebsiteCodeFromTokenPayload();
        },
            'ProcessPreviewThemeUriLiveCanvasLocale::testLiveCanvasClearsOverrideAndDoesNotBackfillLocaleQuery' => function () {
            $this->assert_ProcessPreviewThemeUriLiveCanvasLocale_testLiveCanvasClearsOverrideAndDoesNotBackfillLocaleQuery();
        },
            'ProcessPreviewThemeUriLiveCanvasLocale::testThemeEditorCanvasBuildsPathLocaleWithoutQuery' => function () {
            $this->assert_ProcessPreviewThemeUriLiveCanvasLocale_testThemeEditorCanvasBuildsPathLocaleWithoutQuery();
        },
            'ProcessPreviewThemeUriLiveCanvasLocale::testThemeEditorExposesWebsiteDefaultLocaleForPathCanvas' => function () {
            $this->assert_ProcessPreviewThemeUriLiveCanvasLocale_testThemeEditorExposesWebsiteDefaultLocaleForPathCanvas();
        },
            'ProcessPreviewThemeUriLiveCanvasLocale::testFrontendThemePreviewContentControllerIsDeleted' => function () {
            $this->assert_ProcessPreviewThemeUriLiveCanvasLocale_testFrontendThemePreviewContentControllerIsDeleted();
        },
            'WorkerBootstrapWarmupRank::testRankBucketsMatchIdleWarmupContract' => function () {
            $this->assert_WorkerBootstrapWarmupRank_testRankBucketsMatchIdleWarmupContract();
        },
            'WidgetDefaultInjectionIdentityDdlBefore::testObserverRegisteredOnTableDdlBefore' => function () {
            $this->assert_WidgetDefaultInjectionIdentityDdlBefore_testObserverRegisteredOnTableDdlBefore();
        },
            'WidgetDefaultInjectionIdentityDdlBefore::testHealerUsesLayoutIdentityHasherInjection' => function () {
            $this->assert_WidgetDefaultInjectionIdentityDdlBefore_testHealerUsesLayoutIdentityHasherInjection();
        },
        ];
    }

private function assert_WidgetDefaultInjectionIdentityDdlBefore_testObserverRegisteredOnTableDdlBefore(): void
{
    $xml = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/etc/event.xml');
    self::assertStringContainsString('Weline_Framework_Schema::table_ddl_before', $xml);
    self::assertStringContainsString('WidgetDefaultInjectionIdentityDdlBefore', $xml);
    self::assertStringContainsString('widget_default_injection_identity_ddl_before', $xml);
}

private function assert_WidgetDefaultInjectionIdentityDdlBefore_testHealerUsesLayoutIdentityHasherInjection(): void
{
    $src = (string) file_get_contents(
        '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/LayoutEntity/ThemeWidgetDefaultInjectionIdentityHealer.php'
    );
    self::assertStringContainsString('LayoutIdentityHasher::injection', $src);
    self::assertStringContainsString('layout_identity_hash', $src);
    self::assertStringContainsString('getTableColumns', $src);
    $observer = (string) file_get_contents(
        '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Observer/WidgetDefaultInjectionIdentityDdlBefore.php'
    );
    self::assertStringContainsString('ThemeWidgetDefaultInjection::schema_table', $observer);
    self::assertStringContainsString('ThemeWidgetDefaultInjectionIdentityHealer', $observer);
}

private function assert_HomepageShellSkipTemplateFetch_testFetchFileBeforeArmsSkipForHomepageLayoutType(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Observer/ControllerFetchFileBefore.php');
    self::assertStringContainsString('function applyHomepageLayoutSkipContentFetch', $src);
    self::assertStringContainsString("setData('skipTemplateFetch', true)", $src);
    self::assertStringContainsString("setData('homepage_content_skip', true)", $src);
    self::assertStringContainsString("setData('product_content_skip', true)", $src);
    self::assertStringContainsString('product_content_skip_armed', $src);
    self::assertStringContainsString('applyHomepageLayoutSkipContentFetch(', $src);
    self::assertStringNotContainsString('homepage-shell', $src);
    self::assertStringNotContainsString('homepage_shell_discard', $src);
}

private function assert_HomepageShellSkipTemplateFetch_testFetchFileAfterSkipsControllerContentOnHomepageLayout(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Observer/ControllerFetchFileAfter.php');
    self::assertStringContainsString('function isHomepageLayoutContentSkipped', $src);
    self::assertStringContainsString('function isProductDefaultLayoutContentSkipped', $src);
    self::assertStringContainsString('layout_owned_content_skipped', $src);
    self::assertStringContainsString('homepage_content_skip', $src);
    self::assertStringContainsString('product_content_skip', $src);
    self::assertStringContainsString('skipTemplateFetch', $src);
    self::assertStringContainsString('markStorefrontHtmlPreFinalized', $src);
    self::assertStringNotContainsString('homepage-shell', $src);
    self::assertStringNotContainsString('homepage_shell_discard', $src);
}

private function assert_HomepageShellSkipTemplateFetch_testPcControllerHonorsSkipTemplateFetch(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Framework/Controller/PcController.php');
    self::assertStringContainsString('skipTemplateFetch', $src);
    self::assertStringContainsString('skip_template_fetch', $src);
}

private function assert_HomepageShellSkipTemplateFetch_testHomepageShellTemplateRemovedFromIndexModule(): void
{
    $shell = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Index/view/templates/homepage-shell.phtml';
    self::assertFileDoesNotExist($shell);
}

private function assert_HomepageShellSkipTemplateFetch_testDetailShellTemplateRemovedFromProductModule(): void
{
    $shell = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Product/view/templates/frontend/catalog/detail-shell.phtml';
    self::assertFileDoesNotExist($shell);
}

private function assert_HomepageShellSkipTemplateFetch_testHomepageBagFinalizerAndPreFinalizedSkipWired(): void
{
    $finalizer = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/StorefrontLayoutHtmlFinalizer.php';
    self::assertFileExists($finalizer);
    $bag = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Service/StorefrontHomepageLayoutHtmlBag.php');
    self::assertStringContainsString('theme.homepage_layout.html.v8.', $bag);
    $slot = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Observer/LayoutSlotRenderer.php');
    self::assertStringContainsString('pre_finalized_skip', $slot);
    self::assertStringContainsString('theme.storefront_html_pre_finalized', $slot);
}

private const TOKEN_NormalizeVisitorUriLivePreviewPath = 'pv_abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG';

private function assert_NormalizeVisitorUriLivePreviewPath_testObserverRegisteredOnNormalizeVisitorUriEvent(): void
{
    $xml = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/etc/event.xml');
    self::assertStringContainsString('Weline_Framework_Url::normalize_visitor_uri', $xml);
    self::assertStringContainsString('NormalizeVisitorUriLivePreviewPath', $xml);
}

private function assert_NormalizeVisitorUriLivePreviewPath_testObserverPeelsPreviewMountForSiteProbeRemainder(): void
{
    $uri = '/~preview/' . self::TOKEN_NormalizeVisitorUriLivePreviewPath . '/~site/daocharms/about?keep=1';
    $data = new DataObject(['uri' => $uri, 'routing_uri' => $uri, 'origin_uri' => $uri]);
    $event = new Event(['data' => $data]);
    (new NormalizeVisitorUriLivePreviewPath())->execute($event);
    self::assertSame($uri, (string) $data->getData('origin_uri'));
    self::assertSame('/~site/daocharms/about?keep=1', (string) $data->getData('routing_uri'));
    self::assertSame(self::TOKEN_NormalizeVisitorUriLivePreviewPath, (string) $data->getData('live_preview_token'));
}

private function assert_NormalizeVisitorUriLivePreviewPath_testSharedUrlHelperReturnsRoutingRemainderViaEvent(): void
{
    $uri = '/~preview/' . self::TOKEN_NormalizeVisitorUriLivePreviewPath . '/~site/daocharms/';
    $normalized = Url::normalizeVisitorUri($uri);
    self::assertSame($uri, $normalized['origin_uri']);
    self::assertSame('/~site/daocharms', $normalized['routing_uri']);
    $absolute = Url::applyVisitorUriNormalizeToUrl('https://p05113ef3.test.weline.com' . $uri);
    self::assertSame('https://p05113ef3.test.weline.com/~site/daocharms', $absolute);
    self::assertNotNull(ThemeLivePreviewPathMount::parseFromUri($uri));
}

private function assert_NormalizeVisitorUriLivePreviewPath_testRehydrateHelperPrefixesSiteWhenTokenHasCode(): void
{
    self::assertSame('/~site/grocery/CNY/terms', ThemeLivePreviewPathMount::rehydrateSiteMountIntoRouting('/CNY/terms', 'grocery'));
}

private function assert_NormalizeVisitorUriLivePreviewPath_testExtractWebsiteCodeFromTokenPayload(): void
{
    $code = \Weline\Theme\Service\PreviewTokenService::extractWebsiteCodeFromPayload(['context' => ['editor_context' => ['scope' => ['identity' => ['website_id' => 544, 'website_code' => 'grocery']]]]]);
    self::assertSame('grocery', $code);
    $fromCanonical = \Weline\Theme\Service\PreviewTokenService::extractWebsiteCodeFromPayload(['canonical_scope' => 'daocharms.default.default', 'context' => []]);
    self::assertSame('daocharms', $fromCanonical);
}

private function observerSource_ProcessPreviewThemeUriLiveCanvasLocale(): string
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Observer/ProcessPreviewThemeUriBefore.php';
    self::assertFileExists($path);
    $source = file_get_contents($path);
    self::assertIsString($source);
    return $source;
}

private function assert_ProcessPreviewThemeUriLiveCanvasLocale_testLiveCanvasClearsOverrideAndDoesNotBackfillLocaleQuery(): void
{
    $source = $this->observerSource_ProcessPreviewThemeUriLiveCanvasLocale();
    self::assertStringContainsString('function clearLiveCanvasLanguageOverride', $source);
    self::assertStringContainsString('clearLiveCanvasLanguageOverride()', $source);
    self::assertStringContainsString("State::setRequestLanguageOverride('')", $source);
    self::assertStringContainsString('Visitor language is path-only for live canvas', $source);
    self::assertStringNotContainsString('function applyLiveCanvasPreviewLocale', $source);
    self::assertStringNotContainsString("setGet('locale'", $source);
    self::assertStringNotContainsString("getParam('lang'", $source);
}

private function assert_ProcessPreviewThemeUriLiveCanvasLocale_testThemeEditorCanvasBuildsPathLocaleWithoutQuery(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/view/statics/js/theme-editor.js';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('function buildCanvasLocalizedStorefrontPath', $source);
    self::assertStringContainsString('function stripCanvasVisitorLanguageQuery', $source);
    self::assertStringContainsString('stripCanvasVisitorLanguageQuery(url)', $source);
    self::assertStringContainsString('buildCanvasLocalizedStorefrontPath(route, previewLocale)', $source);
    self::assertStringContainsString('Visitor language is path-only — never ?locale= / ?lang=', $source);
    $canvasFnStart = strpos($source, 'function buildCanvasStorefrontPreviewUrl');
    self::assertNotFalse($canvasFnStart);
    $nextFn = strpos($source, "\n    function ", $canvasFnStart + 10);
    self::assertNotFalse($nextFn);
    $canvasFn = substr($source, $canvasFnStart, $nextFn - $canvasFnStart);
    self::assertStringNotContainsString("searchParams.set('locale'", $canvasFn, 'Live canvas must not write ?locale= visitor language');
}

private function assert_ProcessPreviewThemeUriLiveCanvasLocale_testThemeEditorExposesWebsiteDefaultLocaleForPathCanvas(): void
{
    $controller = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Controller/Backend/ThemeEditor.php';
    $template = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/view/templates/backend/ThemeEditor/index.phtml';
    self::assertFileExists($controller);
    self::assertFileExists($template);
    $controllerSrc = (string) file_get_contents($controller);
    $templateSrc = (string) file_get_contents($template);
    self::assertStringContainsString('function resolveEditorWebsiteDefaultLocale', $controllerSrc);
    self::assertStringContainsString('defaultLanguageForWebsite', $controllerSrc);
    self::assertStringContainsString("assign('website_default_locale'", $controllerSrc);
    self::assertStringContainsString("'is_default'", $controllerSrc);
    // website_id=0 is the system default site — must not gate with >0.
    self::assertStringContainsString('website_id=0 is the system default site', $controllerSrc);
    self::assertStringContainsString('KIND_GLOBAL', $controllerSrc);
    self::assertStringNotContainsString('if ($websiteId > 0 && class_exists(\Weline\Websites\Model\Website::class))', $controllerSrc);
    self::assertStringNotContainsString('->clearData()->load($websiteId)', $controllerSrc, 'Editor default locale must use WebsiteData snapshot, not Website::load.');
    self::assertStringContainsString('website_default_locale', $templateSrc);
    self::assertStringContainsString('data-default-locale=', $templateSrc);
    self::assertStringContainsString('resolveWebsiteDefaultLanguage', $templateSrc);
    self::assertStringContainsString('Never fall back to Env::default_LANGUAGE_CODE alone', $templateSrc);
}

private function assert_ProcessPreviewThemeUriLiveCanvasLocale_testFrontendThemePreviewContentControllerIsDeleted(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Controller/Frontend/ThemePreview/Content.php';
    self::assertFileDoesNotExist($path, 'Frontend theme-preview/content HTTP shell must be fully deleted');
}

private function assert_WorkerBootstrapWarmupRank_testRankBucketsMatchIdleWarmupContract(): void
{
    $ref = new ReflectionClass(WorkerBootstrapWarmup::class);
    $warmup = $ref->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(WorkerBootstrapWarmup::class, 'fpcWarmupPathRank');
    $method->setAccessible(true);
    self::assertSame(0, $method->invoke($warmup, '/'));
    self::assertSame(0, $method->invoke($warmup, '/products'));
    self::assertSame(0, $method->invoke($warmup, '/en_US/'));
    self::assertSame(1, $method->invoke($warmup, '/categories'));
    self::assertSame(1, $method->invoke($warmup, '/category/kids'));
    self::assertSame(2, $method->invoke($warmup, '/product/demo-sku'));
    self::assertSame(3, $method->invoke($warmup, '/policy/privacy'));
    self::assertSame(4, $method->invoke($warmup, '/blog/hello'));
    self::assertSame(4, $method->invoke($warmup, '/product/view'));
}
}
