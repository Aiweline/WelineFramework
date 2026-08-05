<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Runs privileged host utilities without a shell and with fixed resource bounds.
 *
 * stdout and stderr deliberately share one byte budget: a command cannot evade
 * the diagnostic limit by alternating between the two pipes.  Pipe draining is
 * also time-sliced so an infinite writer cannot postpone the wall-clock timeout.
 */
final class GatewayBoundedCommandRunner
{
    private const DEFAULT_TIMEOUT_SECONDS = 120.0;
    private const MIN_TIMEOUT_SECONDS = 0.1;
    private const MAX_TIMEOUT_SECONDS = 3600.0;
    private const TERMINATE_GRACE_SECONDS = 2.0;
    private const KILL_GRACE_SECONDS = 2.0;
    private const SELECT_MICROSECONDS = 100_000;
    private const MAX_DRAIN_BYTES_PER_PIPE_CYCLE = 65_536;
    private const MAX_OUTPUT_BYTES = 262_144;
    private const MAX_ARGUMENTS = 4096;
    private const MAX_ARGUMENT_BYTES = 1_048_576;
    private const MAX_ENVIRONMENT_VARIABLES = 512;
    private const MAX_ENVIRONMENT_BYTES = 1_048_576;
    private const MAX_READY_BYTES = 128;
    private const MAX_DEFERRED_PROCESSES = 8;
    private const WINDOWS_HELPER_RESULT_SCHEMA = 'wls-bounded-command-result/1';
    private const WINDOWS_HELPER_RESULT_MAX_BYTES = 4096;
    private const WINDOWS_HELPER_MAX_BYTES = 16_777_216;
    // The native helper may spend up to 2s terminating its Job, 4s joining
    // capture threads, and another bounded cleanup pass after the child
    // deadline. The PHP watchdog must contain that documented recovery path
    // instead of killing a healthy helper while it is proving containment.
    private const WINDOWS_OUTER_GRACE_SECONDS = 12.0;
    private const WINDOWS_TERMINATE_GRACE_SECONDS = 2.0;
    private const WINDOWS_RESULT_PARENT_LEAF = 'wls-bounded-command-results-v1';
    private const TRUNCATION_MARKER = "\n[WLS gateway command output truncated]\n";

    /** @var list<array{process:resource,group_id:int,result_dir?:string}> */
    private static array $deferredProcesses = [];

    /**
     * @param list<string> $command
     * @param array<string,string>|null $environment Exact child environment,
     *        or null to inherit the current process environment.
     * @return array{code:int,output:string,stdout:string,stderr:string,truncated:bool}
     */
    public static function run(
        array $command,
        float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        ?string $workingDirectory = null,
        bool $failOnTruncatedOutput = true,
        ?array $windowsHelperProof = null,
        ?array $environment = null,
    ): array {
        self::assertCommand($command);
        self::assertWorkingDirectory($workingDirectory);
        self::assertTimeout($timeoutSeconds);
        self::assertEnvironment($environment);
        if (!\function_exists('proc_open')
            || !\function_exists('proc_get_status')
            || !\function_exists('proc_terminate')
            || !\function_exists('proc_close')
        ) {
            return [
                'code' => 127,
                'output' => 'The bounded WLS gateway process API is unavailable.',
                'stdout' => '',
                'stderr' => 'The bounded WLS gateway process API is unavailable.',
                'truncated' => false,
            ];
        }
        if (!self::reapDeferredProcesses()) {
            return self::failure(125, 'The bounded WLS gateway process reap queue is full or unsafe.');
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            return self::runWindowsNative(
                $command,
                $timeoutSeconds,
                $workingDirectory,
                $failOnTruncatedOutput,
                $windowsHelperProof,
                $environment,
            );
        }
        if (!\function_exists('posix_setsid')
            || !\function_exists('posix_kill')
            || !\function_exists('pcntl_exec')
        ) {
            return self::failure(
                127,
                'The POSIX WLS gateway process-group API is unavailable.',
            );
        }
        return self::runPosixProcessGroup(
            $command,
            $timeoutSeconds,
            $workingDirectory,
            $failOnTruncatedOutput,
            $environment,
        );
    }

    /**
     * @param list<string> $command
     * @return array{code:int,output:string,stdout:string,stderr:string,truncated:bool}
     */
    private static function runPosixProcessGroup(
        array $command,
        float $timeoutSeconds,
        ?string $workingDirectory,
        bool $failOnTruncatedOutput,
        ?array $environment,
    ): array {
        $phpBinary = @\realpath(\PHP_BINARY);
        if (!\is_string($phpBinary)
            || $phpBinary === ''
            || !\str_starts_with($phpBinary, '/')
            || !\is_file($phpBinary)
            || !\is_executable($phpBinary)
        ) {
            return self::failure(127, 'The trusted PHP CLI launcher is unavailable.');
        }
        $wrapper = <<<'PHP'
if (!function_exists('posix_setsid') || !function_exists('pcntl_exec')) {
    exit(126);
}
$session = posix_setsid();
$pid = getmypid();
$ready = @fopen('php://fd/3', 'wb');
if (!is_int($session) || $session !== $pid || !is_resource($ready)) {
    exit(126);
}
$token = "wls-process-group/1 " . $pid . "\n";
if (fwrite($ready, $token) !== strlen($token) || !fflush($ready)) {
    fclose($ready);
    exit(126);
}
fclose($ready);
$target = (string)($argv[1] ?? '');
if ($target === '') {
    exit(126);
}
pcntl_exec($target, array_slice($argv, 2));
exit(126);
PHP;
        // Reuse the exact CLI installation that is already running WLS so
        // distro-packaged POSIX/PCNTL modules remain available. `-n` would
        // silently remove those shared modules on common Linux packages and
        // make every bounded command fail. Explicit CLI overrides prevent a
        // host php.ini from injecting prepend/append code into this tiny
        // launcher; every other restriction remains identical to the parent.
        $launchCommand = [
            $phpBinary,
            '-d',
            'auto_prepend_file=',
            '-d',
            'auto_append_file=',
            '-d',
            'display_errors=0',
            '-d',
            'log_errors=0',
            '-d',
            'error_reporting=0',
            '-d',
            'opcache.enable_cli=0',
            '-r',
            $wrapper,
            '--',
            ...$command,
        ];
        $pipes = [];
        $process = @\proc_open(
            $launchCommand,
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
                3 => ['pipe', 'w'],
            ],
            $pipes,
            $workingDirectory,
            $environment,
            ['bypass_shell' => true],
        );
        if (!\is_resource($process)) {
            return self::failure(126, 'Unable to launch the bounded WLS gateway command.');
        }

        foreach ([1, 2, 3] as $index) {
            if (isset($pipes[$index]) && \is_resource($pipes[$index])) {
                @\stream_set_blocking($pipes[$index], false);
            }
        }

        $initialStatus = @\proc_get_status($process);
        $processId = \is_array($initialStatus) ? (int)($initialStatus['pid'] ?? 0) : 0;
        if ($processId < 2) {
            self::closePipes($pipes);
            @\proc_terminate($process, 9);
            self::$deferredProcesses[] = ['process' => $process, 'group_id' => 0];

            return self::failure(125, 'The WLS gateway command process identity is unavailable.');
        }

        $stdout = '';
        $stderr = '';
        $readyBuffer = '';
        $truncated = false;
        $timedOut = false;
        $statusUnknown = false;
        $launchInvalid = false;
        $observedExitCode = -1;
        $startedAt = self::monotonicSeconds();
        $deadline = $startedAt + $timeoutSeconds;
        [$terminateGrace, $killGrace] = self::terminationBudgets($timeoutSeconds);
        $executionDeadline = $deadline - $terminateGrace - $killGrace;
        $launchDeadline = \min($executionDeadline, $startedAt + 2.0);
        $status = ['running' => true, 'exitcode' => -1];
        $provenExited = false;
        $groupReady = false;

        while (true) {
            $now = self::monotonicSeconds();
            $waitMicros = (int)\max(0, \min(
                self::SELECT_MICROSECONDS,
                ($executionDeadline - $now) * 1_000_000,
            ));
            self::drainReadyPipes($pipes, $stdout, $stderr, $truncated, $waitMicros);
            self::drainReadyToken($pipes, $readyBuffer, $launchInvalid);
            if (!$groupReady && !$launchInvalid && \str_contains($readyBuffer, "\n")) {
                $groupReady = \hash_equals(
                    'wls-process-group/1 ' . $processId . "\n",
                    $readyBuffer,
                );
                $launchInvalid = !$groupReady;
                if (isset($pipes[3]) && \is_resource($pipes[3])) {
                    @\fclose($pipes[3]);
                    unset($pipes[3]);
                }
            }
            $status = @\proc_get_status($process);
            if (!\is_array($status)) {
                $statusUnknown = true;
                $status = ['running' => true, 'exitcode' => -1];
                break;
            }
            $exitCode = (int)($status['exitcode'] ?? -1);
            if ($exitCode >= 0) {
                $observedExitCode = $exitCode;
            }
            if (!(bool)($status['running'] ?? false)) {
                $provenExited = true;
                break;
            }
            $now = self::monotonicSeconds();
            if ($launchInvalid || (!$groupReady && $now >= $launchDeadline)) {
                $launchInvalid = true;
                break;
            }
            if ($now >= $executionDeadline) {
                $timedOut = true;
                break;
            }
        }
        if (!$groupReady) {
            $launchInvalid = true;
        }

        if ($timedOut || $statusUnknown || $launchInvalid) {
            // Close our pipe ends before signalling. A grandchild may have
            // inherited the writer and must not keep the recovery path tied
            // to EOF or backpressure after the direct command is terminated.
            self::closePipes($pipes);
            self::signalProcessTree($process, $processId, $groupReady, 15);
            $provenExited = self::waitForExit(
                $process,
                \min($deadline, self::monotonicSeconds() + $terminateGrace),
                $observedExitCode,
            );
            if (!$provenExited) {
                self::signalProcessTree($process, $processId, $groupReady, 9);
                $provenExited = self::waitForExit(
                    $process,
                    $deadline,
                    $observedExitCode,
                );
            }
        } else {
            // A descendant may have inherited a pipe after the direct command
            // exited. Drain only bounded chunks and then close our ends; never
            // wait for descendant EOF.
            for ($attempt = 0; $attempt < 4; $attempt++) {
                self::drainReadyPipes($pipes, $stdout, $stderr, $truncated, 0);
            }
            self::closePipes($pipes);
        }

        $closeCode = -1;
        if ($provenExited) {
            $closeCode = (int)@\proc_close($process);
        } else {
            // Calling proc_close on a process that survived both bounded
            // termination stages would reintroduce the unbounded wait this
            // runner exists to prevent. Retain a small bounded reap queue so
            // the resource destructor cannot turn this invocation back into
            // an implicit wait; a later command collects confirmed exits.
            self::$deferredProcesses[] = [
                'process' => $process,
                'group_id' => $groupReady ? $processId : 0,
            ];
        }

        $descendantsSurvived = false;
        if ($groupReady && self::posixGroupExists($processId)) {
            $descendantsSurvived = true;
            @\posix_kill(-$processId, 15);
            self::waitForGroupExit(
                $processId,
                \min($deadline, self::monotonicSeconds() + $terminateGrace),
            );
            if (self::posixGroupExists($processId)) {
                @\posix_kill(-$processId, 9);
                self::waitForGroupExit($processId, $deadline);
            }
        }

        $code = $timedOut
            ? 124
            : ($statusUnknown || $launchInvalid || !$provenExited || $descendantsSurvived
                ? 125
                : ($observedExitCode >= 0 ? $observedExitCode : $closeCode));
        if ($code < 0) {
            $code = 125;
        }
        // A successful exit is not a trustworthy success when the caller only
        // received a prefix of the command's evidence.  Process ownership and
        // service identity scans in particular must fail closed instead of
        // accepting an incomplete result set.
        if ($truncated && $failOnTruncatedOutput && $code === 0) {
            $code = 125;
        }
        if ($timedOut) {
            self::appendChannel($stdout, $stderr, 2, "\nWLS gateway command timed out.\n", $truncated);
        } elseif ($launchInvalid) {
            self::appendChannel(
                $stdout,
                $stderr,
                2,
                "\nWLS gateway command did not establish an isolated process group.\n",
                $truncated,
            );
        } elseif ($descendantsSurvived) {
            self::appendChannel(
                $stdout,
                $stderr,
                2,
                "\nWLS gateway command left descendant processes after exit.\n",
                $truncated,
            );
        } elseif ($statusUnknown || !$provenExited) {
            self::appendChannel(
                $stdout,
                $stderr,
                2,
                "\nWLS gateway command exit state remained unknown after bounded termination.\n",
                $truncated,
            );
        }
        $stdout = self::sanitizeDiagnostic($stdout);
        $stderr = self::sanitizeDiagnostic($stderr);
        $output = $stdout . ($stdout !== '' && $stderr !== '' ? "\n" : '') . $stderr;
        if ($truncated) {
            $payloadLimit = self::MAX_OUTPUT_BYTES - \strlen(self::TRUNCATION_MARKER);
            $output = \substr($output, 0, \max(0, $payloadLimit))
                . self::TRUNCATION_MARKER;
        }

        return [
            'code' => $code,
            'output' => self::sanitizeDiagnostic($output),
            'stdout' => $stdout,
            'stderr' => $stderr,
            'truncated' => $truncated,
        ];
    }

    /**
     * The Windows implementation is supplied by the packaged
     * wls-bounded-command.exe helper.  Fail closed until the immutable helper
     * and its result protocol have both been verified by the caller.
     *
     * @param list<string> $command
     * @return array{code:int,output:string,stdout:string,stderr:string,truncated:bool}
     */
    private static function runWindowsNative(
        array $command,
        float $timeoutSeconds,
        ?string $workingDirectory,
        bool $failOnTruncatedOutput,
        ?array $windowsHelperProof,
        ?array $environment,
    ): array {
        if ($environment !== null) {
            return self::failure(
                127,
                'Exact custom environments are unavailable for Windows bounded commands.',
            );
        }
        try {
            $helperProof = self::resolveWindowsHelperProof($windowsHelperProof);
        } catch (\Throwable $throwable) {
            return self::failure(
                125,
                'The native Windows bounded-command helper failed verification: '
                    . $throwable->getMessage(),
            );
        }
        if ($helperProof === null) {
            return self::failure(
                127,
                'The signed WLS Windows bounded-command helper is unavailable.',
            );
        }

        return self::executeWindowsHelper(
            $helperProof,
            $command,
            $timeoutSeconds,
            $workingDirectory,
            $failOnTruncatedOutput,
        );
    }

    /**
     * @param array<string,mixed>|null $provided
     * @return array{path:string,size:int,sha256:string,source:string}|null
     */
    private static function resolveWindowsHelperProof(?array $provided): ?array
    {
        if ($provided !== null) {
            return self::validateWindowsHelperProof($provided);
        }

        // A host Controller must use the helper from the same immutable A/B
        // slot as its locked PHP binary. This is deliberately independent of
        // the active-slot pointer so an old healthy Controller can finish a
        // fenced handoff without silently executing bytes from the new slot.
        $php = @\realpath((string)\PHP_BINARY);
        if (\is_string($php) && $php !== '') {
            $binDirectory = \dirname($php);
            $slotDirectory = \dirname($binDirectory);
            $slot = \strtoupper(\basename($slotDirectory));
            if (\hash_equals('bin', \strtolower(\basename($binDirectory)))
                && \in_array($slot, ['A', 'B'], true)
            ) {
                $paths = new GatewayPaths();
                $expected = $paths->slotDir($slot);
                if (self::sameWindowsPath($slotDirectory, $expected)) {
                    return self::validateWindowsHelperProof(
                        (new HostGatewayPackageManager($paths))
                            ->boundedCommandHelperProof($slot),
                    );
                }
            }
        }

        $bootstrap = (new WindowsBoundedCommandBootstrapResolver())->resolve();
        return $bootstrap === null
            ? null
            : self::validateWindowsHelperProof($bootstrap);
    }

    /**
     * @param array<string,mixed> $proof
     * @return array{path:string,size:int,sha256:string,source:string}
     */
    private static function validateWindowsHelperProof(array $proof): array
    {
        $keys = \array_keys($proof);
        \sort($keys, \SORT_STRING);
        $path = \trim((string)($proof['path'] ?? ''));
        $size = $proof['size'] ?? null;
        $digest = \strtolower(\trim((string)($proof['sha256'] ?? '')));
        $source = \trim((string)($proof['source'] ?? ''));
        if ($keys !== ['path', 'sha256', 'size', 'source']
            || !\is_int($size)
            || $size < 1
            || $size > self::WINDOWS_HELPER_MAX_BYTES
            || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
            || $source === ''
            || \strlen($source) > 128
            || \preg_match('/\A[A-Za-z]:[\\\\\/]/D', $path) !== 1
            || \str_contains($path, "\0")
        ) {
            throw new \RuntimeException('Windows helper proof is malformed.');
        }
        $canonical = @\realpath($path);
        if (!\is_string($canonical)
            || !self::sameWindowsPath($path, $canonical)
            || \is_link($path)
        ) {
            throw new \RuntimeException('Windows helper path is aliased or linked.');
        }
        $bytes = GatewayProjectStateFilesystem::read(
            $canonical,
            self::WINDOWS_HELPER_MAX_BYTES,
            'WLS Windows bounded-command helper',
        );
        if (\strlen($bytes) !== $size
            || !\hash_equals($digest, \hash('sha256', $bytes))
        ) {
            throw new \RuntimeException('Windows helper bytes changed after verification.');
        }

        return [
            'path' => \str_replace('/', '\\', $canonical),
            'size' => $size,
            'sha256' => $digest,
            'source' => $source,
        ];
    }

    /**
     * @param array{path:string,size:int,sha256:string,source:string} $helper
     * @param list<string> $command
     * @return array{code:int,output:string,stdout:string,stderr:string,truncated:bool}
     */
    private static function executeWindowsHelper(
        array $helper,
        array $command,
        float $timeoutSeconds,
        ?string $workingDirectory,
        bool $failOnTruncatedOutput,
    ): array {
        try {
            $command = self::canonicalWindowsCommand($command);
            self::assertWindowsCommandLine($command);
            $resultParent = self::windowsResultParent();
            $resultDirectory = $resultParent . DIRECTORY_SEPARATOR
                . 'result-' . \bin2hex(\random_bytes(16));
            if (\strlen($resultDirectory) > 220
                || \file_exists($resultDirectory)
                || \is_link($resultDirectory)
            ) {
                throw new \RuntimeException(
                    'Windows helper result path is unavailable or outside bounds.'
                );
            }
            $timeoutMilliseconds = (int)\ceil($timeoutSeconds * 1000.0);
            $helperCommand = [
                $helper['path'],
                '--result-dir=' . \str_replace('/', '\\', $resultDirectory),
                '--timeout-ms=' . $timeoutMilliseconds,
            ];
            if ($workingDirectory !== null) {
                $helperCommand[] = '--cwd=' . \str_replace('/', '\\', $workingDirectory);
            }
            $helperCommand[] = '--';
            foreach ($command as $argument) {
                $helperCommand[] = $argument;
            }
            self::assertWindowsCommandLine($helperCommand);
        } catch (\Throwable $throwable) {
            return self::failure(125, $throwable->getMessage());
        }

        $pipes = [];
        $process = @\proc_open(
            $helperCommand,
            [
                0 => ['file', 'NUL', 'r'],
                1 => ['file', 'NUL', 'a'],
                2 => ['file', 'NUL', 'a'],
            ],
            $pipes,
            $resultParent,
            null,
            ['bypass_shell' => true, 'blocking_pipes' => false],
        );
        if (!\is_resource($process)) {
            return self::failure(125, 'Unable to launch the Windows bounded-command helper.');
        }
        $status = @\proc_get_status($process);
        $helperPid = \is_array($status) ? (int)($status['pid'] ?? 0) : 0;
        if ($helperPid <= 0) {
            @\proc_terminate($process, 9);
            self::$deferredProcesses[] = [
                'process' => $process,
                'group_id' => 0,
                'result_dir' => $resultDirectory,
            ];
            return self::failure(125, 'Windows helper process identity is unavailable.');
        }

        $helperExit = -1;
        $provenExited = false;
        $outerDeadline = self::monotonicSeconds()
            + $timeoutSeconds + self::WINDOWS_OUTER_GRACE_SECONDS;
        do {
            $status = @\proc_get_status($process);
            if (\is_array($status)) {
                $exit = (int)($status['exitcode'] ?? -1);
                if ($exit >= 0) {
                    $helperExit = $exit;
                }
                if (($status['running'] ?? false) !== true) {
                    $provenExited = true;
                    break;
                }
            }
            $remaining = $outerDeadline - self::monotonicSeconds();
            if ($remaining <= 0.0) {
                break;
            }
            \usleep((int)\max(1, \min(10_000, $remaining * 1_000_000)));
        } while (true);

        if (!$provenExited) {
            @\proc_terminate($process);
            $provenExited = self::waitForExit(
                $process,
                self::monotonicSeconds() + self::WINDOWS_TERMINATE_GRACE_SECONDS,
                $helperExit,
            );
        }
        if (!$provenExited) {
            @\proc_terminate($process, 9);
            $provenExited = self::waitForExit(
                $process,
                self::monotonicSeconds() + self::WINDOWS_TERMINATE_GRACE_SECONDS,
                $helperExit,
            );
        }
        if (!$provenExited) {
            self::$deferredProcesses[] = [
                'process' => $process,
                'group_id' => 0,
                'result_dir' => $resultDirectory,
            ];
            return self::failure(
                125,
                'Windows bounded-command helper exceeded its outer watchdog and did not exit.',
            );
        }
        $closeCode = (int)@\proc_close($process);
        if ($helperExit < 0) {
            $helperExit = $closeCode;
        }
        if ($helperExit !== 0) {
            try {
                self::removeWindowsResultTree($resultDirectory);
            } catch (\Throwable $throwable) {
                return self::failure(
                    125,
                    'Windows bounded-command helper failed and its result tree could not be removed: '
                        . $throwable->getMessage(),
                );
            }
            return self::failure(
                125,
                'Windows bounded-command helper infrastructure failed with exit code '
                    . $helperExit . '.',
            );
        }

        $parsed = null;
        $failure = null;
        try {
            $parsed = self::readWindowsHelperResult($resultDirectory);
        } catch (\Throwable $throwable) {
            $failure = $throwable;
        }
        try {
            self::removeWindowsResultTree($resultDirectory);
        } catch (\Throwable $throwable) {
            $failure ??= $throwable;
        }
        if ($failure instanceof \Throwable || !\is_array($parsed)) {
            return self::failure(
                125,
                'Windows bounded-command result failed verification or cleanup: '
                    . ($failure?->getMessage() ?? 'unknown result failure'),
            );
        }

        $stdout = (string)$parsed['stdout'];
        $stderr = (string)$parsed['stderr'];
        $truncated = (bool)$parsed['truncated'];
        $timedOut = (bool)$parsed['timed_out'];
        $code = $timedOut ? 124 : (int)$parsed['exit_code'];
        if ($truncated && $failOnTruncatedOutput && $code === 0) {
            $code = 125;
        }
        if ($timedOut) {
            self::appendChannel(
                $stdout,
                $stderr,
                2,
                "\nWLS gateway command timed out.\n",
                $truncated,
            );
        }
        $stdout = self::sanitizeDiagnostic($stdout);
        $stderr = self::sanitizeDiagnostic($stderr);
        $output = $stdout . ($stdout !== '' && $stderr !== '' ? "\n" : '') . $stderr;
        if ($truncated) {
            $payloadLimit = self::MAX_OUTPUT_BYTES - \strlen(self::TRUNCATION_MARKER);
            $output = \substr($output, 0, \max(0, $payloadLimit))
                . self::TRUNCATION_MARKER;
        }

        return [
            'code' => $code,
            'output' => self::sanitizeDiagnostic($output),
            'stdout' => $stdout,
            'stderr' => $stderr,
            'truncated' => $truncated,
        ];
    }

    /** @param list<string> $command @return list<string> */
    private static function canonicalWindowsCommand(array $command): array
    {
        $executable = $command[0] ?? '';
        $canonical = @\realpath($executable);
        if (!\is_string($canonical)
            || !self::sameWindowsPath($executable, $canonical)
            || \is_link($executable)
            || !\is_file($canonical)
        ) {
            throw new \RuntimeException(
                'Windows bounded commands require one canonical non-linked executable.'
            );
        }
        $command[0] = \str_replace('/', '\\', $canonical);

        return $command;
    }

    /** @param list<string> $command */
    private static function assertWindowsCommandLine(array $command): void
    {
        if (!\function_exists('iconv')) {
            throw new \RuntimeException(
                'Windows bounded-command argument validation requires iconv.'
            );
        }
        // CreateProcessW accepts at most 32,767 UTF-16 code units including
        // quoting and the terminal NUL. Reserve a fixed margin for escaping.
        $units = 1;
        foreach ($command as $argument) {
            $encoded = @\iconv('UTF-8', 'UTF-16LE', $argument);
            if (!\is_string($encoded)) {
                throw new \InvalidArgumentException(
                    'Windows command arguments must be valid UTF-8.'
                );
            }
            $units += (int)(\strlen($encoded) / 2) * 2 + 3;
            if ($units > 30_000) {
                throw new \InvalidArgumentException(
                    'Windows command line exceeds its fixed UTF-16 limit.'
                );
            }
        }
    }

    private static function windowsResultParent(): string
    {
        $temporary = @\realpath((string)\sys_get_temp_dir());
        if (!\is_string($temporary)
            || \preg_match('/\A[A-Za-z]:[\\\\\/]/D', $temporary) !== 1
            || \is_link((string)\sys_get_temp_dir())
        ) {
            throw new \RuntimeException(
                'Windows bounded-command temporary root is not a canonical local path.'
            );
        }
        $parent = \rtrim($temporary, '/\\') . DIRECTORY_SEPARATOR
            . self::WINDOWS_RESULT_PARENT_LEAF;
        if (!\is_dir($parent)
            && !@\mkdir($parent, 0700)
            && !\is_dir($parent)
        ) {
            throw new \RuntimeException(
                'Unable to create the Windows bounded-command result parent.'
            );
        }
        $canonical = @\realpath($parent);
        if (!\is_string($canonical)
            || !self::sameWindowsPath($parent, $canonical)
            || \is_link($parent)
            || \strlen($canonical) > 170
        ) {
            throw new \RuntimeException(
                'Windows bounded-command result parent is aliased or too long.'
            );
        }

        return \str_replace('/', '\\', $canonical);
    }

    /**
     * @return array{
     *   exit_code:int,
     *   timed_out:bool,
     *   truncated:bool,
     *   stdout:string,
     *   stderr:string
     * }
     */
    private static function readWindowsHelperResult(string $directory): array
    {
        $expectedLeaves = ['result.json', 'stderr.bin', 'stdout.bin'];
        $records = GatewayBoundedTreeWalker::collect($directory, false, false);
        if (\count($records) !== 3) {
            throw new \RuntimeException(
                'Windows bounded-command result tree has an unexpected entry count.'
            );
        }
        $byLeaf = [];
        foreach ($records as $record) {
            $leaf = \basename((string)$record['path']);
            if (($record['directory'] ?? true) === true
                || !\in_array($leaf, $expectedLeaves, true)
                || isset($byLeaf[$leaf])
                || !self::sameWindowsPath(\dirname((string)$record['path']), $directory)
            ) {
                throw new \RuntimeException(
                    'Windows bounded-command result tree contains an unknown artifact.'
                );
            }
            GatewayBoundedTreeWalker::revalidate($record);
            $byLeaf[$leaf] = $record;
        }
        \sort($expectedLeaves, \SORT_STRING);
        $actualLeaves = \array_keys($byLeaf);
        \sort($actualLeaves, \SORT_STRING);
        if ($actualLeaves !== $expectedLeaves) {
            throw new \RuntimeException(
                'Windows bounded-command result tree is incomplete.'
            );
        }

        $json = GatewayProjectStateFilesystem::read(
            $directory . DIRECTORY_SEPARATOR . 'result.json',
            self::WINDOWS_HELPER_RESULT_MAX_BYTES,
            'Windows bounded-command result manifest',
        );
        $result = \json_decode($json, true, 8, \JSON_THROW_ON_ERROR);
        $keys = \is_array($result) ? \array_keys($result) : [];
        \sort($keys, \SORT_STRING);
        $expectedKeys = [
            'exit_code',
            'schema',
            'stderr_bytes',
            'stderr_sha256',
            'stdout_bytes',
            'stdout_sha256',
            'timed_out',
            'truncated',
        ];
        if (!\is_array($result)) {
            throw new \RuntimeException(
                'Windows bounded-command result manifest is not an object.'
            );
        }
        $stdoutBytes = $result['stdout_bytes'] ?? null;
        $stderrBytes = $result['stderr_bytes'] ?? null;
        $exitCode = $result['exit_code'] ?? null;
        $timedOut = $result['timed_out'] ?? null;
        $truncated = $result['truncated'] ?? null;
        if ($keys !== $expectedKeys
            || !\hash_equals(
                self::WINDOWS_HELPER_RESULT_SCHEMA,
                (string)($result['schema'] ?? ''),
            )
            || !\is_int($exitCode)
            || $exitCode < 0
            || $exitCode > 4_294_967_295
            || !\is_bool($timedOut)
            || !\is_bool($truncated)
            || !\is_int($stdoutBytes)
            || !\is_int($stderrBytes)
            || $stdoutBytes < 0
            || $stderrBytes < 0
            || $stdoutBytes > self::MAX_OUTPUT_BYTES
            || $stderrBytes > self::MAX_OUTPUT_BYTES
            || $stdoutBytes + $stderrBytes > self::MAX_OUTPUT_BYTES
            || ($timedOut && $exitCode !== 124)
            || ($truncated && $stdoutBytes + $stderrBytes !== self::MAX_OUTPUT_BYTES)
            || \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($result['stdout_sha256'] ?? ''),
            ) !== 1
            || \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($result['stderr_sha256'] ?? ''),
            ) !== 1
        ) {
            throw new \RuntimeException(
                'Windows bounded-command result manifest contract is invalid.'
            );
        }
        $stdout = GatewayProjectStateFilesystem::read(
            $directory . DIRECTORY_SEPARATOR . 'stdout.bin',
            self::MAX_OUTPUT_BYTES,
            'Windows bounded-command stdout',
            true,
        );
        $stderr = GatewayProjectStateFilesystem::read(
            $directory . DIRECTORY_SEPARATOR . 'stderr.bin',
            self::MAX_OUTPUT_BYTES,
            'Windows bounded-command stderr',
            true,
        );
        if (\strlen($stdout) !== $stdoutBytes
            || \strlen($stderr) !== $stderrBytes
            || !\hash_equals(
                (string)$result['stdout_sha256'],
                \hash('sha256', $stdout),
            )
            || !\hash_equals(
                (string)$result['stderr_sha256'],
                \hash('sha256', $stderr),
            )
        ) {
            throw new \RuntimeException(
                'Windows bounded-command captured bytes failed digest verification.'
            );
        }
        foreach ($byLeaf as $record) {
            GatewayBoundedTreeWalker::revalidate($record);
        }

        return [
            'exit_code' => $exitCode,
            'timed_out' => $timedOut,
            'truncated' => $truncated,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    private static function removeWindowsResultTree(string $directory): void
    {
        if (!\file_exists($directory) && !\is_link($directory)) {
            return;
        }
        $records = GatewayBoundedTreeWalker::collect($directory, true, true);
        foreach ($records as $record) {
            GatewayBoundedTreeWalker::revalidate($record);
            $removed = ($record['directory'] ?? false) === true
                ? @\rmdir((string)$record['path'])
                : @\unlink((string)$record['path']);
            if (!$removed) {
                throw new \RuntimeException(
                    'Unable to remove Windows bounded-command result artifact.'
                );
            }
        }
    }

    private static function sameWindowsPath(string $left, string $right): bool
    {
        $normalize = static fn(string $path): string => \strtolower(\rtrim(
            \str_replace('\\', '/', $path),
            '/',
        ));
        $left = $normalize($left);
        $right = $normalize($right);

        return $left !== '' && \hash_equals($left, $right);
    }

    /** @param list<string> $command */
    private static function assertCommand(array $command): void
    {
        if ($command === []) {
            throw new \InvalidArgumentException('Gateway command cannot be empty.');
        }
        if (!\array_is_list($command)) {
            throw new \InvalidArgumentException('Gateway command arguments must be a list.');
        }
        if (\count($command) > self::MAX_ARGUMENTS) {
            throw new \InvalidArgumentException('Gateway command has too many arguments.');
        }
        $argumentBytes = 0;
        foreach ($command as $argument) {
            if (!\is_string($argument) || \str_contains($argument, "\0")) {
                throw new \InvalidArgumentException(
                    'Gateway command arguments must be NUL-free strings.'
                );
            }
            $argumentBytes += \strlen($argument);
            if ($argumentBytes > self::MAX_ARGUMENT_BYTES) {
                throw new \InvalidArgumentException('Gateway command arguments are too large.');
            }
        }
        if ($command[0] === '') {
            throw new \InvalidArgumentException('Gateway executable cannot be empty.');
        }
        $absolute = \PHP_OS_FAMILY === 'Windows'
            ? \preg_match('/\A[A-Za-z]:[\\\\\/]/D', $command[0]) === 1
            : \str_starts_with($command[0], '/');
        if (!$absolute) {
            throw new \InvalidArgumentException(
                'Gateway privileged commands require an absolute executable path.',
            );
        }
    }

    private static function assertWorkingDirectory(?string $workingDirectory): void
    {
        if ($workingDirectory === null) {
            return;
        }
        if ($workingDirectory === '' || \str_contains($workingDirectory, "\0")) {
            throw new \InvalidArgumentException('Gateway command working directory is invalid.');
        }
        $absolute = \PHP_OS_FAMILY === 'Windows'
            ? \preg_match('/\A[A-Za-z]:[\\\\\/]/D', $workingDirectory) === 1
            : \str_starts_with($workingDirectory, '/');
        $real = $absolute ? @\realpath($workingDirectory) : false;
        if (!\is_string($real) || $real === '' || !\is_dir($real)) {
            throw new \InvalidArgumentException('Gateway command working directory must be an existing absolute directory.');
        }
        $expected = \rtrim(\str_replace('\\', '/', $workingDirectory), '/');
        $actual = \rtrim(\str_replace('\\', '/', $real), '/');
        if (\PHP_OS_FAMILY === 'Windows') {
            $expected = \strtolower($expected);
            $actual = \strtolower($actual);
        }
        if ($expected === '' || !\hash_equals($expected, $actual)) {
            throw new \InvalidArgumentException('Gateway command working directory must not traverse aliases or links.');
        }
    }

    private static function assertTimeout(float $timeoutSeconds): void
    {
        if (!\is_finite($timeoutSeconds)
            || $timeoutSeconds < self::MIN_TIMEOUT_SECONDS
            || $timeoutSeconds > self::MAX_TIMEOUT_SECONDS
        ) {
            throw new \InvalidArgumentException(\sprintf(
                'Gateway command timeout must be between %.1f and %.1f seconds.',
                self::MIN_TIMEOUT_SECONDS,
                self::MAX_TIMEOUT_SECONDS,
            ));
        }
    }

    /** @param array<string,string>|null $environment */
    private static function assertEnvironment(?array $environment): void
    {
        if ($environment === null) {
            return;
        }
        if (\count($environment) > self::MAX_ENVIRONMENT_VARIABLES) {
            throw new \InvalidArgumentException(
                'Gateway command environment has too many variables.'
            );
        }
        $bytes = 0;
        foreach ($environment as $name => $value) {
            if (!\is_string($name)
                || !\is_string($value)
                || \preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,127}\z/D', $name) !== 1
                || \str_contains($value, "\0")
            ) {
                throw new \InvalidArgumentException(
                    'Gateway command environment contains an invalid name or value.'
                );
            }
            $bytes += \strlen($name) + \strlen($value) + 2;
            if ($bytes > self::MAX_ENVIRONMENT_BYTES) {
                throw new \InvalidArgumentException(
                    'Gateway command environment exceeds its fixed byte limit.'
                );
            }
        }
    }

    /** @return array{0:float,1:float} */
    private static function terminationBudgets(float $timeoutSeconds): array
    {
        $perStage = \min(
            self::TERMINATE_GRACE_SECONDS,
            self::KILL_GRACE_SECONDS,
            \max(0.01, $timeoutSeconds * 0.1),
        );

        return [$perStage, $perStage];
    }

    /**
     * @param resource $process
     */
    private static function waitForExit(
        $process,
        float $absoluteDeadline,
        int &$observedExitCode,
    ): bool {
        do {
            $status = @\proc_get_status($process);
            if (\is_array($status)) {
                $exitCode = (int)($status['exitcode'] ?? -1);
                if ($exitCode >= 0) {
                    $observedExitCode = $exitCode;
                }
                if (!(bool)($status['running'] ?? false)) {
                    return true;
                }
            }
            $remaining = $absoluteDeadline - self::monotonicSeconds();
            if ($remaining <= 0.0) {
                break;
            }
            \usleep((int)\max(1, \min(
                self::SELECT_MICROSECONDS,
                $remaining * 1_000_000,
            )));
        } while (true);

        return false;
    }

    /**
     * @param array<int,resource> $pipes
     */
    private static function drainReadyPipes(
        array $pipes,
        string &$stdout,
        string &$stderr,
        bool &$truncated,
        int $waitMicroseconds,
    ): void {
        $read = [];
        foreach ([1, 2] as $index) {
            if (isset($pipes[$index])
                && \is_resource($pipes[$index])
                && !@\feof($pipes[$index])
            ) {
                $read[] = $pipes[$index];
            }
        }
        if ($read === []) {
            if ($waitMicroseconds > 0) {
                \usleep($waitMicroseconds);
            }
            return;
        }
        $write = null;
        $except = null;
        $selected = @\stream_select(
            $read,
            $write,
            $except,
            0,
            \max(0, $waitMicroseconds),
        );
        if (!\is_int($selected)) {
            if ($waitMicroseconds > 0) {
                \usleep($waitMicroseconds);
            }
            return;
        }
        if ($selected < 1) {
            return;
        }
        foreach ($read as $pipe) {
            $index = $pipe === ($pipes[1] ?? null) ? 1 : 2;
            $drained = 0;
            while ($drained < self::MAX_DRAIN_BYTES_PER_PIPE_CYCLE) {
                $remaining = self::MAX_DRAIN_BYTES_PER_PIPE_CYCLE - $drained;
                $chunk = @\fread($pipe, \min(8192, $remaining));
                if (!\is_string($chunk) || $chunk === '') {
                    break;
                }
                $drained += \strlen($chunk);
                self::appendChannel($stdout, $stderr, $index, $chunk, $truncated);
            }
        }
    }

    /** @param array<int,resource> $pipes */
    private static function drainReadyToken(
        array $pipes,
        string &$readyBuffer,
        bool &$invalid,
    ): void {
        if ($invalid || !isset($pipes[3]) || !\is_resource($pipes[3])) {
            return;
        }
        while (\strlen($readyBuffer) <= self::MAX_READY_BYTES) {
            $remaining = self::MAX_READY_BYTES + 1 - \strlen($readyBuffer);
            $chunk = @\fread($pipes[3], \min(128, $remaining));
            if (!\is_string($chunk) || $chunk === '') {
                break;
            }
            $readyBuffer .= $chunk;
            if (\str_contains($readyBuffer, "\n")) {
                break;
            }
        }
        if (\strlen($readyBuffer) > self::MAX_READY_BYTES
            || (\str_contains($readyBuffer, "\n")
                && !\str_ends_with($readyBuffer, "\n"))
        ) {
            $invalid = true;
        }
    }

    /** @param resource $process */
    private static function signalProcessTree(
        $process,
        int $processId,
        bool $groupReady,
        int $signal,
    ): void {
        if ($groupReady && $processId > 1) {
            @\posix_kill(-$processId, $signal);
            return;
        }
        @\proc_terminate($process, $signal);
    }

    private static function posixGroupExists(int $processGroupId): bool
    {
        if ($processGroupId < 2) {
            return false;
        }
        if (@\posix_kill(-$processGroupId, 0)) {
            return true;
        }

        return \function_exists('posix_get_last_error')
            && \posix_get_last_error() === 1;
    }

    private static function waitForGroupExit(int $processGroupId, float $absoluteDeadline): bool
    {
        while (self::posixGroupExists($processGroupId)) {
            $remaining = $absoluteDeadline - self::monotonicSeconds();
            if ($remaining <= 0.0) {
                return false;
            }
            \usleep((int)\max(1, \min(
                self::SELECT_MICROSECONDS,
                $remaining * 1_000_000,
            )));
        }

        return true;
    }

    private static function appendChannel(
        string &$stdout,
        string &$stderr,
        int $index,
        string $chunk,
        bool &$truncated,
    ): void {
        if ($chunk === '') {
            return;
        }
        $payloadLimit = self::MAX_OUTPUT_BYTES - \strlen(self::TRUNCATION_MARKER);
        $available = $payloadLimit - \strlen($stdout) - \strlen($stderr);
        if ($available <= 0) {
            $truncated = true;
            return;
        }
        if (\strlen($chunk) > $available) {
            if ($index === 1) {
                $stdout .= \substr($chunk, 0, $available);
            } else {
                $stderr .= \substr($chunk, 0, $available);
            }
            $truncated = true;
            return;
        }
        if ($index === 1) {
            $stdout .= $chunk;
        } else {
            $stderr .= $chunk;
        }
    }

    /** @param array<int,resource> $pipes */
    private static function closePipes(array &$pipes): void
    {
        foreach ($pipes as $index => $pipe) {
            if (\is_resource($pipe)) {
                @\fclose($pipe);
            }
            unset($pipes[$index]);
        }
    }

    private static function sanitizeDiagnostic(string $output): string
    {
        $output = \str_replace(["\r\n", "\r"], "\n", $output);
        $output = \preg_replace(
            '/\x1B(?:\[[0-?]*[ -\/]*[@-~]|\][^\x07]*(?:\x07|\x1B\\\\))/',
            '',
            $output,
        ) ?? '';
        $output = \preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '?', $output) ?? '';
        // Remove Unicode bidi/isolate markers that can visually reorder a
        // privileged diagnostic while preserving ordinary localized text.
        $output = \str_replace([
            "\xE2\x80\xAA", "\xE2\x80\xAB", "\xE2\x80\xAC",
            "\xE2\x80\xAD", "\xE2\x80\xAE", "\xE2\x81\xA6",
            "\xE2\x81\xA7", "\xE2\x81\xA8", "\xE2\x81\xA9",
        ], '', $output);

        return \trim(\substr($output, 0, self::MAX_OUTPUT_BYTES));
    }

    /** @return array{code:int,output:string,stdout:string,stderr:string,truncated:bool} */
    private static function failure(int $code, string $message): array
    {
        $message = self::sanitizeDiagnostic($message);

        return [
            'code' => $code,
            'output' => $message,
            'stdout' => '',
            'stderr' => $message,
            'truncated' => false,
        ];
    }

    private static function reapDeferredProcesses(): bool
    {
        $remaining = [];
        $cleanupFailed = false;
        foreach (self::$deferredProcesses as $entry) {
            $process = $entry['process'] ?? null;
            $groupId = (int)($entry['group_id'] ?? 0);
            $resultDirectory = (string)($entry['result_dir'] ?? '');
            if (!\is_resource($process)) {
                continue;
            }
            $status = @\proc_get_status($process);
            if (\is_array($status) && !($status['running'] ?? false)) {
                @\proc_close($process);
                if ($resultDirectory !== '') {
                    try {
                        self::removeWindowsResultTree($resultDirectory);
                    } catch (\Throwable) {
                        $cleanupFailed = true;
                    }
                }
                continue;
            }
            if ($groupId > 1 && \PHP_OS_FAMILY !== 'Windows') {
                @\posix_kill(-$groupId, 9);
            } else {
                @\proc_terminate($process, 9);
            }
            $remaining[] = [
                'process' => $process,
                'group_id' => $groupId,
                'result_dir' => $resultDirectory,
            ];
        }
        self::$deferredProcesses = $remaining;
        return !$cleanupFailed
            && \count(self::$deferredProcesses) < self::MAX_DEFERRED_PROCESSES;
    }

    private static function monotonicSeconds(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }
}
