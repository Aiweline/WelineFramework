<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Dispatcher;

use PHPUnit\Framework\TestCase;
use Weline\Server\Dispatcher\Dispatcher;
use Weline\Server\Dispatcher\PassthroughCore;
use Weline\Server\IPC\ChildControl\ChildControlClientInterface;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Supervisor\Client\SupervisorChildClient;
use Weline\Server\Supervisor\Protocol\SupervisorMessage;

class DispatcherDeferredWorkerJobsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\defined('BP')) {
            \define('BP', \getcwd() . \DIRECTORY_SEPARATOR);
        }
        if (!\defined('DS')) {
            \define('DS', \DIRECTORY_SEPARATOR);
        }
    }

    public function testProbeWorkerHealthQueuesDeferredJobInsteadOfBlocking(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->getMockBuilder(PassthroughCore::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['auditWorkerApplicationHealth'])
            ->getMock();

        $core->expects(self::never())->method('auditWorkerApplicationHealth');

        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'running', true);
        $this->setProperty($dispatcher, 'ipcReceivedShutdown', false);
        $this->setProperty($dispatcher, 'lastWorkerProbeTime', 0.0);
        $this->setProperty($dispatcher, 'workerProbeInterval', 0.0);
        $this->setProperty($dispatcher, 'workerHealthAuditEnabled', true);
        $this->setProperty($dispatcher, 'deferredWorkerPoolJobs', []);
        $this->setProperty($dispatcher, 'deferredWorkerPoolFiber', null);

        $method = new \ReflectionMethod(Dispatcher::class, 'probeWorkerHealth');
        $method->setAccessible(true);
        $method->invoke($dispatcher);

        self::assertSame(
            [['type' => 'audit_worker_health']],
            $this->getProperty($dispatcher, 'deferredWorkerPoolJobs')
        );
    }

    public function testAllWorkersUnavailableRecoveryQueuesSingleDeferredAudit(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->getMockBuilder(PassthroughCore::class)
            ->disableOriginalConstructor()
            ->getMock();
        $client = $this->createMock(ChildControlClientInterface::class);
        $client->expects(self::never())->method('send');

        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'ipcClient', $client);
        $this->setProperty($dispatcher, 'deferredWorkerPoolJobs', []);
        $this->setProperty($dispatcher, 'deferredWorkerPoolFiber', null);
        $this->setProperty($dispatcher, 'deferredWorkerPoolFiberKind', null);
        $this->setProperty($dispatcher, 'spinWaitTickInProgress', true);
        $this->setProperty($dispatcher, 'lastAllWorkersUnavailableRecoveryAt', 0.0);
        $this->setProperty($dispatcher, 'lastWorkerProbeTime', 123.0);

        $method = new \ReflectionMethod(Dispatcher::class, 'scheduleAllWorkersUnavailableRecovery');
        $method->setAccessible(true);
        $method->invoke($dispatcher, 'unit-test');
        $method->invoke($dispatcher, 'unit-test-duplicate');

        self::assertSame(0.0, $this->getProperty($dispatcher, 'lastWorkerProbeTime'));
        self::assertSame(
            [[
                'type' => 'audit_worker_health',
                'source' => 'unit-test',
            ]],
            $this->getProperty($dispatcher, 'deferredWorkerPoolJobs')
        );
    }

    public function testPumpDeferredWorkerPoolJobsProcessesDeferredHealthAuditFiber(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->getMockBuilder(PassthroughCore::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setWarmupCooperativeYield', 'auditWorkerApplicationHealth'])
            ->getMock();

        $yieldCallbacks = [];
        $core->expects(self::exactly(2))
            ->method('setWarmupCooperativeYield')
            ->willReturnCallback(function (?callable $yield) use (&$yieldCallbacks): void {
                $yieldCallbacks[] = $yield;
            });
        $core->expects(self::once())
            ->method('auditWorkerApplicationHealth')
            ->willReturn(['healthy' => [19001], 'failed' => []]);

        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'deferredWorkerPoolJobs', [['type' => 'audit_worker_health']]);
        $this->setProperty($dispatcher, 'deferredWorkerPoolFiber', null);
        $this->setProperty($dispatcher, 'deferredWorkerPoolFiberKind', null);

        $method = new \ReflectionMethod(Dispatcher::class, 'pumpDeferredWorkerPoolJobs');
        $method->setAccessible(true);
        $method->invoke($dispatcher);

        self::assertCount(2, $yieldCallbacks);
        self::assertInstanceOf(\Closure::class, $yieldCallbacks[0]);
        self::assertNull($yieldCallbacks[1]);
        self::assertNull($this->getProperty($dispatcher, 'deferredWorkerPoolFiber'));
        self::assertNull($this->getProperty($dispatcher, 'deferredWorkerPoolFiberKind'));
        self::assertSame([], $this->getProperty($dispatcher, 'deferredWorkerPoolJobs'));
    }

    public function testPumpDeferredWorkerPoolJobsProcessesHomepageWarmupFiber(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->getMockBuilder(PassthroughCore::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setWarmupCooperativeYield', 'warmupJoinedWorkersViaHomepage'])
            ->getMock();

        $core->expects(self::never())->method('setWarmupCooperativeYield');
        $core->expects(self::never())->method('warmupJoinedWorkersViaHomepage');

        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'deferredWorkerPoolJobs', [[
            'type' => 'homepage_warmup',
            'claims' => [['port' => 19001, 'ticket' => 7]],
            'source' => 'SET_ROUTE_TABLE',
        ]]);
        $this->setProperty($dispatcher, 'deferredWorkerPoolFiber', null);
        $this->setProperty($dispatcher, 'deferredWorkerPoolFiberKind', null);

        $method = new \ReflectionMethod(Dispatcher::class, 'pumpDeferredWorkerPoolJobs');
        $method->setAccessible(true);
        $method->invoke($dispatcher);

        self::assertNull($this->getProperty($dispatcher, 'deferredWorkerPoolFiber'));
        self::assertNull($this->getProperty($dispatcher, 'deferredWorkerPoolFiberKind'));
        self::assertSame([], $this->getProperty($dispatcher, 'deferredWorkerPoolJobs'));
    }

    public function testDeferredHealthAuditRemovesFailedWorkersAndAlertsMaster(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->getMockBuilder(PassthroughCore::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'setWarmupCooperativeYield',
                'auditWorkerApplicationHealth',
                'removeWorkerPort',
                'getWorkerPorts',
                'getMaintenanceWorkerPorts',
                'getMaintenancePort',
                'getWorkerHealthSummary',
            ])
            ->getMock();

        $core->expects(self::exactly(2))->method('setWarmupCooperativeYield');
        $core->expects(self::once())
            ->method('auditWorkerApplicationHealth')
            ->willReturn(['healthy' => [19002], 'failed' => [19001 => 'health 503']]);
        $core->expects(self::once())
            ->method('removeWorkerPort')
            ->with(19001)
            ->willReturn([]);
        $core->method('getWorkerPorts')->willReturn([19002]);
        $core->method('getMaintenanceWorkerPorts')->willReturn([]);
        $core->method('getMaintenancePort')->willReturn(0);
        $core->method('getWorkerHealthSummary')->willReturn(['healthy' => 1, 'total' => 1]);

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
            public function sendDrainingComplete(int $workerId = 0, int $port = 0, string $msgId = '', string $reason = ''): bool { return true; }
            public function sendStatusReport(int $connections, int $memory, int $requests): bool { return true; }
            public function sendLogLine(string $line, string $level, string $processTag): bool { return true; }
            public function send(string $message, bool $disconnectOnWriteOverflow = true): bool { $this->sent[] = $message; return true; }
            public function flushPendingWrites(float $timeBudgetSec = 0.0): bool { return true; }
            public function handleReadable(): array { return []; }
            public function handleWritable(): bool { return true; }
            public function tryReconnect(): bool { return true; }
            public function close(): void {}
            public function getResurrectionPriority(): int { return 0; }
        };

        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'ipcClient', $client);
        $this->setProperty($dispatcher, 'instanceName', 'ut');
        $this->setProperty($dispatcher, 'port', 9443);
        $this->setProperty($dispatcher, 'deferredWorkerPoolJobs', [['type' => 'audit_worker_health']]);
        $this->setProperty($dispatcher, 'deferredWorkerPoolFiber', null);
        $this->setProperty($dispatcher, 'deferredWorkerPoolFiberKind', null);
        $this->setProperty($dispatcher, 'clientConnections', []);

        $method = new \ReflectionMethod(Dispatcher::class, 'pumpDeferredWorkerPoolJobs');
        $method->setAccessible(true);
        $method->invoke($dispatcher);

        self::assertCount(1, $client->sent);
        $alert = \json_decode(\trim($client->sent[0]), true);
        self::assertSame(ControlMessage::TYPE_DISPATCHER_ALERT, $alert['type'] ?? null);
        self::assertSame('worker_health_probe_failed', $alert['reason'] ?? null);
        self::assertSame([19001], $alert['failed_ports'] ?? null);
        self::assertSame([19002], $alert['business_pool'] ?? null);
    }

    public function testMaintenanceRouteTableDoesNotQueueBusinessSetPoolJob(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->getMockBuilder(PassthroughCore::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getMaintenanceWorkerPorts', 'setMaintenanceWorkerPortsFromMasterReady', 'setMaintenanceRoutingActive'])
            ->getMock();

        $core->expects(self::never())
            ->method('getMaintenanceWorkerPorts')
            ->willReturn([16999]);
        $core->expects(self::once())
            ->method('setMaintenanceWorkerPortsFromMasterReady')
            ->with([16999])
            ->willReturn(['accepted' => [16999], 'rejected' => []]);
        $core->expects(self::atLeastOnce())
            ->method('setMaintenanceRoutingActive')
            ->with(true);

        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'deferredWorkerPoolJobs', []);

        $method = new \ReflectionMethod(Dispatcher::class, 'handleIpcMessage');
        $method->setAccessible(true);
        $method->invoke($dispatcher, [
            'type' => ControlMessage::TYPE_SET_ROUTE_TABLE,
            'role' => ControlMessage::ROLE_MAINTENANCE,
            'ports' => [16999],
            'route_version' => 1,
        ]);

        self::assertSame([], $this->getProperty($dispatcher, 'deferredWorkerPoolJobs'));
    }

    public function testMaintenanceRouteTableAcknowledgesDispatcherTakeover(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->getMockBuilder(PassthroughCore::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getMaintenanceWorkerPorts', 'setMaintenanceWorkerPortsFromMasterReady'])
            ->getMock();

        $core->expects(self::atLeastOnce())
            ->method('getMaintenanceWorkerPorts')
            ->willReturn([16999]);
        $core->expects(self::once())
            ->method('setMaintenanceWorkerPortsFromMasterReady')
            ->with([16999])
            ->willReturn(['accepted' => [16999], 'rejected' => []]);

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
            public function sendDrainingComplete(int $workerId = 0, int $port = 0, string $msgId = '', string $reason = ''): bool { return true; }
            public function sendStatusReport(int $connections, int $memory, int $requests): bool { return true; }
            public function sendLogLine(string $line, string $level, string $processTag): bool { return true; }
            public function send(string $message, bool $disconnectOnWriteOverflow = true): bool { $this->sent[] = $message; return true; }
            public function flushPendingWrites(float $timeBudgetSec = 0.0): bool { return true; }
            public function handleReadable(): array { return []; }
            public function handleWritable(): bool { return true; }
            public function tryReconnect(): bool { return true; }
            public function close(): void {}
            public function getResurrectionPriority(): int { return 0; }
        };

        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'ipcClient', $client);
        $this->setProperty($dispatcher, 'port', 9443);
        $this->setProperty($dispatcher, 'deferredWorkerPoolJobs', []);

        $method = new \ReflectionMethod(Dispatcher::class, 'handleIpcMessage');
        $method->setAccessible(true);
        $method->invoke($dispatcher, [
            'type' => ControlMessage::TYPE_SET_ROUTE_TABLE,
            'role' => ControlMessage::ROLE_MAINTENANCE,
            'ports' => [16999],
            'route_version' => 1,
        ]);

        self::assertCount(2, $client->sent);
        $ack = \json_decode(\trim($client->sent[0]), true);
        self::assertSame(ControlMessage::TYPE_WORKER_POOL_ACK, $ack['type'] ?? null);
        self::assertSame(ControlMessage::ROLE_MAINTENANCE, $ack['role'] ?? null);
        self::assertSame(16999, $ack['port'] ?? null);
        self::assertTrue((bool) ($ack['in_pool'] ?? false));
    }

    public function testEmptyMaintenanceRouteTableEnablesDispatcherFallback(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->getMockBuilder(PassthroughCore::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getMaintenanceWorkerPorts', 'setMaintenanceWorkerPortsFromMasterReady', 'setMaintenanceRoutingActive'])
            ->getMock();

        $core->expects(self::never())
            ->method('getMaintenanceWorkerPorts');
        $core->expects(self::once())
            ->method('setMaintenanceWorkerPortsFromMasterReady')
            ->with([])
            ->willReturn(['accepted' => [], 'rejected' => []]);
        $core->expects(self::atLeastOnce())
            ->method('setMaintenanceRoutingActive')
            ->with(true);

        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'deferredWorkerPoolJobs', []);

        $method = new \ReflectionMethod(Dispatcher::class, 'handleIpcMessage');
        $method->setAccessible(true);
        $method->invoke($dispatcher, [
            'type' => ControlMessage::TYPE_SET_ROUTE_TABLE,
            'role' => ControlMessage::ROLE_MAINTENANCE,
            'ports' => [],
            'route_version' => 1,
        ]);

        self::assertSame([], $this->getProperty($dispatcher, 'deferredWorkerPoolJobs'));
    }

    public function testBusinessRouteTableTrustsMasterReadyAndAcknowledgesImmediately(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->getMockBuilder(PassthroughCore::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'setWorkerPortsFromMasterReady',
                'claimJoinedWorkerHomepageWarmup',
                'getWorkerCount',
                'getWorkerPorts',
                'getMaintenanceWorkerPorts',
                'getWorkerHealthSummary',
            ])
            ->getMock();

        $core->expects(self::once())
            ->method('setWorkerPortsFromMasterReady')
            ->with([19001, 19002])
            ->willReturn(['accepted' => [19001, 19002], 'rejected' => []]);
        $core->expects(self::never())->method('claimJoinedWorkerHomepageWarmup');
        $core->method('getWorkerCount')->willReturn(2);
        $core->method('getWorkerPorts')->willReturn([19001, 19002]);
        $core->method('getMaintenanceWorkerPorts')->willReturn([]);
        $core->method('getWorkerHealthSummary')->willReturn(['healthy' => 2, 'total' => 2]);

        $sent = [];
        $client = $this->createMock(ChildControlClientInterface::class);
        $client->method('isConnected')->willReturn(true);
        $client->method('send')->willReturnCallback(static function (string $message, bool $disconnectOnWriteOverflow = true) use (&$sent): bool {
            unset($disconnectOnWriteOverflow);
            $sent[] = $message;
            return true;
        });

        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'ipcClient', $client);
        $this->setProperty($dispatcher, 'port', 9443);
        $this->setProperty($dispatcher, 'deferredWorkerPoolJobs', []);

        $method = new \ReflectionMethod(Dispatcher::class, 'handleIpcMessage');
        $method->setAccessible(true);
        $method->invoke($dispatcher, [
            'type' => ControlMessage::TYPE_SET_ROUTE_TABLE,
            'role' => ControlMessage::ROLE_WORKER,
            'ports' => [19001, 19002],
            'route_version' => 1,
            'workers' => [
                ['slot_id' => 'worker#1', 'lease_id' => 'lease-1', 'generation' => 1, 'port' => 19001, 'state' => 'ready'],
                ['slot_id' => 'worker#2', 'lease_id' => 'lease-2', 'generation' => 1, 'port' => 19002, 'state' => 'ready'],
            ],
        ]);

        self::assertSame([], $this->getProperty($dispatcher, 'deferredWorkerPoolJobs'));
        self::assertCount(3, $sent);
        $ack = \json_decode(\trim($sent[0]), true);
        self::assertSame(ControlMessage::TYPE_WORKER_POOL_ACK, $ack['type'] ?? null);
        self::assertSame(19001, $ack['port'] ?? null);
        self::assertTrue((bool)($ack['in_pool'] ?? false));
        self::assertSame('worker#1', $ack['slot_id'] ?? null);
    }

    public function testBusinessRouteTableQueuesHomepageWarmupForJoinedWorkers(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->getMockBuilder(PassthroughCore::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'setWorkerPortsFromMasterReady',
                'claimJoinedWorkerHomepageWarmup',
                'getWorkerCount',
                'getWorkerPorts',
                'getMaintenanceWorkerPorts',
                'getWorkerHealthSummary',
            ])
            ->getMock();

        $core->expects(self::once())
            ->method('setWorkerPortsFromMasterReady')
            ->with([19001, 19002])
            ->willReturn(['accepted' => [19001, 19002], 'rejected' => []]);
        $core->expects(self::never())->method('claimJoinedWorkerHomepageWarmup');
        $core->method('getWorkerCount')->willReturn(2);
        $core->method('getWorkerPorts')->willReturn([19001, 19002]);
        $core->method('getMaintenanceWorkerPorts')->willReturn([]);
        $core->method('getWorkerHealthSummary')->willReturn(['healthy' => 2, 'total' => 2]);

        $sent = [];
        $client = $this->createMock(ChildControlClientInterface::class);
        $client->method('isConnected')->willReturn(true);
        $client->method('send')->willReturnCallback(static function (string $message, bool $disconnectOnWriteOverflow = true) use (&$sent): bool {
            unset($disconnectOnWriteOverflow);
            $sent[] = $message;
            return true;
        });

        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'ipcClient', $client);
        $this->setProperty($dispatcher, 'port', 9443);
        $this->setProperty($dispatcher, 'deferredWorkerPoolJobs', []);

        $method = new \ReflectionMethod(Dispatcher::class, 'handleIpcMessage');
        $method->setAccessible(true);
        $method->invoke($dispatcher, [
            'type' => ControlMessage::TYPE_SET_ROUTE_TABLE,
            'role' => ControlMessage::ROLE_WORKER,
            'ports' => [19001, 19002],
            'route_version' => 1,
            'workers' => [
                ['slot_id' => 'worker#1', 'lease_id' => 'lease-1', 'generation' => 1, 'port' => 19001, 'state' => 'ready'],
                ['slot_id' => 'worker#2', 'lease_id' => 'lease-2', 'generation' => 1, 'port' => 19002, 'state' => 'ready'],
            ],
        ]);

        self::assertSame([], $this->getProperty($dispatcher, 'deferredWorkerPoolJobs'));
        self::assertCount(3, $sent);
    }

    public function testDeferredRouteTableKeepsMaintenanceFallbackInactiveWhenPreviousPoolIsRetained(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->getMockBuilder(PassthroughCore::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getWorkerCount', 'getMaintenanceWorkerPorts', 'getWorkerHealthSummary'])
            ->getMock();

        $core->expects(self::atLeastOnce())
            ->method('getWorkerCount')
            ->willReturn(2);
        $core->expects(self::atLeastOnce())
            ->method('getMaintenanceWorkerPorts')
            ->willReturn([17001]);
        $core->expects(self::atLeastOnce())
            ->method('getWorkerHealthSummary')
            ->willReturn([
                'healthy' => 2,
                'total' => 2,
            ]);

        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'maintenanceFallbackActive', false);
        $this->setProperty($dispatcher, 'deferredWorkerPoolFiber', null);
        $this->setProperty($dispatcher, 'deferredWorkerPoolFiberKind', 'set_pool');

        $fiber = new \Fiber(static function (): array {
            return [
                'accepted' => [],
                'rejected' => [
                    16901 => 'health tls handshake timeout',
                    16902 => 'health tls handshake timeout',
                ],
            ];
        });
        $fiber->start();

        $this->setProperty($dispatcher, 'deferredWorkerPoolFiber', $fiber);

        $method = new \ReflectionMethod(Dispatcher::class, 'pumpDeferredWorkerPoolJobs');
        $method->setAccessible(true);
        $method->invoke($dispatcher);

        self::assertFalse((bool) $this->getProperty($dispatcher, 'maintenanceFallbackActive'));
    }

    public function testPoolSnapshotTrustsMasterReadyBusinessPoolAndAcknowledges(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->getMockBuilder(PassthroughCore::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'setMaintenanceRoutingActive',
                'setWorkerPortsFromMasterReady',
                'getWorkerCount',
                'getWorkerPorts',
                'getMaintenanceWorkerPorts',
                'getWorkerHealthSummary',
            ])
            ->getMock();
        $core->expects(self::once())
            ->method('setMaintenanceRoutingActive')
            ->with(false);
        $core->expects(self::once())
            ->method('setWorkerPortsFromMasterReady')
            ->with([18082, 18081])
            ->willReturn([
                'accepted' => [18082, 18081],
                'rejected' => [],
            ]);
        $core->method('getWorkerCount')->willReturn(2);
        $core->method('getWorkerPorts')->willReturn([18082, 18081]);
        $core->method('getMaintenanceWorkerPorts')->willReturn([]);
        $core->method('getWorkerHealthSummary')->willReturn([
            'healthy' => 2,
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
            public function sendDrainingComplete(int $workerId = 0, int $port = 0, string $msgId = '', string $reason = ''): bool { return true; }
            public function sendStatusReport(int $connections, int $memory, int $requests): bool { return true; }
            public function sendLogLine(string $line, string $level, string $processTag): bool { return true; }
            public function send(string $message, bool $disconnectOnWriteOverflow = true): bool { $this->sent[] = $message; return true; }
            public function flushPendingWrites(float $timeBudgetSec = 0.0): bool { return true; }
            public function handleReadable(): array { return []; }
            public function handleWritable(): bool { return true; }
            public function tryReconnect(): bool { return true; }
            public function close(): void {}
            public function getResurrectionPriority(): int { return 0; }
        };

        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'ipcClient', $client);
        $this->setProperty($dispatcher, 'deferredWorkerPoolJobs', []);
        $this->setProperty($dispatcher, 'lastAppliedWorkerPoolSnapshotVersion', 0);
        $this->setProperty($dispatcher, 'port', 9981);

        $method = new \ReflectionMethod(Dispatcher::class, 'handleIpcMessage');
        $method->setAccessible(true);
        $method->invoke($dispatcher, [
            'type' => SupervisorMessage::TYPE_POOL_SNAPSHOT,
            'scope' => 'business',
            'version' => 9,
            'workers' => [
                ['slot_id' => 'worker#2', 'lease_id' => 'lease-2', 'generation' => 4, 'port' => 18082, 'state' => 'ready'],
                ['slot_id' => 'worker#1', 'lease_id' => 'lease-1', 'generation' => 7, 'port' => 18081, 'state' => 'ready'],
                ['slot_id' => 'worker#3', 'port' => 0, 'state' => 'ready'],
                ['slot_id' => 'worker#4', 'port' => 18084, 'state' => 'leased'],
            ],
        ]);

        self::assertSame([], $this->getProperty($dispatcher, 'deferredWorkerPoolJobs'));
        self::assertSame(9, $this->getProperty($dispatcher, 'lastAppliedWorkerPoolSnapshotVersion'));
        self::assertGreaterThanOrEqual(1, \count($client->sent));
        $ack = null;
        foreach ($client->sent as $raw) {
            $decoded = \json_decode(\trim((string)$raw), true);
            if (\is_array($decoded)
                && ($decoded['type'] ?? null) === ControlMessage::TYPE_POOL_SNAPSHOT_ACK
            ) {
                $ack = $decoded;
                break;
            }
        }
        self::assertIsArray($ack);
        self::assertSame(9, $ack['version'] ?? null);
        self::assertSame('business', $ack['scope'] ?? null);
        self::assertTrue($ack['accepted'] ?? false);
    }

    public function testWorkerPoolAckCarriesLeaseDescriptorIdentity(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->getMockBuilder(PassthroughCore::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getWorkerPorts'])
            ->getMock();
        $core->method('getWorkerPorts')->willReturn([18081]);

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
            public function sendDrainingComplete(int $workerId = 0, int $port = 0, string $msgId = '', string $reason = ''): bool { return true; }
            public function sendStatusReport(int $connections, int $memory, int $requests): bool { return true; }
            public function sendLogLine(string $line, string $level, string $processTag): bool { return true; }
            public function send(string $message, bool $disconnectOnWriteOverflow = true): bool { $this->sent[] = $message; return true; }
            public function flushPendingWrites(float $timeBudgetSec = 0.0): bool { return true; }
            public function handleReadable(): array { return []; }
            public function handleWritable(): bool { return true; }
            public function tryReconnect(): bool { return true; }
            public function close(): void {}
            public function getResurrectionPriority(): int { return 0; }
        };

        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'ipcClient', $client);
        $this->setProperty($dispatcher, 'port', 9443);

        $method = new \ReflectionMethod(Dispatcher::class, 'sendWorkerPoolAckForPorts');
        $method->setAccessible(true);
        $method->invoke($dispatcher, [18081], [[
            'role' => ControlMessage::ROLE_WORKER,
            'slot_id' => 'worker#1',
            'lease_id' => 'lease-1',
            'generation' => 7,
            'port' => 18081,
            'state' => 'ready',
        ]]);

        self::assertCount(1, $client->sent);
        $ack = \json_decode(\trim($client->sent[0]), true);
        self::assertSame(ControlMessage::TYPE_WORKER_POOL_ACK, $ack['type'] ?? null);
        self::assertSame(ControlMessage::ROLE_WORKER, $ack['role'] ?? null);
        self::assertSame(18081, $ack['port'] ?? null);
        self::assertTrue((bool)($ack['in_pool'] ?? false));
        self::assertSame('worker#1', $ack['slot_id'] ?? null);
        self::assertSame('lease-1', $ack['lease_id'] ?? null);
        self::assertSame(7, $ack['generation'] ?? null);
    }

    public function testStalePoolSnapshotVersionIsIgnored(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->getMockBuilder(PassthroughCore::class)
            ->disableOriginalConstructor()
            ->getMock();

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
            public function sendDrainingComplete(int $workerId = 0, int $port = 0, string $msgId = '', string $reason = ''): bool { return true; }
            public function sendStatusReport(int $connections, int $memory, int $requests): bool { return true; }
            public function sendLogLine(string $line, string $level, string $processTag): bool { return true; }
            public function send(string $message, bool $disconnectOnWriteOverflow = true): bool { $this->sent[] = $message; return true; }
            public function flushPendingWrites(float $timeBudgetSec = 0.0): bool { return true; }
            public function handleReadable(): array { return []; }
            public function handleWritable(): bool { return true; }
            public function tryReconnect(): bool { return true; }
            public function close(): void {}
            public function getResurrectionPriority(): int { return 0; }
        };

        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'ipcClient', $client);
        $this->setProperty($dispatcher, 'deferredWorkerPoolJobs', []);
        $this->setProperty($dispatcher, 'lastAppliedWorkerPoolSnapshotVersion', 9);

        $method = new \ReflectionMethod(Dispatcher::class, 'handleIpcMessage');
        $method->setAccessible(true);
        $method->invoke($dispatcher, [
            'type' => SupervisorMessage::TYPE_POOL_SNAPSHOT,
            'scope' => 'business',
            'version' => 8,
            'workers' => [
                ['slot_id' => 'worker#1', 'port' => 18081, 'state' => 'ready'],
            ],
        ]);

        self::assertSame([], $this->getProperty($dispatcher, 'deferredWorkerPoolJobs'));
        self::assertSame(9, $this->getProperty($dispatcher, 'lastAppliedWorkerPoolSnapshotVersion'));
        self::assertSame([], $client->sent);
    }

    public function testDispatcherEntrypointAllowsSupervisorModeWithoutLegacyControlPort(): void
    {
        $path = BP . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR . 'Weline'
            . DIRECTORY_SEPARATOR . 'Server' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'dispatcher.php';
        $source = \file_get_contents($path);

        self::assertNotFalse($source, 'failed to read dispatcher.php');
        self::assertStringContainsString("\$supervisorEnabledRaw = \\getenv('WLS_SUPERVISOR_ENABLED');", $source);
        self::assertStringContainsString('if ($controlPort > 0 || $supervisorEnabled)', $source);
    }

    public function testDispatcherEntrypointUsesBoundedDefaultAcceptBatch(): void
    {
        $path = BP . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR . 'Weline'
            . DIRECTORY_SEPARATOR . 'Server' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'dispatcher.php';
        $source = \file_get_contents($path);

        self::assertNotFalse($source, 'failed to read dispatcher.php');
        self::assertStringContainsString(
            "'max_accept_per_loop' => (int)(\$dispatcherConfig['max_accept_per_loop'] ?? 16)",
            $source,
        );
    }

    public function testDispatcherAppliesPosixSelectBackpressureBeforeFdSetOverflow(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $method = new \ReflectionMethod(Dispatcher::class, 'canAcceptNewSelectTunnel');
        $method->setAccessible(true);

        $this->setProperty($dispatcher, 'clientConnections', \array_fill(0, 447, true));
        self::assertTrue($method->invoke($dispatcher));

        $this->setProperty($dispatcher, 'clientConnections', \array_fill(0, 448, true));
        if (\PHP_OS_FAMILY === 'Windows') {
            self::assertTrue($method->invoke($dispatcher));
        } else {
            self::assertFalse($method->invoke($dispatcher));
        }

        $source = (string)\file_get_contents(
            BP . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR . 'Weline'
            . DIRECTORY_SEPARATOR . 'Server' . DIRECTORY_SEPARATOR . 'Dispatcher'
            . DIRECTORY_SEPARATOR . 'Dispatcher.php'
        );
        self::assertSame(2, \substr_count($source, '$this->canAcceptNewSelectTunnel()'));
    }

    public function testDispatcherUsesSupervisorClientWhenInstanceRuntimeMetadataEnablesIt(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $instanceName = 'ut-dispatcher-runtime-supervisor';
        $instanceDir = BP . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'instances';
        $instanceFile = $instanceDir . DIRECTORY_SEPARATOR . $instanceName . '.json';
        if (!\is_dir($instanceDir)) {
            @\mkdir($instanceDir, 0777, true);
        }

        \file_put_contents($instanceFile, \json_encode([
            'control_plane_mode' => 'hybrid',
            'supervisor_enabled' => true,
            'supervisor_channel' => 'channel-' . $instanceName,
        ]));

        $this->setProperty($dispatcher, 'instanceName', $instanceName);

        try {
            $method = new \ReflectionMethod(Dispatcher::class, 'createIpcClient');
            $method->setAccessible(true);
            try {
                $method->invoke($dispatcher);
                self::fail('Supervisor Dispatcher must reject a missing explicit hello credential.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Explicit Supervisor hello authentication is required.',
                    $exception->getMessage(),
                );
            }

            $dispatcher->setHelloAuthSecret(\str_repeat('a', 64));
            $client = $method->invoke($dispatcher);

            self::assertInstanceOf(SupervisorChildClient::class, $client);
        } finally {
            @\unlink($instanceFile);
        }
    }

    private function newDispatcherWithoutConstructor(): Dispatcher
    {
        $reflector = new \ReflectionClass(Dispatcher::class);
        /** @var Dispatcher $dispatcher */
        $dispatcher = $reflector->newInstanceWithoutConstructor();
        $this->setProperty($dispatcher, 'routeTableAsAuthority', true);
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
