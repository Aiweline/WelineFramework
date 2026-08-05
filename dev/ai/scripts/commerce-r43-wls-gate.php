#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * R4.3 named-WLS lifecycle gate.
 *
 * TEST-WLS-01: assert that the dedicated instance is running on the exact
 * Playwright target port before browser acceptance starts.
 * TEST-WLS-06: stop only that instance and prove it has no live worker after
 * automated acceptance completes.
 */

const R43_INSTANCE_PATTERN = '/^ai-test-commerce-r43-[A-Za-z0-9][A-Za-z0-9_-]{0,80}$/D';

$root = dirname(__DIR__, 3);
$action = trim((string)($argv[1] ?? ''));
$instance = trim((string)getenv('WELINE_E2E_WLS_INSTANCE'));
$origin = trim((string)getenv('PLAYWRIGHT_TARGET_ORIGIN'));

if (!in_array($action, ['assert-running', 'stop-verify'], true)) {
    fail('usage: commerce-r43-wls-gate.php assert-running|stop-verify', 64);
}
if (preg_match(R43_INSTANCE_PATTERN, $instance) !== 1) {
    fail('invalid or missing WELINE_E2E_WLS_INSTANCE', 65);
}

$parts = parse_url($origin);
$port = is_array($parts) ? (int)($parts['port'] ?? 0) : 0;
if ($origin === '' || $port < 9502 || $port === 9501) {
    fail('PLAYWRIGHT_TARGET_ORIGIN must use a dedicated port >=9502 and never 9501', 66);
}

if ($action === 'assert-running') {
    [$exitCode, $status] = runWeline($root, ['server:status', $instance]);
    $plain = stripAnsi($status);
    if ($exitCode !== 0
        || !str_contains($plain, $instance)
        || !str_contains($plain, ':' . $port)
        || !str_contains($plain, '状态：全部运行中')
    ) {
        fail('TEST-WLS-01 dedicated instance/port/running assertion failed', 67, $status);
    }
    emit('TEST-WLS-01', $instance, $port, $status, ['running' => true]);
    exit(0);
}

[$stopExit, $stopOutput] = runWeline($root, ['server:stop', $instance]);
[$statusExit, $statusOutput] = runWeline($root, ['server:status', $instance]);
$plainStatus = stripAnsi($statusOutput);
$stillRunning = str_contains($plainStatus, '状态：全部运行中');
if ($stopExit !== 0 || $stillRunning) {
    fail(
        'TEST-WLS-06 dedicated instance still has a live worker after stop',
        68,
        $stopOutput . "\n" . $statusOutput,
    );
}
emit('TEST-WLS-06', $instance, $port, $stopOutput . "\n" . $statusOutput, [
    'stop_exit' => $stopExit,
    'status_exit' => $statusExit,
    'running' => false,
]);

/** @return array{0:int,1:string} */
function runWeline(string $root, array $arguments): array
{
    $command = array_merge([PHP_BINARY, $root . '/bin/w'], $arguments);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $root);
    if (!is_resource($process)) {
        fail('unable to start bin/w process', 69);
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [$exitCode, trim((string)$stdout . "\n" . (string)$stderr)];
}

function stripAnsi(string $value): string
{
    return (string)preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $value);
}

/** @param array<string,mixed> $extra */
function emit(string $testId, string $instance, int $port, string $output, array $extra): void
{
    $payload = array_merge([
        'test_id' => $testId,
        'instance' => $instance,
        'port' => $port,
        'output_sha256' => hash('sha256', $output),
        'checked_at' => gmdate('c'),
    ], $extra);
    fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
}

function fail(string $message, int $code, string $details = ''): never
{
    $payload = ['ok' => false, 'error' => $message];
    if ($details !== '') {
        $payload['details_sha256'] = hash('sha256', $details);
    }
    fwrite(STDERR, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit($code);
}
