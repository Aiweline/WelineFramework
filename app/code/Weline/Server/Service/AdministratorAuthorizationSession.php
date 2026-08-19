<?php

declare(strict_types=1);

namespace Weline\Server\Service;

/**
 * One process-local sudo ticket for bounded privileged startup actions.
 *
 * The session never accepts, stores, pipes, or prints a password. Interactive
 * authentication is handled exclusively by sudo; every later action uses -n.
 */
final class AdministratorAuthorizationSession
{
    private const COMMAND_TIMEOUT_SECONDS = 120.0;
    private const TERMINATION_TIMEOUT_SECONDS = 1.0;

    private ?bool $authorizationGranted = null;

    /** @var null|\Closure(array<int,string>):int */
    private readonly ?\Closure $commandRunner;

    /** @var null|\Closure():bool */
    private readonly ?\Closure $interactiveProbe;

    /** @var null|\Closure():int */
    private readonly ?\Closure $effectiveUidProbe;

    public function __construct(
        ?\Closure $commandRunner = null,
        ?\Closure $interactiveProbe = null,
        ?\Closure $effectiveUidProbe = null,
        private readonly string $sudoBinary = '/usr/bin/sudo',
        private readonly ?string $osFamily = null,
    ) {
        $this->commandRunner = $commandRunner;
        $this->interactiveProbe = $interactiveProbe;
        $this->effectiveUidProbe = $effectiveUidProbe;
    }

    /**
     * @param array<int,string> $command
     */
    public function runPrivileged(array $command): bool
    {
        if (!$this->validCommand($command)) {
            return false;
        }
        if ($this->effectiveUid() === 0) {
            return $this->runCommand($command) === 0;
        }
        if (!$this->authorize()) {
            return false;
        }

        return $this->runCommand([
            $this->sudoBinary,
            '-n',
            '--',
            ...$command,
        ]) === 0;
    }

    public function authorize(): bool
    {
        if ($this->authorizationGranted !== null) {
            return $this->authorizationGranted;
        }
        if ($this->osFamily() === 'Windows') {
            return $this->authorizationGranted = false;
        }
        if ($this->effectiveUid() === 0) {
            return $this->authorizationGranted = true;
        }
        if (!$this->interactive()
            || $this->sudoBinary === ''
            || \str_contains($this->sudoBinary, "\0")
            || ($this->commandRunner === null && !\is_executable($this->sudoBinary))
        ) {
            return $this->authorizationGranted = false;
        }

        return $this->authorizationGranted = $this->runCommand([
            $this->sudoBinary,
            '-v',
        ]) === 0;
    }

    /**
     * @param array<int,string> $command
     */
    private function validCommand(array $command): bool
    {
        if ($command === []) {
            return false;
        }
        foreach ($command as $argument) {
            if (!\is_string($argument)
                || $argument === ''
                || \str_contains($argument, "\0")
            ) {
                return false;
            }
        }

        return true;
    }

    private function osFamily(): string
    {
        return $this->osFamily ?? PHP_OS_FAMILY;
    }

    private function effectiveUid(): int
    {
        if ($this->effectiveUidProbe !== null) {
            return (int)($this->effectiveUidProbe)();
        }
        if ($this->osFamily() === 'Windows' || !\function_exists('posix_geteuid')) {
            return -1;
        }

        return (int)@\posix_geteuid();
    }

    private function interactive(): bool
    {
        if ($this->interactiveProbe !== null) {
            return (bool)($this->interactiveProbe)();
        }
        if (!\defined('STDIN')
            || !\defined('STDOUT')
            || !\is_resource(STDIN)
            || !\is_resource(STDOUT)
            || !\function_exists('stream_isatty')
        ) {
            return false;
        }

        return @\stream_isatty(STDIN) && @\stream_isatty(STDOUT);
    }

    /**
     * @param array<int,string> $command
     */
    private function runCommand(array $command): int
    {
        if ($this->commandRunner !== null) {
            return (int)($this->commandRunner)($command);
        }
        if (!\function_exists('proc_open')
            || !\function_exists('proc_get_status')
            || !\function_exists('proc_terminate')
            || !\function_exists('proc_close')
        ) {
            return 127;
        }

        $nullDevice = $this->osFamily() === 'Windows' ? 'NUL' : '/dev/null';
        $descriptors = [
            0 => \defined('STDIN') && \is_resource(STDIN)
                ? STDIN
                : ['file', $nullDevice, 'r'],
            1 => \defined('STDOUT') && \is_resource(STDOUT)
                ? STDOUT
                : ['file', $nullDevice, 'w'],
            2 => \defined('STDERR') && \is_resource(STDERR)
                ? STDERR
                : ['file', $nullDevice, 'w'],
        ];
        $process = @\proc_open(
            $command,
            $descriptors,
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        if (!\is_resource($process)) {
            return 127;
        }

        $deadline = self::monotonicSeconds() + self::COMMAND_TIMEOUT_SECONDS;
        $exitCode = -1;
        do {
            $status = @\proc_get_status($process);
            if (!\is_array($status)) {
                break;
            }
            if (!(bool)($status['running'] ?? false)) {
                $exitCode = (int)($status['exitcode'] ?? -1);
                break;
            }
            if (self::monotonicSeconds() >= $deadline) {
                @\proc_terminate($process);
                $terminationDeadline = self::monotonicSeconds()
                    + self::TERMINATION_TIMEOUT_SECONDS;
                do {
                    $status = @\proc_get_status($process);
                    if (!\is_array($status) || !(bool)($status['running'] ?? false)) {
                        break 2;
                    }
                    \usleep(10_000);
                } while (self::monotonicSeconds() < $terminationDeadline);
                @\proc_terminate($process, 9);
                $exitCode = 124;
                break;
            }
            \usleep(10_000);
        } while (true);

        $closedCode = @\proc_close($process);
        if ($exitCode < 0 && \is_int($closedCode) && $closedCode >= 0) {
            $exitCode = $closedCode;
        }

        return $exitCode >= 0 ? $exitCode : 127;
    }

    private static function monotonicSeconds(): float
    {
        $seconds = \hrtime(true) / 1_000_000_000;
        if (!\is_finite($seconds) || $seconds <= 0.0) {
            throw new \RuntimeException('Administrator authorization monotonic clock is unavailable.');
        }

        return $seconds;
    }
}
