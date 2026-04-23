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
            public function getResurrectionPriority(): int { return 0; }
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

    /**
     * P1-4 修复验证：
     * startupProtection 不再以「uptime <= startupProtectionWindowSec=45s」为硬闸；
     * 只要从未观察过健康 Worker（hasEverObservedHealthyWorker=false），即视为"仍在启动中"，
     * 即使 uptime 远超旧的 45 秒硬窗口也必须继续返回维护页判定（true）。
     */
    public function testStartupProtectionKeepsActiveBeyondLegacy45sWindowWhenNoHealthyWorkerEverObserved(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->createMock(PassthroughCore::class);
        $core->method('getWorkerHealthSummary')->willReturn(['healthy' => 0, 'total' => 0]);
        $core->method('getWorkerCount')->willReturn(0);
        $core->method('getMaintenanceWorkerPorts')->willReturn([]);

        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'startupProtectionEnabled', true);
        $this->setProperty($dispatcher, 'startupProtectionWindowSec', 45.0);
        // 模拟 uptime = 300s，远超旧硬窗口
        $this->setProperty($dispatcher, 'startTime', \time() - 300);
        $this->setProperty($dispatcher, 'hasEverObservedHealthyWorker', false);

        $method = new \ReflectionMethod(Dispatcher::class, 'shouldApplyStartupProtection');
        $method->setAccessible(true);

        self::assertTrue((bool)$method->invoke($dispatcher));
    }

    /**
     * P1-4 修复验证：
     * 一旦观察过健康 Worker（hasEverObservedHealthyWorker=true），shouldApplyStartupProtection
     * 不再返回 true 除非实际 healthy 低于 required。healthy==1 且 expected==1 → 返回 false。
     */
    public function testStartupProtectionReleasedOnceHealthyWorkerEverObservedAndRequirementMet(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->createMock(PassthroughCore::class);
        $core->method('getWorkerHealthSummary')->willReturn(['healthy' => 1, 'total' => 1]);
        $core->method('getWorkerCount')->willReturn(1);

        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'startupProtectionEnabled', true);
        $this->setProperty($dispatcher, 'startupProtectionReadyRatio', 1.0);
        $this->setProperty($dispatcher, 'startupProtectionMinReady', 1);
        $this->setProperty($dispatcher, 'expectedWorkerCount', 1);
        $this->setProperty($dispatcher, 'hasEverObservedHealthyWorker', true);

        $method = new \ReflectionMethod(Dispatcher::class, 'shouldApplyStartupProtection');
        $method->setAccessible(true);

        self::assertFalse((bool)$method->invoke($dispatcher));
    }

    /**
     * P1-5 修复验证：
     * healthy==0 且 total>0（业务 Worker 已注册但全部异常）持续时长 >= healthyZeroMaintenanceThresholdSec
     * 时，shouldServeMaintenanceFallback 必须返回 true，即便 maintenanceFallbackActive=false。
     */
    public function testMaintenanceFallbackTriggeredWhenAllWorkersUnhealthyLongerThanThreshold(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->createMock(PassthroughCore::class);
        $core->method('getWorkerCount')->willReturn(2);
        $core->method('getMaintenanceWorkerPorts')->willReturn([]);
        $core->method('getWorkerHealthSummary')->willReturn(['healthy' => 0, 'total' => 2]);

        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'maintenanceFallbackActive', false);
        $this->setProperty($dispatcher, 'startupProtectionEnabled', false);
        $this->setProperty($dispatcher, 'healthyZeroMaintenanceThresholdSec', 2.0);
        // 模拟 2.5 秒前进入 healthy==0 状态，已超过阈值
        $this->setProperty($dispatcher, 'healthyZeroSince', \microtime(true) - 2.5);
        $this->setProperty($dispatcher, 'hasEverObservedHealthyWorker', true);

        $method = new \ReflectionMethod(Dispatcher::class, 'shouldServeMaintenanceFallback');
        $method->setAccessible(true);

        self::assertTrue((bool)$method->invoke($dispatcher));
    }

    /**
     * P1-5 修复验证（反例）：
     * healthy==0 但持续时长 < 阈值时，视为瞬时抖动，不触发维护页兜底。
     */
    public function testMaintenanceFallbackNotTriggeredForShortUnhealthyBlip(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->createMock(PassthroughCore::class);
        $core->method('getWorkerCount')->willReturn(2);
        $core->method('getMaintenanceWorkerPorts')->willReturn([]);
        $core->method('getWorkerHealthSummary')->willReturn(['healthy' => 0, 'total' => 2]);

        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'maintenanceFallbackActive', false);
        $this->setProperty($dispatcher, 'startupProtectionEnabled', false);
        $this->setProperty($dispatcher, 'healthyZeroMaintenanceThresholdSec', 5.0);
        $this->setProperty($dispatcher, 'healthyZeroSince', 0.0);
        $this->setProperty($dispatcher, 'hasEverObservedHealthyWorker', true);

        $method = new \ReflectionMethod(Dispatcher::class, 'shouldServeMaintenanceFallback');
        $method->setAccessible(true);

        // 首次调用会 latch healthyZeroSince=now，但此刻差值≈0 < 阈值
        self::assertFalse((bool)$method->invoke($dispatcher));
        self::assertGreaterThan(0.0, (float)$this->getProperty($dispatcher, 'healthyZeroSince'));
    }

    /**
     * P1-4 & P1-5 联动：
     * healthy 从 0 跃迁到 >=1 时，latchHealthyObservation 应清零 healthyZeroSince 并置 latch=true，
     * 之后即便再次全挂，healthyZeroSince 会重新从 0 起步。
     */
    public function testLatchHealthyObservationResetsZeroSinceAndTurnsOnLatch(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();

        $this->setProperty($dispatcher, 'hasEverObservedHealthyWorker', false);
        $this->setProperty($dispatcher, 'healthyZeroSince', 123.456);

        $method = new \ReflectionMethod(Dispatcher::class, 'latchHealthyObservation');
        $method->setAccessible(true);
        $method->invoke($dispatcher, 1);

        self::assertTrue((bool)$this->getProperty($dispatcher, 'hasEverObservedHealthyWorker'));
        self::assertSame(0.0, (float)$this->getProperty($dispatcher, 'healthyZeroSince'));
    }

    /**
     * P0-5 修复验证（核心）：
     * 新 accept 的 non-blocking socket 首字节未到时，tryRespondWithStartupProtection 不再立即关闭，
     * 而是把连接放入 pendingMaintenancePageQueue，由主循环稍后 pump 推进。
     */
    public function testTryRespondEnqueuesWhenFirstByteNotArrivedOnNonBlockingSocket(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        [$server, $client] = $this->createLocalSocketPair();
        self::assertNotEmpty($server);

        // 在没有写入任何数据的前提下，peek 应返回 false（EAGAIN）
        $method = new \ReflectionMethod(Dispatcher::class, 'tryRespondWithStartupProtection');
        $method->setAccessible(true);
        $this->setProperty($dispatcher, 'fallbackMaintenancePage', "HTTP/1.1 503 Service Unavailable\r\n\r\nmaintenance");

        $ok = $method->invoke($dispatcher, $server, false, '127.0.0.1', 20001);
        self::assertTrue($ok, '首字节未到时应返回 true 表示已接管（入队）');

        /** @var array<int, array> $queue */
        $queue = $this->getProperty($dispatcher, 'pendingMaintenancePageQueue');
        self::assertArrayHasKey(20001, $queue);
        self::assertSame($server, $queue[20001]['socket']);

        @\socket_close($client);
        @\socket_close($server);
    }

    /**
     * P0-5 修复验证：
     * 入队后首字节到达，主循环 pump 应写维护页并关闭 socket；客户端可以读到 HTTP/1.1 503 响应。
     */
    public function testPumpPendingQueueWritesPageOncePeerSendsFirstByte(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        [$server, $client] = $this->createLocalSocketPair();

        $this->setProperty(
            $dispatcher,
            'fallbackMaintenancePage',
            "HTTP/1.1 503 Service Unavailable\r\nContent-Length: 11\r\n\r\nmaintenance"
        );
        $this->setProperty($dispatcher, 'pendingMaintenanceWaitTimeoutSec', 5.0);

        $enqueue = new \ReflectionMethod(Dispatcher::class, 'enqueuePendingMaintenancePage');
        $enqueue->setAccessible(true);
        $enqueue->invoke($dispatcher, $server, false, '127.0.0.1', 20002);

        // 客户端发送首字节（模拟 HTTP 请求）
        @\socket_write($client, "GET / HTTP/1.1\r\n\r\n");

        // 给内核一点时间把字节搬进 server 侧缓冲区
        $deadline = \microtime(true) + 1.5;
        do {
            $pump = new \ReflectionMethod(Dispatcher::class, 'pumpPendingMaintenancePageQueue');
            $pump->setAccessible(true);
            $pump->invoke($dispatcher);
            $queue = $this->getProperty($dispatcher, 'pendingMaintenancePageQueue');
            if ($queue === []) {
                break;
            }
            \usleep(20_000);
        } while (\microtime(true) < $deadline);

        self::assertSame([], $this->getProperty($dispatcher, 'pendingMaintenancePageQueue'));

        $received = $this->drainClientResponse($client, 1.0);
        self::assertStringContainsString('HTTP/1.1 503', $received);
        self::assertStringContainsString('maintenance', $received);

        @\socket_close($client);
    }

    /**
     * P0-5 修复验证：
     * 入队后首字节始终未到达，且超过 pendingMaintenanceWaitTimeoutSec 时，pump 应关闭 socket 并出队。
     */
    public function testPumpPendingQueueTimesOutWhenFirstByteNeverArrives(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        [$server, $client] = $this->createLocalSocketPair();

        $this->setProperty($dispatcher, 'pendingMaintenanceWaitTimeoutSec', 0.05);

        $enqueue = new \ReflectionMethod(Dispatcher::class, 'enqueuePendingMaintenancePage');
        $enqueue->setAccessible(true);
        $enqueue->invoke($dispatcher, $server, false, '127.0.0.1', 20003);

        // 故意不向 client 写任何字节，等待超过阈值
        \usleep(80_000);

        $pump = new \ReflectionMethod(Dispatcher::class, 'pumpPendingMaintenancePageQueue');
        $pump->setAccessible(true);
        $pump->invoke($dispatcher);

        self::assertSame([], $this->getProperty($dispatcher, 'pendingMaintenancePageQueue'));

        @\socket_close($client);
    }

    /**
     * P0-5 修复验证：
     * pending 队列中 TLS 首字节（0x16）到达时，pump 应关闭 socket 并出队（不能写明文 503 去污染 TLS）。
     */
    public function testPumpPendingQueueClosesTlsTrafficWithoutWritingMaintenancePage(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        [$server, $client] = $this->createLocalSocketPair();

        $this->setProperty(
            $dispatcher,
            'fallbackMaintenancePage',
            "HTTP/1.1 503 Service Unavailable\r\nContent-Length: 11\r\n\r\nmaintenance"
        );
        $this->setProperty($dispatcher, 'pendingMaintenanceWaitTimeoutSec', 5.0);

        $enqueue = new \ReflectionMethod(Dispatcher::class, 'enqueuePendingMaintenancePage');
        $enqueue->setAccessible(true);
        $enqueue->invoke($dispatcher, $server, false, '127.0.0.1', 20004);

        // 客户端发 TLS ClientHello 首字节
        @\socket_write($client, "\x16\x03\x01\x00");

        $deadline = \microtime(true) + 1.0;
        do {
            $pump = new \ReflectionMethod(Dispatcher::class, 'pumpPendingMaintenancePageQueue');
            $pump->setAccessible(true);
            $pump->invoke($dispatcher);
            if ($this->getProperty($dispatcher, 'pendingMaintenancePageQueue') === []) {
                break;
            }
            \usleep(20_000);
        } while (\microtime(true) < $deadline);

        self::assertSame([], $this->getProperty($dispatcher, 'pendingMaintenancePageQueue'));

        // 客户端不应读到 "HTTP/1.1 503"（服务端只应关闭，不回写明文）
        $received = $this->drainClientResponse($client, 0.3);
        self::assertStringNotContainsString('HTTP/1.1 503', $received);

        @\socket_close($client);
    }

    /**
     * P0-5 修复验证：
     * 维护页直写路径（首字节已到、非 TLS）应仍然正常工作，不改变原有语义。
     */
    public function testTryRespondWritesImmediatelyWhenFirstByteAlreadyBuffered(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        [$server, $client] = $this->createLocalSocketPair();

        $this->setProperty(
            $dispatcher,
            'fallbackMaintenancePage',
            "HTTP/1.1 503 Service Unavailable\r\nContent-Length: 11\r\n\r\nmaintenance"
        );

        // 先让 client 写入 HTTP 首字节
        @\socket_write($client, "GET / HTTP/1.1\r\n\r\n");
        // 给内核一点时间搬到 server
        \usleep(50_000);

        $method = new \ReflectionMethod(Dispatcher::class, 'tryRespondWithStartupProtection');
        $method->setAccessible(true);
        $ok = $method->invoke($dispatcher, $server, false, '127.0.0.1', 20005);
        self::assertTrue($ok);

        $queue = $this->getProperty($dispatcher, 'pendingMaintenancePageQueue');
        self::assertSame([], $queue, '首字节已到时应直接写页关闭，不入队');

        $received = $this->drainClientResponse($client, 0.5);
        self::assertStringContainsString('HTTP/1.1 503', $received);
        self::assertStringContainsString('maintenance', $received);

        @\socket_close($client);
    }

    /**
     * P0-5 修复验证：
     * pendingMaintenancePageQueueMax 达到上限时，新入队尝试应返回 false，由调用方关闭。
     */
    public function testEnqueuePendingQueueRejectsWhenAtCapacity(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $this->setProperty($dispatcher, 'pendingMaintenancePageQueueMax', 1);
        // 先塞一个占位（socket 字段随意，避免真实 I/O）
        $this->setProperty($dispatcher, 'pendingMaintenancePageQueue', [
            99 => ['socket' => null, 'clientIp' => '1.2.3.4', 'acceptedAt' => \microtime(true), 'allWorkersUnavailable' => false],
        ]);

        [$server, $client] = $this->createLocalSocketPair();

        $enqueue = new \ReflectionMethod(Dispatcher::class, 'enqueuePendingMaintenancePage');
        $enqueue->setAccessible(true);
        $ok = $enqueue->invoke($dispatcher, $server, false, '127.0.0.1', 20006);

        self::assertFalse($ok, '队列已满时应返回 false 表示调用方需自行关闭');

        @\socket_close($server);
        @\socket_close($client);
    }

    /**
     * 创建一对本地连通的 Socket：
     *  - server 端设为 non-blocking（模拟 Dispatcher 刚 accept 的连接）
     *  - client 端保持 blocking（方便测试断言客户端能读到响应）
     *
     * 在 Windows / Linux 均可用。
     *
     * @return array{0: \Socket, 1: \Socket} [serverSocket, clientSocket]
     */
    private function createLocalSocketPair(): array
    {
        $listener = \socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        self::assertNotFalse($listener);
        \socket_set_option($listener, SOL_SOCKET, SO_REUSEADDR, 1);
        \socket_bind($listener, '127.0.0.1', 0);
        \socket_listen($listener);
        \socket_getsockname($listener, $addr, $port);

        $client = \socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        self::assertNotFalse($client);
        // 阻塞式 connect，确保三次握手完成再进入测试逻辑
        $connected = @\socket_connect($client, '127.0.0.1', $port);
        self::assertTrue($connected, 'failed to connect local socket pair');

        $server = @\socket_accept($listener);
        self::assertNotFalse($server, 'failed to accept local socket pair');

        // server 侧模拟 Dispatcher 行为：非阻塞
        \socket_set_nonblock($server);
        // client 侧保持阻塞；给 recv 一个较短超时，避免测试被挂住
        \socket_set_option($client, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);
        \socket_set_option($client, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 1, 'usec' => 0]);

        \socket_close($listener);

        return [$server, $client];
    }

    /**
     * 从阻塞式 client socket 读到一段响应（或超时返回 ''）。
     * 使用轮询 socket_select 判断可读，避免 SO_RCVTIMEO 在不同平台的行为差异。
     */
    private function drainClientResponse(\Socket $client, float $deadlineSec = 1.0): string
    {
        $received = '';
        $deadline = \microtime(true) + $deadlineSec;
        while (\microtime(true) < $deadline) {
            $read = [$client];
            $write = null;
            $except = null;
            $remain = \max(0.0, $deadline - \microtime(true));
            $sec = (int)\floor($remain);
            $usec = (int)(($remain - $sec) * 1_000_000);
            $ready = @\socket_select($read, $write, $except, $sec, $usec);
            if ($ready === false || $ready === 0) {
                break;
            }
            $chunk = '';
            $n = @\socket_recv($client, $chunk, 4096, 0);
            if ($n === false) {
                break;
            }
            if ($n === 0) {
                // 对端关闭
                break;
            }
            $received .= $chunk;
        }

        return $received;
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
