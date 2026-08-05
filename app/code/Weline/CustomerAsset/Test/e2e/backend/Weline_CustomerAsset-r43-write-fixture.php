<?php

declare(strict_types=1);

use Weline\CustomerAsset\Model\AssetAccount;
use Weline\CustomerAsset\Model\AssetLedger;
use Weline\CustomerAsset\Model\AssetReservation;
use Weline\CustomerAsset\Service\CustomerAssetRolloutGate;
use Weline\CustomerAsset\Service\CustomerAssetService;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;
use Weline\Test\E2E\Framework\IsolatedSystemConfigPreimage;

require dirname(__DIR__, 7) . '/app/bootstrap.php';
require_once BP . 'tests/e2e/framework/isolated-system-config-preimage.php';

/** @return array<string,mixed> */
function r43_asset_input(): array
{
    $decoded = json_decode((string)file_get_contents('php://stdin'), true);
    if (!is_array($decoded) || array_is_list($decoded)) throw new InvalidArgumentException('stdin_must_be_json_object');
    return $decoded;
}

/** @param array<string,mixed> $payload */
function r43_asset_output(array $payload, int $code = 0): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit($code);
}

/** @template T of object @param class-string<T> $class @return T */
function r43_asset_model(string $class): object
{
    return ObjectManager::getInstance($class, [], false);
}

/** @return array{connector:string,database:string} */
function r43_asset_assert_isolated_pgsql(): array
{
    if (getenv('WELINE_E2E_ISOLATED_DB') !== '1') throw new RuntimeException('r43_asset_requires_isolated_database_opt_in');
    /** @var AssetAccount $model */
    $model = r43_asset_model(AssetAccount::class);
    $connectorObject = $model->getConnection()->getConnector();
    $connector = get_class($connectorObject);
    if (!str_contains(strtolower($connector), 'pgsql') && !str_contains(strtolower($connector), 'postgres')) throw new RuntimeException('r43_asset_requires_postgresql:' . $connector);
    $database = (string)$connectorObject->getConfigProvider()->getDatabase();
    if (!str_starts_with($database, 'mig_clone_')) throw new RuntimeException('r43_asset_requires_migration_clone:' . $database);
    return ['connector' => $connector, 'database' => $database];
}

function r43_asset_token(mixed $value): string
{
    $token = strtoupper(trim((string)$value));
    if (preg_match('/^[A-F0-9]{12}$/D', $token) !== 1) throw new InvalidArgumentException('invalid_r43_asset_token');
    return $token;
}

function r43_asset_customer_id(string $token): string { return 'r43-asset-' . strtolower($token); }
function r43_asset_event(string $token, string $kind): string { return 'r43-asset-' . strtolower($token) . ':' . $kind; }

/** @return array{mode:string,subjects:list<string>,audit_before:array<string,mixed>} */
function r43_asset_rollout_snapshot(CustomerAssetRolloutGate $gate): array
{
    $configuration = $gate->configuration();
    return [
        'mode' => (string)$configuration['mode'],
        'subjects' => array_values(array_keys((array)$configuration['allowlist'])),
        'audit_before' => IsolatedSystemConfigPreimage::capture(
            'Weline_CustomerAsset',
            'frontend',
            [CustomerAssetRolloutGate::CONFIG_MODE, CustomerAssetRolloutGate::CONFIG_ALLOWLIST],
        ),
    ];
}

/** @param array<string,mixed> $snapshot @return array<string,mixed> */
function r43_asset_restore_rollout(CustomerAssetRolloutGate $gate, array $snapshot): array
{
    $mode = (string)($snapshot['mode'] ?? '');
    $subjects = array_values(array_unique(array_map('strval', (array)($snapshot['subjects'] ?? []))));
    sort($subjects, SORT_STRING);
    if (!in_array($mode, CommerceRolloutGateInterface::MODES, true)) {
        throw new RuntimeException('r43_customer_asset_rollout_snapshot_mode_invalid');
    }
    $gate->setMode(
        CustomerAssetService::CAPABILITY,
        $mode,
        $subjects,
        $mode === CommerceRolloutGateInterface::MODE_ON ? 'r43-e2e-official-restore' : '',
    );
    $configuration = $gate->configuration();
    $restoredSubjects = array_values(array_keys((array)($configuration['allowlist'] ?? [])));
    sort($restoredSubjects, SORT_STRING);
    if ((string)($configuration['mode'] ?? '') !== $mode || $restoredSubjects !== $subjects) {
        throw new RuntimeException('r43_customer_asset_rollout_business_restore_mismatch');
    }
    $audit = IsolatedSystemConfigPreimage::assertMonotonicAfterOfficialRestore(
        (array)($snapshot['audit_before'] ?? []),
        'Weline_CustomerAsset',
        'frontend',
        [CustomerAssetRolloutGate::CONFIG_MODE, CustomerAssetRolloutGate::CONFIG_ALLOWLIST],
    );
    return [
        'business_state_restored' => true,
        'mode' => $mode,
        'subjects' => $subjects,
        'logical_hash' => hash('sha256', json_encode([$mode, $subjects], JSON_THROW_ON_ERROR)),
        'audit' => $audit,
    ];
}

function r43_asset_cleanup(string $token): void
{
    $customerId = r43_asset_customer_id($token);
    /** @var AssetReservation $reservations */
    $reservations = r43_asset_model(AssetReservation::class);
    $reservations->where(AssetReservation::schema_fields_CUSTOMER_ID, $customerId)->delete()->fetch();
    /** @var AssetLedger $ledger */
    $ledger = r43_asset_model(AssetLedger::class);
    $ledger->where(AssetLedger::schema_fields_CUSTOMER_ID, $customerId)->delete()->fetch();
    /** @var AssetAccount $accounts */
    $accounts = r43_asset_model(AssetAccount::class);
    $accounts->where(AssetAccount::schema_fields_CUSTOMER_ID, $customerId)->delete()->fetch();
}

function r43_asset_exists(string $class, string $field, string|int $value): bool
{
    $model = r43_asset_model($class);
    $model->where($field, $value)->find()->fetch();
    return (bool)$model->getId();
}

/** @return array<string,mixed> */
function r43_asset_identity(string $token): array
{
    return [
        'customer_id' => r43_asset_customer_id($token),
        'website_id' => 0,
        'asset_code' => 'credit',
        'namespace' => AssetAccount::NS_SANDBOX,
    ];
}

/** @return array{service:CustomerAssetService,reservation_id?:string} */
function r43_asset_prepare_prerequisites(string $token, string $kind): array
{
    /** @var CustomerAssetService $service */
    $service = ObjectManager::getInstance(CustomerAssetService::class);
    if ($kind === 'credit') return ['service' => $service];
    $identity = r43_asset_identity($token);
    $service->credit($identity + ['amount_minor' => 5000, 'event_id' => r43_asset_event($token, 'fixture-credit')]);
    if ($kind === 'reserve') return ['service' => $service];
    $reserved = $service->reserve($identity + ['amount_minor' => 1200, 'event_id' => r43_asset_event($token, 'fixture-reserve')]);
    $reservationId = (string)$reserved['reservation']['reservation_id'];
    if ($kind === 'commit') return ['service' => $service, 'reservation_id' => $reservationId];
    $service->commit($reservationId, r43_asset_event($token, 'fixture-commit'));
    return ['service' => $service, 'reservation_id' => $reservationId];
}

$activeToken = null;
$activeRolloutBefore = null;
try {
    $input = r43_asset_input();
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $isolation = r43_asset_assert_isolated_pgsql();
    $base = ['connector' => $isolation['connector'], 'database' => $isolation['database']];
    /** @var CustomerAssetRolloutGate $gate */
    $gate = ObjectManager::getInstance(CustomerAssetRolloutGate::class);

    if (str_starts_with($action, 'prepare_')) {
        $kind = substr($action, 8);
        if (!in_array($kind, ['credit', 'reserve', 'commit', 'return'], true)) throw new InvalidArgumentException('unknown_asset_prepare:' . $kind);
        $token = strtoupper(bin2hex(random_bytes(6)));
        $activeToken = $token;
        r43_asset_cleanup($token);
        $before = r43_asset_rollout_snapshot($gate);
        $activeRolloutBefore = $before;
        $gate->setMode(CustomerAssetService::CAPABILITY, CommerceRolloutGateInterface::MODE_ALLOWLIST, ['website:0']);
        $prepared = r43_asset_prepare_prerequisites($token, $kind);
        $payload = [
            'ok' => true,
            ...$base,
            'token' => $token,
            'rollout_before' => $before,
            ...r43_asset_identity($token),
            'amount_minor' => $kind === 'return' ? 450 : ($kind === 'credit' ? 3200 : 700),
            'event_id' => r43_asset_event($token, 'browser-' . $kind),
        ];
        if (isset($prepared['reservation_id'])) $payload['reservation_id'] = $prepared['reservation_id'];
        r43_asset_output($payload);
    }

    if (str_starts_with($action, 'inspect_')) {
        $kind = substr($action, 8);
        $token = r43_asset_token($input['token'] ?? '');
        $customerId = r43_asset_customer_id($token);
        /** @var AssetAccount $account */
        $account = r43_asset_model(AssetAccount::class);
        $account->where(AssetAccount::schema_fields_CUSTOMER_ID, $customerId)
            ->where(AssetAccount::schema_fields_WEBSITE_ID, 0)
            ->where(AssetAccount::schema_fields_ASSET_CODE, 'credit')
            ->where(AssetAccount::schema_fields_NAMESPACE, AssetAccount::NS_SANDBOX)
            ->find()->fetch();
        /** @var AssetLedger $ledger */
        $ledger = r43_asset_model(AssetLedger::class);
        $ledger->where(AssetLedger::schema_fields_EVENT_ID, r43_asset_event($token, 'browser-' . $kind))->find()->fetch();
        $reservation = null;
        if (in_array($kind, ['reserve', 'commit', 'return'], true)) {
            /** @var AssetReservation $reservationModel */
            $reservationModel = r43_asset_model(AssetReservation::class);
            if ($kind === 'reserve') {
                $reservationModel->where(AssetReservation::schema_fields_CUSTOMER_ID, $customerId)->where(AssetReservation::schema_fields_STATUS, AssetReservation::STATUS_RESERVED)->find()->fetch();
            } else {
                $reservationModel->where(AssetReservation::schema_fields_RESERVATION_ID, (string)($input['reservation_id'] ?? ''))->find()->fetch();
            }
            $reservation = $reservationModel->getId() ? $reservationModel->getData() : null;
        }
        $ok = $account->getId() && $ledger->getId();
        if ($kind === 'credit') $ok = $ok && (int)$account->getData(AssetAccount::schema_fields_AVAILABLE_MINOR) === 3200 && (string)$ledger->getData(AssetLedger::schema_fields_EVENT_TYPE) === AssetLedger::TYPE_CREDIT;
        if ($kind === 'reserve') $ok = $ok && is_array($reservation) && (string)$reservation[AssetReservation::schema_fields_STATUS] === AssetReservation::STATUS_RESERVED && (string)$ledger->getData(AssetLedger::schema_fields_EVENT_TYPE) === AssetLedger::TYPE_RESERVE;
        if ($kind === 'commit') $ok = $ok && is_array($reservation) && (string)$reservation[AssetReservation::schema_fields_STATUS] === AssetReservation::STATUS_COMMITTED && (string)$ledger->getData(AssetLedger::schema_fields_EVENT_TYPE) === AssetLedger::TYPE_COMMIT;
        if ($kind === 'return') $ok = $ok && is_array($reservation) && (int)$reservation[AssetReservation::schema_fields_RETURNED_AMOUNT_MINOR] === 450 && (string)$ledger->getData(AssetLedger::schema_fields_EVENT_TYPE) === AssetLedger::TYPE_RETURN;
        r43_asset_output(['ok' => (bool)$ok, ...$base, 'account' => $account->getData(), 'reservation' => $reservation, 'ledger' => $ledger->getData()], $ok ? 0 : 1);
    }

    if ($action === 'cleanup') {
        $token = r43_asset_token($input['token'] ?? '');
        $cleanupErrors = [];
        try {
            r43_asset_cleanup($token);
        } catch (Throwable $throwable) {
            $cleanupErrors[] = 'business:' . $throwable->getMessage();
        }
        $rolloutRestore = null;
        try {
            $rolloutRestore = r43_asset_restore_rollout($gate, (array)($input['rollout_before'] ?? []));
        } catch (Throwable $throwable) {
            $cleanupErrors[] = 'rollout:' . $throwable->getMessage();
        }
        if ($cleanupErrors !== []) {
            throw new RuntimeException('r43_customer_asset_cleanup_failed:' . implode('|', $cleanupErrors));
        }
        $customerId = r43_asset_customer_id($token);
        $cleaned = !r43_asset_exists(AssetAccount::class, AssetAccount::schema_fields_CUSTOMER_ID, $customerId)
            && !r43_asset_exists(AssetLedger::class, AssetLedger::schema_fields_CUSTOMER_ID, $customerId)
            && !r43_asset_exists(AssetReservation::class, AssetReservation::schema_fields_CUSTOMER_ID, $customerId);
        r43_asset_output(['ok' => $cleaned, ...$base, 'cleaned' => $cleaned, 'rollout_restore' => $rolloutRestore], $cleaned ? 0 : 1);
    }
    throw new InvalidArgumentException('unknown_action:' . $action);
} catch (Throwable $throwable) {
    if (is_string($activeToken) && $activeToken !== '') {
        try { r43_asset_cleanup($activeToken); } catch (Throwable) {}
        try {
            if (is_array($activeRolloutBefore)) {
                /** @var CustomerAssetRolloutGate $recoveryGate */
                $recoveryGate = ObjectManager::getInstance(CustomerAssetRolloutGate::class);
                r43_asset_restore_rollout($recoveryGate, $activeRolloutBefore);
            }
        } catch (Throwable) {}
    }
    r43_asset_output(['ok' => false, 'error' => $throwable->getMessage()], 1);
}
