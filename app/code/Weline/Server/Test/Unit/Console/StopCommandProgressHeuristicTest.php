<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Stop;
use Weline\Server\Service\Contract\ServerInstanceInfo;
use Weline\Server\Service\Contract\ServiceInfo;
use Weline\Server\Service\Contract\ServiceInstance;

final class StopCommandProgressHeuristicTest extends TestCase
{
    public function testWaitsForMasterExitWhenExplicitMasterExitMessageArrives(): void
    {
        $stop = new Stop();

        self::assertTrue($this->invokeProtected(
            $stop,
            'shouldWaitForMasterExitAfterProgress',
            '所有子进程已完整退出，Master 即将退出进程',
            [],
            0
        ));
    }

    public function testWaitsForMasterExitWhenLegacyExplicitMasterExitMessageArrives(): void
    {
        $stop = new Stop();

        self::assertTrue($this->invokeProtected(
            $stop,
            'shouldWaitForMasterExitAfterProgress',
            '所有子进程已完整退出，Master 即将退出主循环',
            [],
            0
        ));
    }

    public function testDoesNotWaitForMasterExitOnlyBecauseChildrenAlreadyReportedExit(): void
    {
        $stop = new Stop();

        self::assertFalse($this->invokeProtected(
            $stop,
            'shouldWaitForMasterExitAfterProgress',
            '  ✓ HTTP Worker(PID:55448) 已退出',
            [5332 => true, 54540 => true, 27176 => true, 55448 => true, 38624 => true],
            5
        ));
    }

    public function testDoesNotSwitchToMasterExitEarlyWhenChildrenRemain(): void
    {
        $stop = new Stop();

        self::assertFalse($this->invokeProtected(
            $stop,
            'shouldWaitForMasterExitAfterProgress',
            '阶段5/6: 校验子进程退出状态',
            [5332 => true, 54540 => true],
            5
        ));
    }

    public function testFallsBackToLocalCleanupAfterStageFiveIdleWithoutMasterExitSignal(): void
    {
        $stop = new Stop();

        self::assertTrue($this->invokeProtected(
            $stop,
            'shouldAbortToLocalCleanupAfterIdle',
            5,
            false,
            false
        ));
    }

    public function testDoesNotFallbackToLocalCleanupAfterMasterExitSignal(): void
    {
        $stop = new Stop();

        self::assertFalse($this->invokeProtected(
            $stop,
            'shouldAbortToLocalCleanupAfterIdle',
            5,
            true,
            true
        ));
    }

    public function testBypassesGracefulStopWhenInstanceIsStillBootstrapping(): void
    {
        $stop = new Stop();

        self::assertTrue($this->invokeProtected(
            $stop,
            'shouldBypassGracefulStopDuringBootstrap',
            'bootstrapping'
        ));
    }

    public function testDoesNotBypassGracefulStopWhenInstanceIsRunning(): void
    {
        $stop = new Stop();

        self::assertFalse($this->invokeProtected(
            $stop,
            'shouldBypassGracefulStopDuringBootstrap',
            'running'
        ));
    }

    public function testResolvesStartupPhaseFromInstanceRuntime(): void
    {
        $stop = new Stop();

        self::assertSame('bootstrapping', $this->invokeProtected(
            $stop,
            'resolveInstanceStartupPhase',
            ['startup_phase' => ' bootstrapping ']
        ));
    }

    public function testTreatsStartingWorkerWithoutIpcAsPendingStartupService(): void
    {
        $stop = new Stop();

        self::assertTrue($this->invokeProtected(
            $stop,
            'hasPendingStartupServices',
            $this->createInstanceInfoWithServiceState(ServiceInstance::STATE_STARTING, null)
        ));
    }

    public function testDoesNotTreatReadyWorkerWithIpcAsPendingStartupService(): void
    {
        $stop = new Stop();

        self::assertFalse($this->invokeProtected(
            $stop,
            'hasPendingStartupServices',
            $this->createInstanceInfoWithServiceState(ServiceInstance::STATE_READY, 101)
        ));
    }

    public function testIgnoresSharedSessionLikeServiceWithoutIpcForBootstrapBypass(): void
    {
        $stop = new Stop();

        $info = new ServerInstanceInfo(
            'default',
            12345,
            19981,
            '127.0.0.1',
            9981,
            false,
            false,
            1,
            10000,
            0,
            '2026-03-26 00:00:00',
            1774454400,
            [
                new ServiceInfo(
                    'session_server',
                    'Session Server',
                    1,
                    56784,
                    19970,
                    ServiceInstance::STATE_READY,
                    10,
                    1,
                    'session-shared',
                    0.0,
                    null,
                    ['process_name' => 'weline-wls-session-shared-19970']
                ),
            ]
        );

        self::assertFalse($this->invokeProtected(
            $stop,
            'hasPendingStartupServices',
            $info
        ));
    }

    private function createInstanceInfoWithServiceState(string $state, ?int $ipcClientId): ServerInstanceInfo
    {
        return new ServerInstanceInfo(
            'default',
            12345,
            19981,
            '127.0.0.1',
            9981,
            false,
            false,
            1,
            10000,
            0,
            '2026-03-26 00:00:00',
            1774454400,
            [
                new ServiceInfo(
                    'worker',
                    'HTTP Worker',
                    1,
                    22334,
                    19982,
                    $state,
                    20,
                    1,
                    'worker-1',
                    0.0,
                    $ipcClientId,
                    ['process_name' => 'weline-wls-worker-default-1']
                ),
            ]
        );
    }

    private function invokeProtected(object $object, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }
}
