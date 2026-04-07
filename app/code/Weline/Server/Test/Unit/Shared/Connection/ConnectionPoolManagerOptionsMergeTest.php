<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Shared\Connection;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Server\Shared\Connection\ConnectionPoolManager;

final class ConnectionPoolManagerOptionsMergeTest extends TestCase
{
    protected function tearDown(): void
    {
        ConnectionPoolManager::discardPool('127.0.0.1', 47383, 'merge_timeout.token');
        parent::tearDown();
    }

    public function testMergeOptionsPrefersLowerConnectAndReadTimeout(): void
    {
        $pool = ConnectionPoolManager::getInstance('127.0.0.1', 47383, [
            'token_file_name' => 'merge_timeout.token',
            'connect_timeout' => 1.2,
            'timeout' => 2.5,
            'min_idle' => 0,
            'max_size' => 1,
        ]);

        ConnectionPoolManager::getInstance('127.0.0.1', 47383, [
            'token_file_name' => 'merge_timeout.token',
            'connect_timeout' => 0.35,
            'timeout' => 0.8,
            'min_idle' => 0,
            'max_size' => 1,
        ]);

        $optionsProperty = (new ReflectionClass(ConnectionPoolManager::class))->getProperty('options');
        $optionsProperty->setAccessible(true);
        $options = $optionsProperty->getValue($pool);

        self::assertSame(0.35, (float)($options['connect_timeout'] ?? 0.0));
        self::assertSame(0.8, (float)($options['timeout'] ?? 0.0));
    }
}
