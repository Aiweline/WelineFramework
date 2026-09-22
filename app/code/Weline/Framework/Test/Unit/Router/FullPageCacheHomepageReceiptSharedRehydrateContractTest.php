<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Router;

use PHPUnit\Framework\TestCase;

/**
 * Root homepage receipts must survive Process L1 eviction by rehydrating from
 * Shared instead of deleting the receipt (B5 homepage-ready-but-receipt-missing).
 */
final class FullPageCacheHomepageReceiptSharedRehydrateContractTest extends TestCase
{
    public function testResolveHomepageProcessReceiptRehydratesFromSharedOnProcessMiss(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Framework/Router/FullPageCacheCoordinator.php'
        );
        $pos = \strpos($source, 'function resolveHomepageProcessReceipt(');
        self::assertNotFalse($pos);
        $next = \strpos($source, "\n    private function isRootHomepageFullUri(", $pos);
        self::assertNotFalse($next);
        $body = \substr($source, $pos, $next - $pos);
        self::assertStringContainsString('hydrateSharedPayload', $body);
        self::assertStringContainsString('setProcessCachedPayload', $body);
        self::assertStringContainsString('homepage-ready-but-receipt-missing', $body);
    }
}
