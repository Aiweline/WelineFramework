<?php

declare(strict_types=1);

/**
 * Root-only installer for Weline WLS Linux systemd autostart units.
 *
 * Usage: php -n wls_autostart_privileged_installer.php --request=/abs/path/var/server/autostart/install-request-*.json
 */

if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux') {
    \fwrite(STDERR, "The WLS autostart privileged installer is Linux CLI-only.\n");
    exit(64);
}
if (!\function_exists('posix_geteuid') || (int)@\posix_geteuid() !== 0) {
    \fwrite(STDERR, "The WLS autostart privileged installer requires an authenticated root process.\n");
    exit(77);
}
if ($argc !== 2 || !\str_starts_with((string)$argv[1], '--request=')) {
    \fwrite(STDERR, "Invalid WLS autostart privileged installer request.\n");
    exit(64);
}

$requestPath = \substr((string)$argv[1], \strlen('--request='));
if ($requestPath === '' || \str_contains($requestPath, "\0") || !\str_starts_with($requestPath, '/')) {
    \fwrite(STDERR, "Request path must be absolute.\n");
    exit(64);
}
if (\is_link($requestPath) || !\is_file($requestPath)) {
    \fwrite(STDERR, "Request file missing or unsafe.\n");
    exit(64);
}

$projectRoot = \dirname(__DIR__, 5);
$expectedPrefix = \rtrim($projectRoot, '/\\') . '/var/server/autostart/';
$realRequest = \realpath($requestPath);
$realExpectedPrefix = \realpath(\dirname($expectedPrefix));
if (!\is_string($realRequest)
    || !\is_string($realExpectedPrefix)
    || !\str_starts_with($realRequest, \rtrim($realExpectedPrefix, '/\\') . '/')
) {
    \fwrite(STDERR, "Request path escapes the project autostart directory.\n");
    exit(64);
}

$raw = \file_get_contents($realRequest);
if (!\is_string($raw) || $raw === '') {
    \fwrite(STDERR, "Request file unreadable.\n");
    exit(70);
}
$data = \json_decode($raw, true);
if (!\is_array($data) || (int)($data['schema'] ?? 0) !== 1 || !\is_array($data['plan'] ?? null)) {
    \fwrite(STDERR, "Request schema invalid.\n");
    exit(64);
}

$plan = $data['plan'];
$required = [
    'service_unit',
    'health_service_unit',
    'health_timer_unit',
    'script_path',
    'health_script_path',
    'service_body',
    'health_service_body',
    'health_timer_body',
    'script_body',
    'health_script_body',
    'fingerprint',
];
foreach ($required as $key) {
    if (!\is_string($plan[$key] ?? null) || $plan[$key] === '') {
        \fwrite(STDERR, "Request plan missing {$key}.\n");
        exit(64);
    }
}

$writeFile = static function (string $path, string $body, int $mode) : void {
    if (!\str_starts_with($path, '/')) {
        throw new \RuntimeException('Refusing non-absolute path: ' . $path);
    }
    if (\str_contains($path, '..')) {
        throw new \RuntimeException('Refusing path with ..: ' . $path);
    }
    $dir = \dirname($path);
    if (!\is_dir($dir) && !@\mkdir($dir, 0755, true) && !\is_dir($dir)) {
        throw new \RuntimeException('Unable to create directory: ' . $dir);
    }
    $tmp = $path . '.tmp.' . \bin2hex(\random_bytes(4));
    if (@\file_put_contents($tmp, $body) === false) {
        throw new \RuntimeException('Unable to write: ' . $path);
    }
    @\chmod($tmp, $mode);
    if (!@\rename($tmp, $path)) {
        @\unlink($tmp);
        throw new \RuntimeException('Unable to publish: ' . $path);
    }
};

try {
    $writeFile((string)$plan['script_path'], (string)$plan['script_body'], 0755);
    $writeFile((string)$plan['health_script_path'], (string)$plan['health_script_body'], 0755);
    $writeFile('/etc/systemd/system/' . (string)$plan['service_unit'], (string)$plan['service_body'], 0644);
    $writeFile('/etc/systemd/system/' . (string)$plan['health_service_unit'], (string)$plan['health_service_body'], 0644);
    $writeFile('/etc/systemd/system/' . (string)$plan['health_timer_unit'], (string)$plan['health_timer_body'], 0644);
} catch (\Throwable $e) {
    \fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$commands = [
    ['systemctl', 'daemon-reload'],
    ['systemctl', 'enable', '--', (string)$plan['service_unit']],
    ['systemctl', 'enable', '--', (string)$plan['health_timer_unit']],
];
foreach ($commands as $cmd) {
    $line = '';
    foreach ($cmd as $part) {
        $line .= ($line === '' ? '' : ' ') . \escapeshellarg($part);
    }
    $output = [];
    $code = 0;
    @\exec($line . ' 2>&1', $output, $code);
    if ($code !== 0) {
        \fwrite(STDERR, "Command failed ({$code}): {$line}\n" . \implode("\n", $output) . "\n");
        exit(1);
    }
}

\fwrite(STDOUT, "WLS systemd autostart installed: {$plan['service_unit']}\n");
exit(0);
