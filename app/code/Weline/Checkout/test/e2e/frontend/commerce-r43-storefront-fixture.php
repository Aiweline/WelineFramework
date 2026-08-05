<?php

declare(strict_types=1);

/**
 * CK-R43-STORE-101 isolated PostgreSQL fixture.
 *
 * This script may only prepare prerequisites, inspect persistence and remove
 * rows owned by its token. The add, navigation, checkout and submit actions are
 * deliberately absent: Playwright must perform them through the rendered UI.
 */

use Weline\Checkout\Model\CheckoutSession;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Connector as PgsqlConnector;
use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Model\InventoryLedger;
use Weline\Inventory\Model\InventoryStock;
use Weline\Inventory\Model\Reservation;
use Weline\Inventory\Service\InventoryService;
use Weline\Order\Model\CheckoutGroup;
use Weline\Order\Model\DisplayNumberRegistry;
use Weline\Order\Model\FulfillmentUnit;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderItem;
use Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider\FakeProvider;
use Weline\Payment\Model\PaymentMethod;
use Weline\Payment\Service\PaymentMethodManager;
use Weline\Product\Model\Shard\AttributeValue;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Model\Shard\Price;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Model\Shard\StoreOffer;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\PriceRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Service\ProductShardProvisioner;
use Weline\Shipping\Model\Carrier;
use Weline\Shipping\Model\RateTemplate;
use Weline\Shipping\Model\Region;
use Weline\Shipping\Model\ShippingService;
use Weline\Shipping\Model\Zone;
use Weline\Shipping\Model\ZoneRegion;
use Weline\SystemConfig\Api\ConfigStore;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\SystemConfig\Model\SystemConfigVersion;
use Weline\Websites\Model\Store;

$root = dirname(__DIR__, 7);
require $root . '/app/bootstrap.php';

/** @return array<string,mixed> */
function r43_store_input(): array
{
    $decoded = json_decode((string)stream_get_contents(STDIN), true);
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new InvalidArgumentException('stdin_must_be_json_object');
    }

    return $decoded;
}

/** @param array<string,mixed> $payload */
function r43_store_output(array $payload, int $code = 0): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit($code);
}

/** @return array{database:string,connector:string} */
function r43_store_isolation_guard(): array
{
    if ((string)getenv('WELINE_E2E_ISOLATED_DB') !== '1') {
        throw new RuntimeException('r43_store_requires_WELINE_E2E_ISOLATED_DB_1');
    }
    $env = require dirname(__DIR__, 7) . '/app/etc/env.php';
    $db = is_array($env['db']['master'] ?? null)
        ? $env['db']['master']
        : (is_array($env['db'] ?? null) ? $env['db'] : []);
    $database = strtolower(trim((string)($db['database'] ?? '')));
    if (preg_match('/^mig_clone_[a-z0-9_]+$/D', $database) !== 1) {
        throw new RuntimeException('r43_store_requires_mig_clone_database:' . $database);
    }
    $probe = ObjectManager::getInstance(SystemConfig::class, [], false);
    $connectorInstance = $probe->getConnection()->getConnector();
    $connector = get_class($connectorInstance);
    if (!$connectorInstance instanceof PgsqlConnector) {
        throw new RuntimeException('r43_store_requires_postgresql:' . $connector);
    }

    return ['database' => $database, 'connector' => $connector];
}

function r43_store_uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/** @param class-string $class */
function r43_store_model(string $class): object
{
    return ObjectManager::getInstance($class, [], false);
}

/** @param class-string $class */
function r43_store_shard_model(string $class, int $websiteId = 0): object
{
    $model = r43_store_model($class);

    return $model->forWebsite($websiteId);
}

/** @param array<string,mixed> $where @return list<array<string,mixed>> */
function r43_store_rows(object $model, array $where = []): array
{
    $query = $model->clear();
    foreach ($where as $field => $value) {
        $query->where((string)$field, $value);
    }
    $rows = $query->select()->fetchArray();

    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

/**
 * Normalize database rows before exact preimage comparison.
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function r43_store_canonical_rows(array $rows): array
{
    foreach ($rows as &$row) {
        ksort($row);
    }
    unset($row);
    usort($rows, static fn(array $left, array $right): int => strcmp(
        (string)json_encode($left, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        (string)json_encode($right, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ));

    return $rows;
}

function r43_store_canonical_value(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('r43_store_canonical_value', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as &$item) {
        $item = r43_store_canonical_value($item);
    }
    unset($item);

    return $value;
}

/** @param array<string,mixed> $state */
function r43_store_business_hash(array $state): string
{
    return hash('sha256', json_encode(
        r43_store_canonical_value($state),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ));
}

/** @param array<string,mixed> $where */
function r43_store_delete(object $model, array $where): int
{
    $rows = r43_store_rows($model, $where);
    $query = $model->clear();
    foreach ($where as $field => $value) {
        $query->where((string)$field, $value);
    }
    $query->delete()->fetch();

    return count($rows);
}

/** @param array<string,mixed> $where @param list<array<string,mixed>> $rows */
function r43_store_replace_rows(object $model, array $where, array $rows): void
{
    r43_store_delete($model, $where);
    foreach ($rows as $row) {
        $model->clear()->insert($row)->fetch();
    }
}

/** @return array{module:string,area:string,scope:string,locale:string} */
function r43_store_system_config_identity(string $module, string $area): array
{
    $scope = SystemConfig::SCOPE_GLOBAL;
    $locale = SystemConfig::LOCALE_DEFAULT;

    return [
        'module' => $module,
        'area' => $area,
        'scope' => $scope,
        'locale' => $locale,
    ];
}

function r43_store_decode_config_value(array $row): mixed
{
    $value = $row[SystemConfig::schema_fields_VALUE] ?? null;

    return match ((string)($row[SystemConfig::schema_fields_VALUE_TYPE] ?? SystemConfig::VALUE_TYPE_STRING)) {
        SystemConfig::VALUE_TYPE_NULL => null,
        SystemConfig::VALUE_TYPE_BOOL => (string)$value === '1' || $value === true,
        SystemConfig::VALUE_TYPE_INT => (int)$value,
        SystemConfig::VALUE_TYPE_FLOAT => (float)$value,
        SystemConfig::VALUE_TYPE_JSON => json_decode((string)$value, true),
        default => $value,
    };
}

/**
 * @param array{module:string,area:string,scope:string,locale:string} $identity
 * @return array<string,array{exists:bool,value?:mixed,value_type?:string,is_sensitive?:bool,metadata?:array<mixed>}>
 */
function r43_store_payment_config_state(array $identity): array
{
    $state = [];
    foreach (r43_store_payment_keys() as $key) {
        $rows = r43_store_rows(r43_store_model(SystemConfig::class), [
            SystemConfig::schema_fields_KEY => $key,
            SystemConfig::schema_fields_MODULE => $identity['module'],
            SystemConfig::schema_fields_AREA => $identity['area'],
            SystemConfig::schema_fields_SCOPE => $identity['scope'],
            SystemConfig::schema_fields_LOCALE => $identity['locale'],
        ]);
        if (count($rows) > 1) {
            throw new RuntimeException('payment_config_duplicate_exact_row:' . $key);
        }
        $row = $rows[0] ?? null;
        if (!is_array($row) || (int)($row[SystemConfig::schema_fields_IS_ACTIVE] ?? 1) !== 1) {
            $state[$key] = ['exists' => false];
            continue;
        }
        $metadata = json_decode((string)($row[SystemConfig::schema_fields_METADATA] ?? ''), true);
        $state[$key] = [
            'exists' => true,
            'value' => r43_store_decode_config_value($row),
            'value_type' => (string)($row[SystemConfig::schema_fields_VALUE_TYPE] ?? SystemConfig::VALUE_TYPE_STRING),
            'is_sensitive' => (int)($row[SystemConfig::schema_fields_IS_SENSITIVE] ?? 0) === 1,
            'metadata' => is_array($metadata) ? $metadata : [],
        ];
    }
    ksort($state, SORT_STRING);

    return $state;
}

/** @param array{module:string,area:string,scope:string,locale:string} $identity @return list<int> */
function r43_store_config_version_ids(array $identity): array
{
    $ids = array_values(array_filter(array_map(
        static fn(array $row): int => (int)($row[SystemConfigVersion::schema_fields_ID] ?? 0),
        r43_store_rows(r43_store_model(SystemConfigVersion::class), [
            SystemConfigVersion::schema_fields_MODULE => $identity['module'],
            SystemConfigVersion::schema_fields_AREA => $identity['area'],
            SystemConfigVersion::schema_fields_SCOPE => $identity['scope'],
            SystemConfigVersion::schema_fields_LOCALE => $identity['locale'],
        ]),
    )));
    sort($ids, SORT_NUMERIC);

    return $ids;
}

/** @return array{requested:bool,instance:string,exit_code:?int} */
function r43_store_reload_dedicated_wls(): array
{
    $configured = getenv('WELINE_E2E_WLS_INSTANCE');
    if ($configured === false || $configured === '') {
        return ['requested' => false, 'instance' => '', 'exit_code' => null];
    }
    if (trim($configured) !== $configured
        || preg_match('/^ai-test-commerce-r43-[A-Za-z0-9][A-Za-z0-9_-]{0,80}$/D', $configured) !== 1
    ) {
        throw new RuntimeException('r43_store_refuses_non_dedicated_wls_instance:' . $configured);
    }

    $root = dirname(__DIR__, 7);
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $root . '/bin/w', 'server:reload', $configured],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $root,
        null,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        throw new RuntimeException('r43_store_dedicated_wls_reload_start_failed');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException('r43_store_dedicated_wls_reload_failed:' . trim((string)$stderr . "\n" . (string)$stdout));
    }

    return ['requested' => true, 'instance' => $configured, 'exit_code' => $exitCode];
}

/** @param class-string<AbstractModel> $class */
function r43_store_lookup_id(string $class, string $field, int|string $value, string $idField): int
{
    $row = r43_store_model($class)->where($field, $value)->find()->fetch();
    $id = $row instanceof AbstractModel ? (int)$row->getData($idField) : 0;
    if ($id <= 0) {
        throw new RuntimeException('fixture_row_not_persisted:' . $class);
    }

    return $id;
}

/** @return list<string> */
function r43_store_payment_keys(): array
{
    return [
        'payment/method/fake_card/enabled',
        'payment/method/fake_card/environment',
        'payment/method/fake_card/config_test_status',
        'payment/method/fake_card/supported_currencies',
        'payment/method/fake_card/supported_countries',
    ];
}

/** @return array<string,mixed> */
function r43_store_payment_snapshot(): array
{
    $identity = r43_store_system_config_identity('Weline_Payment', SystemConfig::area_BACKEND);
    $configState = r43_store_payment_config_state($identity);
    $paymentMethodRows = r43_store_canonical_rows(r43_store_rows(r43_store_model(PaymentMethod::class), [
        PaymentMethod::schema_fields_CODE => 'fake_card',
    ]));
    $businessState = [
        'config_state' => $configState,
        'payment_method_rows' => $paymentMethodRows,
    ];

    return [
        'schema' => 'weline.r43.payment-business-preimage.v2',
        'identity' => $identity,
        'config_state' => $configState,
        'payment_method_rows' => $paymentMethodRows,
        'version_ids' => r43_store_config_version_ids($identity),
        'business_hash' => r43_store_business_hash($businessState),
    ];
}

/** @param array<string,mixed> $snapshot */
function r43_store_write_snapshot(array $snapshot): string
{
    $token = bin2hex(random_bytes(16));
    $path = sys_get_temp_dir() . '/weline-r43-store-' . $token . '.json';
    if (file_put_contents($path, json_encode($snapshot, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
        throw new RuntimeException('fixture_snapshot_write_failed');
    }
    @chmod($path, 0600);

    return $token;
}

/** @return array<string,mixed> */
function r43_store_read_snapshot(string $token): array
{
    if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
        throw new InvalidArgumentException('invalid_fixture_snapshot_token');
    }
    $path = sys_get_temp_dir() . '/weline-r43-store-' . $token . '.json';
    $decoded = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('fixture_snapshot_invalid');
    }

    return $decoded;
}

function r43_store_prepare_payment(string $country): string
{
    $snapshotToken = r43_store_write_snapshot(r43_store_payment_snapshot());
    try {
        /** @var PaymentMethodManager $manager */
        $manager = ObjectManager::getInstance(PaymentMethodManager::class);
        /** @var FakeProvider $provider */
        $provider = ObjectManager::getInstance(FakeProvider::class);
        $method = $manager->registerProvider($provider, [
            'source_module' => 'Weline_Payment',
            'source_file' => (new ReflectionClass(FakeProvider::class))->getFileName(),
        ]);
        $method->setData(PaymentMethod::schema_fields_IS_ACTIVE, 1)->save();

        /** @var ConfigStore $config */
        $config = ObjectManager::getInstance(ConfigStore::class);
        $values = [
            'payment/method/fake_card/enabled' => true,
            'payment/method/fake_card/environment' => 'sandbox',
            'payment/method/fake_card/config_test_status' => 'passed',
            'payment/method/fake_card/supported_currencies' => ['CNY'],
            'payment/method/fake_card/supported_countries' => ['CN', $country],
        ];
        foreach ($values as $key => $value) {
            $config->setScopedConfig(
                $key,
                $value,
                'Weline_Payment',
                ConfigStore::area_BACKEND,
                ConfigStore::SCOPE_GLOBAL,
                ConfigStore::LOCALE_DEFAULT,
            );
        }

        return $snapshotToken;
    } catch (Throwable $throwable) {
        r43_store_restore_payment($snapshotToken);
        throw $throwable;
    }
}

/** @return array<string,mixed> */
function r43_store_restore_payment(string $snapshotToken): array
{
    $snapshot = r43_store_read_snapshot($snapshotToken);
    $identity = r43_store_system_config_identity('Weline_Payment', SystemConfig::area_BACKEND);
    if (($snapshot['schema'] ?? '') !== 'weline.r43.payment-business-preimage.v2'
        || ($snapshot['identity'] ?? null) !== $identity
        || !is_array($snapshot['config_state'] ?? null)
        || !is_array($snapshot['payment_method_rows'] ?? null)
        || !is_array($snapshot['version_ids'] ?? null)
        || !is_string($snapshot['business_hash'] ?? null)) {
        throw new RuntimeException('payment_snapshot_missing_business_preimage');
    }
    $expectedKeys = r43_store_payment_keys();
    $snapshotKeys = array_keys($snapshot['config_state']);
    sort($expectedKeys, SORT_STRING);
    sort($snapshotKeys, SORT_STRING);
    if ($snapshotKeys !== $expectedKeys) {
        throw new RuntimeException('payment_snapshot_config_key_mismatch');
    }

    /** @var ConfigStore $config */
    $config = ObjectManager::getInstance(ConfigStore::class);
    foreach ($snapshot['config_state'] as $key => $state) {
        if (!is_array($state) || !array_key_exists('exists', $state)) {
            throw new RuntimeException('payment_snapshot_config_state_invalid:' . $key);
        }
        if ($state['exists'] === true) {
            $metadata = is_array($state['metadata'] ?? null) ? $state['metadata'] : [];
            $restored = $config->setScopedConfig(
                (string)$key,
                $state['value'] ?? null,
                $identity['module'],
                $identity['area'],
                $identity['scope'],
                $identity['locale'],
                [
                    'value_types' => [(string)$key => (string)($state['value_type'] ?? SystemConfig::VALUE_TYPE_STRING)],
                    'is_sensitive_values' => [(string)$key => (bool)($state['is_sensitive'] ?? false)],
                    'field_metadata' => [(string)$key => $metadata],
                    'actor_name' => 'commerce-r43-e2e',
                    'reason' => 'restore isolated payment fixture business state',
                ],
            );
        } else {
            $restored = $config->deleteScopedConfig(
                (string)$key,
                $identity['module'],
                $identity['area'],
                $identity['scope'],
                $identity['locale'],
                [
                    'actor_name' => 'commerce-r43-e2e',
                    'reason' => 'restore isolated payment fixture inheritance',
                ],
            );
        }
        if (!$restored) {
            throw new RuntimeException('payment_config_formal_restore_failed:' . $key);
        }
    }
    r43_store_replace_rows(r43_store_model(PaymentMethod::class), [
        PaymentMethod::schema_fields_CODE => 'fake_card',
    ], $snapshot['payment_method_rows']);

    $restoredConfigState = r43_store_payment_config_state($identity);
    $restoredPaymentRows = r43_store_canonical_rows(r43_store_rows(r43_store_model(PaymentMethod::class), [
        PaymentMethod::schema_fields_CODE => 'fake_card',
    ]));
    $postHash = r43_store_business_hash([
        'config_state' => $restoredConfigState,
        'payment_method_rows' => $restoredPaymentRows,
    ]);
    $hashExact = hash_equals((string)$snapshot['business_hash'], $postHash);
    $paymentMethodExact = $restoredPaymentRows === r43_store_canonical_rows($snapshot['payment_method_rows']);
    if (!$hashExact || !$paymentMethodExact) {
        throw new RuntimeException('payment_cleanup_business_state_mismatch:' . json_encode([
            'preimage_hash' => $snapshot['business_hash'],
            'post_cleanup_hash' => $postHash,
            'payment_method_exact' => $paymentMethodExact,
        ]));
    }

    $preimageVersionIds = array_values(array_map('intval', $snapshot['version_ids']));
    sort($preimageVersionIds, SORT_NUMERIC);
    $currentVersionIds = r43_store_config_version_ids($identity);
    $missingVersionIds = array_values(array_diff($preimageVersionIds, $currentVersionIds));
    if ($missingVersionIds !== []) {
        throw new RuntimeException('payment_config_versions_regressed:' . json_encode($missingVersionIds));
    }
    $appendedVersionIds = array_values(array_diff($currentVersionIds, $preimageVersionIds));
    sort($appendedVersionIds, SORT_NUMERIC);
    $wlsReload = r43_store_reload_dedicated_wls();
    @unlink(sys_get_temp_dir() . '/weline-r43-store-' . $snapshotToken . '.json');

    return [
        'system_config_business_exact' => true,
        'payment_method_exact' => true,
        'version_ids_monotonic' => true,
        'preimage_hash' => $snapshot['business_hash'],
        'post_cleanup_hash' => $postHash,
        'hash_exact' => true,
        'preimage_version_count' => count($preimageVersionIds),
        'current_version_count' => count($currentVersionIds),
        'appended_version_ids' => $appendedVersionIds,
        'wls_reload' => $wlsReload,
    ];
}

/** @return array<string,mixed> */
function r43_store_prepare(): array
{
    $run = 'r43store_' . substr(bin2hex(random_bytes(8)), 0, 12);
    $now = gmdate('Y-m-d H:i:s');
    $country = 'XZ';
    $sku = 'R43-STORE-' . strtoupper(substr($run, -12));
    $name = 'R43 Storefront Product ' . substr($run, -6);
    $snapshotToken = r43_store_prepare_payment($country);
    $owned = [
        'run' => $run,
        'website_id' => 0,
        'sku' => $sku,
        'name' => $name,
        'prepared_at' => $now,
        'offer_uuid' => '',
        'payment_snapshot_token' => $snapshotToken,
    ];

    try {
        $region = r43_store_model(Region::class);
        $region->setData([
            Region::schema_fields_COUNTRY_CODE => $country,
            Region::schema_fields_PARENT_REGION_ID => null,
            Region::schema_fields_REGION_CODE => $run,
            Region::schema_fields_REGION_NAME => 'R43 Storefront Country',
            Region::schema_fields_REGION_TYPE => Region::TYPE_COUNTRY,
            Region::schema_fields_IS_ACTIVE => 1,
            Region::schema_fields_SORT_ORDER => 0,
            Region::schema_fields_CREATED_AT => $now,
            Region::schema_fields_UPDATED_AT => $now,
        ])->save();
        $regionId = r43_store_lookup_id(Region::class, Region::schema_fields_REGION_CODE, $run, Region::schema_fields_ID);
        $owned['region_id'] = $regionId;

        $zone = r43_store_model(Zone::class);
        $zone->setData([
            Zone::schema_fields_ZONE_NAME => 'R43 Storefront Zone',
            Zone::schema_fields_ZONE_CODE => $run,
            Zone::schema_fields_DESCRIPTION => 'CK-R43-STORE-101',
            Zone::schema_fields_IS_ACTIVE => 1,
            Zone::schema_fields_SORT_ORDER => 0,
            Zone::schema_fields_CREATED_AT => $now,
            Zone::schema_fields_UPDATED_AT => $now,
        ])->save();
        $zoneId = r43_store_lookup_id(Zone::class, Zone::schema_fields_ZONE_CODE, $run, Zone::schema_fields_ID);
        $owned['zone_id'] = $zoneId;

        $zoneRegion = r43_store_model(ZoneRegion::class);
        $zoneRegion->setData([
            ZoneRegion::schema_fields_ZONE_ID => $zoneId,
            ZoneRegion::schema_fields_REGION_ID => $regionId,
            ZoneRegion::schema_fields_CREATED_AT => $now,
        ])->save();
        $zoneRegionId = r43_store_lookup_id(ZoneRegion::class, ZoneRegion::schema_fields_ZONE_ID, $zoneId, ZoneRegion::schema_fields_ID);
        $owned['zone_region_id'] = $zoneRegionId;

        $carrier = r43_store_model(Carrier::class);
        $carrier->setData([
            Carrier::schema_fields_CARRIER_CODE => $run,
            Carrier::schema_fields_CARRIER_NAME => 'R43 Storefront Carrier',
            Carrier::schema_fields_CARRIER_TYPE => Carrier::TYPE_MANUAL,
            Carrier::schema_fields_TRACKING_URL_TEMPLATE => 'https://example.test/track/{tracking_number}',
            Carrier::schema_fields_TRACKING_API_METHOD => 'GET',
            Carrier::schema_fields_TRACKING_SUPPORT_STATUS => Carrier::TRACKING_SUPPORTED,
            Carrier::schema_fields_IS_ACTIVE => 1,
            Carrier::schema_fields_SORT_ORDER => 0,
            Carrier::schema_fields_CREATED_AT => $now,
            Carrier::schema_fields_UPDATED_AT => $now,
        ])->save();
        $carrierId = r43_store_lookup_id(Carrier::class, Carrier::schema_fields_CARRIER_CODE, $run, Carrier::schema_fields_ID);
        $owned['carrier_id'] = $carrierId;

        $template = r43_store_model(RateTemplate::class);
        $template->setData([
            RateTemplate::schema_fields_TEMPLATE_NAME => 'R43 Storefront Fixed',
            RateTemplate::schema_fields_TEMPLATE_CODE => $run,
            RateTemplate::schema_fields_CALCULATION_TYPE => RateTemplate::CALC_TYPE_FIXED,
            RateTemplate::schema_fields_BASE_FEE => '9.90',
            RateTemplate::schema_fields_WEIGHT_UNIT => 'kg',
            RateTemplate::schema_fields_WEIGHT_RATE => '0',
            RateTemplate::schema_fields_VOLUME_UNIT => 'm3',
            RateTemplate::schema_fields_VOLUME_RATE => '0',
            RateTemplate::schema_fields_QUANTITY_RATE => '0',
            RateTemplate::schema_fields_CURRENCY_CODE => 'CNY',
            RateTemplate::schema_fields_IS_ACTIVE => 1,
            RateTemplate::schema_fields_CREATED_AT => $now,
            RateTemplate::schema_fields_UPDATED_AT => $now,
        ])->save();
        $templateId = r43_store_lookup_id(RateTemplate::class, RateTemplate::schema_fields_TEMPLATE_CODE, $run, RateTemplate::schema_fields_ID);
        $owned['template_id'] = $templateId;

        $service = r43_store_model(ShippingService::class);
        $service->setData([
            ShippingService::schema_fields_SERVICE_NAME => 'R43 Storefront Standard',
            ShippingService::schema_fields_SERVICE_CODE => $run,
            ShippingService::schema_fields_CARRIER_ID => $carrierId,
            ShippingService::schema_fields_ZONE_ID => $zoneId,
            ShippingService::schema_fields_RATE_TEMPLATE_ID => $templateId,
            ShippingService::schema_fields_FREE_SHIPPING_RULE_ID => null,
            ShippingService::schema_fields_ESTIMATED_DAYS_MIN => 1,
            ShippingService::schema_fields_ESTIMATED_DAYS_MAX => 3,
            ShippingService::schema_fields_IS_FREE_SHIPPING => 0,
            ShippingService::schema_fields_IS_ACTIVE => 1,
            ShippingService::schema_fields_SORT_ORDER => 0,
            ShippingService::schema_fields_CREATED_AT => $now,
            ShippingService::schema_fields_UPDATED_AT => $now,
        ])->save();
        $serviceId = r43_store_lookup_id(ShippingService::class, ShippingService::schema_fields_SERVICE_CODE, $run, ShippingService::schema_fields_ID);
        $owned['service_id'] = $serviceId;

        $websiteId = 0;
        $provision = ObjectManager::getInstance(ProductShardProvisioner::class)->provisionWebsite($websiteId);
        if (!$provision->isReady()) {
            throw new RuntimeException('product_shard_not_ready:' . (string)$provision->errorMessage);
        }
        $productUuid = r43_store_uuid();
        $offerUuid = r43_store_uuid();
        $owned['product_uuid'] = $productUuid;
        $owned['offer_uuid'] = $offerUuid;
        /** @var ProductRepository $products */
        $products = ObjectManager::getInstance(ProductRepository::class);
        $product = $products->create($websiteId, [
            Product::schema_fields_SKU => $sku,
            Product::schema_fields_GLOBAL_PRODUCT_UUID => $productUuid,
        ]);
        $productId = (int)$product->getId();
        $owned['product_id'] = $productId;
        /** @var AttributeValueRepository $attributes */
        $attributes = ObjectManager::getInstance(AttributeValueRepository::class);
        $attributes->writeExplicit($websiteId, 0, 'product', $productId, 'name', '', $name, true);
        $attributes->writeExplicit($websiteId, 0, 'product', $productId, 'product_type', '', 'simple', true);
        $products->publish($websiteId, $productId, 0);

        /** @var OfferRepository $offers */
        $offers = ObjectManager::getInstance(OfferRepository::class);
        $offer = $offers->create($websiteId, [
            Offer::schema_fields_PRODUCT_ID => $productId,
            Offer::schema_fields_GLOBAL_OFFER_UUID => $offerUuid,
        ]);
        $offerId = (int)$offer->getId();
        $owned['offer_id'] = $offerId;
        $offers->publish($websiteId, $offerId, 0);
        /** @var PriceRepository $prices */
        $prices = ObjectManager::getInstance(PriceRepository::class);
        $prices->writeExplicit($websiteId, 0, $offerId, 'CNY', 12900);

        $storeIds = [0];
        foreach (r43_store_rows(r43_store_model(Store::class), [Store::schema_fields_WEBSITE_ID => $websiteId]) as $storeRow) {
            $storeId = (int)($storeRow[Store::schema_fields_ID] ?? 0);
            if ($storeId > 0) {
                $storeIds[] = $storeId;
            }
        }
        $storeIds = array_values(array_unique($storeIds));
        $owned['store_ids'] = $storeIds;
        /** @var InventoryService $inventory */
        $inventory = ObjectManager::getInstance(InventoryService::class);
        foreach ($storeIds as $storeId) {
            $key = $run . '-stock-' . $storeId;
            $inventory->setOnHand($websiteId, $storeId, $offerId, 20, $key, hash('sha256', $key));
        }

        return [
            'run' => $run,
            'website_id' => $websiteId,
            'store_ids' => $storeIds,
            'sku' => $sku,
            'name' => $name,
            'prepared_at' => $now,
            'product_id' => $productId,
            'product_uuid' => $productUuid,
            'offer_id' => $offerId,
            'offer_uuid' => $offerUuid,
            'unit_price_minor' => 12900,
            'currency' => 'CNY',
            'country_code' => $country,
            'service_code' => $run,
            'payment_method' => 'fake_card',
            'region_id' => $regionId,
            'zone_id' => $zoneId,
            'zone_region_id' => $zoneRegionId,
            'carrier_id' => $carrierId,
            'template_id' => $templateId,
            'service_id' => $serviceId,
            'payment_snapshot_token' => $snapshotToken,
        ];
    } catch (Throwable $throwable) {
        try {
            r43_store_cleanup($owned, '', '');
        } catch (Throwable) {
            try {
                r43_store_restore_payment($snapshotToken);
            } catch (Throwable) {
            }
        }
        throw $throwable;
    }
}

/** @return array{session:?CheckoutSession,payload:array<string,mixed>} */
function r43_store_find_session(string $groupUuid, int $offerId, string $preparedAt = ''): array
{
    if ($groupUuid === '' && $preparedAt === '') {
        return ['session' => null, 'payload' => []];
    }
    $query = r43_store_model(CheckoutSession::class)->clear();
    if ($preparedAt !== '') {
        $query->where(CheckoutSession::schema_fields_CREATED_AT, $preparedAt, '>=');
    }
    $sessions = $query->select()->fetch();
    foreach ($sessions->getItems() as $session) {
        if (!$session instanceof CheckoutSession) {
            continue;
        }
        $payload = json_decode((string)$session->getData(CheckoutSession::schema_fields_PAYLOAD_JSON), true);
        $payload = is_array($payload) ? $payload : [];
        $submitted = is_array($payload['submitted_result'] ?? null) ? $payload['submitted_result'] : [];
        if ($groupUuid !== '' && (string)($submitted['checkout_group_uuid'] ?? '') !== $groupUuid) {
            continue;
        }
        foreach ((array)($payload['orders'] ?? []) as $order) {
            foreach ((array)(is_array($order) ? ($order['items'] ?? []) : []) as $item) {
                if (is_array($item) && (int)($item['offer_id'] ?? 0) === $offerId) {
                    return ['session' => $session, 'payload' => $payload];
                }
            }
        }
    }

    return ['session' => null, 'payload' => []];
}

/** @param array<string,mixed> $fixture @return array<string,mixed> */
function r43_store_inspect(array $fixture, string $groupUuid, string $orderUuid): array
{
    $websiteId = (int)($fixture['website_id'] ?? 0);
    $offerId = (int)($fixture['offer_id'] ?? 0);
    $productRows = r43_store_rows(
        r43_store_shard_model(Product::class, $websiteId),
        [Product::schema_fields_SKU => (string)($fixture['sku'] ?? '')],
    );
    $offerRows = r43_store_rows(
        r43_store_shard_model(Offer::class, $websiteId),
        [Offer::schema_fields_GLOBAL_OFFER_UUID => (string)($fixture['offer_uuid'] ?? '')],
    );
    $groups = r43_store_rows(
        r43_store_model(CheckoutGroup::class),
        [CheckoutGroup::schema_fields_CHECKOUT_GROUP_UUID => $groupUuid],
    );
    $orders = r43_store_rows(
        r43_store_model(Order::class),
        [Order::schema_fields_ORDER_UUID => $orderUuid],
    );
    $items = r43_store_rows(
        r43_store_model(OrderItem::class),
        [OrderItem::schema_fields_ORDER_UUID => $orderUuid],
    );
    $sessionResult = r43_store_find_session(
        $groupUuid,
        $offerId,
        trim((string)($fixture['prepared_at'] ?? '')),
    );
    $session = $sessionResult['session'];
    $ownedItems = array_values(array_filter(
        $items,
        static fn(array $row): bool => (int)($row[OrderItem::schema_fields_OFFER_ID] ?? 0) === $offerId,
    ));
    $order = $orders[0] ?? [];
    $ok = count($productRows) === 1
        && (string)($productRows[0][Product::schema_fields_STATUS] ?? '') === Product::STATUS_PUBLISHED
        && count($offerRows) === 1
        && (string)($offerRows[0][Offer::schema_fields_STATUS] ?? '') === 'published'
        && count($groups) === 1
        && count($orders) === 1
        && (string)($order[Order::schema_fields_CHECKOUT_GROUP_UUID] ?? '') === $groupUuid
        && count($ownedItems) === 1
        && $session instanceof CheckoutSession
        && (string)$session->getData(CheckoutSession::schema_fields_STATE) === CheckoutSession::STATE_SUBMITTED;

    return [
        'ok' => $ok,
        'product_count' => count($productRows),
        'product_status' => $productRows[0][Product::schema_fields_STATUS] ?? null,
        'offer_count' => count($offerRows),
        'offer_status' => $offerRows[0][Offer::schema_fields_STATUS] ?? null,
        'checkout_group_count' => count($groups),
        'order_count' => count($orders),
        'order_item_count' => count($ownedItems),
        'order_number' => $order[Order::schema_fields_ORDER_NUMBER] ?? null,
        'order_status' => $order[Order::schema_fields_STATUS] ?? null,
        'order_grand_total' => $order[Order::schema_fields_GRAND_TOTAL] ?? null,
        'shipping_method' => $order[Order::schema_fields_SHIPPING_METHOD] ?? null,
        'session_found' => $session instanceof CheckoutSession,
        'session_state' => $session instanceof CheckoutSession
            ? $session->getData(CheckoutSession::schema_fields_STATE)
            : null,
        'quote_token' => $session instanceof CheckoutSession
            ? $session->getData(CheckoutSession::schema_fields_QUOTE_TOKEN)
            : null,
    ];
}

/** @param array<string,mixed> $fixture @return array<string,mixed> */
function r43_store_cleanup(array $fixture, string $groupUuid, string $orderUuid): array
{
    $run = (string)($fixture['run'] ?? '');
    $sku = (string)($fixture['sku'] ?? '');
    if (!str_starts_with($run, 'r43store_') || !str_starts_with($sku, 'R43-STORE-')) {
        throw new RuntimeException('refusing_cleanup_outside_r43_store_namespace');
    }
    $websiteId = (int)($fixture['website_id'] ?? 0);
    $offerId = (int)($fixture['offer_id'] ?? 0);
    $productId = (int)($fixture['product_id'] ?? 0);
    $sessionResult = r43_store_find_session(
        $groupUuid,
        $offerId,
        trim((string)($fixture['prepared_at'] ?? '')),
    );
    $session = $sessionResult['session'];
    $payload = $sessionResult['payload'];
    $submitted = is_array($payload['submitted_result'] ?? null) ? $payload['submitted_result'] : [];
    if ($groupUuid === '') {
        $groupUuid = trim((string)($submitted['checkout_group_uuid'] ?? ''));
    }
    if ($orderUuid === '') {
        $orderUuids = array_values(array_map('strval', (array)($submitted['order_uuids'] ?? [])));
        $orderUuid = trim((string)($orderUuids[0] ?? ''));
    }
    foreach ((array)($payload['reservations'] ?? []) as $reservation) {
        $reservationUuid = is_array($reservation) ? trim((string)($reservation['reservation_uuid'] ?? '')) : '';
        if ($reservationUuid !== '') {
            r43_store_delete(r43_store_model(InventoryLedger::class), [InventoryLedger::schema_fields_RESERVATION_UUID => $reservationUuid]);
            r43_store_delete(r43_store_model(Reservation::class), [Reservation::schema_fields_RESERVATION_UUID => $reservationUuid]);
        }
    }
    if ($session instanceof CheckoutSession) {
        r43_store_delete(
            r43_store_model(CheckoutSession::class),
            [CheckoutSession::schema_fields_ID => (int)$session->getId()],
        );
    }

    $orderUuids = array_values(array_filter(array_unique(array_merge(
        $orderUuid !== '' ? [$orderUuid] : [],
        array_map('strval', (array)($submitted['order_uuids'] ?? [])),
        array_map(
            static fn(array $row): string => (string)($row[Order::schema_fields_ORDER_UUID] ?? ''),
            $groupUuid === '' ? [] : r43_store_rows(
                r43_store_model(Order::class),
                [Order::schema_fields_CHECKOUT_GROUP_UUID => $groupUuid],
            ),
        ),
    )), static fn(string $uuid): bool => $uuid !== ''));
    foreach ($orderUuids as $ownedOrderUuid) {
        r43_store_delete(r43_store_model(OrderItem::class), [OrderItem::schema_fields_ORDER_UUID => $ownedOrderUuid]);
        r43_store_delete(r43_store_model(FulfillmentUnit::class), [FulfillmentUnit::schema_fields_ORDER_UUID => $ownedOrderUuid]);
        r43_store_delete(r43_store_model(DisplayNumberRegistry::class), [DisplayNumberRegistry::schema_fields_ENTITY_UUID => $ownedOrderUuid]);
        r43_store_delete(r43_store_model(Order::class), [Order::schema_fields_ORDER_UUID => $ownedOrderUuid]);
    }
    if ($groupUuid !== '') {
        r43_store_delete(r43_store_model(CheckoutGroup::class), [CheckoutGroup::schema_fields_CHECKOUT_GROUP_UUID => $groupUuid]);
    }

    if ($offerId > 0) {
        r43_store_delete(r43_store_model(InventoryLedger::class), [InventoryLedger::schema_fields_OFFER_ID => $offerId]);
        r43_store_delete(r43_store_model(Reservation::class), [Reservation::schema_fields_OFFER_ID => $offerId]);
        r43_store_delete(r43_store_model(InventoryStock::class), [InventoryStock::schema_fields_OFFER_ID => $offerId]);
        r43_store_delete(r43_store_shard_model(StoreOffer::class, $websiteId), [StoreOffer::schema_fields_OFFER_ID => $offerId]);
        r43_store_delete(r43_store_shard_model(Price::class, $websiteId), [Price::schema_fields_OFFER_ID => $offerId]);
    }
    if ($productId > 0) {
        r43_store_delete(r43_store_shard_model(AttributeValue::class, $websiteId), [
            AttributeValue::schema_fields_ENTITY_TYPE => 'product',
            AttributeValue::schema_fields_ENTITY_ID => $productId,
        ]);
        r43_store_delete(r43_store_shard_model(Offer::class, $websiteId), [Offer::schema_fields_PRODUCT_ID => $productId]);
        r43_store_delete(r43_store_shard_model(Product::class, $websiteId), [Product::schema_fields_ID => $productId]);
    }

    foreach ([
        [ShippingService::class, ShippingService::schema_fields_ID, 'service_id'],
        [ZoneRegion::class, ZoneRegion::schema_fields_ID, 'zone_region_id'],
        [Carrier::class, Carrier::schema_fields_ID, 'carrier_id'],
        [RateTemplate::class, RateTemplate::schema_fields_ID, 'template_id'],
        [Zone::class, Zone::schema_fields_ID, 'zone_id'],
        [Region::class, Region::schema_fields_ID, 'region_id'],
    ] as [$class, $field, $fixtureKey]) {
        $id = (int)($fixture[$fixtureKey] ?? 0);
        if ($id > 0) {
            r43_store_delete(r43_store_model($class), [$field => $id]);
        }
    }

    $paymentCleanup = r43_store_restore_payment((string)($fixture['payment_snapshot_token'] ?? ''));

    $remaining = [
        'products' => count(r43_store_rows(r43_store_shard_model(Product::class, $websiteId), [Product::schema_fields_SKU => $sku])),
        'offers' => count(r43_store_rows(r43_store_shard_model(Offer::class, $websiteId), [Offer::schema_fields_GLOBAL_OFFER_UUID => (string)($fixture['offer_uuid'] ?? '')])),
        'attribute_values' => $productId <= 0 ? 0 : count(r43_store_rows(r43_store_shard_model(AttributeValue::class, $websiteId), [
            AttributeValue::schema_fields_ENTITY_TYPE => 'product',
            AttributeValue::schema_fields_ENTITY_ID => $productId,
        ])),
        'prices' => $offerId <= 0 ? 0 : count(r43_store_rows(r43_store_shard_model(Price::class, $websiteId), [Price::schema_fields_OFFER_ID => $offerId])),
        'store_offers' => $offerId <= 0 ? 0 : count(r43_store_rows(r43_store_shard_model(StoreOffer::class, $websiteId), [StoreOffer::schema_fields_OFFER_ID => $offerId])),
        'inventory_stock' => $offerId <= 0 ? 0 : count(r43_store_rows(r43_store_model(InventoryStock::class), [InventoryStock::schema_fields_OFFER_ID => $offerId])),
        'inventory_ledger' => $offerId <= 0 ? 0 : count(r43_store_rows(r43_store_model(InventoryLedger::class), [InventoryLedger::schema_fields_OFFER_ID => $offerId])),
        'reservations' => $offerId <= 0 ? 0 : count(r43_store_rows(r43_store_model(Reservation::class), [Reservation::schema_fields_OFFER_ID => $offerId])),
        'checkout_sessions' => count(array_filter(
            [$session],
            static fn(mixed $row): bool => $row instanceof CheckoutSession
                && count(r43_store_rows(r43_store_model(CheckoutSession::class), [
                    CheckoutSession::schema_fields_ID => (int)$row->getId(),
                ])) > 0,
        )),
        'orders' => array_sum(array_map(
            static fn(string $uuid): int => count(r43_store_rows(r43_store_model(Order::class), [Order::schema_fields_ORDER_UUID => $uuid])),
            $orderUuids,
        )),
        'order_items' => array_sum(array_map(
            static fn(string $uuid): int => count(r43_store_rows(r43_store_model(OrderItem::class), [OrderItem::schema_fields_ORDER_UUID => $uuid])),
            $orderUuids,
        )),
        'fulfillment_units' => array_sum(array_map(
            static fn(string $uuid): int => count(r43_store_rows(r43_store_model(FulfillmentUnit::class), [FulfillmentUnit::schema_fields_ORDER_UUID => $uuid])),
            $orderUuids,
        )),
        'display_numbers' => array_sum(array_map(
            static fn(string $uuid): int => count(r43_store_rows(r43_store_model(DisplayNumberRegistry::class), [DisplayNumberRegistry::schema_fields_ENTITY_UUID => $uuid])),
            $orderUuids,
        )),
        'groups' => $groupUuid === '' ? 0 : count(r43_store_rows(r43_store_model(CheckoutGroup::class), [CheckoutGroup::schema_fields_CHECKOUT_GROUP_UUID => $groupUuid])),
        'shipping_services' => count(r43_store_rows(r43_store_model(ShippingService::class), [ShippingService::schema_fields_SERVICE_CODE => $run])),
        'shipping_carriers' => count(r43_store_rows(r43_store_model(Carrier::class), [Carrier::schema_fields_CARRIER_CODE => $run])),
        'shipping_templates' => count(r43_store_rows(r43_store_model(RateTemplate::class), [RateTemplate::schema_fields_TEMPLATE_CODE => $run])),
        'shipping_zones' => count(r43_store_rows(r43_store_model(Zone::class), [Zone::schema_fields_ZONE_CODE => $run])),
        'shipping_regions' => count(r43_store_rows(r43_store_model(Region::class), [Region::schema_fields_REGION_CODE => $run])),
    ];
    if (array_sum($remaining) !== 0) {
        throw new RuntimeException('r43_store_cleanup_left_rows:' . json_encode($remaining));
    }

    return ['remaining' => $remaining, 'payment_preimage' => $paymentCleanup];
}

try {
    $input = r43_store_input();
    $guard = r43_store_isolation_guard();
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $fixture = is_array($input['fixture'] ?? null) ? $input['fixture'] : [];
    if ($action === 'prepare') {
        r43_store_output(['ok' => true] + $guard + ['fixture' => r43_store_prepare()]);
    }
    if ($action === 'inspect') {
        $result = r43_store_inspect(
            $fixture,
            trim((string)($input['checkout_group_uuid'] ?? '')),
            trim((string)($input['order_uuid'] ?? '')),
        );
        r43_store_output(['ok' => (bool)$result['ok']] + $guard + ['data' => $result], $result['ok'] ? 0 : 1);
    }
    if ($action === 'cleanup') {
        r43_store_output(['ok' => true] + $guard + ['data' => r43_store_cleanup(
            $fixture,
            trim((string)($input['checkout_group_uuid'] ?? '')),
            trim((string)($input['order_uuid'] ?? '')),
        )]);
    }
    throw new InvalidArgumentException('unknown_fixture_action:' . $action);
} catch (Throwable $throwable) {
    r43_store_output([
        'ok' => false,
        'error' => $throwable->getMessage(),
        'type' => $throwable::class,
    ], 1);
}
