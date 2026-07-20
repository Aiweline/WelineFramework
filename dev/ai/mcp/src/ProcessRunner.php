<?php

declare(strict_types=1);

namespace LearningMcp;

use RuntimeException;

final class ProcessRunner
{
    private const MAX_CAPTURE_BYTES = 8_388_608;

    /**
     * Run an argv-only child process without involving a shell.
     *
     * @param list<string> $argv
     * @param array<string, scalar> $env
     * @return array{exit_code:int,stdout:string,stderr:string,timed_out:bool,duration_ms:int}
     */
    public function run(
        array $argv,
        string $cwd,
        string $stdin = '',
        int $timeoutSeconds = 60,
        array $env = [],
    ): array {
        $this->assertArguments($argv);
        $cwd = realpath($cwd) ?: '';
        if ($cwd === '' || !is_dir($cwd)) {
            throw new RuntimeException('Process working directory does not exist');
        }
        if ($timeoutSeconds < 1 || $timeoutSeconds > 3_600) {
            throw new RuntimeException('Process timeout must be between 1 and 3600 seconds');
        }

        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];
        foreach ($env as $name => $value) {
            if (!is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) {
                throw new RuntimeException('Invalid child-process environment variable name');
            }
            if (!is_scalar($value) || str_contains((string) $value, "\0")) {
                throw new RuntimeException('Invalid child-process environment variable value');
            }
            $environment[$name] = (string) $value;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $startedAt = hrtime(true);
        $process = proc_open(
            $argv,
            $descriptors,
            $pipes,
            $cwd,
            $environment,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start child process');
        }

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        $stdout = '';
        $stderr = '';
        $stdoutTruncated = false;
        $stderrTruncated = false;
        $stdinOffset = 0;
        $stdinLength = strlen($stdin);
        $stdinOpen = true;
        $timedOut = false;
        $observedExitCode = null;

        try {
            while (true) {
                $status = proc_get_status($process);
                if (!$status['running'] && (int) $status['exitcode'] >= 0) {
                    $observedExitCode = (int) $status['exitcode'];
                }

                $read = [];
                if (is_resource($pipes[1])) {
                    $read[] = $pipes[1];
                }
                if (is_resource($pipes[2])) {
                    $read[] = $pipes[2];
                }
                $write = [];
                if ($stdinOpen && is_resource($pipes[0]) && $stdinOffset < $stdinLength) {
                    $write[] = $pipes[0];
                } elseif ($stdinOpen && is_resource($pipes[0])) {
                    fclose($pipes[0]);
                    $stdinOpen = false;
                }

                if ($read !== [] || $write !== []) {
                    $except = null;
                    @stream_select($read, $write, $except, 0, 200_000);
                }

                if ($write !== [] && is_resource($pipes[0])) {
                    $written = @fwrite($pipes[0], substr($stdin, $stdinOffset, 65_536));
                    if ($written === false) {
                        fclose($pipes[0]);
                        $stdinOpen = false;
                    } elseif ($written > 0) {
                        $stdinOffset += $written;
                    }
                }

                foreach ($read as $pipe) {
                    $chunk = stream_get_contents($pipe);
                    if (!is_string($chunk) || $chunk === '') {
                        continue;
                    }
                    if ($pipe === $pipes[1]) {
                        self::appendCaptured($stdout, $chunk, $stdoutTruncated);
                    } else {
                        self::appendCaptured($stderr, $chunk, $stderrTruncated);
                    }
                }

                $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
                if ($status['running'] && $elapsedSeconds >= $timeoutSeconds) {
                    $timedOut = true;
                    @proc_terminate($process, 15);
                    $graceDeadline = microtime(true) + 1.0;
                    do {
                        usleep(20_000);
                        $status = proc_get_status($process);
                    } while ($status['running'] && microtime(true) < $graceDeadline);
                    if ($status['running']) {
                        @proc_terminate($process, 9);
                    }
                }

                if (!$status['running'] || $timedOut) {
                    foreach ([1, 2] as $descriptor) {
                        if (!is_resource($pipes[$descriptor])) {
                            continue;
                        }
                        $chunk = stream_get_contents($pipes[$descriptor]);
                        if (!is_string($chunk) || $chunk === '') {
                            continue;
                        }
                        if ($descriptor === 1) {
                            self::appendCaptured($stdout, $chunk, $stdoutTruncated);
                        } else {
                            self::appendCaptured($stderr, $chunk, $stderrTruncated);
                        }
                    }
                    break;
                }
            }
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }

        $closedExitCode = proc_close($process);
        $exitCode = $timedOut
            ? 124
            : ($observedExitCode ?? ($closedExitCode >= 0 ? $closedExitCode : 1));

        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout . ($stdoutTruncated ? "\n[output truncated]" : ''),
            'stderr' => $stderr . ($stderrTruncated ? "\n[output truncated]" : ''),
            'timed_out' => $timedOut,
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
        ];
    }

    /** @param list<string> $argv */
    private function assertArguments(array $argv): void
    {
        if ($argv === [] || !array_is_list($argv)) {
            throw new RuntimeException('Process argv must be a non-empty list');
        }
        foreach ($argv as $argument) {
            if (!is_string($argument) || $argument === '' || str_contains($argument, "\0")) {
                throw new RuntimeException('Process argv must contain non-empty strings without NUL bytes');
            }
        }
    }

    private static function appendCaptured(string &$buffer, string $chunk, bool &$truncated): void
    {
        $remaining = self::MAX_CAPTURE_BYTES - strlen($buffer);
        if ($remaining <= 0) {
            $truncated = true;
            return;
        }
        if (strlen($chunk) > $remaining) {
            $buffer .= substr($chunk, 0, $remaining);
            $truncated = true;
            return;
        }
        $buffer .= $chunk;
    }
}
