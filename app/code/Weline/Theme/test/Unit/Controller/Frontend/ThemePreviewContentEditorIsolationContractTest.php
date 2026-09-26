<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Controller\Frontend;

use PHPUnit\Framework\TestCase;

final class ThemePreviewContentEditorIsolationContractTest extends TestCase
{
    public function testFrontendThemePreviewContentControllerIsDeleted(): void
    {
        $path = dirname(__DIR__, 4) . '/Controller/Frontend/ThemePreview/Content.php';
        self::assertFileDoesNotExist($path, 'Frontend theme-preview/content HTTP shell must be fully deleted');
    }

    public function testEditorCanvasLoadsStorefrontRouteNotHydrate(): void
    {
        $js = dirname(__DIR__, 4) . '/view/statics/js/theme-editor.js';
        self::assertFileExists($js);
        $source = (string)file_get_contents($js);

        self::assertStringContainsString('function buildCanvasStorefrontPreviewUrl', $source);
        self::assertStringContainsString('function resolveCanvasStorefrontPath', $source);
        self::assertStringContainsString('buildCanvasStorefrontPreviewUrl(overrides)', $source);
        self::assertStringContainsString("url.searchParams.delete('page_type')", $source);
        self::assertStringContainsString("url.searchParams.delete('layout_type')", $source);
        self::assertStringContainsString("url.searchParams.delete('weline_preview_token')", $source);
        // Bare /product is Theme Policy shell + R43 mock — canvas must require product/{slug}.
        self::assertStringNotContainsString("product: 'product'", $source);
        self::assertStringNotContainsString('StorefrontSampleCanvasHydrator', $source);
    }

    public function testLayoutSlotRendererBootstrapsEditorCanvasOnAnyRoute(): void
    {
        $path = dirname(__DIR__, 4) . '/Observer/LayoutSlotRenderer.php';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('function isEditorCanvasRequest', $source);
        self::assertStringContainsString('function bootstrapEditorCanvasIdentity', $source);
        self::assertStringContainsString('EditorModeAssetInjector', $source);
        self::assertStringContainsString("getParam('editor_mode'", $source);
        // 画布身份安装必须真实被调用（带 template 参数），仅断言无参形式会漏掉 $this->bootstrapEditorCanvasIdentity($template) 的真实调用。
        self::assertStringContainsString('bootstrapEditorCanvasIdentity($template)', $source);
    }
}
