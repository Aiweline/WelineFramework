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
