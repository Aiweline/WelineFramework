<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Dispatcher;

use PHPUnit\Framework\TestCase;
use Weline\Server\Dispatcher\Dispatcher;
use Weline\Server\Dispatcher\PassthroughCore;

class DispatcherMaintenanceFallbackRoutingTest extends TestCase
{
    public function testTryRouteToMaintenanceWorkerRetriesAndRegistersConnection(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->createMock(PassthroughCore::class);

        $core->expects(self::once())
            ->method('getWorkerCount')
            ->willReturn(0);

        $core->expects(self::exactly(2))
            ->method('handleNewConnection')
            ->willReturnOnConsecutiveCalls(false, true);

        $core->expects(self::once())
            ->method('getConnectionWorkerPort')
            ->willReturn(19001);

        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'maintenanceFallbackActive', true);
        $this->setProperty($dispatcher, 'maintenanceTakeoverRetryTicks', 2);

        $socket = \tmpfile();
        self::assertIsResource($socket);

        $method = new \ReflectionMethod(Dispatcher::class, 'tryRouteToMaintenanceWorker');
        $method->setAccessible(true);
        $ok = $method->invoke($dispatcher, $socket, '127.0.0.1', 9527);

        self::assertTrue($ok);
        self::assertSame(1, $this->getProperty($dispatcher, 'requestCount'));

        /** @var array<int, mixed> $clientConnections */
        $clientConnections = $this->getProperty($dispatcher, 'clientConnections');
        self::assertArrayHasKey(9527, $clientConnections);
        self::assertSame($socket, $clientConnections[9527]);

        \fclose($socket);
    }

    public function testTryRouteToMaintenanceWorkerSkipsWhenFallbackNotApplicable(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->createMock(PassthroughCore::class);

        $core->expects(self::never())->method('handleNewConnection');
        $core->expects(self::once())->method('getWorkerHealthSummary')->willReturn([
            'total' => 1,
            'healthy' => 1,
            'unhealthy' => 0,
            'saturated' => 0,
        ]);
        $core->method('getMaintenanceWorkerPorts')->willReturn([]);

        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'maintenanceFallbackActive', false);
        $this->setProperty($dispatcher, 'startupProtectionEnabled', false);

        $socket = \tmpfile();
        self::assertIsResource($socket);

        $method = new \ReflectionMethod(Dispatcher::class, 'tryRouteToMaintenanceWorker');
        $method->setAccessible(true);
        $ok = $method->invoke($dispatcher, $socket, '127.0.0.1', 9528);

        self::assertFalse($ok);
        self::assertSame(0, $this->getProperty($dispatcher, 'requestCount'));

        \fclose($socket);
    }

    public function testTryRouteToMaintenanceWorkerRunsWhenRegisteredMaintenancePortsEvenIfFallbackOff(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->createMock(PassthroughCore::class);

        $core->expects(self::once())
            ->method('getMaintenanceWorkerPorts')
            ->willReturn([19002]);

        $core->expects(self::exactly(2))
            ->method('handleNewConnection')
            ->willReturnOnConsecutiveCalls(false, true);

        $core->expects(self::once())
            ->method('getConnectionWorkerPort')
            ->willReturn(19002);

        $core->expects(self::once())
            ->method('getWorkerHealthSummary')
            ->willReturn([
                'total' => 1,
                'healthy' => 1,
                'unhealthy' => 0,
                'saturated' => 0,
            ]);

        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'maintenanceFallbackActive', false);
        $this->setProperty($dispatcher, 'startupProtectionEnabled', false);
        $this->setProperty($dispatcher, 'maintenanceTakeoverRetryTicks', 2);

        $socket = \tmpfile();
        self::assertIsResource($socket);

        $method = new \ReflectionMethod(Dispatcher::class, 'tryRouteToMaintenanceWorker');
        $method->setAccessible(true);
        $ok = $method->invoke($dispatcher, $socket, '127.0.0.1', 9529);

        self::assertTrue($ok);

        \fclose($socket);
    }

    public function testStartupProtectionIsPreferredBeforeMaintenanceRoutingWhenPoolIsEmpty(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->createMock(PassthroughCore::class);

        $core->expects(self::exactly(2))
            ->method('getWorkerCount')
            ->willReturn(0);
        $core->method('getMaintenanceWorkerPorts')->willReturn([]);

        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'maintenanceFallbackActive', true);

        $method = new \ReflectionMethod(Dispatcher::class, 'shouldRespondWithStartupProtectionBeforeMaintenanceRouting');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($dispatcher));
    }

    public function testStartupProtectionSkippedWhenPoolEmptyButMaintenanceWorkerPortsRegistered(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->createMock(PassthroughCore::class);

        $core->method('getWorkerCount')->willReturn(0);
        $core->method('getMaintenanceWorkerPorts')->willReturn([19003]);

        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'maintenanceFallbackActive', true);

        $method = new \ReflectionMethod(Dispatcher::class, 'shouldRespondWithStartupProtectionBeforeMaintenanceRouting');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($dispatcher));
    }

    public function testStartupProtectionIsNotPreferredWhenPoolHasMaintenancePort(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->createMock(PassthroughCore::class);

        $core->expects(self::once())
            ->method('getWorkerCount')
            ->willReturn(1);
        $core->expects(self::once())
            ->method('getWorkerHealthSummary')
            ->willReturn([
                'total' => 1,
                'healthy' => 1,
                'unhealthy' => 0,
                'saturated' => 0,
            ]);
        $core->method('getMaintenanceWorkerPorts')->willReturn([]);

        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'maintenanceFallbackActive', true);
        $this->setProperty($dispatcher, 'startupProtectionEnabled', false);

        $method = new \ReflectionMethod(Dispatcher::class, 'shouldRespondWithStartupProtectionBeforeMaintenanceRouting');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($dispatcher));
    }

    public function testFriendlyStartupMaintenancePageContainsFriendlyMessage(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();

        $method = new \ReflectionMethod(Dispatcher::class, 'buildFriendlyStartupMaintenancePage');
        $method->setAccessible(true);
        $response = (string) $method->invoke($dispatcher);

        self::assertStringContainsString('HTTP/1.1 503 Service Unavailable', $response);
        self::assertStringContainsString('WLS正在启动中', $response);
        self::assertStringContainsString('业务 Worker 正在初始化', $response);
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
