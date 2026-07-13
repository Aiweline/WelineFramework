<?php

declare(strict_types=1);

namespace Weline\Server\Runtime\Resumable;

use DateTimeImmutable;
use Throwable;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeProcessProbe;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeRunnerProcessLauncherInterface;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeRunnerProcessSupervisorInterface;

/**
 * One cooperative watchdog tick for resumable task Runners.
 *
 * This is designed to be called by a WLS service provider once per second, or
 * by the standalone runtime:task:watch daemon outside WLS. It does not execute
 * task business logic and does not rely on a browser connection.
 */
final class RuntimeTaskWatchdog
{
    public function __construct(
        private readonly RuntimeWatchdogGatewayInterface $gateway,
        private readonly RuntimeRunnerProcessSupervisorInterface $supervisor,
        private readonly RuntimeRunnerProcessLauncherInterface $launcher,
    ) {
    }

    public function tick(?DateTimeImmutable $now = null, int $limit = 100): RuntimeWatchdogReport
    {
        $now ??= new DateTimeImmutable('now');
        $limit = max(1, min(1000, $limit));
        $report = new RuntimeWatchdogReport();

        foreach ($this->gateway->dueSubjects($now, $limit) as $subject) {
            if (!$subject instanceof RuntimeWatchdogSubject || $subject->isTerminal) {
                continue;
            }

            $report->inspected++;
            $probe = $this->resolveProbe($subject);
            $this->gateway->recordProcessProbe($subject, $probe, $now);

            if ($subject->allClientLeasesExpired && !$subject->stopRequested) {
                $this->gateway->requestCooperativeStop($subject, 'client_lease_expired', $now);
                $report->leaseExpiryStopsRequested++;
                continue;
            }

            if ($subject->stopRequested) {
                $this->handleCooperativeStop($subject, $probe, $now, $report);
                continue;
            }

            if ($subject->runnerHeartbeatExpired) {
                $this->handleHeartbeatExpiry($subject, $probe, $now, $report);
                continue;
            }

            // An exited process can be discovered before the heartbeat lease
            // expires. Recover it only when the durable task adapter confirms
            // the task is still eligible and atomically advances its fence.
            if ($probe->allowsRecovery() && $subject->recoveryEligible) {
                $this->launchRecovery($subject, $now, $report);
            }
        }

        return $report;
    }

    private function resolveProbe(RuntimeWatchdogSubject $subject): RuntimeProcessProbe
    {
        if ($subject->runnerLeaseReleased && $subject->process->pid === 0) {
            return new RuntimeProcessProbe(
                $subject->process,
                RuntimeProcessProbe::EXITED,
                'runner_launch_lease_released',
                released: true,
            );
        }

        return $this->supervisor->probe($subject->process);
    }

    private function handleCooperativeStop(
        RuntimeWatchdogSubject $subject,
        RuntimeProcessProbe $probe,
        DateTimeImmutable $now,
        RuntimeWatchdogReport $report,
    ): void {
        if ($probe->isRunning() && $subject->cooperativeStopDeadlineReached) {
            $forced = $this->supervisor->forceTerminate($subject->process);
            $this->gateway->recordForceTermination($subject, $forced, $now);
            $report->forceTerminations++;
            $this->completeStopOrRecovery($subject, $forced, $now, $report);
            return;
        }

        if ($probe->state === RuntimeProcessProbe::UNKNOWN && $subject->forceTerminationConfirmationExpired) {
            $this->gateway->recordRecoveryBlocked($subject, 'runner_process_identity_unknown', $now);
            $report->recoveriesBlocked++;
            return;
        }

        $this->completeStopOrRecovery($subject, $probe, $now, $report);
    }

    private function handleHeartbeatExpiry(
        RuntimeWatchdogSubject $subject,
        RuntimeProcessProbe $probe,
        DateTimeImmutable $now,
        RuntimeWatchdogReport $report,
    ): void {
        if ($probe->isRunning()) {
            // The process may still be inside a safe checkpointable step. Ask
            // it to leave cooperatively; a later tick enforces the deadline.
            $this->gateway->requestRecoveryStop($subject, 'runner_heartbeat_expired', $now);
            $report->recoveryStopsRequested++;
            return;
        }

        if ($probe->allowsRecovery() && $subject->recoveryEligible) {
            $this->launchRecovery($subject, $now, $report);
            return;
        }

        $this->gateway->recordRecoveryBlocked($subject, 'runner_process_identity_unknown', $now);
        $report->recoveriesBlocked++;
    }

    private function launchRecovery(
        RuntimeWatchdogSubject $subject,
        DateTimeImmutable $now,
        RuntimeWatchdogReport $report,
    ): void {
        $command = $this->gateway->claimRecovery($subject, $now);
        if ($command === null) {
            // A concurrent watchdog or a manual action won the CAS. This is a
            // normal no-op and never warrants a second launch attempt.
            return;
        }

        try {
            $process = $this->launcher->launch($command);
            $this->gateway->recordRunnerLaunched($subject, $process, $now);
            $report->recoveriesLaunched++;
        } catch (Throwable $_throwable) {
            $this->gateway->recordRunnerLaunchFailure(
                $subject,
                'runner_launch_failed',
                $now,
            );
            $report->launchFailures++;
        }
    }

    private function completeStopOrRecovery(
        RuntimeWatchdogSubject $subject,
        RuntimeProcessProbe $probe,
        DateTimeImmutable $now,
        RuntimeWatchdogReport $report,
    ): void {
        if (!$probe->allowsRecovery()) {
            return;
        }

        // Only a watchdog-initiated recovery stop starts another generation.
        if ($subject->recoveryStopRequested && $subject->recoveryEligible) {
            $this->launchRecovery($subject, $now, $report);
            return;
        }

        $this->gateway->finalizeStop($subject, $probe, $now);
    }
}
