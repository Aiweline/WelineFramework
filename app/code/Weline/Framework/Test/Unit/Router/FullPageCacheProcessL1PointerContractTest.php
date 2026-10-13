<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Router;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Router\FullPageCacheCoordinator;
use Weline\Framework\Runtime\FullPageCacheReclaimableAdapter;

final class FullPageCacheProcessL1PointerContractTest extends TestCase
{
    public function testProcessL1PointerOnlyAndSoftCompactApisExist(): void
    {
        $src = (string)\file_get_contents(
            (string)(new \ReflectionClass(FullPageCacheCoordinator::class))->getFileName()
        );
        self::assertStringContainsString('processL1PointerOnlyPayload', $src);
        self::assertStringContainsString('PROCESS_FPC_INLINE_MAX_BYTES', $src);
        self::assertStringContainsString('softCompactProcessCache', $src);
        self::assertStringContainsString('processPayloadTotalBytes', $src);
        self::assertStringContainsString('hydrateSharedPayload($payload)', $src);
    }

    public function testReclaimableAdapterSoftCompactUsesRealBytes(): void
    {
        $src = (string)\file_get_contents(
            (string)(new \ReflectionClass(FullPageCacheReclaimableAdapter::class))->getFileName()
        );
        self::assertStringContainsString('processPayloadTotalBytes', $src);
        self::assertStringContainsString('softCompactProcessCache', $src);
        self::assertStringContainsString('function estimateBytes()', $src);
        self::assertStringContainsString('function compact()', $src);
    }

    public function testSoftCompactIsCallable(): void
    {
        FullPageCacheCoordinator::clearProcessCache();
        self::assertSame(0, FullPageCacheCoordinator::processPayloadTotalBytes());
        self::assertSame(0, FullPageCacheCoordinator::softCompactProcessCache());
    }
}
