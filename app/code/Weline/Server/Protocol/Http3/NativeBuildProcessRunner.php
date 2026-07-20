<?php

declare(strict_types=1);

namespace Weline\Server\Protocol\Http3;

use Weline\Framework\Runtime\SchedulerSystem;

/**
 * Bounded control-plane process runner for native dependency builds.
 */
final class NativeBuildProcessRunner
{
    private const MAX_OUTPUT_BYTES = 1048576;

    /**
     * @param list<string> $command
     * @param array<string,string> $environment
     * @return array{success:bool,exit_code:int,output:string}
     */
    public function run(
        array $command,
        int $timeout,
        ?string $workingDirectory = null,
        array $environment = [],
        bool $inheritEnvironment = true,
    ): array {
        if ($command === [] || $timeout < 1) {
            return ['success' => false, 'exit_code' => 127, 'output' => 'invalid native build command'];
        }
        if ($workingDirectory !== null && !\is_dir($workingDirectory)) {
            return ['success' => false, 'exit_code' => 127, 'output' => 'native build working directory is missing'];
        }

        $effectiveCommand = $command;
        $processGroup = false;
        if (\PHP_OS_FAMILY === 'Linux' && \function_exists('posix_kill')) {
            $setsid = $this->findExecutable(['setsid'], $environment);
            if ($setsid !== null && ($command[0] ?? '') !== $setsid) {
                \array_unshift($effectiveCommand, $setsid);
                $processGroup = true;
            }
        }

        $pipes = [];
        $process = @\proc_open($effectiveCommand, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $workingDirectory, $inheritEnvironment
            ? $this->mergedEnvironment($environment)
            : $environment, ['bypass_shell' => true]);
        if (!\is_resource($process)) {
            return ['success' => false, 'exit_code' => 127, 'output' => 'unable to start native build process'];
        }

        @\fclose($pipes[0]);
        @\stream_set_blocking($pipes[1], false);
        @\stream_set_blocking($pipes[2], false);
        $deadline = \microtime(true) + $timeout;
        $output = '';
        $exitCode = -1;
        $pid = 0;
        do {
            foreach ([1, 2] as $index) {
                $chunk = @\stream_get_contents($pipes[$index]);
                if (\is_string($chunk) && $chunk !== '') {
                    $output = $this->appendOutput($output, $chunk);
                }
            }
            $status = \proc_get_status($process);
            $pid = (int)($status['pid'] ?? $pid);
            if (!($status['running'] ?? false)) {
                $exitCode = (int)($status['exitcode'] ?? -1);
                break;
            }
            if (\microtime(true) >= $deadline) {
                $this->terminate($process, $pid, $processGroup, 15);
                SchedulerSystem::usleep(100000);
                $this->terminate($process, $pid, $processGroup, 9);
                $exitCode = 124;
                $output = $this->appendOutput($output, "\nnative build command timed out");
                break;
            }
            SchedulerSystem::usleep(20000);
        } while (true);

        foreach ([1, 2] as $index) {
            $chunk = @\stream_get_contents($pipes[$index]);
            if (\is_string($chunk) && $chunk !== '') {
                $output = $this->appendOutput($output, $chunk);
            }
            @\fclose($pipes[$index]);
        }
        $closed = @\proc_close($process);
        if ($exitCode < 0 && \is_int($closed)) {
            $exitCode = $closed;
        }
        return ['success' => $exitCode === 0, 'exit_code' => $exitCode, 'output' => $output];
    }

    /**
     * @param list<string> $names
     * @param array<string,string> $environment
     */
    public function findExecutable(array $names, array $environment = []): ?string
    {
        $path = $environment['PATH'] ?? (string)\getenv('PATH');
        $directories = \array_filter(\explode(\PATH_SEPARATOR, $path));
        $directories = \array_values(\array_unique(\array_merge(
            $directories,
            ['/opt/homebrew/bin', '/usr/local/bin', '/usr/bin', '/bin', '/usr/sbin', '/sbin']
        )));
        foreach ($names as $name) {
            if (\str_contains($name, \DIRECTORY_SEPARATOR)
                && \is_file($name) && \is_executable($name)
            ) {
                return $name;
            }
            foreach ($directories as $directory) {
                $candidate = \rtrim($directory, '\\/') . \DIRECTORY_SEPARATOR . $name;
                if (\is_file($candidate) && \is_executable($candidate)) {
                    return $candidate;
                }
            }
        }
        return null;
    }

    /** @param array<string,string> $overrides @return array<string,string> */
    private function mergedEnvironment(array $overrides): array
    {
        $current = \getenv();
        $environment = \is_array($current) ? $current : [];
        foreach ($overrides as $name => $value) {
            $environment[$name] = $value;
        }
        return $environment;
    }

    private function appendOutput(string $output, string $chunk): string
    {
        $combined = $output . $chunk;
        if (\strlen($combined) <= self::MAX_OUTPUT_BYTES) {
            return $combined;
        }
        return "[earlier native build output truncated]\n"
            . \substr($combined, -self::MAX_OUTPUT_BYTES);
    }

    /** @param resource $process */
    private function terminate(mixed $process, int $pid, bool $processGroup, int $signal): void
    {
        if ($processGroup && $pid > 1 && \function_exists('posix_kill')) {
            @\posix_kill(-$pid, $signal);
            return;
        }
        @\proc_terminate($process, $signal);
    }
}
