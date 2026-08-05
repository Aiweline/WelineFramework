<?php

declare(strict_types=1);

/**
 * TASK-MIG-P4D isolated PostgreSQL full-clone fixture/probe.
 *
 * stdin JSON:
 * - {"action":"prepare","database":"mig_clone_*","token":"..."}
 * - {"action":"status","database":"mig_clone_*"}
 * - {"action":"tender","database":"mig_clone_*","token":"..."}
 * - {"action":"assert-blocked","database":"mig_clone_*","token":"..."}
 * - {"action":"cleanup","database":"mig_clone_*","customer_id":"..."}
 */

use Weline\CustomerAsset\Model\AssetAccount;
use Weline\CustomerAsset\Model\AssetLedger;
use Weline\CustomerAsset\Model\AssetReservation;
use Weline\CustomerAsset\Service\CustomerAssetConflictException;
use Weline\CustomerAsset\Service\CustomerAssetRolloutGate;
use Weline\CustomerAsset\Service\CustomerAssetService;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Migration\Service\MigrationCloneService;
use Weline\Framework\Database\Migration\Service\MigrationTargetBinder;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;

require dirname(__DIR__, 6) . '/app/bootstrap.php';

/** @return array<string,mixed> */
function mig_p4d_fixture_input(): array
{
    $raw = stream_get_contents(STDIN);
    $decoded = json_decode($raw !== false && $raw !== '' ? $raw : '{}', true);

    return is_array($decoded) ? $decoded : [];
}

/** @param array<string,mixed> $payload */
function mig_p4d_fixture_output(array $payload): never
{
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    ) . "\n";
    exit(($payload['ok'] ?? false) === true ? 0 : 2);
}

function mig_p4d_fixture_bind(string $database): void
{
    $database = strtolower(trim($database));
    if ($database === '') {
        throw new InvalidArgumentException('fixture_database_required');
    }
    /** @var MigrationCloneService $clones */
    $clones = ObjectManager::getInstance(MigrationCloneService::class);
    $handle = null;
    foreach ($clones->list() as $candidate) {
        if ($candidate->database === $database) {
            $handle = $candidate;
            break;
        }
    }
    if ($handle === null) {
        throw new RuntimeException('fixture_registered_clone_required:' . $database);
    }
    if ($handle->mode !== MigrationCloneService::MODE_FULL) {
        throw new RuntimeException('fixture_full_clone_required:' . $database);
    }
    if (($handle->config['type'] ?? null) !== 'pgsql') {
        throw new RuntimeException('fixture_postgresql_required');
    }

    (new MigrationTargetBinder())->bindIsolated($handle->config);
}

/** @return array<string,mixed> */
function mig_p4d_fixture_prepare(string $database, string $token): array
{
    mig_p4d_fixture_bind($database);
    $token = preg_replace('/[^a-zA-Z0-9]/', '', $token) ?: bin2hex(random_bytes(5));
    $customerId = 'mig-p4d-' . strtolower($token);
    mig_p4d_fixture_cleanup_bound($customerId);

    $gate = CustomerAssetRolloutGate::forConnection(
        ConnectionFactory::getInstance(),
    );
    $gate->setMode(
        CustomerAssetService::CAPABILITY,
        CommerceRolloutGateInterface::MODE_ALLOWLIST,
        ['website:0'],
    );
    $assets = new CustomerAssetService(rolloutGate: $gate);
    $credit = $assets->credit([
        'customer_id' => $customerId,
        'website_id' => 0,
        'asset_code' => 'credit',
        'namespace' => AssetAccount::NS_LIVE,
        'amount_minor' => 1000,
        'event_id' => $customerId . ':credit',
    ]);
    $reserve = $assets->reserve([
        'customer_id' => $customerId,
        'website_id' => 0,
        'asset_code' => 'credit',
        'namespace' => AssetAccount::NS_LIVE,
        'amount_minor' => 300,
        'event_id' => $customerId . ':reserve',
    ]);
    $gate->setMode(
        CustomerAssetService::CAPABILITY,
        CommerceRolloutGateInterface::MODE_OFF,
    );

    return [
        'ok' => ($credit['ok'] ?? false) === true
            && ($reserve['ok'] ?? false) === true,
        'database' => $database,
        'database_type' => 'pgsql',
        'clone_mode' => 'full',
        'customer_id' => $customerId,
        'account_id' => $credit['account']['account_id'] ?? null,
        'reservation_id' => $reserve['reservation']['reservation_id'] ?? null,
        'available_minor' => $reserve['account']['available_minor'] ?? null,
        'reserved_minor' => $reserve['account']['reserved_minor'] ?? null,
        'mode' => $gate->mode(CustomerAssetService::CAPABILITY),
    ];
}

/** @return array<string,mixed> */
function mig_p4d_fixture_status(string $database): array
{
    mig_p4d_fixture_bind($database);
    $configuration = CustomerAssetRolloutGate::forConnection(
        ConnectionFactory::getInstance(),
    )->configuration();

    return [
        'ok' => true,
        'database' => $database,
        'database_type' => 'pgsql',
        'clone_mode' => 'full',
        'mode' => $configuration['mode'],
        'allowlist' => $configuration['allowlist_rows'],
    ];
}

/** @return array<string,mixed> */
function mig_p4d_fixture_tender(string $database, string $token): array
{
    mig_p4d_fixture_bind($database);
    $token = preg_replace('/[^a-zA-Z0-9]/', '', $token) ?: bin2hex(random_bytes(5));
    $customerId = 'mig-p4d-tender-' . strtolower($token);
    mig_p4d_fixture_cleanup_bound($customerId);

    // No gate is injected: this proves a fresh process resolves the durable
    // CustomerAsset rollout written by the migration command.
    $assets = new CustomerAssetService();
    $credit = $assets->credit([
        'customer_id' => $customerId,
        'website_id' => 0,
        'asset_code' => 'credit',
        'amount_minor' => 200,
        'event_id' => $customerId . ':credit',
    ]);
    $reserve = $assets->reserve([
        'customer_id' => $customerId,
        'website_id' => 0,
        'asset_code' => 'credit',
        'amount_minor' => 100,
        'event_id' => $customerId . ':reserve',
    ]);

    return [
        'ok' => ($credit['ok'] ?? false) === true
            && ($reserve['ok'] ?? false) === true,
        'database' => $database,
        'customer_id' => $customerId,
        'mode' => $assets->rollout()->mode(CustomerAssetService::CAPABILITY),
        'available_minor' => $reserve['account']['available_minor'] ?? null,
        'reserved_minor' => $reserve['account']['reserved_minor'] ?? null,
    ];
}

/** @return array<string,mixed> */
function mig_p4d_fixture_assert_blocked(string $database, string $token): array
{
    mig_p4d_fixture_bind($database);
    $token = preg_replace('/[^a-zA-Z0-9]/', '', $token) ?: bin2hex(random_bytes(5));
    $customerId = 'mig-p4d-blocked-' . strtolower($token);
    mig_p4d_fixture_cleanup_bound($customerId);
    $assets = new CustomerAssetService();
    try {
        $assets->credit([
            'customer_id' => $customerId,
            'website_id' => 0,
            'asset_code' => 'credit',
            'amount_minor' => 1,
            'event_id' => $customerId . ':credit',
        ]);
    } catch (CustomerAssetConflictException $exception) {
        return [
            'ok' => $exception->errorCode === CustomerAssetService::ERROR_MODE_OFF,
            'database' => $database,
            'customer_id' => $customerId,
            'blocked' => true,
            'error_code' => $exception->errorCode,
            'mode' => $assets->rollout()->mode(CustomerAssetService::CAPABILITY),
        ];
    }
    mig_p4d_fixture_cleanup_bound($customerId);

    return [
        'ok' => false,
        'database' => $database,
        'customer_id' => $customerId,
        'blocked' => false,
        'error' => 'new_tender_was_not_blocked',
    ];
}

/** @return array<string,mixed> */
function mig_p4d_fixture_cleanup(string $database, string $customerId): array
{
    mig_p4d_fixture_bind($database);
    mig_p4d_fixture_cleanup_bound($customerId);

    return [
        'ok' => true,
        'database' => $database,
        'cleaned_customer_id' => $customerId,
    ];
}

function mig_p4d_fixture_cleanup_bound(string $customerId): void
{
    if ($customerId === '') {
        return;
    }
    /** @var AssetReservation $reservation */
    $reservation = ObjectManager::create(AssetReservation::class, [], false);
    $reservation->clear()
        ->where(AssetReservation::schema_fields_CUSTOMER_ID, $customerId)
        ->delete()
        ->fetch();

    /** @var AssetLedger $ledger */
    $ledger = ObjectManager::create(AssetLedger::class, [], false);
    $ledger->clear()
        ->where(AssetLedger::schema_fields_CUSTOMER_ID, $customerId)
        ->delete()
        ->fetch();

    /** @var AssetAccount $account */
    $account = ObjectManager::create(AssetAccount::class, [], false);
    $account->clear()
        ->where(AssetAccount::schema_fields_CUSTOMER_ID, $customerId)
        ->delete()
        ->fetch();
}

$input = mig_p4d_fixture_input();
$action = (string) ($input['action'] ?? '');
$database = (string) ($input['database'] ?? '');
try {
    $result = match ($action) {
        'prepare' => mig_p4d_fixture_prepare(
            $database,
            (string) ($input['token'] ?? ''),
        ),
        'status' => mig_p4d_fixture_status($database),
        'tender' => mig_p4d_fixture_tender(
            $database,
            (string) ($input['token'] ?? ''),
        ),
        'assert-blocked' => mig_p4d_fixture_assert_blocked(
            $database,
            (string) ($input['token'] ?? ''),
        ),
        'cleanup' => mig_p4d_fixture_cleanup(
            $database,
            (string) ($input['customer_id'] ?? ''),
        ),
        default => throw new InvalidArgumentException('fixture_unknown_action'),
    };
    mig_p4d_fixture_output($result);
} catch (Throwable $throwable) {
    mig_p4d_fixture_output([
        'ok' => false,
        'error' => $throwable->getMessage(),
    ]);
}
