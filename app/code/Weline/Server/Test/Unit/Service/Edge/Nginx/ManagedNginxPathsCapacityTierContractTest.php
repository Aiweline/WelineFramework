<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Capacity\HostCapacityTier;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPaths;

final class ManagedNginxPathsCapacityTierContractTest extends TestCase
{
    protected function tearDown(): void
    {
        @\putenv('WLS_CAPACITY_TIER_FORCE');
        unset($_ENV['WLS_CAPACITY_TIER_FORCE'], $_SERVER['WLS_CAPACITY_TIER_FORCE']);
        @\putenv('WLS_HOST_LIMIT_MB');
        unset($_ENV['WLS_HOST_LIMIT_MB'], $_SERVER['WLS_HOST_LIMIT_MB']);
        parent::tearDown();
    }

    public function testTinyForceClampsConnectionsAndKeepalive(): void
    {
        @\putenv('WLS_CAPACITY_TIER_FORCE=tiny');
        $_ENV['WLS_CAPACITY_TIER_FORCE'] = 'tiny';
        $_SERVER['WLS_CAPACITY_TIER_FORCE'] = 'tiny';

        $paths = new ManagedNginxPaths('/tmp/wls-nginx-capacity-tier', [
            'worker_connections' => 32768,
            'upstream_keepalive' => 256,
        ]);
        self::assertSame(4096, $paths->workerConnections());
        self::assertSame(32, $paths->upstreamKeepalive());
        self::assertSame(4096, HostCapacityTier::clampNginxWorkerConnections(32768, null, ['force' => 'tiny']));
    }
}
