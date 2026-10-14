<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

final class ThemeLayoutScopeSlotMergeContractTest extends TestCase
{
    public function testGetLayoutMergesSparseChildScopeBySlotInsteadOfWholePageOwnership(): void
    {
        // Slot-near-priority ownership lives on the scoped publish read path
        // (ThemeScopedWorkspace), not the legacy ThemeLayoutService whole-page merge.
        $source = $this->read('app/code/Weline/Theme/Service/Scoped/ThemeScopedWorkspace.php');

        self::assertStringContainsString('composeLayoutPayloadBySlotNearPriority', $source);
        self::assertStringContainsString('claimedSlots', $source);
        self::assertStringContainsString('Slot-near-priority compose across parent_release_id chain', $source);
        self::assertStringContainsString("isset(\$claimedSlots[\$slotId])", $source);
    }

    public function testPublishedStateComposesLayoutPayloadBySlotNearPriority(): void
    {
        $source = $this->read('app/code/Weline/Theme/Service/Scoped/ThemeScopedWorkspace.php');

        self::assertStringContainsString('composeLayoutPayloadBySlotNearPriority', $source);
        self::assertStringContainsString('Slot-near-priority compose across parent_release_id chain', $source);
        self::assertStringContainsString('RESOURCE_LAYOUT', $source);
        self::assertStringContainsString('claimedSlots', $source);
        // Storefront published reads must not return sparse near Release payloads as-is:
        // lock inside publishedState() body (not a brittle whole-file char-distance window —
        // doc comments above the compose call already exceed 1200 bytes).
        $publishedStateBody = $this->extractMethodBody($source, 'publishedState');
        self::assertNotSame('', $publishedStateBody, 'publishedState method body must be extractable');
        self::assertStringContainsString(
            'composeLayoutPayloadBySlotNearPriority',
            $publishedStateBody,
            'publishedState must compose LAYOUT via slot-near-priority',
        );
        self::assertStringContainsString('RESOURCE_LAYOUT', $publishedStateBody);
    }

    public function testThemeRuntimeCacheCleanerPurgesStorefrontChromeHotCache(): void
    {
        $source = $this->read('app/code/Weline/Theme/Service/ThemeRuntimeCacheCleaner.php');

        self::assertStringContainsString('storefront_chrome_hot_cache', $source);
        self::assertStringContainsString('published_layout_structure_hot_cache', $source);
        self::assertStringContainsString('theme.chrome.', $source);
        self::assertStringContainsString('StorefrontScopeHotCache', $source);
        self::assertStringContainsString('weline_theme_storefront_chrome', $source);
        self::assertStringContainsString('PUBLISHED_LAYOUT_STRUCTURE_POOL', $source);
        self::assertStringContainsString('purgeStorefrontChromeHotCachePool', $source);
        self::assertStringContainsString('purgePublishedLayoutStructureHotCachePool', $source);
        self::assertStringContainsString('->clear()', $source);
        self::assertStringContainsString('compiled_template_cache', $source);
        self::assertStringContainsString('module_view_tpl_compiled', $source);
        self::assertStringContainsString('purgeModuleCompiledViewTpl', $source);
        self::assertStringContainsString('TemplateCacheManager::getInstance()->clearAll()', $source);
        self::assertStringContainsString("pool('taglib')->clear()", $source);
        self::assertStringContainsString('fpc_staging_warm_promote', $source);
        self::assertStringContainsString('rebuildFpcViaStagingWarmPromote', $source);
        self::assertStringContainsString('beginStagingGeneration', $source);
        self::assertStringContainsString('assertPromotedHtmlDiskMatchesStorefrontGeneration', $source);
        self::assertStringContainsString('fpc_router_cache_pools', $source);
        self::assertStringContainsString('theme_namespace_generations_all', $source);
        self::assertStringContainsString('bumpAllThemeNamespaces(', $source);
        self::assertStringContainsString('fpc_cache_pools', $source);
        self::assertStringContainsString('wls_shared_fpc_full', $source);
        self::assertStringContainsString('wls_worker_broadcast_all', $source);
        self::assertStringContainsString('cdn_full_page_purge', $source);
        self::assertStringContainsString('purgeCdnFullPageCaches(', $source);
        self::assertStringContainsString('cacheClear(null)', $source);

        $workspace = $this->read('app/code/Weline/Theme/Service/Scoped/ThemeScopedWorkspace.php');
        self::assertStringContainsString('dispatchScopedPublishResourceChange(', $workspace);
        self::assertStringContainsString('\\w_changed($change)', $workspace);

        $capability = $this->read('app/code/Weline/Theme/extends/module/Weline_Framework/Changed/Capability/ThemeRuntimeCapability.php');
        self::assertStringContainsString('clearScopedCaches(', $capability);
        self::assertStringNotContainsString('clearAllThemeRelatedCaches(', $capability);
        self::assertStringContainsString('CODE_THEME_RUNTIME_CLEAR', $capability);
        self::assertStringContainsString('theme_runtime', $capability);

        $cleaner = $this->read('app/code/Weline/Theme/Service/ThemeRuntimeCacheCleaner.php');
        self::assertStringContainsString('function clearAllThemeRelatedCaches(', $cleaner);
        self::assertStringContainsString('function clearScopedCaches(', $cleaner);
        self::assertStringContainsString('function invalidateAfterVersionPublish(', $cleaner);
        self::assertStringContainsString('theme_namespace_generations_all', $cleaner);

        $request = $this->read('app/code/Weline/Theme/Service/Scoped/ThemeScopedWorkspaceRequestService.php');
        self::assertStringContainsString('invalidateAfterVersionPublish(', $request);
        self::assertStringNotContainsString('clearAllThemeRelatedCaches(', $request);

        self::assertFileDoesNotExist(BP . 'app/code/Weline/Theme/Observer/ResourceChanged.php');
    }

    private function read(string $relative): string
    {
        $path = BP . \str_replace('/', \DIRECTORY_SEPARATOR, $relative);
        self::assertFileExists($path);
        $contents = \file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }

    /**
     * Extract one private/protected/public method body by brace depth (source-contract helper).
     */
    private function extractMethodBody(string $source, string $methodName): string
    {
        $pattern = '/(?:public|protected|private)\s+function\s+'
            . \preg_quote($methodName, '/')
            . '\s*\(/';
        if (\preg_match($pattern, $source, $m, \PREG_OFFSET_CAPTURE) !== 1) {
            return '';
        }
        $start = (int)$m[0][1];
        $open = \strpos($source, '{', $start);
        if ($open === false) {
            return '';
        }
        $depth = 0;
        $len = \strlen($source);
        for ($i = $open; $i < $len; $i++) {
            $ch = $source[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return \substr($source, $open, $i - $open + 1);
                }
            }
        }

        return '';
    }
}
