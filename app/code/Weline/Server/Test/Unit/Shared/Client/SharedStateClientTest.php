<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Shared\Client;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Server\Shared\Client\SharedStateClient;
use Weline\Server\Shared\Contract\ConnectionPoolInterface;
use Weline\Server\Shared\Contract\PooledConnectionInterface;

final class SharedStateClientTest extends TestCase
{
    public function testDisconnectDoesNotShutdownProcessLevelPool(): void
    {
        $pool = $this->createMock(ConnectionPoolInterface::class);
        $pool->expects(self::never())->method('shutdown');

        $client = $this->createClientWithPool($pool);
        $client->disconnect();

        self::assertTrue(true);
    }

    public function testShutdownPoolStillClosesUnderlyingPool(): void
    {
        $pool = $this->createMock(ConnectionPoolInterface::class);
        $pool->expects(self::once())->method('shutdown');

        $client = $this->createClientWithPool($pool);
        $client->shutdownPool();
    }

    private function createClientWithPool(ConnectionPoolInterface $pool): SharedStateClient
    {
        $reflection = new ReflectionClass(SharedStateClient::class);
        /** @var SharedStateClient $client */
        $client = $reflection->newInstanceWithoutConstructor();

        $poolProperty = $reflection->getProperty('pool');
        $poolProperty->setAccessible(true);
        $poolProperty->setValue($client, $pool);

        $timeoutProperty = $reflection->getProperty('acquireTimeout');
        $timeoutProperty->setAccessible(true);
        $timeoutProperty->setValue($client, 0.2);

        return $client;
    }
}
