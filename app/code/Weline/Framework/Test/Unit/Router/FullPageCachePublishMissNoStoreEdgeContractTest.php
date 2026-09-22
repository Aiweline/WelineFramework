<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Router;

use PHPUnit\Framework\TestCase;

/**
 * After a successful FPC publish, the live SSR response still says MISS for
 * this request. It must carry Cache-Control: private, no-store so managed Nginx
 * edge does not persist MISS headers for cookieless public probes.
 */
final class FullPageCachePublishMissNoStoreEdgeContractTest extends TestCase
{
    public function testPublishResponseSetsNoStoreAfterSuccessfulStoreSnapshot(): void
    {
        $source = \file_get_contents(
            BP . 'app/code/Weline/Framework/Router/FullPageCacheCoordinator.php'
        );
        self::assertIsString($source);
        self::assertStringContainsString(
            "setHeader('Cache-Control', 'private, no-store, max-age=0, must-revalidate')",
            $source,
        );
        self::assertStringContainsString(
            'must not persist the MISS headers',
            $source,
        );
        $publishPos = \strpos($source, 'function publishResponse(');
        $noStorePos = \strpos($source, "setHeader('Cache-Control', 'private, no-store, max-age=0, must-revalidate')");
        $setProcessPos = \strpos($source, 'setProcessCachedPayload(');
        self::assertNotFalse($publishPos);
        self::assertNotFalse($noStorePos);
        self::assertNotFalse($setProcessPos);
        self::assertGreaterThan(
            $setProcessPos,
            $noStorePos,
            'no-store must be applied after payload headers were snapshotted for storage',
        );
    }
}
