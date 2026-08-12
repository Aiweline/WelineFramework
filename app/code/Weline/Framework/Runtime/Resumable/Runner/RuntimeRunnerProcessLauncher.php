<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable\Runner;

use DateTimeImmutable;
use RuntimeException;
use Weline\Framework\System\Process\Processer;

/**
 * Starts a Runner outside the HTTP/WLS request Fiber.
 */
final class RuntimeRunnerProcessLauncher implements RuntimeRunnerProcessLauncherInterface
{
    public function launch(RuntimeRunnerCommand $command): RuntimeProcessIdentity
    {
        $argv = $command->toArgv();
        $process = $command->invocation->process;
        try {
            $pid = Processer::createDetachedPhpArgv(
                $argv,
                $command->projectRoot,
                $process->managedPname(),
                true,
            );
        } catch (\Throwable $throwable) {
            throw new RuntimeException(
                'Unable to start the resumable task Runner process: '
                . mb_substr($throwable->getMessage(), 0, 256),
                previous: $throwable,
            );
        }
        if ($pid < 1) {
            throw new RuntimeException('Unable to start the resumable task Runner process (no PID).');
        }

        $liveCommand = trim(Processer::getProcessCommandLine($pid, true));
        if (!$this->matchesInvocation($liveCommand, $command)) {
            // The POSIX parent can observe the freshly-forked child before
            // pcntl_exec replaces its inherited command line. Persist the
            // intended argv identity instead of accidentally binding the
            // parent Runner command to the child PID.
            $liveCommand = $command->toShellCommand();
        }
        return $command->invocation->process->withStartedProcess(
            $pid,
            $liveCommand,
            new DateTimeImmutable('now'),
        );
    }

    private function matchesInvocation(string $liveCommand, RuntimeRunnerCommand $command): bool
    {
        if ($liveCommand === '') {
            return false;
        }

        $process = $command->invocation->process;
        foreach ([
            '--task-id=' . $process->taskId,
            '--runner-id=' . $command->invocation->runnerId,
            '--name=' . $process->processName,
            '--launch-id=' . $process->launchId,
        ] as $identityToken) {
            if (!str_contains($liveCommand, $identityToken)) {
                return false;
            }
        }

        return true;
    }
}
