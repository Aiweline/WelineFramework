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
 *   { "cwd": "...", "argv": ["php","bin/w",...], "stdout": "/path", "stderr": "/path" }
 *
 * Prints the detached child PID on stdout and exits 0.
 */

if (\PHP_SAPI !== 'cli') {
    \fwrite(\STDERR, "posix_detached_spawn requires CLI\n");
    \exit(2);
}

$configPath = $argv[1] ?? '';
if (
    $configPath === ''
    || \str_contains($configPath, "\0")
    || !\str_starts_with($configPath, '/')
    || \preg_match('/\Aspawn-[a-f0-9]{32}\.json\z/D', \basename($configPath)) !== 1
) {
    \fwrite(\STDERR, "spawn config file missing\n");
    \exit(2);
}

foreach (['pcntl_fork', 'pcntl_exec', 'posix_setsid', 'posix_geteuid'] as $required) {
    if (!\function_exists($required)) {
        \fwrite(\STDERR, "missing {$required}\n");
        \exit(2);
    }
}

$effectiveUid = \posix_geteuid();
$configDir = \dirname($configPath);
$configDirStat = @\lstat($configDir);
if (
    !\is_array($configDirStat)
    || (((int)($configDirStat['mode'] ?? 0) & 0170000) !== 0040000)
    || (((int)($configDirStat['mode'] ?? 0) & 0777) !== 0700)
    || (int)($configDirStat['uid'] ?? -1) !== $effectiveUid
    || \is_link($configDir)
) {
    \fwrite(\STDERR, "spawn config directory is unsafe\n");
    \exit(2);
}

$configPathStat = @\lstat($configPath);
if (
    !\is_array($configPathStat)
    || (((int)($configPathStat['mode'] ?? 0) & 0170000) !== 0100000)
    || (((int)($configPathStat['mode'] ?? 0) & 0777) !== 0600)
    || (int)($configPathStat['nlink'] ?? 0) !== 1
    || (int)($configPathStat['uid'] ?? -1) !== $effectiveUid
    || (int)($configPathStat['size'] ?? 0) <= 0
    || (int)($configPathStat['size'] ?? 0) > 1_048_576
) {
    \fwrite(\STDERR, "spawn config file is unsafe\n");
    \exit(2);
}

$configHandle = @\fopen($configPath, 'rb');
if (!\is_resource($configHandle)) {
    \fwrite(\STDERR, "spawn config file could not be opened\n");
    \exit(2);
}
$openedStat = @\fstat($configHandle);
$currentPathStat = @\lstat($configPath);
$sameIdentity = static function (array $left, array $right): bool {
    return (int)($left['dev'] ?? -1) === (int)($right['dev'] ?? -2)
        && (int)($left['ino'] ?? -1) === (int)($right['ino'] ?? -2)
        && (int)($left['mode'] ?? 0) === (int)($right['mode'] ?? -1)
        && (int)($left['uid'] ?? -1) === (int)($right['uid'] ?? -2)
        && (int)($left['nlink'] ?? 0) === 1
        && (int)($right['nlink'] ?? 0) === 1;
};
if (
    !\is_array($openedStat)
    || !\is_array($currentPathStat)
    || !$sameIdentity($configPathStat, $openedStat)
    || !$sameIdentity($openedStat, $currentPathStat)
    || (int)($openedStat['size'] ?? 0) <= 0
    || (int)($openedStat['size'] ?? 0) > 1_048_576
    || !@\unlink($configPath)
) {
    @\fclose($configHandle);
    \fwrite(\STDERR, "spawn config identity changed\n");
    \exit(2);
}

$configJson = '';
while (!\feof($configHandle) && \strlen($configJson) <= 1_048_576) {
    $chunk = @\fread($configHandle, 8192);
    if (!\is_string($chunk)) {
        @\fclose($configHandle);
        \fwrite(\STDERR, "spawn config read failed\n");
        \exit(2);
    }
    $configJson .= $chunk;
}
@\fclose($configHandle);
if (
    $configJson === ''
    || \strlen($configJson) > 1_048_576
    || \strlen($configJson) !== (int)($openedStat['size'] ?? -1)
) {
    \fwrite(\STDERR, "spawn config read was incomplete\n");
    \exit(2);
}

$config = \json_decode($configJson, true);
if (!\is_array($config)) {
    \fwrite(\STDERR, "invalid spawn config\n");
    \exit(2);
}

$cwd = (string)($config['cwd'] ?? '');
$argvList = $config['argv'] ?? null;
$stdoutPath = (string)($config['stdout'] ?? '/dev/null');
$stderrPath = (string)($config['stderr'] ?? $stdoutPath);
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

if (!@\chdir($cwd)) {
    \fwrite(\STDERR, "spawn working directory unavailable\n");
    \exit(70);
}

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
$stderr = @\fopen($stderrPath !== '' ? $stderrPath : '/dev/null', 'ab');
if (!\is_resource($stderr)) {
    $stderr = @\fopen('/dev/null', 'ab');
}

$php = (string)\array_shift($normalizedArgv);
$logArgv = [];
$redactNext = false;
foreach ($normalizedArgv as $argument) {
    if ($redactNext) {
        $logArgv[] = '[redacted]';
        $redactNext = false;
        continue;
    }
    if (\preg_match('/\A(--[^=]*(?:token|secret|password|credential|handoff|launch-id)[^=]*)=(.*)\z/iD', $argument, $matches) === 1) {
        $logArgv[] = (string)$matches[1] . '=[redacted]';
        continue;
    }
    if (\preg_match('/\A--[^=]*(?:token|secret|password|credential|handoff|launch-id)[^=]*\z/iD', $argument) === 1) {
        $logArgv[] = $argument;
        $redactNext = true;
        continue;
    }
    $logArgv[] = $argument;
}
@\fwrite($stdout, '[spawn] detached child pid=' . \getmypid() . ' at ' . \date('c') . PHP_EOL);
@\fwrite($stdout, '[spawn] exec=' . $php . ' ' . \implode(' ', $logArgv) . PHP_EOL);
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
