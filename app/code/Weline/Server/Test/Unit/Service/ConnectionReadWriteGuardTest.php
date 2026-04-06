<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\ConnectionReadWriteGuard;

final class ConnectionReadWriteGuardTest extends TestCase
{
    public function testDefersReadWhenConnectionHasPendingWriteBuffer(): void
    {
        self::assertTrue(
            ConnectionReadWriteGuard::shouldDeferRead(
                [12 => "HTTP/1.1 200 OK\r\n\r\nbody"],
                [],
                12,
                false
            )
        );
    }

    public function testDefersReadWhenConnectionIsPendingClose(): void
    {
        self::assertTrue(
            ConnectionReadWriteGuard::shouldDeferRead(
                [],
                [12 => true],
                12,
                false
            )
        );
    }

    public function testDefersReadWhenConnectionStillHasActiveInFlightRequest(): void
    {
        self::assertTrue(
            ConnectionReadWriteGuard::shouldDeferRead(
                [],
                [],
                12,
                true
            )
        );
    }

    public function testAllowsReadWhenNoPendingWriteOrCloseExists(): void
    {
        self::assertFalse(
            ConnectionReadWriteGuard::shouldDeferRead(
                [12 => ''],
                [],
                12,
                false
            )
        );
    }
}
