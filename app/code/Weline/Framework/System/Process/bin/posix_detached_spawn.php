<?php

declare(strict_types=1);

/**
 * Out-of-process POSIX detach helper.
 *
 * WLS HTTP workers must not pcntl_fork() in-process: the child becomes a
 * zombie under the worker and never reaches queue:run. This helper is started
 * via proc_open from the worker, then forks + posix_setsid + clears WLS_*
 * env + pcntl_exec so the real PHP worker keeps the reported PID and runs
 * outside the WLS process group.
 *
 * Usage:
 *   php posix_detached_spawn.php <config.json-path>
 *
 * Config JSON file:
 *   { "cwd": "...", "argv": ["php","bin/w",...], "stdout": "/path/or/dev/null" }
 *
 * Prints the detached child PID on stdout and exits 0.
 */

if (\PHP_SAPI !== 'cli') {
    \fwrite(\STDERR, "posix_detached_spawn requires CLI\n");
    \exit(2);
}

$configPath = $argv[1] ?? '';
if ($configPath === '' || !\is_file($configPath)) {
    \fwrite(\STDERR, "spawn config file missing\n");
    \exit(2);
}
$configJson = (string)\file_get_contents($configPath);
$config = \json_decode($configJson, true);
if (!\is_array($config)) {
    \fwrite(\STDERR, "invalid spawn config\n");
    \exit(2);
}

$cwd = (string)($config['cwd'] ?? '');
$argvList = $config['argv'] ?? null;
$stdoutPath = (string)($config['stdout'] ?? '/dev/null');
if ($cwd === '' || !\is_dir($cwd) || !\is_array($argvList) || $argvList === []) {
    \fwrite(\STDERR, "spawn config missing cwd/argv\n");
    \exit(2);
}

$normalizedArgv = [];
foreach (\array_values($argvList) as $argument) {
    if (!\is_scalar($argument) && !$argument instanceof \Stringable) {
        \fwrite(\STDERR, "spawn argv must be scalar\n");
        \exit(2);
    }
    $argument = (string)$argument;
    if ($argument === '' || \str_contains($argument, "\0")) {
        \fwrite(\STDERR, "spawn argv invalid\n");
        \exit(2);
    }
    $normalizedArgv[] = $argument;
}

foreach (['pcntl_fork', 'pcntl_exec', 'posix_setsid'] as $required) {
    if (!\function_exists($required)) {
        \fwrite(\STDERR, "missing {$required}\n");
        \exit(2);
    }
}

$pid = \pcntl_fork();
if ($pid < 0) {
    \fwrite(\STDERR, "pcntl_fork failed\n");
    \exit(1);
}
if ($pid > 0) {
    // Helper parent: report the child PID (becomes the detached worker after setsid/exec).
    \fwrite(\STDOUT, (string)$pid . "\n");
    \exit(0);
}

$sessionId = @\posix_setsid();
if (!\is_int($sessionId) || $sessionId <= 0) {
    \exit(1);
}

@\chdir($cwd);

// Drop WLS worker identity so bin/w does not inherit in-worker runtime hooks
// (shared memory clients, supervisor topology, etc.) from the parent HTTP worker.
foreach (\array_keys($_ENV) as $envKey) {
    if (!\is_string($envKey) || !\str_starts_with($envKey, 'WLS_')) {
        continue;
    }
    @\putenv($envKey);
    unset($_ENV[$envKey], $_SERVER[$envKey]);
}
foreach (\array_keys($_SERVER) as $envKey) {
    if (!\is_string($envKey) || !\str_starts_with($envKey, 'WLS_')) {
        continue;
    }
    @\putenv($envKey);
    unset($_SERVER[$envKey], $_ENV[$envKey]);
}
@\putenv('WELINE_DETACHED_QUEUE_WORKER=1');
$_ENV['WELINE_DETACHED_QUEUE_WORKER'] = '1';
$_SERVER['WELINE_DETACHED_QUEUE_WORKER'] = '1';

@\fclose(\STDIN);
@\fclose(\STDOUT);
@\fclose(\STDERR);
$stdin = @\fopen('/dev/null', 'rb');
$stdout = @\fopen($stdoutPath !== '' ? $stdoutPath : '/dev/null', 'ab');
if (!\is_resource($stdout)) {
    $stdout = @\fopen('/dev/null', 'ab');
}
$stderr = @\fopen($stdoutPath !== '' ? $stdoutPath : '/dev/null', 'ab');
if (!\is_resource($stderr)) {
    $stderr = @\fopen('/dev/null', 'ab');
}

$php = (string)\array_shift($normalizedArgv);
@\fwrite($stdout, '[spawn] detached child pid=' . \getmypid() . ' at ' . \date('c') . PHP_EOL);
@\fwrite($stdout, '[spawn] exec=' . $php . ' ' . \implode(' ', $normalizedArgv) . PHP_EOL);
@\fflush($stdout);

// pcntl_exec keeps this PID as the queue worker. QueueDispatch records the
// helper-child PID before launch; passthru would start a *new* PHP PID and
// queue:run would refuse with "already running (pid=helper)".
@\pcntl_exec($php, $normalizedArgv);
$execError = \function_exists('pcntl_get_last_error') ? \pcntl_get_last_error() : 0;
$execText = \function_exists('pcntl_strerror') ? \pcntl_strerror($execError) : 'unknown';
@\fwrite(
    $stderr,
    '[spawn] pcntl_exec failed errno=' . $execError . ' message=' . $execText
    . ' php=' . $php . PHP_EOL
);
@\fflush($stderr);
\exit(127);
