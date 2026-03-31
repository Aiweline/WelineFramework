<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Dispatcher;

use PHPUnit\Framework\TestCase;
use Weline\Server\Dispatcher\PassthroughCore;

class PassthroughCoreSeedWorkerPoolTest extends TestCase
{
    private function invokePrivateMethod(object $target, string $method, mixed ...$args): mixed
    {
        $caller = function (string $methodName, array $invokeArgs): mixed {
            return $this->{$methodName}(...$invokeArgs);
        };
        $bound = \Closure::bind($caller, $target, $target);
        self::assertInstanceOf(\Closure::class, $bound);
        return $bound($method, $args);
    }

    private function getPrivateProperty(object $target, string $property): mixed
    {
        $reader = function (string $propertyName): mixed {
            return $this->{$propertyName};
        };
        $bound = \Closure::bind($reader, $target, $target);
        self::assertInstanceOf(\Closure::class, $bound);
        return $bound($property);
    }

    /**
     * @return array{0: mixed, 1: mixed, 2: mixed}
     */
    private function createConnectedSocketPair(): array
    {
        $server = \socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        self::assertNotFalse($server);
        self::assertTrue(\socket_bind($server, '127.0.0.1', 0));
        self::assertTrue(\socket_listen($server, 1));
        self::assertTrue(\socket_getsockname($server, $host, $port));

        $client = \socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        self::assertNotFalse($client);
        self::assertTrue(\socket_connect($client, '127.0.0.1', (int)$port));

        $accepted = \socket_accept($server);
        self::assertNotFalse($accepted);
        \socket_set_nonblock($accepted);

        return [$accepted, $client, $server];
    }

    public function testSeedWorkerPoolMatchesWorkerProviderNumbering(): void
    {
        $core = new PassthroughCore('127.0.0.1', 19981, 2);

        self::assertSame([19982, 19983], $core->getWorkerPorts());
        self::assertSame(2, $core->getWorkerCount());
    }

    public function testShouldSpinWaitOnlyWhenWorkerPoolIsEmpty(): void
    {
        $core = new PassthroughCore('127.0.0.1', 19981, 2);
        self::assertFalse($this->invokePrivateMethod($core, 'shouldSpinWaitForWorkerRecovery'));

        $core->setWorkerPorts([]);
        self::assertTrue($this->invokePrivateMethod($core, 'shouldSpinWaitForWorkerRecovery'));
    }

    public function testShouldSpinWaitWhenAllWorkersAreBlacklisted(): void
    {
        $core = new PassthroughCore('127.0.0.1', 19981, 2);
        self::assertFalse($this->invokePrivateMethod($core, 'shouldSpinWaitForWorkerRecovery'));

        $core->blacklistWorker(19982);
        $core->blacklistWorker(19983);
        self::assertTrue($this->invokePrivateMethod($core, 'shouldSpinWaitForWorkerRecovery'));

        $core->unblacklistWorker(19982);
        self::assertFalse($this->invokePrivateMethod($core, 'shouldSpinWaitForWorkerRecovery'));
    }

    public function testBackendPoolReleaseAndAcquireRoundTrip(): void
    {
        $core = new PassthroughCore('127.0.0.1', 19981, 1);
        $core->configure([
            'backend_pool_enabled' => true,
            'backend_pool_max_idle_per_worker' => 2,
            'backend_pool_idle_ttl' => 30,
        ]);

        [$socket, $peer, $server] = $this->createConnectedSocketPair();

        $released = $this->invokePrivateMethod($core, 'releaseWorkerSocketToPool', 19982, $socket);
        self::assertTrue($released);

        $acquired = $this->invokePrivateMethod($core, 'acquireIdleWorkerSocket', 19982);
        self::assertSame($socket, $acquired);

        $this->invokePrivateMethod($core, 'discardWorkerSocket', $acquired);
        @\socket_close($peer);
        @\socket_close($server);
    }

    public function testDisablingBackendPoolClosesIdleSockets(): void
    {
        $core = new PassthroughCore('127.0.0.1', 19981, 1);
        $core->configure([
            'backend_pool_enabled' => true,
            'backend_pool_max_idle_per_worker' => 2,
            'backend_pool_idle_ttl' => 30,
        ]);

        [$socket, $peer, $server] = $this->createConnectedSocketPair();

        self::assertTrue($this->invokePrivateMethod($core, 'releaseWorkerSocketToPool', 19982, $socket));

        $core->configure(['backend_pool_enabled' => false]);
        self::assertSame([], $this->getPrivateProperty($core, 'idleWorkerPool'));
        @\socket_close($peer);
        @\socket_close($server);
    }
}
