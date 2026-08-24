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
        $moduleRoot = dirname(__DIR__, 3);
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

    public function testInjectAddsAssetsAroundHeadAndBody(): void
    {
        $injector = $this->createInjector();
        $html = '<html><head><title>Preview</title></head><body><main>Preview</main></body></html>';

        $result = $injector->inject($html);

        self::assertStringContainsString('/Weline/Theme/view/statics/ui/pages/weline-theme-preview.css', $result);
        self::assertStringContainsString('/Weline/Theme/view/statics/ui/pages/weline-theme-preview.js', $result);
        self::assertLessThan(
            strpos($result, '</head>'),
            strpos($result, '/Weline/Theme/view/statics/ui/pages/weline-theme-preview.css')
        );
        self::assertLessThan(
            strpos($result, '</body>'),
            strpos($result, '/Weline/Theme/view/statics/ui/pages/weline-theme-preview.js')
        );
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
        $manifestPath = dirname(__DIR__, 7) . '/app/code/Weline/Theme/etc/weline-ui-assets.json';
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
