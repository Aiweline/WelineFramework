<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * /dev/tool/docs?search=… must auto-fill the search box and run search on boot.
 * Live page loads Theme `weline-developer-docs.js` (see Docs/index.phtml);
 * DeveloperWorkspace `docs-browser.js` keeps the same contract for parity.
 * Deep links from DevTool (e.g. ?search=WLS, ?module=Weline_Server) depend on this.
 */
final class DocsBrowserSearchQueryContractTest extends TestCase
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

    /** @return array<string, string> */
    private function docsScriptSources(): array
    {
        $sources = [];
        foreach ($this->docsScriptPaths() as $path) {
            self::assertFileExists($path);
            $sources[$path] = (string)file_get_contents($path);
        }

        return $sources;
    }

    public function testReadsSearchQueryKeysFromUrl(): void
    {
        foreach ($this->docsScriptSources() as $path => $src) {
            self::assertStringContainsString("SEARCH_QUERY_KEYS = ['search', 'keyword', 'q', 'module']", $src, $path);
            self::assertStringContainsString('function readUrlSearchKeyword', $src, $path);
        }
    }

    public function testBootAppliesUrlSearchAutomatically(): void
    {
        foreach ($this->docsScriptSources() as $path => $src) {
            self::assertStringContainsString('const bootSearch = readUrlSearchKeyword()', $src, $path);
            self::assertStringContainsString('searchInput.value = bootSearch', $src, $path);
            self::assertStringContainsString('search(bootSearch, false)', $src, $path);
        }
    }

    public function testPopstateRestoresSearchFromUrl(): void
    {
        foreach ($this->docsScriptSources() as $path => $src) {
            self::assertStringContainsString('const keyword = readUrlSearchKeyword(url)', $src, $path);
            self::assertStringContainsString('search(keyword, false)', $src, $path);
        }
    }

    public function testDocsIndexLoadsThemeDeveloperDocsScript(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Docs/index.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('Weline_Theme::ui/pages/weline-developer-docs.js', $src);
    }
}
