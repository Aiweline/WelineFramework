<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Theme UI global full-screen loading: Weline.UI.loading (refcount) + host markup.
 */
final class GlobalLoadingOverlayContractTest extends TestCase
{
    public function testUiRuntimeExposesLoadingApiSyncedToStatics(): void
    {
        $root = dirname(__DIR__, 2);
        $js = $root . '/view/ui/js/weline-ui.js';
        $published = $root . '/view/statics/ui/weline-ui.js';
        self::assertFileExists($js);
        self::assertFileExists($published);

        $jsSrc = (string) file_get_contents($js);
        $publishedSrc = (string) file_get_contents($published);

        foreach ([$jsSrc, $publishedSrc] as $src) {
            self::assertStringContainsString('function showGlobalLoading(', $src);
            self::assertStringContainsString('function hideGlobalLoading(', $src);
            self::assertStringContainsString('function withGlobalLoading(', $src);
            self::assertStringContainsString('globalLoadingDepth', $src);
            self::assertStringContainsString('[data-w-component="loading"][data-w-scope="global"]', $src);
            self::assertStringContainsString('loading: loadingApi', $src);
            self::assertStringContainsString('UI.loading = loadingApi', $src);
            self::assertStringNotContainsString('function showLoading(', $src);
            self::assertStringNotContainsString('window.showLoading', $src);
        }
    }

    public function testBackendLoadingPartialOwnsGlobalHostNotAdminBridge(): void
    {
        $root = dirname(__DIR__, 2);
        $partial = $root . '/view/theme/backend/partials/loading/default.phtml';
        $component = $root . '/view/theme/backend/components/loading.phtml';
        self::assertFileExists($partial);
        self::assertFileExists($component);

        $partialSrc = (string) file_get_contents($partial);
        $componentSrc = (string) file_get_contents($component);

        self::assertStringNotContainsString('Weline_Admin::common/page/loading.phtml', $partialSrc);
        self::assertStringContainsString('data-w-scope="global"', $partialSrc . $componentSrc);
        self::assertStringContainsString('data-w-component="loading"', $partialSrc . $componentSrc);
        self::assertStringNotContainsString('function showLoading', $componentSrc);
        self::assertStringNotContainsString('function hideLoading', $componentSrc);
    }

    public function testDefaultBackendLayoutMountsLoadingPartial(): void
    {
        $root = dirname(__DIR__, 2);
        $default = $root . '/view/theme/backend/layouts/default/default.phtml';
        $dashboard = $root . '/view/theme/backend/layouts/dashboard/default.phtml';
        self::assertFileExists($default);
        self::assertFileExists($dashboard);

        $defaultSrc = (string) file_get_contents($default);
        $dashboardSrc = (string) file_get_contents($dashboard);

        self::assertStringContainsString('showGlobalLoading', $defaultSrc);
        self::assertStringContainsString('type="loading"', $defaultSrc);
        self::assertStringContainsString('showGlobalLoading', $dashboardSrc);
        self::assertStringContainsString('type="loading"', $dashboardSrc);
    }
}
