<?php

declare(strict_types=1);

/**
 * External session-detach helper for resumable task Runners.
 *
 * argv: [spawn.php, readyFile, phpBinary, ...runtime:task:run arguments]
 *
 * The READY file is written by the long-lived grandchild before posix_setsid()
 * so the HTTP launcher can bind the real Runner PID. The short-lived parent
 * exits immediately after fork and must not wait on READY.
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

$pid = getmypid() ?: 0;
if ($pid < 1 || @file_put_contents($readyFile, (string)$pid, LOCK_EX) === false) {
    fwrite(STDERR, "unable to publish Runner ready pid\n");
    exit(127);
}

if (function_exists('posix_setsid')) {
    $sessionId = @posix_setsid();
    if (!is_int($sessionId) || $sessionId <= 0) {
        fwrite(STDERR, "posix_setsid failed\n");
        exit(127);
    }
}

@pcntl_exec($phpBinary, array_values($argv));
fwrite(STDERR, "pcntl_exec failed\n");
exit(127);
