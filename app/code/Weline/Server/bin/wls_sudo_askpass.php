#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Bounded sudo askpass helper for WLS local-dev elevation.
 *
 * Invoked only by sudo (-A). Prints the password to STDOUT for sudo and never
 * for WLS PHP. No password is accepted from argv/env/files.
 */
if (PHP_SAPI !== 'cli') {
    \fwrite(STDERR, "The WLS sudo askpass helper is CLI-only.\n");
    exit(64);
}

if (PHP_OS_FAMILY !== 'Darwin') {
    \fwrite(STDERR, "The WLS sudo askpass helper currently supports macOS only.\n");
    exit(64);
}

$osascript = '/usr/bin/osascript';
if (!\is_file($osascript) || !\is_executable($osascript)) {
    \fwrite(STDERR, "osascript is unavailable for WLS sudo askpass.\n");
    exit(70);
}

$prompt = 'Weline WLS needs administrator access to update /etc/hosts and trust the local development CA.';
$appleScript = 'display dialog '
    . appleScriptString($prompt)
    . ' with title "Weline WLS"'
    . ' default answer ""'
    . ' with hidden answer'
    . ' with icon caution'
    . ' buttons {"Cancel", "OK"}'
    . ' default button "OK"'
    . ' cancel button "Cancel"';

$descriptors = [
    0 => ['file', '/dev/null', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$process = @\proc_open(
    [$osascript, '-e', $appleScript],
    $descriptors,
    $pipes,
    null,
    null,
    ['bypass_shell' => true],
);
if (!\is_resource($process)) {
    \fwrite(STDERR, "Unable to open the macOS administrator password dialog.\n");
    exit(70);
}

$stdout = \stream_get_contents($pipes[1]);
$stderr = \stream_get_contents($pipes[2]);
foreach ($pipes as $pipe) {
    if (\is_resource($pipe)) {
        @\fclose($pipe);
    }
}
$exitCode = @\proc_close($process);
if ($exitCode !== 0) {
    if (\is_string($stderr) && \trim($stderr) !== '') {
        \fwrite(STDERR, \trim($stderr) . "\n");
    }
    exit($exitCode > 0 ? $exitCode : 1);
}

$password = '';
if (\is_string($stdout) && \preg_match('/^text returned:(.*)\R?\z/s', $stdout, $matches) === 1) {
    $password = (string)$matches[1];
}
// osascript may also return "button returned:OK, text returned:..."
if ($password === '' && \is_string($stdout) && \preg_match('/text returned:(.*)\R?\z/s', $stdout, $matches) === 1) {
    $password = (string)$matches[1];
}

\fwrite(STDOUT, $password);
exit(0);

function appleScriptString(string $value): string
{
    return '"' . \str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
}
