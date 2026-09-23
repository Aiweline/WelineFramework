<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Router;

use PHPUnit\Framework\TestCase;

/**
 * Process L1 must prefer keeping homepage-receipt-pinned and cookieless
 * `/products` catalog-pinned payloads when multi-MB locale warmup inserts
 * pressure MAX_ITEMS/BYTES (wave4-4c / wave7-7c axis C).
 * Pinning stays inside the existing FPC process bag — no epoch-less parallel bag.
 */
final class FullPageCacheProcessL1HomepagePinContractTest extends TestCase
{
    public function testProcessL1EvictionPrefersNonPinnedAndRefusesLocaleOverHomepage(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Framework/Router/FullPageCacheCoordinator.php'
        );

        self::assertStringContainsString('function isProcessFpcHomepagePinnedKey(', $source);
        self::assertStringContainsString('function evictProcessFpcPayloadForIncoming(', $source);
        self::assertStringContainsString('processLocalizedHomepageReceipts', $source);
        self::assertStringContainsString('processCriticalCatalogReceipts', $source);
        self::assertStringContainsString('registerRootCatalogProcessReceipt', $source);
        self::assertStringContainsString('isRootCatalogProductsFullUri', $source);
        self::assertStringContainsString('Keep homepage/catalog receipt L1', $source);
        self::assertStringContainsString('not a parallel epoch-less static bag', $source);

        $getPos = \strpos($source, 'function getProcessCachedPayload(');
        self::assertNotFalse($getPos);
        $setPos = \strpos($source, 'function setProcessCachedPayload(', $getPos);
        self::assertNotFalse($setPos);
        $getBody = \substr($source, $getPos, $setPos - $getPos);
        self::assertStringContainsString('unset(self::$processFpcPayloadCache[$cacheKey])', $getBody);
        self::assertStringContainsString('self::$processFpcPayloadCache[$cacheKey] = $payload', $getBody);
    }
}
