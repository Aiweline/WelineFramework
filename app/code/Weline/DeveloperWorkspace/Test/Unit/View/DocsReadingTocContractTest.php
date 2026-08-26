<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Reading pages must restore floating TOC helpers lost during Weline UI migration.
 * Live pages load Theme published bundles; module statics remain the source of truth.
 */
final class DocsReadingTocContractTest extends TestCase
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

    /** @return list<string> */
    private function apiScriptPaths(): array
    {
        $moduleRoot = dirname(__DIR__, 3);
        $themeRoot = dirname($moduleRoot) . '/Theme';

        return [
            $themeRoot . '/view/statics/ui/pages/weline-developer-api.js',
            $moduleRoot . '/view/statics/js/api-docs.js',
        ];
    }

    public function testDocsBrowserExposesFloatingTocHelpers(): void
    {
        foreach ($this->docsScriptPaths() as $path) {
            self::assertFileExists($path);
            $src = (string)file_get_contents($path);
            self::assertStringContainsString('function refreshDocumentToc', $src, $path);
            self::assertStringContainsString('function clearDocumentToc', $src, $path);
            self::assertStringContainsString('function toggleDocumentToc', $src, $path);
            self::assertStringContainsString('function setActiveDocsTocItem', $src, $path);
            self::assertStringContainsString('lockUntil', $src, $path);
            self::assertStringContainsString('data-docs-toc', $src, $path);
            self::assertStringContainsString('refreshDocumentToc(body)', $src, $path);
        }
    }

    public function testApiDocsExposesFloatingTocHelpers(): void
    {
        foreach ($this->apiScriptPaths() as $path) {
            self::assertFileExists($path);
            $src = (string)file_get_contents($path);
            self::assertStringContainsString('function refreshApiToc', $src, $path);
            self::assertStringContainsString('function clearApiToc', $src, $path);
            self::assertStringContainsString('function toggleApiToc', $src, $path);
            self::assertStringContainsString('data-api-toc', $src, $path);
            self::assertStringContainsString('refreshApiToc()', $src, $path);
        }
    }

    public function testDocsCssDefinesFloatingTocSurface(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $themeRoot = dirname($moduleRoot) . '/Theme';
        foreach ([
            $moduleRoot . '/view/statics/css/docs-browser.css',
            $themeRoot . '/view/statics/ui/pages/weline-developer-docs.css',
            $moduleRoot . '/view/statics/css/api-docs.css',
            $themeRoot . '/view/statics/ui/pages/weline-developer-api.css',
        ] as $path) {
            self::assertFileExists($path);
            $src = (string)file_get_contents($path);
            self::assertTrue(
                str_contains($src, '.w-docs-toc') || str_contains($src, '.w-api-toc'),
                $path
            );
        }
    }
}
