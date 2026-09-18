<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class LayoutResolveServiceContractTest extends TestCase
{
    public function testLayoutResolveIsPathToLayoutOnly(): void
    {
        $path = BP . 'app/code/Weline/Theme/Service/LayoutResolveService.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString("EVENT_LAYOUT_RESOLVE = 'Weline_Theme::layout_resolve'", $source);
        self::assertStringContainsString('function resolveFromRequest', $source);
        self::assertStringContainsString('function resolveFromPath', $source);
        self::assertStringNotContainsString('EVENT_LAYOUT_PREVIEW_SAMPLE', $source);
        self::assertStringNotContainsString('function resolvePreviewSample', $source);
        self::assertStringNotContainsString('LayoutStorefrontRouteFromModuleRouter', $source);
        self::assertStringNotContainsString('StorefrontSampleCanvasHydrator', $source);

        $eventPhp = dirname(__DIR__, 3) . '/event.php';
        self::assertFileExists($eventPhp);
        $eventSource = (string)file_get_contents($eventPhp);
        self::assertStringContainsString("'Weline_Theme::layout_resolve'", $eventSource);
        self::assertStringNotContainsString('layout_preview_sample', $eventSource);
        self::assertFileDoesNotExist(dirname(__DIR__, 3) . '/Service/LayoutStorefrontRouteFromModuleRouter.php');
        self::assertFileDoesNotExist(dirname(__DIR__, 3) . '/Observer/LayoutPreviewSampleObserver.php');
    }

    public function testFetchFileBeforeDispatchesLayoutResolveWhenLayoutTypeEmpty(): void
    {
        $path = BP . 'app/code/Weline/Theme/Observer/ControllerFetchFileBefore.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('LayoutResolveService', $source);
        self::assertStringContainsString('resolveFromRequest', $source);
        self::assertStringNotContainsString('StorefrontSampleCanvasHydrator', $source);
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*empty\(\s*\$layoutType\s*\)\s*\)\s*\{[\s\S]*LayoutResolveService/m',
            $source
        );
    }

    public function testThemeEditorPreviewSampleAndPublicRouteWiring(): void
    {
        $editor = BP . 'app/code/Weline/Theme/Controller/Backend/ThemeEditor.php';
        $js = BP . 'app/code/Weline/Theme/view/statics/js/theme-editor.js';
        $phtml = BP . 'app/code/Weline/Theme/view/templates/backend/ThemeEditor/index.phtml';
        self::assertFileExists($editor);
        self::assertFileExists($js);
        self::assertFileExists($phtml);

        $editorSource = (string)file_get_contents($editor);
        $jsSource = (string)file_get_contents($js);
        $phtmlSource = (string)file_get_contents($phtml);

        self::assertStringNotContainsString('function postPreviewSample', $editorSource);
        self::assertStringNotContainsString('ThemePreviewContentRenderer', $editorSource);
        self::assertStringNotContainsString('theme_public_route', $editorSource);
        self::assertStringContainsString('buildFrontendPreviewUrl', $editorSource);
        self::assertStringNotContainsString('data-api-preview-sample=', $phtmlSource);
        self::assertStringNotContainsString('previewSampleSelect', $phtmlSource);
        self::assertStringContainsString('Path ↔ layout 1:1', $jsSource);
        self::assertStringNotContainsString('Wait for preview-sample', $jsSource);
        self::assertStringNotContainsString('theme_public_route', $jsSource);
        self::assertStringNotContainsString('refreshPreviewSample', $jsSource);
        self::assertStringNotContainsString('shell_plus_slug', $jsSource);
        self::assertStringNotContainsString('function buildLayoutPreviewUrl', $jsSource);
        self::assertStringContainsString('canvasRoute', $jsSource);
        self::assertStringContainsString('syncCanvasRouteFromLayout', $jsSource);
        self::assertStringContainsString('buildCanvasStorefrontPreviewUrl', $jsSource);
    }

    /**
     * Live WLS serves theme overlay from pub/static/Weline/hanfu/...
     * After removing preview-sample, published UI bundle must match source
     * (otherwise editor keeps calling the deleted API and panels stay Loading).
     */
    public function testPublishedHanfuThemeEditorJsMatchesRealPathCanvas(): void
    {
        $published = BP . 'pub/static/Weline/hanfu/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js';
        if (!is_file($published)) {
            $this->markTestSkipped('pub/static hanfu theme-editor bundle not present');
        }
        $source = (string)file_get_contents($published);
        self::assertStringNotContainsString('refreshPreviewSample', $source);
        self::assertStringNotContainsString('preview-sample', $source);
        self::assertStringNotContainsString('shell_plus_slug', $source);
        self::assertStringContainsString('syncCanvasRouteFromLayout', $source);
        self::assertStringContainsString('buildCanvasStorefrontPreviewUrl', $source);
    }
}
