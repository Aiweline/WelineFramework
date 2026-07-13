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
        $pid = Processer::create(
            $command->toShellCommand(),
            block: false,
            foreground: false,
            enableLog: true,
        );
        if ($pid < 1) {
            throw new RuntimeException('Unable to start the resumable task Runner process.');
        }

        // Capture the live identity once. A temporary inability to read it is
        // preserved as empty and causes later termination/recovery to fail
        // closed instead of guessing from a reused PID.
        $liveCommand = Processer::getProcessCommandLine($pid, true);

        return $command->invocation->process->withStartedProcess(
            $pid,
            $liveCommand,
            new DateTimeImmutable('now'),
        );
    }
}
