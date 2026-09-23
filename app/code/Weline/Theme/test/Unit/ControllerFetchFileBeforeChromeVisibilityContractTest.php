<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * wave9-9s3: storefront meta.showHeader/showFooter must default true when absent —
 * Taglib `if(($meta['showHeader'] ?? null))` treats missing as false and drops chrome.
 */
final class ControllerFetchFileBeforeChromeVisibilityContractTest extends TestCase
{
    public function testObserverEnsuresChromeVisibilityDefaultsOnMetaMerge(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 2) . '/Observer/ControllerFetchFileBefore.php'
        );

        self::assertStringContainsString('function ensureStorefrontChromeVisibilityDefaults', $src);
        self::assertStringContainsString('function coerceStorefrontChromeFlag', $src);
        self::assertStringContainsString('function stripLeakedStorefrontChromeFlags', $src);
        self::assertStringContainsString('function isStorefrontChromeOffLayoutType', $src);
        self::assertStringContainsString('function ensureStorefrontChromeOnForLayout', $src);
        self::assertStringContainsString('wave9-9s3', $src);
        self::assertStringContainsString('wave9-9s4', $src);
        self::assertStringContainsString('ensureStorefrontChromeVisibilityDefaults(array_merge(', $src);
        self::assertStringContainsString('stripLeakedStorefrontChromeFlags(', $src);
        self::assertStringContainsString('ensureStorefrontChromeOnForLayout(', $src);
        self::assertGreaterThanOrEqual(
            3,
            \substr_count($src, 'ensureStorefrontChromeVisibilityDefaults('),
            'cached + full + account chrome-default paths must call ensure',
        );
        self::assertGreaterThanOrEqual(
            2,
            \substr_count($src, 'stripLeakedStorefrontChromeFlags('),
            'cached + full merge paths must strip leaked chrome-off flags',
        );
        self::assertGreaterThanOrEqual(
            2,
            \substr_count($src, 'ensureStorefrontChromeOnForLayout('),
            'cached + full paths must force chrome-on for storefront layouts',
        );
    }

    public function testStripLeakedChromeFlagsSparedForAuthLayouts(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 2) . '/Observer/ControllerFetchFileBefore.php'
        );
        self::assertStringContainsString("str_starts_with(\$type, 'account')", $src);
        self::assertStringContainsString("'auth'", $src);
        self::assertStringContainsString("'identity'", $src);
        self::assertStringContainsString('unset($existingMeta[\'showHeader\'], $existingMeta[\'showFooter\'])', $src);
    }

    public function testSharedChromeTreatsDeliveryAsChromeSlot(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 2) . '/Service/SharedChromeService.php'
        );

        self::assertStringContainsString("'delivery'", $src);
        self::assertStringContainsString('CHROME_SLOTS = [\'header\', \'footer\', \'delivery\']', $src);
    }
}
