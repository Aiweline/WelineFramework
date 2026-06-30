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

    public function testImmediateStartup503ReturnsTrueWhenAllWorkerPoolsAreEmpty(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->createMock(PassthroughCore::class);

        $core->expects(self::once())
            ->method('lastNewConnectionEndedInAllWorkersDown')
            ->willReturn(true);
        $core->expects(self::once())
            ->method('getMaintenanceWorkerPorts')
            ->willReturn([]);
        $core->expects(self::never())->method('getWorkerHealthSummary');
        $core->expects(self::never())->method('getWorkerCount');

        $this->setProperty($dispatcher, 'passthroughCore', $core);

        $method = new \ReflectionMethod(Dispatcher::class, 'shouldReturnStartup503Immediately');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($dispatcher));
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
        self::assertStringContainsString('Content-Length:', $response);
        self::assertStringContainsString('Cache-Control: no-store, no-cache, must-revalidate', $response);
        self::assertStringContainsString('Pragma: no-cache', $response);
        self::assertStringContainsString('Retry-After: 5', $response);
        self::assertStringContainsString('WLS正在启动中', $response);
        self::assertStringContainsString('业务 Worker 启动中', $response);
        self::assertStringContainsString('window.location.reload', $response);
        self::assertStringContainsString('setInterval', $response);
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
        self::assertStringNotContainsString('wls-dev-alert', $nonDevPage);

        $this->setProperty($dispatcher, 'isDevMode', true);
        $devPage = (string)$resolve->invoke($dispatcher, true);

        self::assertStringContainsString('wls-dev-alert', $devPage);
        self::assertStringContainsString('DEV：当前所有 Worker 不可用</strong>', $devPage);
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
        $method->invoke($dispatcher, true, 'SET_ROUTE_TABLE accepted=0, rejected=0');

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
     * P1-4 婵烇絽娴傞崰鏍囬崣澶樻鐎光偓閸愵亝顫嶉梺?
     * startupProtection 婵炴垶鎸哥粔鎾疮閳ь剙霉閻橆喖鍔橀柍褜鍓欓惁鍧ime <= startupProtectionWindowSec=45s闂侀潧妫楃粔宕囨嫻閻旂儤鍏滄い鎺戝椤︹晠鏌?
     * 闂佸憡鐟禍锝夈€呴敂鐣岊浄閹兼番鍨哄鎾绘偡濞嗗繐鈧悂鎯傞崒娑欎氦闁搞儜鍌涙▕闁?Worker闂佹寧绋戝婕歴EverObservedHealthyWorker=false闂佹寧绋戦¨鈧紒杈ㄧ箞瀹曪紕鈧湱濯鎺戔槈?婵炲濮寸粔鏉戯耿椤忓牆瑙︽い鏍ㄨ壘琚熸繛?闂?
     * 闂佸憡顨呴崢鏍ㄧ箾?uptime 闁哄鏅滅划蹇曠矓閹绢喖绫嶇憸蹇撯枔?45 缂備礁顦扮敮鐐哄灳閺嶎偆鐜绘俊銈傚亾鐟滅増绋掔粙濠囨偄妞嬪海鐣辨俊鐐€涘畷鐢稿箣妞嬪海纾兼い鎿勭磿缁犳煡鏌涢妷褍浠ф繛锝庡櫍楠炲酣濡舵径鍫氬亾婢舵劕绀嗛柕鍫濇噽閺嗕即鏌ㄥ☉妯荤窡rue闂佹寧绋戦ˇ顓㈠焵?
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
        // 濠碘槅鍨崜婵堚偓?uptime = 300s闂佹寧绋戦惌浣烘崲濞嗘垶鎯ラ柛娑卞枟閿涘鐥褍澧伴柣娑栧劦瀹?
        $this->setProperty($dispatcher, 'startTime', \time() - 300);
        $this->setProperty($dispatcher, 'hasEverObservedHealthyWorker', false);

        $method = new \ReflectionMethod(Dispatcher::class, 'shouldApplyStartupProtection');
        $method->setAccessible(true);

        self::assertTrue((bool)$method->invoke($dispatcher));
    }

    /**
     * P1-4 婵烇絽娴傞崰鏍囬崣澶樻鐎光偓閸愵亝顫嶉梺?
     * 婵炴垶鎸撮崑鎾绘煛閸愯法鐣虫い顐㈡川閳ь剛鏁搁崰鏇犳崲閸愵喖纾婚柕澶堝劜閸?Worker闂佹寧绋戝婕歴EverObservedHealthyWorker=true闂佹寧绋戦¨鈧紒杈ㄥ珘houldApplyStartupProtection
     * 婵炴垶鎸哥粔鎾疮閳ь剟寮堕埡鍌涚叆婵?true 闂傚倸瀚ㄩ崐婵嗩焽椤忓棌鍋撻崷顓炰槐婵?healthy 婵炶揪绲界花鑲╄姳?required闂侀潧妫斿Δ绌峚lthy==1 婵?expected==1 闂?闁哄鏅滈弻銊ッ?false闂?
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
     * P1-5 婵烇絽娴傞崰鏍囬崣澶樻鐎光偓閸愵亝顫嶉梺?
     * healthy==0 婵?total>0闂佹寧绋戦悧鍛箔閻旂厧绀?Worker 閻庡湱顭堝鍫曞极閻愬搫绀冮悘鐐跺亹缁嬪鏌涜箛瀣闁稿孩娼欓锝夊磼濮樺崬鐓戦梺鎸庣☉椤︽壆鈧灚姊荤槐鎺楊敇閻斿壊妲梻鍌楀亾?>= healthyZeroMaintenanceThresholdSec
     * 闂佸搫鍟抽鎰濠曠すouldServeMaintenanceFallback 闂婎偄娲ら幊姗€濡磋箛鏃€浜ら柡鍌涘缁€鈧?true闂佹寧绋戦懟顖氱暤閸℃鐟?maintenanceFallbackActive=false闂?
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
        // 濠碘槅鍨崜婵堚偓?2.5 缂備礁顦扮敮鎺撴櫠閻樿櫕浜ゆ繛鎴灻?healthy==0 闂佺粯顭堥崺鏍焵椤戣法绛忕紒杈ㄧ箓椤斿繘鎳犻崜浣囥垽寮堕埡浣瑰枠婵℃彃娲畷?
        $this->setProperty($dispatcher, 'healthyZeroSince', \microtime(true) - 2.5);
        $this->setProperty($dispatcher, 'hasEverObservedHealthyWorker', true);

        $method = new \ReflectionMethod(Dispatcher::class, 'shouldServeMaintenanceFallback');
        $method->setAccessible(true);

        self::assertTrue((bool)$method->invoke($dispatcher));
    }

    /**
     * P1-5 婵烇絽娴傞崰鏍囬崣澶樻鐎光偓閸愵亝顫嶉梺鎸庣☉閻楀棗顕ｉ懞銉х懝閻庯絽澧庣粈鍡涙煥?
     * healthy==0 婵炶揪绲藉Λ娆戔偓鍨⒒缁辨帡顢橀悢鍓叉Н闂傚倵鍋?< 闂傚倸鍟悧鍡涘焵椤掆偓閸氬顪冮崒鐐存櫖鐎光偓閸愮偓缍婃繛鎴炴崄濞咃綁鎮樺☉銏犵睄闁兼悂娼ч～宥夋煕閺冩垹鍘滅紒杈ㄧ箖缁嬪顓奸崺鎸庢礋瀹曪綁骞嬮悩铏枙闂佺缈伴崐婵嬪Υ婢舵劕绀傛繝濠傚暟娣囨椽鏌?
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

        // 婵☆偓绲鹃悧妤咁敃閼测晜瀚柛鎰典簼閺嗗繐霉?latch healthyZeroSince=now闂佹寧绋戞總鏃傚緤閹屾桨闁靛牆鎳庨悡婵堚偓鐟板閸犳牠鍩€椤掆偓閻ㄩ攱鏅? < 闂傚倸鍟悧鍡涘焵?
        self::assertFalse((bool)$method->invoke($dispatcher));
        self::assertGreaterThan(0.0, (float)$this->getProperty($dispatcher, 'healthyZeroSince'));
    }

    /**
     * P1-4 & P1-5 闂佽壈椴搁弻銊︽叏閳哄懏鏅?
     * healthy 婵?0 闁荤姴鎼崯宕囩紦婵傜绀?>=1 闂佸搫鍟抽鎰濠曨湩tchHealthyObservation 闁圭厧鐡ㄥ鍦博婵犳碍鈷?healthyZeroSince 濡ょ姷鍋涙晶搴ㄦ偪?latch=true闂?
     * 婵炴垶鏌ㄩ鍛村箖濡ゅ懎纭€闁稿繐鎳愰埞鎺楁煕閹邦剛校妞ゆ劕銈稿畷妤呭Ω閿曗偓閻︻垶鏌ㄥ☉姗嗗悩ealthyZeroSince 婵炴潙鍚嬪畝鎼佸闯閹间礁妫樺Λ棰佽兌閻?0 闁荤姍鍥ㄦ暠妞ゆ帞鍋ゆ俊?
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
     * P0-5 婵烇絽娴傞崰鏍囬崣澶樻鐎光偓閸愵亝顫嶉梺鎸庣☉閻楀﹪鎮х€圭姷鐤€闁告劘娉曠粈鍡涙煥?
     * 闂?accept 闂?non-blocking socket 婵☆偓绲鹃悧鏇㈡偤瑜旈幊鐐哄磼濞戞瑥绱﹂梺鍛婂笧婢ф顪冮崒鐐存櫖閻庡湱纾RespondWithStartupProtection 婵炴垶鎸哥粔鎾疮閳ь剛绱掗弬娆惧剰鐎规挸妫濆畷妤呭箮閼恒儻绱ㄩ梺?
     * 闂佸吋婢橀張顒€危閹间礁绠┑鐘叉噽缁犻箖鏌熼幁鎺戝姕闁哄倷绶氬畷?pendingMaintenancePageQueue闂佹寧绋戦惉濂稿极鏉堛劎鈻旈悹鍥у级閸庢洟鏌ｅ搴＄仯闁崇鍨藉畷?pump 闂佽浜介崝蹇曟崲濮椻偓婵?
     */
    public function testTryRespondEnqueuesWhenFirstByteNotArrivedOnNonBlockingSocket(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        [$server, $client] = $this->createLocalSocketPair();
        self::assertNotEmpty($server);

        // 闂侀潻璐熼崝宥夋儉閸涙潙瀚夊璺猴工閺呮悂鏌涜箛瀣姍闁瑰箍鍨洪幏鍛村即閻斿憡顔嶉梺纭咁嚃閸犳艾鈻撻幋锕€绀堢€广儱妫楃徊鐟扳槈閹炬剚鍎滅紒杈ㄥ強eek 闁圭厧鐡ㄥΛ浣烘崲閹达箑鐐?false闂佹寧绋戝﹢鏃矴AIN闂?
        $method = new \ReflectionMethod(Dispatcher::class, 'tryRespondWithStartupProtection');
        $method->setAccessible(true);
        $this->setProperty($dispatcher, 'fallbackMaintenancePage', "HTTP/1.1 503 Service Unavailable\r\n\r\nmaintenance");

        $ok = $method->invoke($dispatcher, $server, false, '127.0.0.1', 20001);
        self::assertTrue($ok);

        /** @var array<int, array> $queue */
        $queue = $this->getProperty($dispatcher, 'pendingMaintenancePageQueue');
        self::assertArrayHasKey(20001, $queue);
        self::assertSame($server, $queue[20001]['socket']);

        @\socket_close($client);
        @\socket_close($server);
    }

    /**
     * P0-5 婵烇絽娴傞崰鏍囬崣澶樻鐎光偓閸愵亝顫嶉梺?
     * 闂佺绻堥崕闈浳ｉ敃鍌氳Е閹兼惌鐓堝ù鏇㈡倵濞戞瑯娈欏┑鈽嗗弮瀹曟岸骞忕仦鎯ф锭闂佹寧绋戞總鏃傗偓闈涜嫰椤曘儵顢旈崱妤冪暰 pump 闁圭厧鐡ㄩ弻銊╁疮閹惧墎纾奸悗娑櫭婵＄偑鍊濆褔鎳熼悢鐓庣闁规儼濮ら敍?socket闂佹寧绋掔粙鎴︻敇瑜版帒绠ｅ瀣瘨娴煎倿鏌涘▎妯虹仧濞存粍甯為幏鐘垫嫚閹绘帞鍘?HTTP/1.1 503 闂佸憡绻傜粔瀵歌姳閺屻儱违?
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

        // 闁诲骸绠嶉崹娲春濞戞氨鍗氭い鏍ㄨ壘缁叉椽姊洪锛勭獢妞も晞宕甸埀顒佺⊕椤ㄥ淇婇銏℃櫖闁割偆鍞芥笟鈧獮?HTTP 闁荤姴娲弨閬嶆儑娴煎瓨鏅?
        @\socket_write($client, "GET / HTTP/1.1\r\n\r\n");

        // 缂傚倷鐒﹂悷銉╁船閹绢喖鍐€闂傗偓閹邦噮浼囬梺缁樺姌椤顪冮崒鐐粹拻閻庢稒锚鎯熼柣搴㈢⊕椤ㄥ淇婇銏犵妞ゆ帒鍟扮粻?server 婵炴挻鐨滈崨顖氼槱闂佸憡鍔栬ぐ鍐焊?
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
     * P0-5 婵烇絽娴傞崰鏍囬崣澶樻鐎光偓閸愵亝顫嶉梺?
     * 闂佺绻堥崕闈浳ｉ敃鍌氳Е閹兼惌鐓堝ù鏇㈡倵濞戞瑯娈欏┑鈽嗗幗閹便劎鈧綆鍓涢惌鎺楁煛閸偂绨婚柛鈺佹湰濞煎繘骞橀崨顖滎槷婵炴垶鎸诲Λ浣虹矓鐎涙ɑ浜?pendingMaintenanceWaitTimeoutSec 闂佸搫鍟抽鎰濠曠殟mp 闁圭厧鐡ㄩ弻銊╁矗瑜斿?socket 濡ょ姷鍋犲▔娑㈠吹椤撱垺鈷撻柣鏃傝ˉ閸?
     */
    public function testPumpPendingQueueTimesOutWhenFirstByteNeverArrives(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        [$server, $client] = $this->createLocalSocketPair();

        $this->setProperty($dispatcher, 'pendingMaintenanceWaitTimeoutSec', 0.05);

        $enqueue = new \ReflectionMethod(Dispatcher::class, 'enqueuePendingMaintenancePage');
        $enqueue->setAccessible(true);
        $enqueue->invoke($dispatcher, $server, false, '127.0.0.1', 20003);

        // 闂佽桨绀侀幊蹇涘礈閻楀牏鈻旂€广儱鎳忛崐?client 闂佸憡鍔栭悷銈夊箲閵忊剝濯撮柡鍥╁仧閹界喖鏌ら崫鍕喊缂佽鲸绻勭划鍨緞婵犲嫮顎€闁烩剝甯掗幊鎾舵崲閸愵喗鈷撻柛顐ｇ妇閸?
        \usleep(80_000);

        $pump = new \ReflectionMethod(Dispatcher::class, 'pumpPendingMaintenancePageQueue');
        $pump->setAccessible(true);
        $pump->invoke($dispatcher);

        self::assertSame([], $this->getProperty($dispatcher, 'pendingMaintenancePageQueue'));

        @\socket_close($client);
    }

    /**
     * P0-5 婵烇絽娴傞崰鏍囬崣澶樻鐎光偓閸愵亝顫嶉梺?
     * pending 闂傚倸鍟伴崰搴ㄥ垂椤忓懐鈻?TLS 婵☆偓绲鹃悧鏇㈡偤瑜旈幊鐐哄磼閿斿墽顦?x16闂佹寧绋戦ˇ顖炲春鐏炵偓缍囬柣鎰靛墯椤ρ囨煥濞戞﹩鍞箄mp 闁圭厧鐡ㄩ弻銊╁矗瑜斿?socket 濡ょ姷鍋犲▔娑㈠吹椤撱垺鈷撻柣鏇炲€荤粈鍕槈閹惧磭啸闁稿繑锕㈠畷妯衡枎閹邦収娼犻梺?503 闂佸憡顭囩划顖炴寘閸曨垰钃?TLS闂佹寧绋戦ˇ顓㈠焵?
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

        // 闁诲骸绠嶉崹娲春濞戞氨鍗氭い鏍ㄨ壘缁?TLS ClientHello 婵☆偓绲鹃悧鏇㈡偤瑜旈幊?
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

        // 闁诲骸绠嶉崹娲春濞戞氨鍗氭い鏍ㄧ懅閻熸繈骞栫€涙ɑ顥嗘い鏇氬嵆瀹?"HTTP/1.1 503"闂佹寧绋戦悧濠傦耿閸ヮ剙绀夐柨娑樺娴煎倿鏌涘▎妯圭盎缂併劍鐓″畷妤呭箮閼恒儻绱ㄩ梺鎸庣☉婵傛梻绮径鎰倞闁绘劖褰冮弲鎼佹煛閸曨偄顒㈤柡瀣暣閺?
        $received = $this->drainClientResponse($client, 0.3);
        self::assertStringNotContainsString('HTTP/1.1 503', $received);

        @\socket_close($client);
    }

    /**
     * P0-5 婵烇絽娴傞崰鏍囬崣澶樻鐎光偓閸愵亝顫嶉梺?
     * 缂傚倷绀侀悺銊︽叏閵徛颁簻闁汇垹鐏氱痪顖炴煕閹邦厾鎳嗛柣顓熷劤椤曘儵宕熼崜浣侯槱婵☆偓绲鹃悧鏇㈡偤瑜旈幊鐐哄磼濮橆剙娈ラ梺鍛婂笩閸╁洭鍩€椤戣法绐旀繛?TLS闂佹寧绋戦ˇ顖滆姳閸欏顩风€广儱娲ゆ慨褎鎱ㄥ┑鎾跺埌闁绘牞娉涢蹇涘Ψ閵堝洨鈻曢梺鎸庣☉婵傛梻绮径鎰哗闁荤喐婢樼紞渚€鏌涘Ο鐓庢瀾婵犫偓娴ｇ儤瀚氭い鎾寸箘閻ゅ懘鏌?
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

        // 闂佺绻愰悧鎰邦敊閳?client 闂佸憡鍔栭悷銉╁矗?HTTP 婵☆偓绲鹃悧鏇㈡偤瑜旈幊?
        @\socket_write($client, "GET / HTTP/1.1\r\n\r\n");
        // 缂傚倷鐒﹂悷銉╁船閹绢喖鍐€闂傗偓閹邦噮浼囬梺缁樺姌椤顪冮崒鐐粹拻閻庢稒蓱閸庢棃鏌?server
        \usleep(50_000);

        $method = new \ReflectionMethod(Dispatcher::class, 'tryRespondWithStartupProtection');
        $method->setAccessible(true);
        $ok = $method->invoke($dispatcher, $server, false, '127.0.0.1', 20005);
        self::assertTrue($ok);

        $queue = $this->getProperty($dispatcher, 'pendingMaintenancePageQueue');
        self::assertSame([], $queue);

        $received = $this->drainClientResponse($client, 0.5);
        self::assertStringContainsString('HTTP/1.1 503', $received);
        self::assertStringContainsString('maintenance', $received);

        @\socket_close($client);
    }

    /**
     * P0-5 婵烇絽娴傞崰鏍囬崣澶樻鐎光偓閸愵亝顫嶉梺?
     * pendingMaintenancePageQueueMax 闁哄鐗婇崕鎶藉春鐏炲墽鈻斿┑鐘叉处椤庢瑩鏌￠崘顓у晣缂佽鲸绻堝顒勫级閸喖姹查梻鍌氬暟閸犲酣鎯冮崜褎瀚氶柡鍥╁仧鐎瑰寮堕埡鍌涚叆婵?false闂佹寧绋戦惉濂稿极鏉堚晜瀚柛鎰典簼閺嗗繘鏌￠崒婊勫殌闁告鍥ㄢ拻妞ゆ挻澹曢崑?
     */
    public function testEnqueuePendingQueueRejectsWhenAtCapacity(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $this->setProperty($dispatcher, 'pendingMaintenancePageQueueMax', 1);
        // 闂佺绻愰悧鍡涖€侀敐鍡欌枖闁逞屽墯缁嬪顢旈崟顐ょ崶婵炶揪绲界粙鍕濡炬cket 闁诲孩绋掗〃鍡涱敊瀹€鍕挄闊洦鏌ㄦ竟鍫ユ煥濞戞鐒稿ù灏栨櫊瀹曟顓奸崶銊ョ劯闁?I/O闂?
        $this->setProperty($dispatcher, 'pendingMaintenancePageQueue', [
            99 => ['socket' => null, 'clientIp' => '1.2.3.4', 'acceptedAt' => \microtime(true), 'allWorkersUnavailable' => false],
        ]);

        [$server, $client] = $this->createLocalSocketPair();

        $enqueue = new \ReflectionMethod(Dispatcher::class, 'enqueuePendingMaintenancePage');
        $enqueue->setAccessible(true);
        $ok = $enqueue->invoke($dispatcher, $server, false, '127.0.0.1', 20006);

        self::assertFalse($ok);

        @\socket_close($server);
        @\socket_close($client);
    }

    /**
     * 闂佸憡甯楃粙鎴犵磽閹惧鈻旈柍褜鍓涢埀顑跨祷椤锕㈡导鏉戞嵍闁瑰嘲鐬肩粻楣冩⒑椤愶絾鐨戞繛?Socket闂?
     *  - server 缂備焦妫忛崹鐢割敊閺囩喓鈻?non-blocking闂佹寧绋戦悧濠呭暞闂?Dispatcher 闂?accept 闂佹眹鍔岀€氼垳鎹㈠☉銏犵闁靛鍨崇粈?
     *  - client 缂備焦妫忛崹顖滄崲濮樿泛绠?blocking闂佹寧绋戦悧濠囧蓟閻斿摜鐟归柟绋挎捣閵堟挳鎮归崶銊︾闁哄苯娲ㄩ幊娑㈠焵椤掑倵鍋撻獮鍨仾闁糕晜绋撶划鈺咁敍濠靛棗骞嬮柣鐘叉穿椤曆囧春瀹€鍕紶鐎广儱鎳愮€瑰鏌?
     *
     * 闂?Windows / Linux 闂佺鍐╂悙鐟滅増鐓￠幃浠嬪Ω閿濆倸浜?
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
        // 闂傚倸鍟抽褔銆侀敐鍜佸殨?connect闂佹寧绋戦惉濂稿灳濡崵鈹嶆繝闈涙噽閻熷繑绻涢崱蹇撳⒉鐟滅増鐟╅獮宥団偓锝庝簽閺嗘岸鏌熺€涙ê濮囬柛鐔插亾闁哄鏅滅粙鎴﹀矗閸℃鍦偓锝庡幘濡叉悂姊洪锝嗩潡缂?
        $connected = @\socket_connect($client, '127.0.0.1', $port);
        self::assertTrue($connected, 'failed to connect local socket pair');

        $server = @\socket_accept($listener);
        self::assertNotFalse($server, 'failed to accept local socket pair');

        // server 婵炴挻鐨滈崟鈧笟鈧獮?Dispatcher 闁荤偞绋戞總鏃傛嫻閻斿吋鏅慨妯垮煐婵粓姊婚崘顓у殭妞?
        \socket_set_nonblock($server);
        // client 婵炴挻鐨滈崒婊咁啍闂佸綊鏅茬欢姘熼崱妯肩畽闁绘垵娲ㄩ獮銏㈢磽?recv 婵炴垶鎸撮崑鎾斥槈閹垮啩娴风紒鑸电箞閹矂顢橀敍鍕戙垽鏌￠崘顓у晣缂佽鲸绻堥弻鍡涘垂椤旂厧璧嬪┑鐐存綑椤戝牓鎯侀婊勫仏妞ゆ劧绲介惁顖毭?
        \socket_set_option($client, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);
        \socket_set_option($client, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 1, 'usec' => 0]);

        \socket_close($listener);

        return [$server, $client];
    }

    /**
     * 婵炲濮撮柊锝呂熼崱妯肩畽闁绘劘灏欑涵鈧?client socket 闁荤姴娲╅褔宕虹仦鍓р枖闁逞屽墮閳绘捇妫冨☉娆愬劌闁圭厧鐡ㄥΛ鎴犳濞嗘挸绠ｉ柡宥庡墰瀛濋梺鍝勫暞閸庤偐鎹㈤幋锕€鐐?''闂佹寧绋戦ˇ顓㈠焵?
     * 婵炶揪缍€濞夋洟寮妶鍡樺妞ゆ梹顑欓崵?socket_select 闂佸憡甯囬崐鏍蓟閸ヮ剙鐭楁い鏍ㄧ箥閸ゃ垽鏌ㄥ☉妯肩劯濞村皷鏅犲畷?SO_RCVTIMEO 闂侀潻璐熼崝瀣箔婢舵劕瑙﹂悘鐐扮矙閹割剟鏌涘▎鎰棆婵炲牊鍨归幃鎵沪閼规壆顦伴悗鐟板閸犳牜妲愰幘璇参?
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
                // 闁诲酣娼у﹢閬嶎敂椤掑嫬绀傞柟鎯板Г閿?
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
