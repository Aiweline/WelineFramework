<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Dispatcher;

use PHPUnit\Framework\TestCase;
use Weline\Server\Dispatcher\Dispatcher;
use Weline\Server\Dispatcher\PassthroughCore;

class DispatcherHalfClosedConnectionTest extends TestCase
{
    public function testHandleClientDataClosesHalfClosedConnectionWithoutRequest(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $socket = \socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        self::assertInstanceOf(\Socket::class, $socket);
        $connId = \spl_object_id($socket);

        $core = $this->createMock(PassthroughCore::class);
        $core->expects(self::once())->method('forwardToWorker')->with($socket)->willReturn(-2);
        $core->expects(self::once())->method('isClientInputClosed')->with($socket)->willReturn(true);
        $core->expects(self::once())->method('hasBufferedData')->with($socket)->willReturn(false);
        $core->expects(self::once())->method('closeConnection')->with($socket);

        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'clientConnections', [$connId => $socket]);
        $this->setProperty($dispatcher, 'connectionLastActivity', [$connId => \microtime(true) - 1.0]);
        $this->setProperty($dispatcher, 'connectionAcceptTime', [$connId => \microtime(true) - 1.0]);
        $this->setProperty($dispatcher, 'connectionBytes', [$connId => ['in' => 0, 'out' => 0]]);

        $method = new \ReflectionMethod(Dispatcher::class, 'handleClientData');
        $method->setAccessible(true);
        $method->invoke($dispatcher, $socket);

        /** @var array<int, mixed> $clients */
        $clients = $this->getProperty($dispatcher, 'clientConnections');
        self::assertArrayNotHasKey($connId, $clients);
    }

    public function testHandleClientDataKeepsHalfClosedConnectionWhenRequestAlreadyForwarded(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $socket = \socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        self::assertInstanceOf(\Socket::class, $socket);
        $connId = \spl_object_id($socket);

        $core = $this->createMock(PassthroughCore::class);
        $core->expects(self::once())->method('forwardToWorker')->with($socket)->willReturn(-2);
        $core->expects(self::once())->method('isClientInputClosed')->with($socket)->willReturn(true);
        $core->expects(self::never())->method('hasBufferedData');
        $core->expects(self::never())->method('closeConnection');

        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'clientConnections', [$connId => $socket]);
        $this->setProperty($dispatcher, 'connectionLastActivity', [$connId => \microtime(true) - 1.0]);
        $this->setProperty($dispatcher, 'connectionAcceptTime', [$connId => \microtime(true) - 1.0]);
        $this->setProperty($dispatcher, 'connectionBytes', [$connId => ['in' => 128, 'out' => 0]]);

        $method = new \ReflectionMethod(Dispatcher::class, 'handleClientData');
        $method->setAccessible(true);
        $method->invoke($dispatcher, $socket);

        /** @var array<int, mixed> $clients */
        $clients = $this->getProperty($dispatcher, 'clientConnections');
        self::assertArrayHasKey($connId, $clients);

        @\socket_close($socket);
    }

    public function testCleanupClosesStaleHalfClosedRequestConnection(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $socket = \socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        self::assertInstanceOf(\Socket::class, $socket);
        $connId = \spl_object_id($socket);

        $core = $this->createMock(PassthroughCore::class);
        $core->expects(self::once())->method('isClientInputClosed')->with($socket)->willReturn(true);
        $core->expects(self::once())->method('hasBufferedData')->with($socket)->willReturn(false);
        $core->expects(self::once())->method('closeConnection')->with($socket);

        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'clientConnections', [$connId => $socket]);
        $this->setProperty($dispatcher, 'connectionLastActivity', [$connId => \microtime(true) - 31.0]);
        $this->setProperty($dispatcher, 'connectionAcceptTime', [$connId => \microtime(true) - 31.0]);
        $this->setProperty($dispatcher, 'connectionBytes', [$connId => ['in' => 128, 'out' => 0]]);
        $this->setProperty($dispatcher, 'lastConnectionCleanup', 0);
        $this->setProperty($dispatcher, 'connectionCleanupInterval', 0);
        $this->setProperty($dispatcher, 'clientHalfClosedIdleTimeoutSec', 5.0);
        $this->setProperty($dispatcher, 'clientHalfClosedRequestIdleTimeoutSec', 30.0);
        $this->setProperty($dispatcher, 'connectionTimeout', 300);
        $this->setProperty($dispatcher, 'isDevMode', false);
        $this->setProperty($dispatcher, 'banLogThrottle', []);

        $method = new \ReflectionMethod(Dispatcher::class, 'cleanupExpiredConnections');
        $method->setAccessible(true);
        $method->invoke($dispatcher);

        /** @var array<int, mixed> $clients */
        $clients = $this->getProperty($dispatcher, 'clientConnections');
        self::assertArrayNotHasKey($connId, $clients);
    }

    private function newDispatcherWithoutConstructor(): Dispatcher
    {
        $reflector = new \ReflectionClass(Dispatcher::class);
        /** @var Dispatcher $dispatcher */
        $dispatcher = $reflector->newInstanceWithoutConstructor();
        return $dispatcher;
    }

    private function setProperty(object $target, string $name, mixed $value): void
    {
        $property = new \ReflectionProperty($target, $name);
        $property->setAccessible(true);
        $property->setValue($target, $value);
    }

    private function getProperty(object $target, string $name): mixed
    {
        $property = new \ReflectionProperty($target, $name);
        $property->setAccessible(true);
        return $property->getValue($target);
    }
}
