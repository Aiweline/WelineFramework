<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Supervisor;

use PHPUnit\Framework\TestCase;
use Weline\Server\Supervisor\Client\SupervisorChildClient;
use Weline\Server\Supervisor\Lease\LeaseRegistry;
use Weline\Server\Supervisor\Lease\SlotLease;
use Weline\Server\Supervisor\SupervisorServer;

final class SupervisorMonotonicLeaseTimingTest extends TestCase
{
    public function testLeaseHeartbeatsAndSessionTimeoutsDoNotUseWallClockMicrotime(): void
    {
        foreach ([
            SupervisorServer::class,
            SupervisorChildClient::class,
            LeaseRegistry::class,
            SlotLease::class,
        ] as $class) {
            $source = (string)\file_get_contents((new \ReflectionClass($class))->getFileName());

            self::assertStringNotContainsString('\\microtime(true)', $source, $class);
            self::assertStringContainsString('\\hrtime(true)', $source, $class);
        }
    }
}
