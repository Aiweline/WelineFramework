<?php

declare(strict_types=1);

/**
 * Commerce kernel completion fixture.
 *
 * stdin JSON:
 * - {"action":"prepare","port":29821,"token":"optional"}
 * - {"action":"drift","website_ids":[1,2]}
 * - {"action":"inspect","website_ids":[1,2],"expected_schema_signature":"..."}
 * - {"action":"cleanup","token":"...","guest_token":"optional"}
 *
 * The fixture owns only token-derived Websites and their Product shard tables.
 * It deliberately performs DDL from the CLI setup path; the Browser acceptance
 * path is read/business-only and must leave the returned schema signature
 * unchanged.
 */

use Weline\Cart\Service\CartV2CacheStore;
use Weline\Cart\Service\CartV2HarnessCatalog;
use Weline\Framework\Database\Schema\DbSchemaReader;
use Weline\Framework\Database\Schema\IndexDefinitionContract;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\I18n\Api\Translation\DictionaryRepositoryInterface;
use Weline\I18n\Service\PhraseScopeResolver;
use Weline\Meta\Api\Data\MetaConfigIdentity;
use Weline\Meta\Api\Data\MetaConfigWrite;
use Weline\Meta\Api\MetaConfigRepositoryInterface;
use Weline\Product\Model\ProductShardKey;
use Weline\Product\Model\ProductShardRegistry;
use Weline\Product\Service\ProductShardProvisioner;
use Weline\Product\Service\ProductShardSchemaCatalog;
use Weline\SystemConfig\Api\ConfigReader;
use Weline\SystemConfig\Api\ConfigStore;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteDomain;
use Weline\Websites\Service\ScopeKernelRolloutPolicy;
use Weline\Websites\Service\WebsiteCacheInvalidationService;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

const CK_SCOPE_STORE = 'scope';
const CK_SCOPE_CHANNEL = 'default';
const CK_LOCALE = 'zh_Hans_CN';
const CK_META_NAMESPACE = 'public.commerce_kernel_e2e';
const CK_META_KEY_PREFIX = 'dual_scope_';
const CK_I18N_SOURCE_PREFIX = 'Commerce kernel scoped greeting ';

/** @return array<string, mixed> */
function ck_read_input(): array
{
    $raw = stream_get_contents(STDIN);
    $decoded = json_decode($raw !== false && $raw !== '' ? $raw : '{}', true);

    return is_array($decoded) ? $decoded : [];
}

/** @param array<string, mixed> $payload */
function ck_output(array $payload): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

function ck_token(mixed $candidate): string
{
    $token = strtolower((string)$candidate);
    $token = preg_replace('/[^a-f0-9]/', '', $token) ?: '';

    return $token !== '' ? substr($token, 0, 12) : bin2hex(random_bytes(5));
}

function ck_website_code(string $token, string $side): string
{
    return 'cke2e_' . $token . '_' . $side;
}

function ck_host(string $token, string $side): string
{
    return $side . '-' . $token . '.weline.localhost';
}

function ck_offer_uuid(string $token): string
{
    $hex = substr(hash('sha256', 'commerce-kernel-offer|' . $token), 0, 32);

    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        '4' . substr($hex, 13, 3),
        '8' . substr($hex, 17, 3),
        substr($hex, 20, 12),
    );
}

function ck_scope(string $code, int $websiteId): ScopeIdentity
{
    return ScopeIdentity::channel(
        $websiteId,
        $code,
        CK_SCOPE_STORE,
        CK_SCOPE_CHANNEL,
        ScopeIdentity::MODE_TEST,
    );
}

function ck_rollout_snapshot_path(string $token): string
{
    return BP . 'var/tmp/commerce-kernel-rollout-' . $token . '.json';
}

/** @return list<string> */
function ck_rollout_keys(): array
{
    return [
        ScopeKernelRolloutPolicy::CONFIG_MODE,
        ScopeKernelRolloutPolicy::CONFIG_ALLOWLIST,
        ScopeKernelRolloutPolicy::CONFIG_SHADOW_SAMPLE_BP,
    ];
}

function ck_restore_rollout(string $token): void
{
    $path = ck_rollout_snapshot_path($token);
    if (!is_file($path)) {
        return;
    }
    $raw = file_get_contents($path);
    $snapshot = json_decode(is_string($raw) ? $raw : '', true);
    if (!is_array($snapshot)) {
        throw new RuntimeException('commerce kernel rollout snapshot is invalid: ' . $path);
    }

    /** @var ConfigStore $store */
    $store = ObjectManager::getInstance(ConfigStore::class);
    $entries = is_array($snapshot['entries'] ?? null) ? $snapshot['entries'] : [];
    foreach (ck_rollout_keys() as $key) {
        $entry = is_array($entries[$key] ?? null) ? $entries[$key] : [];
        if (($entry['exists'] ?? false) === true) {
            $store->setScopedConfig(
                $key,
                $entry['value'] ?? null,
                'Weline_Websites',
                ConfigReader::area_FRONTEND,
                ConfigReader::SCOPE_GLOBAL,
                ConfigReader::LOCALE_DEFAULT,
            );
            continue;
        }
        $store->deleteScopedConfig(
            $key,
            'Weline_Websites',
            ConfigReader::area_FRONTEND,
            ConfigReader::SCOPE_GLOBAL,
            ConfigReader::LOCALE_DEFAULT,
        );
    }
    unlink($path);
}

/**
 * Run the two test Store tuples through the reversible allowlist rollout.
 *
 * @param array{website_id:int,store_id:int,channel_id:int} $sideA
 * @param array{website_id:int,store_id:int,channel_id:int} $sideB
 */
function ck_enable_rollout(string $token, array $sideA, array $sideB): void
{
    $path = ck_rollout_snapshot_path($token);
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('unable to create rollout snapshot directory: ' . $directory);
    }

    /** @var SystemConfig $model */
    $model = clone ObjectManager::getInstance(SystemConfig::class);
    /** @var ConfigReader $reader */
    $reader = ObjectManager::getInstance(ConfigReader::class);
    $entries = [];
    foreach (ck_rollout_keys() as $key) {
        $row = $model->getScopedConfigRow(
            $key,
            'Weline_Websites',
            ConfigReader::area_FRONTEND,
            ConfigReader::SCOPE_GLOBAL,
            ConfigReader::LOCALE_DEFAULT,
        );
        $entries[$key] = [
            'exists' => $row !== null,
            'value' => $reader->getConfig(
                $key,
                'Weline_Websites',
                ConfigReader::area_FRONTEND,
                null,
                ConfigReader::SCOPE_GLOBAL,
                ConfigReader::LOCALE_DEFAULT,
            ),
        ];
    }
    $encoded = json_encode(
        ['schema' => 1, 'token' => $token, 'entries' => $entries],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    );
    if (!is_string($encoded) || file_put_contents($path, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('unable to persist rollout snapshot: ' . $path);
    }

    /** @var ConfigStore $store */
    $store = ObjectManager::getInstance(ConfigStore::class);
    $write = static function (string $key, mixed $value) use ($store): void {
        if (!$store->setScopedConfig(
            $key,
            $value,
            'Weline_Websites',
            ConfigReader::area_FRONTEND,
            ConfigReader::SCOPE_GLOBAL,
            ConfigReader::LOCALE_DEFAULT,
        )) {
            throw new RuntimeException('unable to set commerce kernel rollout key: ' . $key);
        }
    };
    $write(ScopeKernelRolloutPolicy::CONFIG_ALLOWLIST, [
        [
            'website_id' => $sideA['website_id'],
            'store_id' => $sideA['store_id'],
            'channel_id' => $sideA['channel_id'],
        ],
        [
            'website_id' => $sideB['website_id'],
            'store_id' => $sideB['store_id'],
            'channel_id' => $sideB['channel_id'],
        ],
    ]);
    $write(ScopeKernelRolloutPolicy::CONFIG_SHADOW_SAMPLE_BP, 10000);
    $write(ScopeKernelRolloutPolicy::CONFIG_MODE, 'allowlist');

    $activeMode = ObjectManager::getInstance(ScopeKernelRolloutPolicy::class)->mode();
    if ($activeMode !== 'allowlist') {
        throw new RuntimeException(
            'commerce kernel rollout env lock prevents reversible allowlist; active=' . $activeMode,
        );
    }
}

/** @return list<string> */
function ck_product_tables(int $websiteId): array
{
    return array_map(
        static fn(string $entity): string => ProductShardKey::tableName((string)$websiteId, $entity),
        ProductShardSchemaCatalog::ENTITIES,
    );
}

/** @param list<int> $websiteIds */
function ck_schema_signature(array $websiteIds): string
{
    $reader = new DbSchemaReader();
    $connector = ObjectManager::getInstance(ProductShardRegistry::class)
        ->getConnection()
        ->getConnector();
    $schemas = [];
    foreach ($websiteIds as $websiteId) {
        foreach (ck_product_tables($websiteId) as $tableName) {
            $schemas[$tableName] = $reader->readTable($connector, $tableName);
        }
    }

    $normalize = static function (mixed $value) use (&$normalize): mixed {
        if (is_object($value)) {
            $value = get_object_vars($value);
            unset($value['modelClass']);
        }
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as $key => $item) {
                $value[$key] = $normalize($item);
            }
        }

        return $value;
    };
    ksort($schemas);

    return hash('sha256', (string)json_encode(
        $normalize($schemas),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
    ));
}

/** @return array<string, mixed>|null */
function ck_find_website(string $code): ?array
{
    /** @var Website $website */
    $website = clone ObjectManager::getInstance(Website::class);
    $rows = $website->clearData()->clearQuery()
        ->where(Website::schema_fields_CODE, $code)
        ->select()
        ->fetchArray();
    $row = is_array($rows[0] ?? null) ? $rows[0] : null;

    return $row;
}

/** @return array{website_id:int,website_code:string,store_id:int,channel_id:int,host:string,origin:string,url:string} */
function ck_create_side(string $token, string $side, int $port): array
{
    $code = ck_website_code($token, $side);
    $host = ck_host($token, $side);
    $origin = 'https://' . $host . ':' . $port;

    /** @var Website $website */
    $website = clone ObjectManager::getInstance(Website::class);
    $website->clearData()->clearQuery()
        ->setName('Commerce Kernel E2E ' . strtoupper($side) . ' ' . $token)
        ->setCode($code)
        ->setUrl($origin)
        ->setDefaultCurrency('CNY')
        ->setDefaultLanguage(CK_LOCALE)
        ->setDefaultTimezone('Asia/Shanghai')
        ->setScope('commerce-kernel-e2e')
        ->save();
    $websiteId = $website->getWebsiteId();
    if ($websiteId <= 0) {
        throw new RuntimeException('commerce kernel fixture failed to create Website ' . $side);
    }

    /** @var Store $store */
    $store = clone ObjectManager::getInstance(Store::class);
    $store->clearData()->clearQuery()
        ->setWebsiteId($websiteId)
        ->setCode(CK_SCOPE_STORE)
        ->setName('Commerce Scope ' . strtoupper($side))
        ->setStoreMode(Store::MODE_TEST)
        ->setIsDefault(false)
        ->setStatus(true)
        ->setUrl($origin)
        ->save();
    $storeId = $store->getStoreId();

    /** @var SalesChannel $channel */
    $channel = clone ObjectManager::getInstance(SalesChannel::class);
    $channel->clearData()->clearQuery()
        ->setWebsiteId($websiteId)
        ->setStoreId($storeId)
        ->setCode(CK_SCOPE_CHANNEL)
        ->setName('Commerce Scope Default')
        ->setIsDefault(true)
        ->setStatus(true)
        ->save();
    $channelId = $channel->getChannelId();

    return [
        'website_id' => $websiteId,
        'website_code' => $code,
        'store_id' => $storeId,
        'channel_id' => $channelId,
        'host' => $host,
        'origin' => $origin,
        'url' => $origin . '/dev/tool/docs/api',
    ];
}

/**
 * @param array{website_id:int,website_code:string} $side
 */
function ck_seed_scope_values(string $token, string $label, array $side): void
{
    $identity = ck_scope($side['website_code'], $side['website_id']);
    $storage = ObjectManager::getInstance(SystemConfigScopeResolver::class)
        ->toStorageScope($identity);

    /** @var MetaConfigRepositoryInterface $meta */
    $meta = ObjectManager::getInstance(MetaConfigRepositoryInterface::class);
    $meta->upsert(new MetaConfigWrite(
        new MetaConfigIdentity(
            namespace: CK_META_NAMESPACE,
            configKey: CK_META_KEY_PREFIX . $token,
            scope: $storage,
            locale: CK_LOCALE,
            identifyId: '0',
        ),
        'meta-' . strtolower($label) . '-' . $token,
    ));

    /** @var DictionaryRepositoryInterface $dictionary */
    $dictionary = ObjectManager::getInstance(DictionaryRepositoryInterface::class);
    /** @var PhraseScopeResolver $phrases */
    $phrases = ObjectManager::getInstance(PhraseScopeResolver::class);
    $dictionary->upsert(
        $phrases->scopedWord(CK_I18N_SOURCE_PREFIX . $token, $storage),
        CK_LOCALE,
        'phrase-' . strtolower($label) . '-' . $token,
    );
}

/**
 * @param array{website_id:int} $sideA
 * @param array{website_id:int} $sideB
 * @return array<string, mixed>
 */
function ck_prepare_product_ready(array $sideA, array $sideB): array
{
    /** @var ProductShardProvisioner $provisioner */
    $provisioner = ObjectManager::getInstance(ProductShardProvisioner::class);
    /** @var ProductShardRegistry $registry */
    $registry = ObjectManager::getInstance(ProductShardRegistry::class);
    $connector = $registry->getConnection()->getConnector();
    $driver = strtolower((string)$connector->getConfigProvider()->getDbType());
    if (!in_array($driver, ['pgsql', 'postgres', 'postgresql'], true)) {
        throw new RuntimeException('TEST-P2A-02 requires live PostgreSQL; actual driver=' . $driver);
    }

    $readyA = $provisioner->provisionWebsite($sideA['website_id'], [
        'operation_id' => 'commerce-kernel-e2e-a-initial',
    ]);
    $readyB = $provisioner->provisionWebsite($sideB['website_id'], [
        'operation_id' => 'commerce-kernel-e2e-b-initial',
    ]);
    if (!$readyA->isReady() || !$readyB->isReady()) {
        throw new RuntimeException(
            'initial Product shard provision failed: A=' . (string)$readyA->errorMessage
            . '; B=' . (string)$readyB->errorMessage,
        );
    }

    return [
        'database_driver' => $driver,
        'a_status' => $registry->getStatus($sideA['website_id']),
        'a_writable' => $provisioner->isWritable($sideA['website_id']),
        'b_status' => $registry->getStatus($sideB['website_id']),
        'b_writable' => $provisioner->isWritable($sideB['website_id']),
    ];
}

/**
 * Replace A's physical uk_sku index with the same identity on the wrong
 * column. The declarative diff must fail closed for A while B remains ready.
 *
 * @param array{website_id:int} $sideA
 * @param array{website_id:int} $sideB
 * @return array<string, mixed>
 */
function ck_apply_product_drift(array $sideA, array $sideB): array
{
    /** @var ProductShardProvisioner $provisioner */
    $provisioner = ObjectManager::getInstance(ProductShardProvisioner::class);
    /** @var ProductShardRegistry $registry */
    $registry = ObjectManager::getInstance(ProductShardRegistry::class);
    $connector = $registry->getConnection()->getConnector();
    $driver = strtolower((string)$connector->getConfigProvider()->getDbType());
    if (!in_array($driver, ['pgsql', 'postgres', 'postgresql'], true)) {
        throw new RuntimeException('TEST-P2A-02 requires live PostgreSQL; actual driver=' . $driver);
    }
    if ($registry->getStatus($sideA['website_id']) !== ProductShardRegistry::STATUS_READY
        || $registry->getStatus($sideB['website_id']) !== ProductShardRegistry::STATUS_READY) {
        throw new RuntimeException('Product shards must both be ready before drift injection');
    }

    $table = ProductShardKey::tableName((string)$sideA['website_id'], 'product');
    $physicalIndex = IndexDefinitionContract::physicalIdentity($connector, $table, 'uk_sku');
    $physicalTable = $connector->formatTableName($table);
    $connector->query('DROP INDEX IF EXISTS "' . $physicalIndex . '"')->fetch();
    $connector->query(
        'CREATE UNIQUE INDEX "' . $physicalIndex . '" ON ' . $physicalTable . ' ("global_product_uuid")',
    )->fetch();

    // Force the ready shard through declarative verification on the next call.
    $registry->clearData()->clearQuery()
        ->where(ProductShardRegistry::schema_fields_WEBSITE_ID, $sideA['website_id'])
        ->update([
            ProductShardRegistry::schema_fields_SCHEMA_VERSION => '0.0.0-e2e-drift',
        ])
        ->fetch();

    $driftA = $provisioner->provisionWebsite($sideA['website_id'], [
        'operation_id' => 'commerce-kernel-e2e-a-drift',
    ]);
    $stillReadyB = $provisioner->provisionWebsite($sideB['website_id'], [
        'operation_id' => 'commerce-kernel-e2e-b-control',
    ]);

    return [
        'database_driver' => $driver,
        'a_status' => $registry->getStatus($sideA['website_id']),
        'a_result_status' => $driftA->status,
        'a_error' => (string)$driftA->errorMessage,
        'a_writable' => $provisioner->isWritable($sideA['website_id']),
        'b_status' => $registry->getStatus($sideB['website_id']),
        'b_result_status' => $stillReadyB->status,
        'b_writable' => $provisioner->isWritable($sideB['website_id']),
        'physical_drift_index' => $physicalIndex,
        'schema_signature' => ck_schema_signature([
            $sideA['website_id'],
            $sideB['website_id'],
        ]),
    ];
}

function ck_cleanup_side(string $code, ?string $guestToken): void
{
    $row = ck_find_website($code);
    if ($row === null) {
        return;
    }
    $websiteId = (int)($row[Website::schema_fields_ID] ?? 0);
    if ($websiteId <= 0) {
        return;
    }
    $scope = ck_scope($code, $websiteId);
    $storage = ObjectManager::getInstance(SystemConfigScopeResolver::class)
        ->toStorageScope($scope);
    $token = preg_replace('/^cke2e_|_[ab]$/', '', $code) ?: '';

    try {
        /** @var MetaConfigRepositoryInterface $meta */
        $meta = ObjectManager::getInstance(MetaConfigRepositoryInterface::class);
        $meta->delete(new MetaConfigIdentity(
            namespace: CK_META_NAMESPACE,
            configKey: CK_META_KEY_PREFIX . $token,
            scope: $storage,
            locale: CK_LOCALE,
            identifyId: '0',
        ));
    } catch (Throwable) {
    }
    try {
        /** @var DictionaryRepositoryInterface $dictionary */
        $dictionary = ObjectManager::getInstance(DictionaryRepositoryInterface::class);
        /** @var PhraseScopeResolver $phrases */
        $phrases = ObjectManager::getInstance(PhraseScopeResolver::class);
        $dictionary->deleteEntry(
            $phrases->scopedWord(CK_I18N_SOURCE_PREFIX . $token, $storage),
            CK_LOCALE,
        );
    } catch (Throwable) {
    }
    if ($guestToken !== null && trim($guestToken) !== '') {
        try {
            /** @var CartV2CacheStore $carts */
            $carts = ObjectManager::getInstance(CartV2CacheStore::class);
            $carts->delete($scope->canonicalKey() . '|guest:' . trim($guestToken));
        } catch (Throwable) {
        }
    }

    $connector = ObjectManager::getInstance(ProductShardRegistry::class)
        ->getConnection()
        ->getConnector();
    foreach (array_reverse(ck_product_tables($websiteId)) as $tableName) {
        $connector->dropTableIfExists($tableName);
    }
    // Default Store/Channel rows are deliberately protected from normal model
    // deletion. Test teardown owns this exact Website and therefore purges its
    // children in reverse order with connector-formatted, id-bounded SQL.
    $deleteByWebsiteId = static function (string $logicalTable, string $field) use (
        $connector,
        $websiteId,
    ): void {
        $connector->query(
            'DELETE FROM ' . $connector->formatTableName($logicalTable)
            . ' WHERE "' . $field . '" = ' . $websiteId,
        )->fetch();
    };
    $deleteByWebsiteId(
        ProductShardRegistry::schema_table,
        ProductShardRegistry::schema_fields_WEBSITE_ID,
    );
    $deleteByWebsiteId(SalesChannel::schema_table, SalesChannel::schema_fields_WEBSITE_ID);
    $deleteByWebsiteId(Store::schema_table, Store::schema_fields_WEBSITE_ID);
    $deleteByWebsiteId(WebsiteDomain::schema_table, WebsiteDomain::schema_fields_WEBSITE_ID);
    $connector->query(
        'DELETE FROM ' . $connector->formatTableName(Website::schema_table)
        . ' WHERE "' . Website::schema_fields_ID . '" = ' . $websiteId,
    )->fetch();

    try {
        ObjectManager::getInstance(WebsiteCacheInvalidationService::class)
            ->invalidateDeletedWebsite(
                ObjectManager::getInstance(Website::class)->getConnection(),
                $code,
            );
    } catch (Throwable) {
    }
}

function ck_cleanup(string $token, ?string $guestToken = null): void
{
    ck_restore_rollout($token);
    ck_cleanup_side(ck_website_code($token, 'a'), $guestToken);
    ck_cleanup_side(ck_website_code($token, 'b'), $guestToken);
    CartV2HarnessCatalog::delete(ck_offer_uuid($token));
}

/** @return array<string, mixed> */
function ck_prepare(string $token, int $port): array
{
    if ($port < 9502 || $port > 65535) {
        throw new InvalidArgumentException('port must be an integer between 9502 and 65535');
    }
    ck_cleanup($token);

    try {
        $sideA = ck_create_side($token, 'a', $port);
        $sideB = ck_create_side($token, 'b', $port);
        ck_seed_scope_values($token, 'a', $sideA);
        ck_seed_scope_values($token, 'b', $sideB);
        ck_enable_rollout($token, $sideA, $sideB);

        $offerUuid = ck_offer_uuid($token);
        CartV2HarnessCatalog::put($offerUuid, [
            'name' => 'Commerce Kernel Dual Scope ' . $token,
            'sku' => 'ck-e2e-' . $token,
            'currency' => 'CNY',
            'unit_price_minor' => 8800,
            'stock' => 20,
            'sellable' => true,
            'found' => true,
            'product_type' => 'simple',
        ]);
        $product = ck_prepare_product_ready($sideA, $sideB);
        $signature = ck_schema_signature([$sideA['website_id'], $sideB['website_id']]);

        return [
            'token' => $token,
            'a' => $sideA,
            'b' => $sideB,
            'locale' => CK_LOCALE,
            'meta_namespace' => CK_META_NAMESPACE,
            'meta_key' => CK_META_KEY_PREFIX . $token,
            'meta_value_a' => 'meta-a-' . $token,
            'meta_value_b' => 'meta-b-' . $token,
            'i18n_source' => CK_I18N_SOURCE_PREFIX . $token,
            'i18n_value_a' => 'phrase-a-' . $token,
            'i18n_value_b' => 'phrase-b-' . $token,
            'offer_uuid' => $offerUuid,
            'provider_code' => 'product',
            'rollout_mode' => 'allowlist',
            'product' => $product,
            'schema_signature' => $signature,
        ];
    } catch (Throwable $exception) {
        ck_cleanup($token);
        throw $exception;
    }
}

try {
    $input = ck_read_input();
    $action = trim((string)($input['action'] ?? ''));
    if ($action === 'prepare') {
        $token = ck_token($input['token'] ?? null);
        ck_output([
            'ok' => true,
            'action' => 'prepare',
        ] + ck_prepare($token, (int)($input['port'] ?? 0)));
    }
    if ($action === 'inspect') {
        $websiteIds = array_values(array_map(
            'intval',
            is_array($input['website_ids'] ?? null) ? $input['website_ids'] : [],
        ));
        if (count($websiteIds) !== 2 || min($websiteIds) <= 0) {
            throw new InvalidArgumentException('inspect requires exactly two positive website_ids');
        }
        $actual = ck_schema_signature($websiteIds);
        $expected = trim((string)($input['expected_schema_signature'] ?? ''));
        ck_output([
            'ok' => true,
            'action' => 'inspect',
            'schema_signature' => $actual,
            'unchanged' => $expected !== '' && hash_equals($expected, $actual),
        ]);
    }
    if ($action === 'drift') {
        $websiteIds = array_values(array_map(
            'intval',
            is_array($input['website_ids'] ?? null) ? $input['website_ids'] : [],
        ));
        if (count($websiteIds) !== 2 || min($websiteIds) <= 0) {
            throw new InvalidArgumentException('drift requires exactly two positive website_ids');
        }
        ck_output([
            'ok' => true,
            'action' => 'drift',
        ] + ck_apply_product_drift(
            ['website_id' => $websiteIds[0]],
            ['website_id' => $websiteIds[1]],
        ));
    }
    if ($action === 'cleanup') {
        $token = ck_token($input['token'] ?? null);
        ck_cleanup(
            $token,
            isset($input['guest_token']) ? (string)$input['guest_token'] : null,
        );
        ck_output(['ok' => true, 'action' => 'cleanup', 'token' => $token]);
    }
    throw new InvalidArgumentException('unknown action: ' . $action);
} catch (Throwable $exception) {
    ck_output([
        'ok' => false,
        'error' => $exception->getMessage(),
        'exception' => $exception::class,
    ]);
}
