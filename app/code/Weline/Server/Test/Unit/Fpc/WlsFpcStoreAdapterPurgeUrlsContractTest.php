<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Fpc;

use PHPUnit\Framework\TestCase;

final class WlsFpcStoreAdapterPurgeUrlsContractTest extends TestCase
{
    public function testPurgeUrlsDelegatesToCoordinator(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3)
            . '/extends/module/Weline_Framework/Fpc/Store/WlsFpcStoreAdapter.php'
        );
        self::assertStringContainsString('FullPageCacheCoordinator::purgeUrls', $src);
        self::assertStringContainsString('router-fpc-path-index', $src);
        self::assertStringContainsString('RecursiveDirectoryIterator', $src);
    }
}
