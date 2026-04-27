<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Template;
use Weline\Theme\Service\EditorModeAssetInjector;

final class EditorModeAssetInjectorTest extends TestCase
{
    private function createInjector(): EditorModeAssetInjector
    {
        $template = $this->createMock(Template::class);
        $template->method('fetchTagSource')
            ->willReturnMap([
                ['statics', 'Weline_Theme::css/editor-mode.css', '/Weline/Theme/view/statics/css/editor-mode.css'],
                ['statics', 'Weline_Theme::js/editor-mode.js', '/Weline/Theme/view/statics/js/editor-mode.js'],
            ]);

        return new EditorModeAssetInjector($template);
    }

    public function testInjectAddsAssetsAroundHeadAndBody(): void
    {
        $injector = $this->createInjector();
        $html = '<html><head><title>Preview</title></head><body><main>Preview</main></body></html>';

        $result = $injector->inject($html);

        self::assertStringContainsString('/Weline/Theme/view/statics/css/editor-mode.css', $result);
        self::assertStringContainsString('/Weline/Theme/view/statics/js/editor-mode.js', $result);
        self::assertLessThan(
            strpos($result, '</head>'),
            strpos($result, '/Weline/Theme/view/statics/css/editor-mode.css')
        );
        self::assertLessThan(
            strpos($result, '</body>'),
            strpos($result, '/Weline/Theme/view/statics/js/editor-mode.js')
        );
    }

    public function testInjectDoesNotDuplicateExistingAssets(): void
    {
        $injector = $this->createInjector();
        $html = <<<HTML
<html>
<head>
<link rel="stylesheet" href="/Weline/Theme/view/statics/css/editor-mode.css">
</head>
<body>
<main>Preview</main>
<script src="/Weline/Theme/view/statics/js/editor-mode.js"></script>
</body>
</html>
HTML;

        $result = $injector->inject($html);

        self::assertSame(1, substr_count($result, '/Weline/Theme/view/statics/css/editor-mode.css'));
        self::assertSame(1, substr_count($result, '/Weline/Theme/view/statics/js/editor-mode.js'));
    }
}
