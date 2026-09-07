<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

final class ThemeLayoutScopeSlotMergeContractTest extends TestCase
{
    public function testGetLayoutMergesSparseChildScopeBySlotInsteadOfWholePageOwnership(): void
    {
        $source = $this->read('app/code/Weline/Theme/Service/ThemeLayoutService.php');

        self::assertStringContainsString('$ownedSlotRows', $source);
        self::assertStringContainsString('Sparse Scope ownership is per-slot', $source);
        self::assertStringContainsString('isNoWidgetPlacementsRow', $source);
        self::assertStringContainsString("array_key_exists(\$slotId, \$ownedSlotRows)", $source);
        self::assertStringNotContainsString(
            'Any exact rows establish ownership. An all-inactive set',
            $source,
        );
    }

    public function testThemeRuntimeCacheCleanerPurgesStorefrontChromeHotCache(): void
    {
        $source = $this->read('app/code/Weline/Theme/Service/ThemeRuntimeCacheCleaner.php');

        self::assertStringContainsString('storefront_chrome_hot_cache', $source);
        self::assertStringContainsString('theme.chrome.', $source);
        self::assertStringContainsString('StorefrontScopeHotCache', $source);
        self::assertStringContainsString('weline_theme_storefront_chrome', $source);
        self::assertStringContainsString('purgeStorefrontChromeHotCachePool', $source);
        self::assertStringContainsString('->clear()', $source);
        self::assertStringContainsString('compiled_template_cache', $source);
        self::assertStringContainsString('module_view_tpl_compiled', $source);
        self::assertStringContainsString('purgeModuleCompiledViewTpl', $source);
        self::assertStringContainsString('TemplateCacheManager::getInstance()->clearAll()', $source);
        self::assertStringContainsString("pool('taglib')->clear()", $source);
        self::assertStringContainsString('router_fpc_payload_files', $source);

        $workspace = $this->read('app/code/Weline/Theme/Service/Scoped/ThemeScopedWorkspace.php');
        self::assertStringContainsString('dispatchScopedPublishResourceChange(', $workspace);
        self::assertStringContainsString('\\w_changed($change)', $workspace);

        $observer = $this->read('app/code/Weline/Theme/Observer/ResourceChanged.php');
        self::assertStringContainsString('clearScopedCaches($scope', $observer);
        self::assertStringContainsString('scopeFromChange(', $observer);
    }

    private function read(string $relative): string
    {
        $path = BP . \str_replace('/', \DIRECTORY_SEPARATOR, $relative);
        self::assertFileExists($path);
        $contents = \file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }
}
