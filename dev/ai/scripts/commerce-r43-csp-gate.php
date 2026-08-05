#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * R4.3 isolated-clone CSP compatibility gate.
 *
 * The stock backend theme uses Bootstrap data:image icons. This gate records
 * the exact SystemConfig preimage, applies the compatible policy only to an
 * explicitly identified PostgreSQL migration clone, and restores the exact
 * row before clone destruction.
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Model\SystemConfig;

const R43_CSP_POLICY = "default-src 'self'; img-src 'self' data:; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'";
const R43_CSP_MODULE = 'Weline_Framework';
const R43_CSP_AREA = 'backend';
const R43_CSP_KEY = 'security.headers.csp';
const R43_CSP_STATE_SCHEMA = 'commerce-r43-csp-preimage-v1';

$root = dirname(__DIR__, 3);
require $root . '/app/bootstrap.php';

$action = trim((string)($argv[1] ?? ''));
$statePath = trim((string)($argv[2] ?? ''));

try {
    if (!in_array($action, ['apply', 'status', 'restore'], true) || $statePath === '') {
        throw new InvalidArgumentException(
            'usage: commerce-r43-csp-gate.php apply|status|restore /absolute/path/to/state.json',
        );
    }
    if ($statePath[0] !== '/') {
        throw new InvalidArgumentException('state path must be absolute');
    }

    $database = requireIsolatedClone();
    if ($action === 'apply') {
        applyPolicy($database, $statePath);
    } elseif ($action === 'restore') {
        restorePolicy($database, $statePath);
    } else {
        emit([
            'ok' => true,
            'action' => 'status',
            'database' => $database,
            'policy' => currentRow()[SystemConfig::schema_fields_VALUE] ?? null,
            'expected_policy' => R43_CSP_POLICY,
            'matches' => (currentRow()[SystemConfig::schema_fields_VALUE] ?? null) === R43_CSP_POLICY,
        ]);
    }
} catch (Throwable $error) {
    emit(['ok' => false, 'action' => $action, 'error' => $error->getMessage()]);
    exit(1);
}

function requireIsolatedClone(): string
{
    if ((string)getenv('WELINE_E2E_ISOLATED_DB') !== '1') {
        throw new RuntimeException('WELINE_E2E_ISOLATED_DB=1 is required');
    }
    $env = require BP . 'app/etc/env.php';
    $type = strtolower((string)($env['db']['master']['type'] ?? ''));
    $database = (string)($env['db']['master']['database'] ?? '');
    if ($type !== 'pgsql') {
        throw new RuntimeException('R4.3 CSP gate requires PostgreSQL, got: ' . $type);
    }
    if (preg_match('/^mig_clone_[a-z0-9_]+$/D', $database) !== 1) {
        throw new RuntimeException('R4.3 CSP gate refuses non-clone database: ' . $database);
    }

    return $database;
}

function applyPolicy(string $database, string $statePath): void
{
    if (is_file($statePath)) {
        throw new RuntimeException('state file already exists; restore or remove it after verifying ownership');
    }
    $row = currentRow();
    if ($row === null) {
        throw new RuntimeException('expected global backend CSP row is missing');
    }

    writeState($statePath, [
        'schema' => R43_CSP_STATE_SCHEMA,
        'database' => $database,
        'identity' => identity(),
        'preimage' => $row,
        'preimage_sha256' => digest($row),
        'applied_policy' => R43_CSP_POLICY,
        'recorded_at' => gmdate('c'),
    ]);

    $updated = $row;
    $updated[SystemConfig::schema_fields_VALUE] = R43_CSP_POLICY;
    $updated[SystemConfig::schema_fields_VERSION] = max(1, (int)($row[SystemConfig::schema_fields_VERSION] ?? 0) + 1);
    $updated[SystemConfig::schema_fields_UPDATED_AT] = date('Y-m-d H:i:s');
    $updated[SystemConfig::schema_fields_UPDATED_BY] = 'commerce-r43-csp-gate';

    replaceRow($updated);
    $actual = currentRow();
    if (($actual[SystemConfig::schema_fields_VALUE] ?? null) !== R43_CSP_POLICY) {
        replaceRow($row);
        throw new RuntimeException('CSP policy verification failed; exact preimage was restored');
    }
    clearConfigCache();
    emit([
        'ok' => true,
        'action' => 'apply',
        'database' => $database,
        'policy' => R43_CSP_POLICY,
        'preimage_sha256' => digest($row),
        'state' => $statePath,
    ]);
}

function restorePolicy(string $database, string $statePath): void
{
    $state = readState($statePath);
    if (($state['schema'] ?? '') !== R43_CSP_STATE_SCHEMA) {
        throw new RuntimeException('unsupported state schema');
    }
    if (($state['database'] ?? '') !== $database) {
        throw new RuntimeException('state database does not match current clone');
    }
    $preimage = $state['preimage'] ?? null;
    if (!is_array($preimage) || !hash_equals((string)($state['preimage_sha256'] ?? ''), digest($preimage))) {
        throw new RuntimeException('state preimage digest mismatch');
    }
    $current = currentRow();
    if (($current[SystemConfig::schema_fields_VALUE] ?? null) !== ($state['applied_policy'] ?? null)) {
        throw new RuntimeException('current CSP no longer matches the gate-applied value; refusing overwrite');
    }

    replaceRow($preimage);
    $restored = currentRow();
    if (!is_array($restored) || !hash_equals(digest($preimage), digest($restored))) {
        throw new RuntimeException('exact CSP preimage restoration failed');
    }
    clearConfigCache();
    if (!unlink($statePath)) {
        throw new RuntimeException('preimage restored but state file could not be removed');
    }
    emit([
        'ok' => true,
        'action' => 'restore',
        'database' => $database,
        'restored_sha256' => digest($restored),
    ]);
}

/** @return array<string,mixed>|null */
function currentRow(): ?array
{
    $rows = ObjectManager::getInstance(SystemConfig::class, [], false)
        ->reset()
        ->where(identity())
        ->select()
        ->fetchArray();
    if (count($rows) > 1) {
        throw new RuntimeException('duplicate global backend CSP rows detected');
    }

    return isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
}

/** @return array<string,string> */
function identity(): array
{
    return [
        SystemConfig::schema_fields_KEY => R43_CSP_KEY,
        SystemConfig::schema_fields_MODULE => R43_CSP_MODULE,
        SystemConfig::schema_fields_AREA => R43_CSP_AREA,
        SystemConfig::schema_fields_SCOPE => SystemConfig::SCOPE_GLOBAL,
        SystemConfig::schema_fields_LOCALE => SystemConfig::LOCALE_DEFAULT,
    ];
}

/** @param array<string,mixed> $row */
function replaceRow(array $row): void
{
    $model = ObjectManager::getInstance(SystemConfig::class, [], false);
    $model->reset()->where(identity())->delete()->fetch();
    $model->reset()->insert([$row], array_keys($row))->fetch();
}

function clearConfigCache(): void
{
    SystemConfig::$configs = [];
    try {
        w_cache('system_config')->clear();
    } catch (Throwable $error) {
        throw new RuntimeException('system_config cache clear failed: ' . $error->getMessage(), 0, $error);
    }
}

/** @param array<string,mixed> $state */
function writeState(string $path, array $state): void
{
    $directory = dirname($path);
    if (!is_dir($directory) || !is_writable($directory)) {
        throw new RuntimeException('state directory is not writable: ' . $directory);
    }
    $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    if (file_put_contents($temporary, $json . "\n", LOCK_EX) === false || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('unable to persist CSP preimage state');
    }
    chmod($path, 0600);
}

/** @return array<string,mixed> */
function readState(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('state file does not exist: ' . $path);
    }
    $state = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($state)) {
        throw new RuntimeException('state file must contain a JSON object');
    }

    return $state;
}

/** @param array<string,mixed> $row */
function digest(array $row): string
{
    ksort($row);
    return hash('sha256', json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

/** @param array<string,mixed> $payload */
function emit(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
}
