<?php

declare(strict_types=1);

/**
 * External session-detach helper for resumable task Runners.
 *
 * argv: [spawn.php, readyFile, phpBinary, ...runtime:task:run arguments]
 *
 * The READY file is written by the detached second child only after
 * posix_setsid() and the daemonizing second fork succeed, so the HTTP launcher
 * never observes a Runner that still belongs to the request process tree.
 */

if (!function_exists('pcntl_exec') || !function_exists('pcntl_fork')) {
    fwrite(STDERR, "pcntl_fork/pcntl_exec unavailable\n");
    exit(127);
}

$argv = $_SERVER['argv'] ?? [];
array_shift($argv); // spawn.php
$readyFile = (string)array_shift($argv);
$phpBinary = (string)array_shift($argv);
if ($readyFile === '' || $phpBinary === '' || $argv === []) {
    fwrite(STDERR, "invalid Runner argv\n");
    exit(127);
}

$child = @pcntl_fork();
if ($child < 0) {
    fwrite(STDERR, "pcntl_fork failed\n");
    exit(127);
}
if ($child > 0) {
    exit(0);
}

if (function_exists('posix_setsid')) {
    $sessionId = @posix_setsid();
    if (!is_int($sessionId) || $sessionId <= 0) {
        fwrite(STDERR, "posix_setsid failed\n");
        exit(127);
    }
}

$runner = @pcntl_fork();
if ($runner < 0) {
    fwrite(STDERR, "Runner daemon fork failed\n");
    exit(127);
}
if ($runner > 0) {
    exit(0);
}

$pid = getmypid() ?: 0;

// This helper can be launched from an HTTP/WLS worker. The detached Runner is
// a normal CLI process and must not inherit worker identity: Runtime would
// otherwise bootstrap WlsRuntime again and the task command would never run.
$wlsIdentityVariables = [
    'WLS_PROCESS_ROLE',
    'WLS_INSTANCE',
    'WLS_INSTANCE_NAME',
    'WLS_WORKER_ID',
    'WLS_WORKER_COUNT',
    'WLS_RUNTIME_TOPOLOGY',
    'WLS_PORT',
    'WLS_PUBLIC_ORIGIN',
    'WLS_REQUEST_COUNT',
    'WLS_PROCESS_TAG',
    'WLS_WORKER_PID',
];
foreach ($wlsIdentityVariables as $name) {
    unset($_SERVER[$name], $_ENV[$name]);
    if (!@\putenv($name)) {
        fwrite(STDERR, "unable to clear inherited WLS Runner identity\n");
        exit(127);
    }
}

if ($pid < 1 || @file_put_contents($readyFile, (string)$pid, LOCK_EX) === false) {
    fwrite(STDERR, "unable to publish Runner ready pid\n");
    exit(127);
}

@pcntl_exec($phpBinary, array_values($argv));
fwrite(STDERR, "pcntl_exec failed\n");
exit(127);
