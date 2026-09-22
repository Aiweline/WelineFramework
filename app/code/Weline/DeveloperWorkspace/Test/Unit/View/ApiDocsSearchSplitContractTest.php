<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * API docs: sidebar module filter vs main-area interface search must stay split.
 * Live page loads Theme `weline-developer-api.js`; `api-docs.js` keeps parity.
 * Controller must not force-regenerate / embed full content for every API (OOM→503 blank).
 */
final class ApiDocsSearchSplitContractTest extends TestCase
{
    public function testTemplateSplitsModuleFilterAndApiSearch(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Docs/api-manager.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('Weline_Theme::ui/pages/weline-developer-api.js', $src);
        self::assertStringContainsString('data-api-module-filter', $src);
        self::assertStringContainsString('data-api-search', $src);
        self::assertStringContainsString('w-api-docs__api-search', $src);

        $sidebarPos = strpos($src, 'w-api-docs__sidebar');
        $contentPos = strpos($src, 'w-api-docs__content');
        $moduleFilterPos = strpos($src, 'data-api-module-filter');
        $apiSearchPos = strpos($src, 'data-api-search');
        self::assertNotFalse($sidebarPos);
        self::assertNotFalse($contentPos);
        self::assertNotFalse($moduleFilterPos);
        self::assertNotFalse($apiSearchPos);
        self::assertGreaterThan($sidebarPos, $moduleFilterPos);
        self::assertLessThan($contentPos, $moduleFilterPos);
        self::assertGreaterThan($contentPos, $apiSearchPos);
    }

    public function testScriptsSupportModuleFilter(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $themeRoot = dirname($moduleRoot) . '/Theme';
        $paths = [
            $themeRoot . '/view/statics/ui/pages/weline-developer-api.js',
            $moduleRoot . '/view/statics/js/api-docs.js',
        ];
        foreach ($paths as $path) {
            self::assertFileExists($path, $path);
            $src = (string)file_get_contents($path);
            self::assertStringContainsString("document.querySelector('[data-api-module-filter]')", $src, $path);
            self::assertStringContainsString('function matchesModule', $src, $path);
            self::assertStringContainsString('state.moduleFilter', $src, $path);
            self::assertStringContainsString('function ensureAreaForCurrentFilters', $src, $path);
            self::assertStringContainsString('function matchesAreaQuery', $src, $path);
            self::assertStringContainsString('ensureAreaForCurrentFilters()', $src, $path);
            self::assertStringContainsString('function readUrlDocsParams', $src, $path);
            self::assertStringContainsString('function updateDocsUrl', $src, $path);
            self::assertStringContainsString("searchParams.set('module'", $src, $path);
            self::assertStringContainsString("searchParams.set('q'", $src, $path);
            self::assertStringContainsString('syncFilterInputsFromState', $src, $path);
        }
    }

    public function testApiActionAvoidsForceRegenAndBulkContent(): void
    {
        $path = dirname(__DIR__, 3) . '/Controller/Docs.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringNotContainsString('generateAll(true)', $src);
        self::assertStringContainsString('generateAll($forceRefresh)', $src);
        self::assertStringContainsString('$includeContent = $selectedApiId !== \'\' && $apiId === $selectedApiId', $src);
        self::assertStringContainsString('localizeApiDocument($api, $currentLanguage, $includeContent)', $src);
    }
}
