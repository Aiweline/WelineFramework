<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Template;
use Weline\Theme\Service\EditorModeAssetInjector;
use Weline\Theme\Service\Ui\IconRegistry;

final class EditorModeAssetInjectorTest extends TestCase
{
    private function createInjector(): EditorModeAssetInjector
    {
        $moduleRoot = dirname(__DIR__, 4);
        if (!class_exists(IconRegistry::class, false)) {
            require_once $moduleRoot . '/Service/Ui/IconRegistry.php';
        }
        if (!class_exists(EditorModeAssetInjector::class, false)) {
            require_once $moduleRoot . '/Service/EditorModeAssetInjector.php';
        }

        $template = $this->createMock(Template::class);
        $template->method('fetchTagSource')
            ->willReturnMap([
                ['statics', 'Weline_Theme::ui/pages/weline-theme-preview.css', '/Weline/Theme/view/statics/ui/pages/weline-theme-preview.css'],
                ['statics', 'Weline_Theme::ui/pages/weline-theme-preview.js', '/Weline/Theme/view/statics/ui/pages/weline-theme-preview.js'],
            ]);

        return new EditorModeAssetInjector($template, new IconRegistry());
    }

    public function testInjectAddsPreviewExitButtonInsteadOfBackendLink(): void
    {
        if (!\function_exists('__')) {
            /** @noinspection PhpUnused */
            eval('function __($text) { return $text; }');
        }

        $injector = $this->createInjector();
        $html = '<html><head><title>Preview</title></head><body><main>Preview</main></body></html>';

        $result = $injector->inject($html, '/theme/frontend/theme-preview/gateway?exit=1');

        self::assertStringContainsString('data-w-preview-exit', $result);
        self::assertStringContainsString('data-w-preview-exit-url="/theme/frontend/theme-preview/gateway?exit=1"', $result);
        self::assertStringNotContainsString('target="_top"', $result);
    }

    public function testInjectAddsAssetsAroundHeadAndBody(): void
    {
        $injector = $this->createInjector();
        $html = '<html><head><title>Preview</title></head><body><main>Preview</main></body></html>';

        $result = $injector->inject($html);

        self::assertStringContainsString('/Weline/Theme/view/statics/ui/pages/weline-theme-preview.css', $result);
        self::assertStringContainsString('/Weline/Theme/view/statics/ui/pages/weline-theme-preview.js?v=20260901-theme-editor-virtual-gate-v1', $result);
        self::assertStringNotContainsString('product-card-purchase-actions', $result);
        self::assertLessThan(
            strpos($result, '</head>'),
            strpos($result, '/Weline/Theme/view/statics/ui/pages/weline-theme-preview.css')
        );
        // Preview engine (incl. reportWidgetHtmlHealth) must load from <head>, not body end.
        self::assertLessThan(
            strpos($result, '</head>'),
            strpos($result, '/Weline/Theme/view/statics/ui/pages/weline-theme-preview.js')
        );
    }

    public function testInjectUsesAmpersandWhenSourceAlreadyHasQuery(): void
    {
        $moduleRoot = dirname(__DIR__, 4);
        if (!class_exists(IconRegistry::class, false)) {
            require_once $moduleRoot . '/Service/Ui/IconRegistry.php';
        }
        if (!class_exists(EditorModeAssetInjector::class, false)) {
            require_once $moduleRoot . '/Service/EditorModeAssetInjector.php';
        }

        $template = $this->createMock(Template::class);
        $template->method('fetchTagSource')
            ->willReturnMap([
                ['statics', 'Weline_Theme::ui/pages/weline-theme-preview.css', '/Weline/Theme/view/statics/ui/pages/weline-theme-preview.css?v=preview_x'],
                ['statics', 'Weline_Theme::ui/pages/weline-theme-preview.js', '/Weline/Theme/view/statics/ui/pages/weline-theme-preview.js?v=preview_x'],
            ]);

        $injector = new EditorModeAssetInjector($template, new IconRegistry());
        $result = $injector->inject('<html><head></head><body><button class="btn-buy-now">Buy</button></body></html>');

        self::assertStringContainsString(
            'weline-theme-preview.js?v=preview_x&amp;v=20260901-theme-editor-virtual-gate-v1',
            $result
        );
        self::assertStringNotContainsString('data-weline-product-card-purchase-actions', $result);
        self::assertStringNotContainsString('?v=preview_x?v=', $result);
    }

    public function testInjectDoesNotDuplicateExistingAssets(): void
    {
        $injector = $this->createInjector();
        $html = <<<HTML
<html>
<head>
<link rel="stylesheet" href="/Weline/Theme/view/statics/ui/pages/weline-theme-preview.css" data-w-editor-preview-asset="style">
</head>
<body>
<main>Preview</main>
<script type="module" src="/Weline/Theme/view/statics/ui/pages/weline-theme-preview.js" data-w-editor-preview-asset="script"></script>
</body>
</html>
HTML;

        $result = $injector->inject($html);

        self::assertSame(1, substr_count($result, '/Weline/Theme/view/statics/ui/pages/weline-theme-preview.css'));
        self::assertSame(1, substr_count($result, '/Weline/Theme/view/statics/ui/pages/weline-theme-preview.js'));
    }

    public function testPreviewBundleKeepsTheFullEditorEngineBeforeTheUiAdapter(): void
    {
        $manifestPath = dirname(__DIR__, 8) . '/app/code/Weline/Theme/etc/weline-ui-assets.json';
        $manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            'app/code/Weline/Theme/view',
            $manifest['bundles']['theme-preview-css']['source_root'] ?? null
        );
        self::assertSame([
            'statics/css/editor-mode.css',
            'ui/css/pages/theme-preview.css',
        ], $manifest['bundles']['theme-preview-css']['sources'] ?? null);
        self::assertSame(
            'app/code/Weline/Theme/view',
            $manifest['bundles']['theme-preview-js']['source_root'] ?? null
        );
        self::assertSame([
            'statics/js/editor-mode.js',
            'ui/js/pages/theme-preview.js',
        ], $manifest['bundles']['theme-preview-js']['sources'] ?? null);
    }
}
