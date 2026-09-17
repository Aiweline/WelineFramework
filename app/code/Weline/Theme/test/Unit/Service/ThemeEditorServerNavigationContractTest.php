<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class ThemeEditorServerNavigationContractTest extends TestCase
{
    public function testEditorTemplateExposesServerNavigationEndpoint(): void
    {
        $template = $this->read('app/code/Weline/Theme/view/templates/backend/ThemeEditor/index.phtml');

        self::assertStringContainsString(
            'data-api-resolve-navigation="@url{\'theme/backend/theme-editor/resolve-navigation\'}"',
            $template
        );
    }

    public function testBothEditorBundlesUseTheServerAsTheOnlyPageTypeSource(): void
    {
        foreach ([
            'app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js',
        ] as $path) {
            $source = $this->read($path);

            self::assertStringContainsString('apiResolveNavigation', $source, $path);
            self::assertStringContainsString('container.dataset.apiResolveNavigation', $source, $path);
            self::assertStringContainsString('apiJson(config.apiResolveNavigation', $source, $path);
            self::assertStringContainsString('resolveSameOriginEditorUrl(navigation.target_url)', $source, $path);
            self::assertStringNotContainsString("pathname.includes('/category/')", $source, $path);
            self::assertStringNotContainsString("pathname.includes('/product/')", $source, $path);
            self::assertStringNotContainsString("pathname.includes('/checkout')", $source, $path);
            self::assertStringNotContainsString("let pageType = 'homepage'", $source, $path);
        }
    }

    public function testThemeQueryProviderMapsResolveNavigationEditorRequest(): void
    {
        $provider = $this->read(
            'app/code/Weline/Theme/extends/module/Weline_Framework/Query/ThemeQueryProvider.php'
        );

        self::assertStringContainsString(
            "'/theme/backend/theme-editor/resolve-navigation'",
            $provider
        );
        self::assertStringContainsString('postResolveNavigation()', $provider);
    }

    private function read(string $relativePath): string
    {
        $root = \dirname(__DIR__, 7);
        $path = $root . '/' . $relativePath;
        self::assertFileExists($path);

        return (string)\file_get_contents($path);
    }
}
