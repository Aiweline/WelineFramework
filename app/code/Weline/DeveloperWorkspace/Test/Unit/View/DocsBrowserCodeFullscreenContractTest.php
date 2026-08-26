<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Docs code blocks (esp. ASCII diagrams fenced as swift/yaml) must offer fullscreen enlarge.
 */
final class DocsBrowserCodeFullscreenContractTest extends TestCase
{
    /** @return list<string> */
    private function docsScriptPaths(): array
    {
        $moduleRoot = dirname(__DIR__, 3);
        $themeRoot = dirname($moduleRoot) . '/Theme';

        return [
            $themeRoot . '/view/statics/ui/pages/weline-developer-docs.js',
            $moduleRoot . '/view/statics/js/docs-browser.js',
        ];
    }

    public function testFullscreenHelpersExistInLiveAndParityScripts(): void
    {
        foreach ($this->docsScriptPaths() as $path) {
            self::assertFileExists($path);
            $src = (string)file_get_contents($path);
            self::assertStringContainsString('function isAsciiDiagram', $src, $path);
            self::assertStringContainsString('function openCodeFullscreen', $src, $path);
            self::assertStringContainsString('data-docs-fullscreen', $src, $path);
            self::assertStringContainsString('w-docs-code-fs', $src, $path);
        }
    }

    public function testFullscreenStylesExist(): void
    {
        $themeCss = dirname(__DIR__, 3) . '/../Theme/view/statics/ui/pages/weline-developer-docs.css';
        $parityCss = dirname(__DIR__, 3) . '/view/statics/css/docs-browser.css';
        foreach ([$themeCss, $parityCss] as $path) {
            self::assertFileExists($path);
            $src = (string)file_get_contents($path);
            self::assertStringContainsString('.w-docs-code-fs', $src, $path);
            self::assertStringContainsString('.w-docs-code-fs__pre', $src, $path);
            self::assertStringContainsString('[data-diagram="1"]', $src, $path);
        }
    }

    public function testIndexExposesFullscreenI18nKeys(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Docs/index.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString("'fullscreen'", $src);
        self::assertStringContainsString("'fullscreenClose'", $src);
    }
}
