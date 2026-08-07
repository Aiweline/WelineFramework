<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Shared\Connection;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Server\Session\Client\SessionClient;
use Weline\Server\Shared\Client\SharedStateClient;
use Weline\Server\Shared\Connection\ConnectionPoolManager;
use Weline\Server\Shared\Connection\PooledConnection;

final class ConnectionPoolManagerOptionsMergeTest extends TestCase
{
    protected function tearDown(): void
    {
        ConnectionPoolManager::discardPool('127.0.0.1', 47383, 'merge_timeout.token');
        ConnectionPoolManager::discardPool('127.0.0.1', 47384, 'authority.token');
        ConnectionPoolManager::discardPool('127.0.0.1', 47385, 'session-client-authority.token');
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

    public function testAuthorityInstanceIsPartOfPoolIdentityAndReachesConnection(): void
    {
        $first = ConnectionPoolManager::getInstance('127.0.0.1', 47384, [
            'token_file_name' => 'authority.token',
            'token_authority_instance' => 'shared-session-alpha',
            'service_type' => 'Session',
            'min_idle' => 0,
        ]);
        $second = ConnectionPoolManager::getInstance('127.0.0.1', 47384, [
            'token_file_name' => 'authority.token',
            'token_authority_instance' => 'shared-session-beta',
            'service_type' => 'Session',
            'min_idle' => 0,
        ]);

        self::assertNotSame($first, $second, 'Different capability authorities must not share a pool.');
        $method = (new ReflectionClass(ConnectionPoolManager::class))->getMethod('createConnection');
        $method->setAccessible(true);
        $connection = $method->invoke($first);
        self::assertInstanceOf(PooledConnection::class, $connection);
        $authorityProperty = (new ReflectionClass(PooledConnection::class))
            ->getProperty('tokenAuthorityInstance');
        $authorityProperty->setAccessible(true);
        self::assertSame('shared-session-alpha', $authorityProperty->getValue($connection));
    }

    public function testImplicitAndExplicitDefaultAuthorityReuseSamePool(): void
    {
        $implicit = ConnectionPoolManager::getInstance('127.0.0.1', 47384, [
            'token_file_name' => 'authority.token',
            'service_type' => 'Session',
            'min_idle' => 0,
        ]);
        $explicit = ConnectionPoolManager::getInstance('127.0.0.1', 47384, [
            'token_file_name' => 'authority.token',
            'token_authority_instance' => 'session_server@loopback:47384',
            'service_type' => 'Session',
            'min_idle' => 0,
        ]);

        self::assertSame($implicit, $explicit);
    }

    public function testSessionClientForwardsExplicitCapabilityAuthorityToPool(): void
    {
        $client = new SessionClient('127.0.0.1', 47385, [
            'token_file_name' => 'session-client-authority.token',
            'token_authority_instance' => 'shared-session-controlled',
            'pool_min_idle' => 0,
        ]);
        $stateProperty = (new ReflectionClass(SessionClient::class))->getProperty('stateClient');
        $stateProperty->setAccessible(true);
        $stateClient = $stateProperty->getValue($client);
        self::assertInstanceOf(SharedStateClient::class, $stateClient);
        $poolProperty = (new ReflectionClass(SharedStateClient::class))->getProperty('pool');
        $poolProperty->setAccessible(true);
        $pool = $poolProperty->getValue($stateClient);
        self::assertInstanceOf(ConnectionPoolManager::class, $pool);
        $optionsProperty = (new ReflectionClass(ConnectionPoolManager::class))->getProperty('options');
        $optionsProperty->setAccessible(true);
        $options = $optionsProperty->getValue($pool);

        self::assertSame(
            'shared-session-controlled',
            $options['token_authority_instance'] ?? null,
        );
    }
}
