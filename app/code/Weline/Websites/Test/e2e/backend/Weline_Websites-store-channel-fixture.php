<?php

declare(strict_types=1);

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;
use Weline\Websites\Service\StoreChannelAdminService;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

/** @return array<string,mixed> */
function r43_websites_input(): array
{
    $input = json_decode((string)stream_get_contents(STDIN), true);
    return is_array($input) ? $input : [];
}

/** @param array<string,mixed> $payload */
function r43_websites_output(array $payload, int $code = 0): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit($code);
}

function r43_websites_token(array $input): string
{
    $token = strtolower((string)($input['token'] ?? ''));
    $token = preg_replace('/[^a-z0-9]/', '', $token) ?: '';
    return $token !== '' ? substr($token, 0, 18) : substr(bin2hex(random_bytes(8)), 0, 12);
}

/** @param array<string,mixed> $where @return list<array<string,mixed>> */
function r43_websites_rows(object $model, array $where): array
{
    $query = $model->clear();
    foreach ($where as $field => $value) {
        $query->where((string)$field, $value);
    }
    $rows = $query->select()->fetchArray();
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

/** @param array<string,mixed> $where */
function r43_websites_delete(object $model, array $where): int
{
    $rows = r43_websites_rows($model, $where);
    if ($rows === []) {
        return 0;
    }

    // Store::delete() intentionally tombstones production records. This test-only
    // cleanup uses the connector query directly, bounded by the verified R43 keys.
    $query = $model->getConnection()->getQuery()->table($model->getTable());
    foreach ($where as $field => $value) {
        $query->where((string)$field, $value);
    }
    $query->delete()->fetch();
    return count($rows);
}

function r43_websites_assert_isolated_database(): string
{
    if (getenv('WELINE_E2E_ISOLATED_DB') !== '1') {
        throw new RuntimeException('r43_websites_requires_isolated_database_flag');
    }
    $env = require dirname(__DIR__, 7) . '/app/etc/env.php';
    $database = trim((string)($env['db']['master']['database'] ?? ''));
    if (preg_match('/^mig_clone_[a-z0-9_]+$/D', $database) !== 1) {
        throw new RuntimeException('r43_websites_requires_mig_clone_database:' . $database);
    }
    return $database;
}

function r43_websites_assert_pgsql(): string
{
    $model = ObjectManager::getInstance(Store::class, [], false);
    $connector = get_class($model->getConnection()->getConnector());
    $driver = strtolower($connector);
    if (!str_contains($driver, 'pgsql') && !str_contains($driver, 'postgres')) {
        throw new RuntimeException('r43_websites_requires_postgresql:' . $connector);
    }
    return $connector;
}

/** @return array<string,mixed> */
function r43_websites_data(string $token, string $kind): array
{
    return [
        'token' => $token,
        'kind' => $kind,
        'website_id' => 0,
        'store_code' => 'r43_store_' . $token,
        'store_name' => 'R43 Store ' . $token,
        'store_mode' => 'test',
        'store_url' => '',
        'channel_code' => 'r43_channel_' . $token,
        'channel_name' => 'R43 Channel ' . $token,
    ];
}

/** @param array<string,mixed> $data @return array<string,mixed> */
function r43_websites_inspect(array $data): array
{
    $stores = r43_websites_rows(
        ObjectManager::getInstance(Store::class, [], false),
        [Store::schema_fields_WEBSITE_ID => (int)$data['website_id'], Store::schema_fields_CODE => (string)$data['store_code']],
    );
    $storeId = (int)($stores[0][Store::schema_fields_ID] ?? 0);
    $channels = $storeId > 0 ? r43_websites_rows(
        ObjectManager::getInstance(SalesChannel::class, [], false),
        [SalesChannel::schema_fields_STORE_ID => $storeId, SalesChannel::schema_fields_CODE => (string)$data['channel_code']],
    ) : [];
    return compact('stores', 'channels');
}

/** @param array<string,mixed> $data @return array<string,mixed> */
function r43_websites_cleanup(array $data): array
{
    if (!str_starts_with((string)$data['store_code'], 'r43_store_')
        || !str_starts_with((string)$data['channel_code'], 'r43_channel_')) {
        throw new RuntimeException('refusing Websites cleanup outside R43 namespace');
    }
    $snapshot = r43_websites_inspect($data);
    $storeId = (int)($snapshot['stores'][0][Store::schema_fields_ID] ?? 0);
    $deletedChannels = $storeId > 0 ? r43_websites_delete(
        ObjectManager::getInstance(SalesChannel::class, [], false),
        [SalesChannel::schema_fields_STORE_ID => $storeId, SalesChannel::schema_fields_CODE => (string)$data['channel_code']],
    ) : 0;
    $deletedStores = r43_websites_delete(
        ObjectManager::getInstance(Store::class, [], false),
        [Store::schema_fields_WEBSITE_ID => (int)$data['website_id'], Store::schema_fields_CODE => (string)$data['store_code']],
    );
    $remaining = array_map('count', r43_websites_inspect($data));
    if (array_sum($remaining) !== 0) {
        throw new RuntimeException('Websites fixture cleanup left rows: ' . json_encode($remaining));
    }
    return ['deleted' => ['channels' => $deletedChannels, 'stores' => $deletedStores], 'remaining' => $remaining];
}

try {
    $input = r43_websites_input();
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $kind = strtolower(trim((string)($input['kind'] ?? 'store')));
    if (!in_array($kind, ['store', 'channel'], true)) {
        throw new InvalidArgumentException('kind must be store or channel');
    }
    $data = array_merge(r43_websites_data(r43_websites_token($input), $kind), $input);
    r43_websites_assert_isolated_database();
    $connector = r43_websites_assert_pgsql();

    if ($action === 'prepare') {
        r43_websites_cleanup($data);
        if ($kind === 'channel') {
            $summary = ObjectManager::getInstance(StoreChannelAdminService::class)->createStore(
                (int)$data['website_id'],
                (string)$data['store_code'],
                (string)$data['store_name'],
                (string)$data['store_mode'],
                null,
            );
            $data['store_id'] = $summary->id;
        }
        r43_websites_output(['ok' => true, 'connector' => $connector] + $data);
    }
    if ($action === 'inspect') {
        r43_websites_output(['ok' => true, 'connector' => $connector] + r43_websites_inspect($data));
    }
    if ($action === 'cleanup') {
        r43_websites_output(['ok' => true, 'connector' => $connector] + r43_websites_cleanup($data));
    }
    throw new InvalidArgumentException('unsupported action: ' . $action);
} catch (Throwable $throwable) {
    r43_websites_output(['ok' => false, 'error' => $throwable->getMessage()], 1);
}
