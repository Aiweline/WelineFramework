<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class LayoutResolveServiceContractTest extends TestCase
{
    public function testLayoutResolveServiceExposesResolveAndPreviewSampleEvents(): void
    {
        $path = BP . 'app/code/Weline/Theme/Service/LayoutResolveService.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString("EVENT_LAYOUT_RESOLVE = 'Weline_Theme::layout_resolve'", $source);
        self::assertStringContainsString("EVENT_LAYOUT_PREVIEW_SAMPLE = 'Weline_Theme::layout_preview_sample'", $source);
        self::assertStringContainsString('function resolveFromRequest', $source);
        self::assertStringContainsString('function resolveFromPath', $source);
        self::assertStringContainsString('function resolvePreviewSample', $source);
        self::assertStringNotContainsString('StorefrontSampleCanvasHydrator', $source);
        self::assertStringNotContainsString('hydrateCanvasBody', $source);
        self::assertStringNotContainsString('EVENT_LAYOUT_CANVAS_BODY', $source);
        self::assertStringContainsString("'promotion'", $source);
        self::assertStringContainsString('shell_plus_slug', $source);

        $eventPhp = dirname(__DIR__, 3) . '/event.php';
        self::assertFileExists($eventPhp);
        $eventSource = (string)file_get_contents($eventPhp);
        self::assertStringContainsString("'Weline_Theme::layout_resolve'", $eventSource);
        self::assertStringContainsString("'Weline_Theme::layout_preview_sample'", $eventSource);
        self::assertStringNotContainsString('layout_canvas_body', $eventSource);
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

        self::assertStringContainsString('function postPreviewSample', $editorSource);
        self::assertStringContainsString('theme_public_route', $editorSource);
        self::assertStringContainsString('buildFrontendPreviewUrl', $editorSource);
        self::assertStringContainsString('data-api-preview-sample=', $phtmlSource);
        self::assertStringContainsString('previewSampleSelect', $phtmlSource);
        self::assertStringContainsString('apiPreviewSample', $jsSource);
        self::assertStringContainsString('refreshPreviewSample', $jsSource);
        self::assertStringContainsString('theme_public_route: themePublicRoute', $jsSource);
        self::assertStringContainsString('previewEntityRoute', $jsSource);
        self::assertStringContainsString('shell_plus_slug', $jsSource);
        self::assertStringContainsString('buildCanvasStorefrontPreviewUrl', $jsSource);
    }
}
