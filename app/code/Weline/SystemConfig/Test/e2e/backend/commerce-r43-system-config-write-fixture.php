<?php

declare(strict_types=1);

use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\SystemConfig\Model\SystemConfigVersion;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

const R43_CONFIG_MODULE = 'Weline_Tax';
const R43_CONFIG_AREA = 'backend';
const R43_CONFIG_KEY = 'tax/general/default_jurisdiction';

function r43_config_require_isolated_clone(): string
{
    if ((string)\getenv('WELINE_E2E_ISOLATED_DB') !== '1') {
        throw new \RuntimeException('R4.3 SystemConfig fixture requires WELINE_E2E_ISOLATED_DB=1');
    }
    $env = require BP . 'app/etc/env.php';
    $type = \strtolower((string)($env['db']['master']['type'] ?? ''));
    if ($type !== 'pgsql') {
        throw new \RuntimeException('R4.3 SystemConfig fixture requires PostgreSQL, got: ' . $type);
    }
    $database = (string)($env['db']['master']['database'] ?? '');
    if (\preg_match('/^mig_clone_[a-z0-9_]+$/D', $database) !== 1) {
        throw new \RuntimeException('R4.3 SystemConfig fixture refuses non-clone database: ' . $database);
    }
    return $database;
}

/** @return array<string,mixed> */
function r43_config_input(): array
{
    $input = \json_decode((string)\file_get_contents('php://stdin'), true);
    if (!\is_array($input)) {
        throw new \InvalidArgumentException('stdin must be a JSON object');
    }
    return $input;
}

/** @param array<string,mixed> $payload */
function r43_config_output(array $payload): void
{
    echo \json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}

/** @param array<int,array<string,mixed>> $rows */
function r43_config_canonical_rows(array $rows): array
{
    foreach ($rows as &$row) {
        \ksort($row);
    }
    unset($row);
    \usort($rows, static fn(array $left, array $right): int =>
        \strcmp(
            (string)\json_encode($left, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            (string)\json_encode($right, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        )
    );
    return $rows;
}

/** @return array<string,mixed> */
function r43_config_prepare(?string $requestedToken): array
{
    $token = \preg_replace('/[^a-z0-9]/i', '', (string)$requestedToken) ?: \bin2hex(\random_bytes(6));
    $token = \strtoupper(\substr($token, 0, 10));
    $rows = ObjectManager::getInstance(SystemConfig::class, [], false)
        ->reset()
        ->where(SystemConfig::schema_fields_MODULE, R43_CONFIG_MODULE)
        ->where(SystemConfig::schema_fields_AREA, R43_CONFIG_AREA)
        ->select()
        ->fetchArray();
    $versions = ObjectManager::getInstance(SystemConfigVersion::class, [], false)
        ->reset()
        ->fields([SystemConfigVersion::schema_fields_ID])
        ->where(SystemConfigVersion::schema_fields_MODULE, R43_CONFIG_MODULE)
        ->where(SystemConfigVersion::schema_fields_AREA, R43_CONFIG_AREA)
        ->select()
        ->fetchArray();

    return [
        'token' => $token,
        'module' => R43_CONFIG_MODULE,
        'area' => R43_CONFIG_AREA,
        'key' => R43_CONFIG_KEY,
        'value' => 'ZZ|R43' . $token,
        'reason' => 'R4.3 WebUI config ' . $token,
        'preimage_rows' => $rows,
        'preimage_version_ids' => \array_values(\array_map(
            static fn(array $row): int => (int)($row[SystemConfigVersion::schema_fields_ID] ?? 0),
            $versions,
        )),
    ];
}

/** @param array<string,mixed> $input */
function r43_config_assert(array $input): array
{
    $row = ObjectManager::getInstance(SystemConfig::class, [], false)
        ->reset()
        ->where(SystemConfig::schema_fields_MODULE, (string)($input['module'] ?? ''))
        ->where(SystemConfig::schema_fields_AREA, (string)($input['area'] ?? ''))
        ->where(SystemConfig::schema_fields_KEY, (string)($input['key'] ?? ''))
        ->where(SystemConfig::schema_fields_VALUE, (string)($input['value'] ?? ''))
        ->find()
        ->fetch();
    if ((string)$row->getData(SystemConfig::schema_fields_VALUE) !== (string)($input['value'] ?? '')) {
        throw new \RuntimeException('SystemConfig value was not persisted by the browser action');
    }
    return [
        'key' => (string)$row->getData(SystemConfig::schema_fields_KEY),
        'value' => (string)$row->getData(SystemConfig::schema_fields_VALUE),
        'scope' => (string)$row->getData(SystemConfig::schema_fields_SCOPE),
        'locale' => (string)$row->getData(SystemConfig::schema_fields_LOCALE),
        'version' => (int)$row->getData(SystemConfig::schema_fields_VERSION),
    ];
}

/** @param array<string,mixed> $input */
function r43_config_cleanup(array $input): array
{
    $module = (string)($input['module'] ?? R43_CONFIG_MODULE);
    $area = (string)($input['area'] ?? R43_CONFIG_AREA);
    $model = ObjectManager::getInstance(SystemConfig::class, [], false);
    $model->reset()
        ->where(SystemConfig::schema_fields_MODULE, $module)
        ->where(SystemConfig::schema_fields_AREA, $area)
        ->delete()
        ->fetch();

    $preimage = $input['preimage_rows'] ?? [];
    if (\is_array($preimage) && $preimage !== []) {
        $rows = \array_values(\array_filter($preimage, 'is_array'));
        if ($rows !== []) {
            $fields = \array_keys($rows[0]);
            ObjectManager::getInstance(SystemConfig::class, [], false)
                ->reset()->insert($rows, $fields)->fetch();
        }
    }

    $keepIds = \array_values(\array_filter(\array_map('intval', (array)($input['preimage_version_ids'] ?? []))));
    $versionModel = ObjectManager::getInstance(SystemConfigVersion::class, [], false)
        ->reset()
        ->where(SystemConfigVersion::schema_fields_MODULE, $module)
        ->where(SystemConfigVersion::schema_fields_AREA, $area);
    if ($keepIds !== []) {
        $versionModel->where(SystemConfigVersion::schema_fields_ID, $keepIds, 'not in');
    }
    $newVersions = $versionModel->select()->fetchArray();
    $newVersionIds = \array_values(\array_filter(\array_map(
        static fn(array $row): int => (int)($row[SystemConfigVersion::schema_fields_ID] ?? 0),
        $newVersions,
    )));
    if ($newVersionIds !== []) {
        ObjectManager::getInstance(SystemConfigVersion::class, [], false)
            ->reset()->where(SystemConfigVersion::schema_fields_ID, $newVersionIds, 'in')->delete()->fetch();
    }
    try {
        w_cache('system_config')->clear();
    } catch (\Throwable) {
        // The database restoration is decisive; cache cleanup is best effort.
    }

    $remaining = ObjectManager::getInstance(SystemConfig::class, [], false)
        ->reset()
        ->where(SystemConfig::schema_fields_MODULE, $module)
        ->where(SystemConfig::schema_fields_AREA, $area)
        ->select()
        ->fetchArray();
    $expected = \array_values(\array_filter(\is_array($preimage) ? $preimage : [], 'is_array'));
    if (r43_config_canonical_rows($remaining) !== r43_config_canonical_rows($expected)) {
        throw new \RuntimeException('SystemConfig cleanup did not restore the exact preimage');
    }
    return ['restored_rows' => \count($remaining), 'deleted_version_ids' => $newVersionIds];
}

try {
    r43_config_require_isolated_clone();
    $input = r43_config_input();
    $action = (string)($input['action'] ?? '');
    if ($action === 'prepare') {
        r43_config_output(['ok' => true, 'action' => $action] + r43_config_prepare($input['token'] ?? null));
        exit(0);
    }
    if ($action === 'assert') {
        r43_config_output(['ok' => true, 'action' => $action] + r43_config_assert($input));
        exit(0);
    }
    if ($action === 'cleanup') {
        r43_config_output(['ok' => true, 'action' => $action] + r43_config_cleanup($input));
        exit(0);
    }
    throw new \InvalidArgumentException('unknown action: ' . $action);
} catch (\Throwable $error) {
    r43_config_output(['ok' => false, 'error' => $error->getMessage()]);
    exit(1);
}
