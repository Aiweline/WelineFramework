<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeProcessIdentity;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeProcessProbe;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeRunnerProcessLauncherInterface;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeRunnerProcessSupervisorInterface;
use Weline\Server\Runtime\Resumable\RuntimeTaskWatchdog;
use Weline\Server\Runtime\Resumable\RuntimeWatchdogGatewayInterface;
use Weline\Server\Runtime\Resumable\RuntimeWatchdogSubject;

final class RuntimeTaskWatchdogShutdownDrainTest extends TestCase
{
    public function testShutdownDrainRequestsCheckpointStopWithoutLaunchingRecovery(): void
    {
        $now = new DateTimeImmutable('2026-08-16 06:00:00');
        $identity = RuntimeProcessIdentity::forTask('task-shutdown-drain', 1, 'launch-1')
            ->withStartedProcess(1234, 'php runner', $now);
        $subject = new RuntimeWatchdogSubject(
            taskId: $identity->taskId,
            process: $identity,
            isTerminal: false,
            allClientLeasesExpired: false,
            runnerHeartbeatExpired: false,
            stopRequested: false,
            recoveryStopRequested: false,
            cooperativeStopDeadlineReached: false,
            forceTerminationConfirmationExpired: false,
            recoveryEligible: true,
        );

        $gateway = $this->createMock(RuntimeWatchdogGatewayInterface::class);
        $gateway->method('dueSubjects')->willReturn([$subject]);
        $gateway->expects(self::once())
            ->method('requestRecoveryStop')
            ->with($subject, 'wls_shutdown', $now);
        $gateway->expects(self::never())->method('claimRecovery');

        $supervisor = $this->createMock(RuntimeRunnerProcessSupervisorInterface::class);
        $supervisor->method('probe')->willReturn(
            new RuntimeProcessProbe($identity, RuntimeProcessProbe::RUNNING, 'running')
        );
        $launcher = $this->createMock(RuntimeRunnerProcessLauncherInterface::class);
        $launcher->expects(self::never())->method('launch');

        $watchdog = new RuntimeTaskWatchdog($gateway, $supervisor, $launcher);
        $watchdog->beginShutdownDrain();
        $report = $watchdog->tick($now);

        self::assertSame(1, $report->recoveryStopsRequested);
    }

    public function testShutdownDrainDoesNotRecoverRunnerThatAlreadyCheckpointedAndExited(): void
    {
        $now = new DateTimeImmutable('2026-08-16 06:00:00');
        $identity = RuntimeProcessIdentity::forTask('task-checkpointed', 2, 'launch-2');
        $subject = new RuntimeWatchdogSubject(
            taskId: $identity->taskId,
            process: $identity,
            isTerminal: false,
            allClientLeasesExpired: false,
            runnerHeartbeatExpired: false,
            stopRequested: true,
            recoveryStopRequested: true,
            cooperativeStopDeadlineReached: false,
            forceTerminationConfirmationExpired: false,
            recoveryEligible: true,
            runnerLeaseReleased: true,
        );

        $gateway = $this->createMock(RuntimeWatchdogGatewayInterface::class);
        $gateway->method('dueSubjects')->willReturn([$subject]);
        $gateway->expects(self::never())->method('claimRecovery');
        $gateway->expects(self::never())->method('finalizeStop');

        $supervisor = $this->createMock(RuntimeRunnerProcessSupervisorInterface::class);
        $launcher = $this->createMock(RuntimeRunnerProcessLauncherInterface::class);
        $launcher->expects(self::never())->method('launch');

        $watchdog = new RuntimeTaskWatchdog($gateway, $supervisor, $launcher);
        $watchdog->beginShutdownDrain();
        $watchdog->tick($now);
    }
}
