<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Service\WlsDomainPoolSyncService;

final class WlsDomainPoolSyncServiceTest extends TestCase
{
    public function testSkipsNonManagedDomainRegistration(): void
    {
        $service = new WlsDomainPoolSyncService(new DomainPool());

        $result = $service->syncFromWlsRegistration('shop.example.com', '127.0.0.1', 'added');

        self::assertTrue($result['skipped']);
        self::assertSame(0, $result['pool_id']);
    }

    public function testEnsurePoolEntryIfMissingSkipsLoopbackHost(): void
    {
        $service = new WlsDomainPoolSyncService(new DomainPool());

        $result = $service->ensurePoolEntryIfMissing('127.0.0.1');

        self::assertTrue($result['skipped']);
    }
}
