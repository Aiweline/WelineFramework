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
        if ($liveCommand === '') {
            $liveCommand = implode(' ', $argv);
        }
        return $command->invocation->process->withStartedProcess(
            $pid,
            $liveCommand,
            new DateTimeImmutable('now'),
        );
    }
}
