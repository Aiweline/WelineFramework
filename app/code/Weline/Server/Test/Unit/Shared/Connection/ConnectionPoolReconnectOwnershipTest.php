<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Shared\Connection;

use PHPUnit\Framework\TestCase;
use Weline\Server\Shared\Connection\ConnectionPoolManager;
use Weline\Server\Shared\Contract\PooledConnectionInterface;

final class ConnectionPoolReconnectOwnershipTest extends TestCase
{
    public function testFailedReconnectReleasesReservationForNextBorrower(): void
    {
        $pool = ConnectionPoolManager::getInstance('127.0.0.1', 47390, [
            'min_idle' => 0, 'max_size' => 1, 'token_file_name' => 'isolated_reconnect_failure.token',
        ]);
        $connection = $this->createMock(PooledConnectionInterface::class);
        $connection->method('isConnected')->willReturn(false);
        $connection->method('connect')->willReturnOnConsecutiveCalls(false, true);
        $property = (new \ReflectionClass($pool))->getProperty('pool');
        $property->setValue($pool, [[
            'conn' => $connection, 'busy' => false, 'last_used' => hrtime(true) / 1e9, 'lease_fiber_id' => null,
        ]]);
        try {
            self::assertNull($pool->acquire(0.001));
            self::assertFalse($property->getValue($pool)[0]['busy']);
            self::assertSame($connection, $pool->acquire(0.35));
            $pool->release($connection);
            self::assertFalse($property->getValue($pool)[0]['busy']);
        } finally {
            ConnectionPoolManager::discardPool('127.0.0.1', 47390, 'isolated_reconnect_failure.token');
        }
    }

    public function testReconnectingSlotCannotBeLeasedBySecondFiber(): void
    {
        $pool = ConnectionPoolManager::getInstance('127.0.0.1', 47389, [
            'min_idle' => 0, 'max_size' => 1, 'token_file_name' => 'isolated_reconnect_ownership.token',
        ]);
        $connection = $this->createMock(PooledConnectionInterface::class);
        $connected = false;
        $calls = 0;
        $connection->method('isConnected')->willReturnCallback(static function () use (&$connected): bool { return $connected; });
        $connection->method('connect')->willReturnCallback(static function () use (&$calls, &$connected): bool {
            if (++$calls === 1) {
                \Fiber::suspend('connect_wait');
            }
            $connected = true;
            return true;
        });
        $property = (new \ReflectionClass($pool))->getProperty('pool');
        $property->setValue($pool, [[
            'conn' => $connection, 'busy' => false, 'last_used' => hrtime(true) / 1e9, 'lease_fiber_id' => null,
        ]]);
        $first = new \Fiber(static fn() => $pool->acquire(0.02));
        $second = new \Fiber(static fn() => $pool->acquire(0.002));
        try {
            self::assertSame('connect_wait', $first->start());
            $second->start();
            $first->resume();
            self::assertSame($connection, $first->getReturn());
            self::assertTrue($second->getReturn() === null, 'The reconnecting socket already belongs to the first Fiber.');
            self::assertSame(1, $calls);
        } finally {
            ConnectionPoolManager::discardPool('127.0.0.1', 47389, 'isolated_reconnect_ownership.token');
        }
    }
}
