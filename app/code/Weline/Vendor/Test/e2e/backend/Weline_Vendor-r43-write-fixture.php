<?php

declare(strict_types=1);

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\SkuAlias;
use Weline\Product\Model\SkuRegistry;
use Weline\Product\Service\SkuRegistryService;
use Weline\Test\E2E\Framework\IsolatedSystemConfigPreimage;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;
use Weline\Vendor\Model\VendorIdentity;
use Weline\Vendor\Model\VendorPayoutRecord;
use Weline\Vendor\Model\VendorProductBindingRecord;
use Weline\Vendor\Model\VendorRecord;
use Weline\Vendor\Model\VendorRefundReversalRecord;
use Weline\Vendor\Model\VendorSplitRuleRecord;
use Weline\Vendor\Model\VendorSplitSnapshotRecord;
use Weline\Vendor\Model\VendorStoreAccountBindingRecord;
use Weline\Vendor\Model\VendorWebsiteAuthorizationRecord;
use Weline\Vendor\Service\VendorRolloutGate;
use Weline\Vendor\Service\VendorService;
use Weline\Vendor\Service\VendorSettlementService;
use Weline\Websites\Model\Store;

require dirname(__DIR__, 7) . '/app/bootstrap.php';
require_once BP . 'tests/e2e/framework/isolated-system-config-preimage.php';

/** @return array<string,mixed> */
function r43_vendor_input(): array
{
    $decoded = json_decode((string)file_get_contents('php://stdin'), true);
    if (!is_array($decoded) || array_is_list($decoded)) throw new InvalidArgumentException('stdin_must_be_json_object');
    return $decoded;
}

/** @param array<string,mixed> $payload */
function r43_vendor_output(array $payload, int $code = 0): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit($code);
}

/** @template T of object @param class-string<T> $class @return T */
function r43_vendor_model(string $class): object
{
    return ObjectManager::getInstance($class, [], false);
}

/** @return array{connector:string,database:string} */
function r43_vendor_assert_isolated_pgsql(): array
{
    if (getenv('WELINE_E2E_ISOLATED_DB') !== '1') throw new RuntimeException('r43_vendor_requires_isolated_database_opt_in');
    /** @var VendorRecord $model */
    $model = r43_vendor_model(VendorRecord::class);
    $connectorObject = $model->getConnection()->getConnector();
    $connector = get_class($connectorObject);
    if (!str_contains(strtolower($connector), 'pgsql') && !str_contains(strtolower($connector), 'postgres')) throw new RuntimeException('r43_vendor_requires_postgresql:' . $connector);
    $database = (string)$connectorObject->getConfigProvider()->getDatabase();
    if (!str_starts_with($database, 'mig_clone_')) throw new RuntimeException('r43_vendor_requires_migration_clone:' . $database);
    return ['connector' => $connector, 'database' => $database];
}

function r43_vendor_token(mixed $value): string
{
    $token = strtoupper(trim((string)$value));
    if (preg_match('/^[A-F0-9]{12}$/D', $token) !== 1) throw new InvalidArgumentException('invalid_r43_vendor_token');
    return $token;
}

function r43_vendor_code(string $token): string
{
    return 'r43_vendor_' . strtolower($token);
}

/** @return array{mode:string,subjects:list<string>,audit_before:array<string,mixed>} */
function r43_vendor_rollout_snapshot(VendorRolloutGate $gate): array
{
    $configuration = $gate->configuration();
    return [
        'mode' => (string)$configuration['mode'],
        'subjects' => array_values(array_keys((array)$configuration['allowlist'])),
        'audit_before' => IsolatedSystemConfigPreimage::capture(
            'Weline_Vendor',
            'frontend',
            [VendorRolloutGate::CONFIG_MODE, VendorRolloutGate::CONFIG_ALLOWLIST],
        ),
    ];
}

/** @param array<string,mixed> $snapshot @return array<string,mixed> */
function r43_vendor_restore_rollout(VendorRolloutGate $gate, array $snapshot): array
{
    $mode = (string)($snapshot['mode'] ?? '');
    $subjects = array_values(array_unique(array_map('strval', (array)($snapshot['subjects'] ?? []))));
    sort($subjects, SORT_STRING);
    if (!in_array($mode, CommerceRolloutGateInterface::MODES, true)) {
        throw new RuntimeException('r43_vendor_rollout_snapshot_mode_invalid');
    }
    $gate->setMode(
        VendorService::CAPABILITY,
        $mode,
        $subjects,
        $mode === CommerceRolloutGateInterface::MODE_ON ? 'r43-e2e-official-restore' : '',
    );
    $configuration = $gate->configuration();
    $restoredSubjects = array_values(array_keys((array)($configuration['allowlist'] ?? [])));
    sort($restoredSubjects, SORT_STRING);
    if ((string)($configuration['mode'] ?? '') !== $mode || $restoredSubjects !== $subjects) {
        throw new RuntimeException('r43_vendor_rollout_business_restore_mismatch');
    }
    $audit = IsolatedSystemConfigPreimage::assertMonotonicAfterOfficialRestore(
        (array)($snapshot['audit_before'] ?? []),
        'Weline_Vendor',
        'frontend',
        [VendorRolloutGate::CONFIG_MODE, VendorRolloutGate::CONFIG_ALLOWLIST],
    );
    return [
        'business_state_restored' => true,
        'mode' => $mode,
        'subjects' => $subjects,
        'logical_hash' => hash('sha256', json_encode([$mode, $subjects], JSON_THROW_ON_ERROR)),
        'audit' => $audit,
    ];
}

/** @return array{store_id:int,website_id:int,store_mode:string,environment:string} */
function r43_vendor_store(): array
{
    /** @var Store $store */
    $store = r43_vendor_model(Store::class);
    $rows = $store->where(Store::schema_fields_STATUS, 1)
        ->where(Store::schema_fields_LIFECYCLE_STATUS, Store::LIFECYCLE_ACTIVE)
        ->select()->fetchArray();
    if (!is_array($rows) || $rows === []) throw new RuntimeException('r43_vendor_active_store_unavailable');
    usort($rows, static function (array $left, array $right): int {
        $score = static fn(array $row): int => in_array((string)($row[Store::schema_fields_STORE_MODE] ?? ''), [Store::MODE_TEST, Store::MODE_DEV], true) ? 0 : 1;
        return $score($left) <=> $score($right);
    });
    $row = $rows[0];
    $mode = (string)$row[Store::schema_fields_STORE_MODE];
    return [
        'store_id' => (int)$row[Store::schema_fields_ID],
        'website_id' => (int)$row[Store::schema_fields_WEBSITE_ID],
        'store_mode' => $mode,
        'environment' => in_array($mode, [Store::MODE_TEST, Store::MODE_DEV], true) ? VendorIdentity::ENV_SANDBOX : VendorIdentity::ENV_LIVE,
    ];
}

/** @return array{sku:string,registry_id:int} */
function r43_vendor_product(string $token): array
{
    $sku = 'R43-VENDOR-' . $token;
    /** @var SkuRegistryService $registry */
    $registry = ObjectManager::getInstance(SkuRegistryService::class);
    $identity = $registry->claimLocked($sku, hash('sha256', 'r43-vendor-product|' . $token));
    return ['sku' => $identity->sku, 'registry_id' => $identity->registryId];
}

/** @return list<array<string,mixed>> */
function r43_vendor_rows(string $token): array
{
    /** @var VendorRecord $model */
    $model = r43_vendor_model(VendorRecord::class);
    $rows = $model->where(VendorRecord::schema_fields_CODE, r43_vendor_code($token))->select()->fetchArray();
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

function r43_vendor_cleanup(string $token): void
{
    foreach (r43_vendor_rows($token) as $row) {
        $vendorId = (string)$row[VendorRecord::schema_fields_VENDOR_ID];
        foreach ([
            VendorRefundReversalRecord::class,
            VendorPayoutRecord::class,
            VendorSplitSnapshotRecord::class,
            VendorProductBindingRecord::class,
            VendorSplitRuleRecord::class,
            VendorStoreAccountBindingRecord::class,
            VendorWebsiteAuthorizationRecord::class,
        ] as $class) {
            $model = r43_vendor_model($class);
            $model->where($class::schema_fields_VENDOR_ID, $vendorId)->delete()->fetch();
        }
        /** @var VendorRecord $vendor */
        $vendor = r43_vendor_model(VendorRecord::class);
        $vendor->where(VendorRecord::schema_fields_VENDOR_ID, $vendorId)->delete()->fetch();
    }
    $sku = 'R43-VENDOR-' . $token;
    /** @var SkuRegistry $registry */
    $registry = r43_vendor_model(SkuRegistry::class);
    $registry->where(SkuRegistry::schema_fields_SKU, $sku)->find()->fetch();
    if ($registry->getId()) {
        /** @var SkuAlias $aliases */
        $aliases = r43_vendor_model(SkuAlias::class);
        $aliases->where(SkuAlias::schema_fields_REGISTRY_ID, (int)$registry->getId())->delete()->fetch();
        $registry->delete()->fetch();
    }
}

function r43_vendor_exists(string $class, string $field, string|int $value): bool
{
    $model = r43_vendor_model($class);
    $model->where($field, $value)->find()->fetch();
    return (bool)$model->getId();
}

/** @return array{vendor_id:string,environment:string,website_id:int,store_id?:int,store_mode?:string} */
function r43_vendor_prepare_identity(string $token, bool $needsStore, bool $authorize = true): array
{
    $scope = $needsStore ? r43_vendor_store() : ['website_id' => 0, 'environment' => VendorIdentity::ENV_SANDBOX];
    /** @var VendorRolloutGate $gate */
    $gate = ObjectManager::getInstance(VendorRolloutGate::class);
    $gate->setMode(VendorService::CAPABILITY, CommerceRolloutGateInterface::MODE_ON, [], 'r43-isolated-clone');
    $runtime = VendorSettlementService::forRuntime($gate);
    $vendor = $runtime->vendors()->registerVendor([
        'code' => r43_vendor_code($token),
        'legal_name' => 'R43 Vendor ' . $token,
        'environment' => $scope['environment'],
    ]);
    $vendorId = (string)$vendor['vendor_id'];
    $websiteId = (int)$scope['website_id'];
    if ($authorize) {
        $runtime->vendors()->authorizeWebsite($vendorId, $websiteId);
    }
    if ($needsStore) {
        $runtime->vendors()->bindAccount([
            'vendor_id' => $vendorId,
            'website_id' => $websiteId,
            'store_id' => (int)$scope['store_id'],
            'environment' => (string)$scope['environment'],
            'account_ref' => $scope['environment'] . ':r43-' . strtolower($token),
        ]);
    }
    return ['vendor_id' => $vendorId] + $scope;
}

$activeToken = null;
$activeRolloutBefore = null;
try {
    $input = r43_vendor_input();
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $isolation = r43_vendor_assert_isolated_pgsql();
    $base = ['connector' => $isolation['connector'], 'database' => $isolation['database']];
    /** @var VendorRolloutGate $gate */
    $gate = ObjectManager::getInstance(VendorRolloutGate::class);

    if (str_starts_with($action, 'prepare_')) {
        $kind = substr($action, 8);
        if (!in_array($kind, ['vendor', 'authorization', 'product', 'split', 'payout'], true)) throw new InvalidArgumentException('unknown_vendor_prepare:' . $kind);
        $token = strtoupper(bin2hex(random_bytes(6)));
        $activeToken = $token;
        r43_vendor_cleanup($token);
        $before = r43_vendor_rollout_snapshot($gate);
        $activeRolloutBefore = $before;
        $payload = ['ok' => true, ...$base, 'token' => $token, 'rollout_before' => $before, 'code' => r43_vendor_code($token), 'legal_name' => 'R43 Vendor ' . $token];
        if ($kind === 'vendor') {
            $gate->setMode(VendorService::CAPABILITY, CommerceRolloutGateInterface::MODE_ON, [], 'r43-isolated-clone');
            r43_vendor_output($payload + ['environment' => VendorIdentity::ENV_SANDBOX]);
        }
        $scope = r43_vendor_prepare_identity(
            $token,
            in_array($kind, ['product', 'payout'], true),
            $kind !== 'authorization',
        );
        $payload += $scope;
        if ($kind === 'product') {
            r43_vendor_output($payload + r43_vendor_product($token));
        }
        if ($kind === 'split') {
            r43_vendor_output($payload + ['commission_bps' => 743, 'currency' => 'CNY', 'legal_entity' => 'R43 Legal ' . $token]);
        }
        if ($kind === 'payout') {
            $runtime = VendorSettlementService::forRuntime($gate);
            $runtime->upsertRule(['vendor_id' => $scope['vendor_id'], 'website_id' => $scope['website_id'], 'commission_bps' => 900, 'currency' => 'CNY', 'legal_entity' => 'R43 Payout ' . $token]);
            $snapshot = $runtime->captureSnapshot([
                'vendor_id' => $scope['vendor_id'],
                'website_id' => $scope['website_id'],
                'store_id' => $scope['store_id'],
                'checkout_group_ref' => 'r43-group-' . strtolower($token),
                'order_ref' => 'r43-order-' . strtolower($token),
                'payment_ref' => 'r43-payment-' . strtolower($token),
                'gross_minor' => 10000,
                'currency' => 'CNY',
                'required_environment' => $scope['environment'],
            ]);
            r43_vendor_output($payload + ['snapshot_id' => $snapshot['snapshot_id'], 'idempotency_key' => 'r43-payout-' . strtolower($token), 'expected_amount_minor' => $snapshot['vendor_share_minor']]);
        }
        r43_vendor_output($payload);
    }

    if (str_starts_with($action, 'inspect_')) {
        $kind = substr($action, 8);
        $token = r43_vendor_token($input['token'] ?? '');
        $rows = r43_vendor_rows($token);
        $vendor = $rows[0] ?? null;
        $vendorId = (string)($vendor[VendorRecord::schema_fields_VENDOR_ID] ?? '');
        $row = null;
        if ($kind === 'vendor') $row = $vendor;
        if ($kind === 'authorization') {
            $model = r43_vendor_model(VendorWebsiteAuthorizationRecord::class);
            $model->where(VendorWebsiteAuthorizationRecord::schema_fields_VENDOR_ID, $vendorId)->where(VendorWebsiteAuthorizationRecord::schema_fields_WEBSITE_ID, (int)($input['website_id'] ?? 0))->find()->fetch();
            $row = $model->getId() ? $model->getData() : null;
        }
        if ($kind === 'product') {
            $model = r43_vendor_model(VendorProductBindingRecord::class);
            $model->where(VendorProductBindingRecord::schema_fields_VENDOR_ID, $vendorId)->where(VendorProductBindingRecord::schema_fields_PRODUCT_SKU, (string)($input['sku'] ?? ''))->find()->fetch();
            $row = $model->getId() ? $model->getData() : null;
        }
        if ($kind === 'split') {
            $model = r43_vendor_model(VendorSplitRuleRecord::class);
            $model->where(VendorSplitRuleRecord::schema_fields_VENDOR_ID, $vendorId)->find()->fetch();
            $row = $model->getId() ? $model->getData() : null;
        }
        if ($kind === 'payout') {
            $model = r43_vendor_model(VendorPayoutRecord::class);
            $model->where(VendorPayoutRecord::schema_fields_SNAPSHOT_ID, (string)($input['snapshot_id'] ?? ''))->find()->fetch();
            $row = $model->getId() ? $model->getData() : null;
        }
        $ok = is_array($row);
        if ($kind === 'vendor') $ok = $ok && (string)$row[VendorRecord::schema_fields_LEGAL_NAME] === 'R43 Vendor ' . $token;
        if ($kind === 'authorization') $ok = $ok && (string)$row[VendorWebsiteAuthorizationRecord::schema_fields_STATUS] === 'authorized';
        if ($kind === 'product') $ok = $ok && (string)$row[VendorProductBindingRecord::schema_fields_STATUS] === 'bound';
        if ($kind === 'split') $ok = $ok && (int)$row[VendorSplitRuleRecord::schema_fields_COMMISSION_BPS] === 743;
        if ($kind === 'payout') $ok = $ok && (string)$row[VendorPayoutRecord::schema_fields_IDEMPOTENCY_KEY] === (string)($input['idempotency_key'] ?? '');
        r43_vendor_output(['ok' => $ok, ...$base, 'vendor_id' => $vendorId, 'row' => $row], $ok ? 0 : 1);
    }

    if ($action === 'cleanup') {
        $token = r43_vendor_token($input['token'] ?? '');
        $vendorIds = array_values(array_map(
            static fn(array $row): string => (string)$row[VendorRecord::schema_fields_VENDOR_ID],
            r43_vendor_rows($token),
        ));
        $cleanupErrors = [];
        try {
            r43_vendor_cleanup($token);
        } catch (Throwable $throwable) {
            $cleanupErrors[] = 'business:' . $throwable->getMessage();
        }
        $rolloutRestore = null;
        try {
            $rolloutRestore = r43_vendor_restore_rollout($gate, (array)($input['rollout_before'] ?? []));
        } catch (Throwable $throwable) {
            $cleanupErrors[] = 'rollout:' . $throwable->getMessage();
        }
        if ($cleanupErrors !== []) {
            throw new RuntimeException('r43_vendor_cleanup_failed:' . implode('|', $cleanupErrors));
        }
        $cleaned = r43_vendor_rows($token) === [];
        foreach ($vendorIds as $vendorId) {
            foreach ([
                VendorRefundReversalRecord::class,
                VendorPayoutRecord::class,
                VendorSplitSnapshotRecord::class,
                VendorProductBindingRecord::class,
                VendorSplitRuleRecord::class,
                VendorStoreAccountBindingRecord::class,
                VendorWebsiteAuthorizationRecord::class,
            ] as $class) {
                $cleaned = $cleaned && !r43_vendor_exists($class, $class::schema_fields_VENDOR_ID, $vendorId);
            }
        }
        $cleaned = $cleaned && !r43_vendor_exists(SkuRegistry::class, SkuRegistry::schema_fields_SKU, 'R43-VENDOR-' . $token);
        r43_vendor_output(['ok' => $cleaned, ...$base, 'cleaned' => $cleaned, 'vendor_ids' => $vendorIds, 'rollout_restore' => $rolloutRestore], $cleaned ? 0 : 1);
    }
    throw new InvalidArgumentException('unknown_action:' . $action);
} catch (Throwable $throwable) {
    if (is_string($activeToken) && $activeToken !== '') {
        try { r43_vendor_cleanup($activeToken); } catch (Throwable) {}
        try {
            if (is_array($activeRolloutBefore)) {
                /** @var VendorRolloutGate $recoveryGate */
                $recoveryGate = ObjectManager::getInstance(VendorRolloutGate::class);
                r43_vendor_restore_rollout($recoveryGate, $activeRolloutBefore);
            }
        } catch (Throwable) {}
    }
    r43_vendor_output(['ok' => false, 'error' => $throwable->getMessage()], 1);
}
