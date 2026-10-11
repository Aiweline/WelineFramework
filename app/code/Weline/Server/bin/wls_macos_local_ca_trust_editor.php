#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Bounded root-only macOS System Keychain Local CA trust editor.
 *
 * Runs every System.keychain mutation for one trust sync inside a single
 * elevated process so server:start's AdministratorAuthorizationSession only
 * needs one sudo ticket (askpass once), not one prompt per delete/add.
 *
 * HARD (machine_shared_side_effects_require_workspace_scope): only deletes
 * fingerprints explicitly passed by the caller (workspace retired list). Never
 * deletes by CN alone.
 */
if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Darwin') {
    \fwrite(STDERR, "The WLS macOS Local CA trust editor is Darwin CLI-only.\n");
    exit(64);
}
if (!\function_exists('posix_geteuid') || (int)@\posix_geteuid() !== 0) {
    \fwrite(STDERR, "The WLS macOS Local CA trust editor requires an authenticated root process.\n");
    exit(77);
}

$caPath = '';
$retire = [];
for ($i = 1; $i < $argc; $i++) {
    $arg = (string)$argv[$i];
    if (\str_starts_with($arg, '--ca=')) {
        $caPath = \substr($arg, \strlen('--ca='));
        continue;
    }
    if (\str_starts_with($arg, '--retire=')) {
        $raw = \substr($arg, \strlen('--retire='));
        if ($raw === '') {
            continue;
        }
        foreach (\explode(',', $raw) as $fingerprint) {
            $normalized = \strtoupper((string)\preg_replace('/[^A-F0-9]/i', '', $fingerprint));
            if (\preg_match('/\A[A-F0-9]{40}\z/D', $normalized) !== 1) {
                \fwrite(STDERR, "Invalid retired Local CA fingerprint.\n");
                exit(64);
            }
            $retire[$normalized] = $normalized;
        }
        continue;
    }
    \fwrite(STDERR, "Invalid WLS macOS Local CA trust editor request.\n");
    exit(64);
}

if ($caPath === '' || \str_contains($caPath, "\0")) {
    \fwrite(STDERR, "Local CA certificate path is required.\n");
    exit(64);
}

$resolvedCa = @\realpath($caPath);
if (!\is_string($resolvedCa)
    || $resolvedCa === ''
    || !\is_file($resolvedCa)
    || \is_link($caPath)
    || \is_link($resolvedCa)
) {
    \fwrite(STDERR, "Local CA certificate path is unsafe or missing.\n");
    exit(64);
}

$status = @\lstat($resolvedCa);
if (!\is_array($status)
    || (($status['mode'] ?? 0) & 0170000) !== 0100000
    || (int)($status['nlink'] ?? 0) !== 1
    || (int)($status['size'] ?? 0) <= 0
    || (int)($status['size'] ?? 0) > 1_048_576
) {
    \fwrite(STDERR, "Local CA certificate is not a safe regular file.\n");
    exit(64);
}

$pem = @\file_get_contents($resolvedCa);
if (!\is_string($pem)
    || $pem === ''
    || !\str_contains($pem, 'BEGIN CERTIFICATE')
    || !\str_contains($pem, 'END CERTIFICATE')
) {
    \fwrite(STDERR, "Local CA certificate material is invalid.\n");
    exit(64);
}

$security = '/usr/bin/security';
if (!\is_file($security) || !\is_executable($security)) {
    \fwrite(STDERR, "macOS security tool is unavailable.\n");
    exit(70);
}

$systemKeychain = '/Library/Keychains/System.keychain';
foreach ($retire as $fingerprint) {
    $delete = @\proc_open(
        [$security, 'delete-certificate', '-Z', $fingerprint, $systemKeychain],
        [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        null,
        null,
        ['bypass_shell' => true],
    );
    if (\is_resource($delete)) {
        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                @\stream_get_contents($pipe);
                @\fclose($pipe);
            }
        }
        @\proc_close($delete);
    }
}

$add = @\proc_open(
    [
        $security,
        'add-trusted-cert',
        '-d',
        '-r',
        'trustRoot',
        '-p',
        'ssl',
        '-p',
        'basic',
        '-k',
        $systemKeychain,
        $resolvedCa,
    ],
    [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $addPipes,
    null,
    null,
    ['bypass_shell' => true],
);
if (!\is_resource($add)) {
    \fwrite(STDERR, "Unable to import Local CA into the System Keychain.\n");
    exit(70);
}
$stdout = \is_resource($addPipes[1]) ? (string)@\stream_get_contents($addPipes[1]) : '';
$stderr = \is_resource($addPipes[2]) ? (string)@\stream_get_contents($addPipes[2]) : '';
foreach ($addPipes as $pipe) {
    if (\is_resource($pipe)) {
        @\fclose($pipe);
    }
}
$exitCode = @\proc_close($add);
if ($exitCode !== 0) {
    $detail = \trim($stdout . "\n" . $stderr);
    \fwrite(
        STDERR,
        $detail !== ''
            ? $detail . "\n"
            : "Local CA System Keychain import failed.\n",
    );
    exit($exitCode > 0 ? $exitCode : 1);
}

exit(0);
