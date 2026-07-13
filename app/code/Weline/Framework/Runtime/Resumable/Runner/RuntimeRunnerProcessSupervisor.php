<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable\Runner;

use Weline\Framework\System\Process\Processer;

/**
 * Identity-safe probe and last-resort process-tree termination.
 *
 * Cooperative stop is stored first and observed by the Runner. This class is
 * deliberately not a cancellation mechanism; forceTerminate() is valid only
 * after the watchdog's cooperative grace deadline.
 */
final class RuntimeRunnerProcessSupervisor implements RuntimeRunnerProcessSupervisorInterface
{
    public function probe(RuntimeProcessIdentity $identity): RuntimeProcessProbe
    {
        if ($identity->pid < 1) {
            return RuntimeProcessProbe::unknown($identity, 'runner_pid_missing');
        }

        if (!$identity->canSafelyTerminate()) {
            $state = Processer::probeProcessState($identity->pid, true);
            if ($state === Processer::PROCESS_STATE_EXITED) {
                return new RuntimeProcessProbe($identity, RuntimeProcessProbe::EXITED, 'process_exited');
            }

            return RuntimeProcessProbe::unknown($identity, 'live_identity_missing');
        }

        return RuntimeProcessProbe::fromProcesser(
            $identity,
            Processer::probeManagedProcessIdentity(
                $identity->pid,
                $identity->liveCommand,
                $identity->launchId,
                $identity->managedPname(),
                true,
            ),
        );
    }

    public function forceTerminate(RuntimeProcessIdentity $identity): RuntimeProcessProbe
    {
        if (!$identity->canSafelyTerminate()) {
            return RuntimeProcessProbe::unknown($identity, 'force_termination_identity_unavailable');
        }

        return RuntimeProcessProbe::fromProcesser(
            $identity,
            Processer::terminateManagedProcessLease(
                $identity->pid,
                $identity->liveCommand,
                $identity->launchId,
                $identity->managedPname(),
                true,
            ),
        );
    }
}
