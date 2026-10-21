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

final class RuntimeTaskWatchdogRecoveryExhaustedTest extends TestCase
{
    public function testExhaustedRecoveryIsTerminalizedInsteadOfReinspectedForever(): void
    {
        $now = new DateTimeImmutable('2026-10-21 14:40:00');
        $identity = RuntimeProcessIdentity::forTask('task-exhausted-recovery', 4, 'launch-4');
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
            recoveryEligible: false,
            runnerLeaseReleased: true,
            recoveryExhausted: true,
        );

        $gateway = $this->createMock(RuntimeWatchdogGatewayInterface::class);
        $gateway->method('dueSubjects')->willReturn([$subject]);
        $gateway->expects(self::once())
            ->method('recordRecoveryBlocked')
            ->with($subject, 'recovery_exhausted', $now);
        $gateway->expects(self::never())->method('claimRecovery');
        $gateway->expects(self::never())->method('finalizeStop');

        $supervisor = $this->createMock(RuntimeRunnerProcessSupervisorInterface::class);
        $supervisor->method('probe')->willReturn(
            new RuntimeProcessProbe($identity, RuntimeProcessProbe::EXITED, 'exited')
        );
        $launcher = $this->createMock(RuntimeRunnerProcessLauncherInterface::class);
        $launcher->expects(self::never())->method('launch');

        $watchdog = new RuntimeTaskWatchdog($gateway, $supervisor, $launcher);
        $report = $watchdog->tick($now);

        self::assertSame(1, $report->recoveriesBlocked);
        self::assertSame(0, $report->recoveriesLaunched);
    }
}
