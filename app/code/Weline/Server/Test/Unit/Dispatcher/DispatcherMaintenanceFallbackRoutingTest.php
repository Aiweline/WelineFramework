<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Dispatcher;

use PHPUnit\Framework\TestCase;
use Weline\Server\Dispatcher\Dispatcher;
use Weline\Server\Dispatcher\PassthroughCore;
use Weline\Server\IPC\ChildControl\ChildControlClientInterface;
use Weline\Server\IPC\ControlMessage;

class DispatcherMaintenanceFallbackRoutingTest extends TestCase
{
    public function testTryRouteToMaintenanceWorkerRetriesAndRegistersConnection(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->createMock(PassthroughCore::class);

        $core->expects(self::atLeastOnce())
            ->method('getWorkerCount')
            ->willReturn(0);

        $core->expects(self::atLeastOnce())
            ->method('getMaintenanceWorkerPorts')
            ->willReturn([19001]);

        $core->expects(self::atLeastOnce())
            ->method('getWorkerHealthSummary')
            ->willReturn([
                'total' => 0,
                'healthy' => 0,
                'unhealthy' => 0,
                'saturated' => 0,
            ]);

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

        $core->expects(self::atLeastOnce())
            ->method('getMaintenanceWorkerPorts')
            ->willReturn([19002]);

        $core->expects(self::exactly(2))
            ->method('handleNewConnection')
            ->willReturnOnConsecutiveCalls(false, true);

        $core->expects(self::once())
            ->method('getConnectionWorkerPort')
            ->willReturn(19002);

        $core->expects(self::atLeastOnce())
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

    public function testImmediateStartup503WaitsForRegisteredMaintenanceWorkers(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->createMock(PassthroughCore::class);

        $core->expects(self::once())
            ->method('lastNewConnectionEndedInAllWorkersDown')
            ->willReturn(true);
        $core->expects(self::once())
            ->method('getMaintenanceWorkerPorts')
            ->willReturn([19004]);
        $core->expects(self::never())->method('getWorkerHealthSummary');
        $core->expects(self::never())->method('getWorkerCount');

        $this->setProperty($dispatcher, 'passthroughCore', $core);

        $method = new \ReflectionMethod(Dispatcher::class, 'shouldReturnStartup503Immediately');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($dispatcher));
    }

    public function testTlsHandshakePeekIsDetectedAsTlsTraffic(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();

        $method = new \ReflectionMethod(Dispatcher::class, 'isTlsHandshakePeek');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($dispatcher, "\x16\x03\x01\x00"));
        self::assertFalse($method->invoke($dispatcher, 'GET / HT'));
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

    public function testAllWorkersUnavailableFloatingAlertIsInjectedOnlyInDevMode(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();

        $build = new \ReflectionMethod(Dispatcher::class, 'buildFriendlyStartupMaintenancePage');
        $build->setAccessible(true);
        $basePage = (string)$build->invoke($dispatcher);
        self::assertStringNotContainsString('wls-dev-alert', $basePage);

        $this->setProperty($dispatcher, 'fallbackMaintenancePage', $basePage);
        $this->setProperty($dispatcher, 'isDevMode', false);

        $resolve = new \ReflectionMethod(Dispatcher::class, 'resolveFallbackMaintenancePage');
        $resolve->setAccessible(true);
        $nonDevPage = (string)$resolve->invoke($dispatcher, true);
        self::assertStringNotContainsString('wls-dev-alert', $nonDevPage);
        self::assertStringNotContainsString('当前所有 Worker 不可用', $nonDevPage);

        $this->setProperty($dispatcher, 'isDevMode', true);
        $devPage = (string)$resolve->invoke($dispatcher, true);

        self::assertStringContainsString('wls-dev-alert', $devPage);
        self::assertStringContainsString('当前所有 Worker 不可用', $devPage);
        self::assertStringContainsString('#dc2626', $devPage);
    }

    public function testFormatMaintenanceRoutingContextIncludesFallbackStateAndCandidates(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->createMock(PassthroughCore::class);

        $core->method('getWorkerCount')->willReturn(0);
        $core->method('getMaintenanceWorkerPorts')->willReturn([19002, 19003]);
        $core->method('getWorkerHealthSummary')->willReturn([
            'healthy' => 0,
            'total' => 0,
        ]);

        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'maintenanceFallbackActive', true);

        $method = new \ReflectionMethod(Dispatcher::class, 'formatMaintenanceRoutingContext');
        $method->setAccessible(true);
        $context = (string) $method->invoke($dispatcher);

        self::assertStringContainsString('maintenance_fallback_active=true', $context);
        self::assertStringContainsString('worker_pool_size=0', $context);
        self::assertStringContainsString('maintenance_candidates=19002,19003', $context);
        self::assertStringContainsString('health=0/0', $context);
    }

    public function testUpdateMaintenanceFallbackStateSwitchesFlag(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->createMock(PassthroughCore::class);

        $core->method('getWorkerCount')->willReturn(0);
        $core->method('getMaintenanceWorkerPorts')->willReturn([]);
        $core->method('getWorkerHealthSummary')->willReturn([
            'healthy' => 0,
            'total' => 0,
        ]);

        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'maintenanceFallbackActive', false);

        $method = new \ReflectionMethod(Dispatcher::class, 'updateMaintenanceFallbackState');
        $method->setAccessible(true);
        $method->invoke($dispatcher, true, 'SET_WORKER_POOL accepted=0, rejected=0');

        self::assertTrue((bool) $this->getProperty($dispatcher, 'maintenanceFallbackActive'));
    }

    public function testReportAllWorkersUnavailableToMasterIsThrottledAndCarriesPoolContext(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->createMock(PassthroughCore::class);
        $core->method('getWorkerPorts')->willReturn([16896, 16895]);
        $core->method('getMaintenanceWorkerPorts')->willReturn([16995]);
        $core->method('getMaintenancePort')->willReturn(0);
        $core->method('getWorkerHealthSummary')->willReturn([
            'healthy' => 0,
            'total' => 2,
        ]);

        $client = new class implements ChildControlClientInterface {
            public array $sent = [];

            public function connect(string $host, int $port): bool { return true; }
            public function isConnected(): bool { return true; }
            public function getSocket() { return null; }
            public function hasPendingWrites(): bool { return false; }
            public function hasReceivedShutdown(): bool { return false; }
            public function isReadyStateConfirmed(): bool { return true; }
            public function onMessage(callable $handler): void {}
            public function onDisconnect(callable $handler): void {}
            public function setVerboseLog(bool $verbose): void {}
            public function setSelfTag(string $tag): void {}
            public function register(string $role, int $pid, int $port = 0, int $workerId = 0, int $epoch = 0, string $launchId = '', string $processKind = 'framework', string $moduleCode = '', string $instanceCode = '', string $msgId = ''): bool { return true; }
            public function rememberRegistration(string $role, int $pid, int $port = 0, int $workerId = 0, int $epoch = 0, string $launchId = '', string $processKind = 'framework', string $moduleCode = '', string $instanceCode = '', string $msgId = ''): void {}
            public function markReadyState(bool $isReady = true): void {}
            public function sendReady(string $role = '', int $workerId = 0, int $port = 0, int $epoch = 0, string $launchId = '', string $msgId = ''): bool { return true; }
            public function sendWorkerLoopStarted(int $workerId, int $port, int $pid): bool { return true; }
            public function sendDrainingComplete(int $workerId = 0, int $port = 0, string $msgId = ''): bool { return true; }
            public function sendStatusReport(int $connections, int $memory, int $requests): bool { return true; }
            public function sendLogLine(string $line, string $level, string $processTag): bool { return true; }
            public function send(string $message, bool $disconnectOnWriteOverflow = true): bool { $this->sent[] = $message; return true; }
            public function flushPendingWrites(float $timeBudgetSec = 0.0): bool { return true; }
            public function handleReadable(): array { return []; }
            public function handleWritable(): bool { return true; }
            public function tryReconnect(): bool { return true; }
            public function close(): void {}
        };

        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'ipcClient', $client);
        $this->setProperty($dispatcher, 'instanceName', 'default');
        $this->setProperty($dispatcher, 'port', 9580);

        $method = new \ReflectionMethod(Dispatcher::class, 'reportAllWorkersUnavailableToMaster');
        $method->setAccessible(true);
        $method->invoke($dispatcher);
        $method->invoke($dispatcher);

        self::assertCount(1, $client->sent);
        $alert = \json_decode(\trim($client->sent[0]), true);
        self::assertSame(ControlMessage::TYPE_DISPATCHER_ALERT, $alert['type'] ?? null);
        self::assertSame('default', $alert['instance'] ?? null);
        self::assertSame('all_workers_unavailable', $alert['reason'] ?? null);
        self::assertSame(ControlMessage::ROLE_WORKER, $alert['subject_role'] ?? null);
        self::assertSame([16895, 16896], $alert['business_pool'] ?? null);
        self::assertSame([16995], $alert['maintenance_candidates'] ?? null);
        self::assertSame(0, $alert['maintenance_port'] ?? null);
        self::assertSame(0, $alert['healthy'] ?? null);
        self::assertSame(2, $alert['total'] ?? null);
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
