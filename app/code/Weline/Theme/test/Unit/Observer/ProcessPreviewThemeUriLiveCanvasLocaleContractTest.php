<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;

/**
 * Live theme-editor canvas puts visitor language on the real storefront path.
 * App path-first wins; never backfill or override from ?locale= / ?lang=.
 */
final class ProcessPreviewThemeUriLiveCanvasLocaleContractTest extends TestCase
{
    private function observerSource(): string
    {
        $path = dirname(__DIR__, 3) . '/Observer/ProcessPreviewThemeUriBefore.php';
        self::assertFileExists($path);
        $source = file_get_contents($path);
        self::assertIsString($source);

        return $source;
    }

    public function testLiveCanvasClearsOverrideAndDoesNotBackfillLocaleQuery(): void
    {
        $source = $this->observerSource();

        self::assertStringContainsString('function clearLiveCanvasLanguageOverride', $source);
        self::assertStringContainsString('clearLiveCanvasLanguageOverride()', $source);
        self::assertStringContainsString("State::setRequestLanguageOverride('')", $source);
        self::assertStringContainsString(
            'Visitor language is path-only for live canvas',
            $source,
        );
        self::assertStringNotContainsString('function applyLiveCanvasPreviewLocale', $source);
        self::assertStringNotContainsString("setGet('locale'", $source);
        self::assertStringNotContainsString("getParam('lang'", $source);
    }

    public function testThemeEditorCanvasBuildsPathLocaleWithoutQuery(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/js/theme-editor.js';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('function buildCanvasLocalizedStorefrontPath', $source);
        self::assertStringContainsString('function stripCanvasVisitorLanguageQuery', $source);
        self::assertStringContainsString('stripCanvasVisitorLanguageQuery(url)', $source);
        self::assertStringContainsString('buildCanvasLocalizedStorefrontPath(route, previewLocale)', $source);
        self::assertStringContainsString(
            'Visitor language is path-only — never ?locale= / ?lang=',
            $source,
        );
        $canvasFnStart = strpos($source, 'function buildCanvasStorefrontPreviewUrl');
        self::assertNotFalse($canvasFnStart);
        $nextFn = strpos($source, "\n    function ", $canvasFnStart + 10);
        self::assertNotFalse($nextFn);
        $canvasFn = substr($source, $canvasFnStart, $nextFn - $canvasFnStart);
        self::assertStringNotContainsString(
            "searchParams.set('locale'",
            $canvasFn,
            'Live canvas must not write ?locale= visitor language',
        );
    }

    public function testThemeEditorExposesWebsiteDefaultLocaleForPathCanvas(): void
    {
        $controller = dirname(__DIR__, 3) . '/Controller/Backend/ThemeEditor.php';
        $template = dirname(__DIR__, 3) . '/view/templates/backend/ThemeEditor/index.phtml';
        self::assertFileExists($controller);
        self::assertFileExists($template);
        $controllerSrc = (string)file_get_contents($controller);
        $templateSrc = (string)file_get_contents($template);
        self::assertStringContainsString('function resolveEditorWebsiteDefaultLocale', $controllerSrc);
        self::assertStringContainsString("assign('website_default_locale'", $controllerSrc);
        self::assertStringContainsString("'is_default'", $controllerSrc);
        // website_id=0 is the system default site — must not gate with >0.
        self::assertStringContainsString('website_id=0 is the system default site', $controllerSrc);
        self::assertStringContainsString('KIND_GLOBAL', $controllerSrc);
        self::assertStringNotContainsString(
            'if ($websiteId > 0 && class_exists(\\Weline\\Websites\\Model\\Website::class))',
            $controllerSrc,
        );
        self::assertStringContainsString('website_default_locale', $templateSrc);
        self::assertStringContainsString('data-default-locale=', $templateSrc);
        self::assertStringContainsString('resolveWebsiteDefaultLanguage', $templateSrc);
        self::assertStringContainsString(
            'Never fall back to Env::default_LANGUAGE_CODE alone',
            $templateSrc,
        );
    }

    public function testFrontendThemePreviewContentControllerIsDeleted(): void
    {
        $path = dirname(__DIR__, 3) . '/Controller/Frontend/ThemePreview/Content.php';
        self::assertFileDoesNotExist($path, 'Frontend theme-preview/content HTTP shell must be fully deleted');
    }
}
