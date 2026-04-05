<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Shared\Connection;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Server\Shared\Connection\ConnectionPoolManager;
use Weline\Server\Shared\Contract\PooledConnectionInterface;

final class ConnectionPoolManagerFiberLeaseTest extends TestCase
{
    protected function tearDown(): void
    {
        ConnectionPoolManager::discardPool('127.0.0.1', 47381, 'lease_test.token');
        ConnectionPoolManager::discardPool('127.0.0.1', 47382, 'lease_test2.token');
        parent::tearDown();
    }

    public function testReleaseFromWrongFiberContextInvalidatesConnection(): void
    {
        $poolMgr = ConnectionPoolManager::getInstance('127.0.0.1', 47381, [
            'min_idle' => 0,
            'max_size' => 1,
            'token_file_name' => 'lease_test.token',
        ]);

        $mockConn = $this->createMock(PooledConnectionInterface::class);
        $mockConn->expects(self::once())->method('close');

        $poolProp = (new ReflectionClass(ConnectionPoolManager::class))->getProperty('pool');
        $poolProp->setAccessible(true);
        $poolProp->setValue($poolMgr, [
            [
                'conn' => $mockConn,
                'busy' => true,
                'last_used' => \microtime(true),
                'lease_fiber_id' => 424242,
            ],
        ]);

        $poolMgr->release($mockConn);

        self::assertSame([], $poolProp->getValue($poolMgr));
    }

    public function testReleaseWhenLeaseMatchesMainThread(): void
    {
        $poolMgr = ConnectionPoolManager::getInstance('127.0.0.1', 47382, [
            'min_idle' => 0,
            'max_size' => 1,
            'token_file_name' => 'lease_test2.token',
        ]);

        $mockConn = $this->createMock(PooledConnectionInterface::class);
        $mockConn->expects(self::never())->method('close');

        $poolProp = (new ReflectionClass(ConnectionPoolManager::class))->getProperty('pool');
        $poolProp->setAccessible(true);
        $poolProp->setValue($poolMgr, [
            [
                'conn' => $mockConn,
                'busy' => true,
                'last_used' => \microtime(true),
                'lease_fiber_id' => 0,
            ],
        ]);

        $poolMgr->release($mockConn);

        $after = $poolProp->getValue($poolMgr);
        self::assertCount(1, $after);
        self::assertFalse($after[0]['busy']);
        self::assertNull($after[0]['lease_fiber_id']);
    }
}
