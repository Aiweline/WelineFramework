<?php

declare(strict_types=1);

use Weline\B2B\Model\B2BOrderPriceSnapshotRecord;
use Weline\B2B\Model\B2BQuoteTokenRecord;
use Weline\B2B\Model\CustomerGroupMembershipRecord;
use Weline\B2B\Model\CustomerGroupRecord;
use Weline\B2B\Model\PriceListItemRecord;
use Weline\B2B\Model\PriceListRecord;
use Weline\B2B\Service\B2BRolloutGate;
use Weline\B2B\Service\B2BService;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;
use Weline\Test\E2E\Framework\IsolatedSystemConfigPreimage;

require dirname(__DIR__, 7) . '/app/bootstrap.php';
require_once BP . 'tests/e2e/framework/isolated-system-config-preimage.php';

/** @return array<string,mixed> */
function r43_b2b_input(): array
{
    $decoded = json_decode((string)file_get_contents('php://stdin'), true);
    if (!is_array($decoded) || array_is_list($decoded)) throw new InvalidArgumentException('stdin_must_be_json_object');
    return $decoded;
}

/** @param array<string,mixed> $payload */
function r43_b2b_output(array $payload, int $code = 0): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit($code);
}

/** @template T of object @param class-string<T> $class @return T */
function r43_b2b_model(string $class): object
{
    return ObjectManager::getInstance($class, [], false);
}

/** @return array{connector:string,database:string} */
function r43_b2b_assert_isolated_pgsql(): array
{
    if (getenv('WELINE_E2E_ISOLATED_DB') !== '1') throw new RuntimeException('r43_b2b_requires_isolated_database_opt_in');
    /** @var CustomerGroupRecord $model */
    $model = r43_b2b_model(CustomerGroupRecord::class);
    $connectorObject = $model->getConnection()->getConnector();
    $connector = get_class($connectorObject);
    if (!str_contains(strtolower($connector), 'pgsql') && !str_contains(strtolower($connector), 'postgres')) throw new RuntimeException('r43_b2b_requires_postgresql:' . $connector);
    $database = (string)$connectorObject->getConfigProvider()->getDatabase();
    if (!str_starts_with($database, 'mig_clone_')) throw new RuntimeException('r43_b2b_requires_migration_clone:' . $database);
    return ['connector' => $connector, 'database' => $database];
}

function r43_b2b_token(mixed $value): string
{
    $token = strtoupper(trim((string)$value));
    if (preg_match('/^[A-F0-9]{12}$/D', $token) !== 1) throw new InvalidArgumentException('invalid_r43_b2b_token');
    return $token;
}

function r43_b2b_group_id(string $token): string { return 'grp_r43_' . strtolower($token); }
function r43_b2b_list_id(string $token): string { return 'pl_r43_' . strtolower($token); }
function r43_b2b_customer_id(string $token): string { return 'cust_r43_' . strtolower($token); }
function r43_b2b_order_ref(string $token): string { return 'order_r43_' . strtolower($token); }

/** @return array{mode:string,subjects:list<string>,audit_before:array<string,mixed>} */
function r43_b2b_rollout_snapshot(B2BRolloutGate $gate): array
{
    $configuration = $gate->configuration();
    return [
        'mode' => (string)$configuration['mode'],
        'subjects' => array_values(array_keys((array)$configuration['allowlist'])),
        'audit_before' => IsolatedSystemConfigPreimage::capture(
            'Weline_B2B',
            'frontend',
            [B2BRolloutGate::CONFIG_MODE, B2BRolloutGate::CONFIG_ALLOWLIST],
        ),
    ];
}

/** @param array<string,mixed> $snapshot @return array<string,mixed> */
function r43_b2b_restore_rollout(B2BRolloutGate $gate, array $snapshot): array
{
    $mode = (string)($snapshot['mode'] ?? '');
    $subjects = array_values(array_unique(array_map('strval', (array)($snapshot['subjects'] ?? []))));
    sort($subjects, SORT_STRING);
    if (!in_array($mode, CommerceRolloutGateInterface::MODES, true)) {
        throw new RuntimeException('r43_b2b_rollout_snapshot_mode_invalid');
    }
    $gate->setMode(
        B2BService::CAPABILITY,
        $mode,
        $subjects,
        $mode === CommerceRolloutGateInterface::MODE_ON ? 'r43-e2e-official-restore' : '',
    );
    $configuration = $gate->configuration();
    $restoredSubjects = array_values(array_keys((array)($configuration['allowlist'] ?? [])));
    sort($restoredSubjects, SORT_STRING);
    if ((string)($configuration['mode'] ?? '') !== $mode || $restoredSubjects !== $subjects) {
        throw new RuntimeException('r43_b2b_rollout_business_restore_mismatch');
    }
    $audit = IsolatedSystemConfigPreimage::assertMonotonicAfterOfficialRestore(
        (array)($snapshot['audit_before'] ?? []),
        'Weline_B2B',
        'frontend',
        [B2BRolloutGate::CONFIG_MODE, B2BRolloutGate::CONFIG_ALLOWLIST],
    );
    return [
        'business_state_restored' => true,
        'mode' => $mode,
        'subjects' => $subjects,
        'logical_hash' => hash('sha256', json_encode([$mode, $subjects], JSON_THROW_ON_ERROR)),
        'audit' => $audit,
    ];
}

function r43_b2b_delete_where(string $class, string $field, string|int $value): void
{
    $model = r43_b2b_model($class);
    $model->where($field, $value)->delete()->fetch();
}

function r43_b2b_exists(string $class, string $field, string|int $value): bool
{
    $model = r43_b2b_model($class);
    $model->where($field, $value)->find()->fetch();
    return (bool)$model->getId();
}

function r43_b2b_cleanup(string $token): void
{
    $groupId = r43_b2b_group_id($token);
    $listId = r43_b2b_list_id($token);
    r43_b2b_delete_where(B2BOrderPriceSnapshotRecord::class, B2BOrderPriceSnapshotRecord::schema_fields_ORDER_REF, r43_b2b_order_ref($token));
    r43_b2b_delete_where(B2BQuoteTokenRecord::class, B2BQuoteTokenRecord::schema_fields_CUSTOMER_ID, r43_b2b_customer_id($token));
    r43_b2b_delete_where(PriceListItemRecord::class, PriceListItemRecord::schema_fields_LIST_ID, $listId);
    r43_b2b_delete_where(PriceListRecord::class, PriceListRecord::schema_fields_LIST_ID, $listId);
    r43_b2b_delete_where(CustomerGroupMembershipRecord::class, CustomerGroupMembershipRecord::schema_fields_GROUP_ID, $groupId);
    r43_b2b_delete_where(CustomerGroupRecord::class, CustomerGroupRecord::schema_fields_GROUP_ID, $groupId);
}

$activeToken = null;
$activeRolloutBefore = null;
try {
    $input = r43_b2b_input();
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $isolation = r43_b2b_assert_isolated_pgsql();
    $base = ['connector' => $isolation['connector'], 'database' => $isolation['database']];
    /** @var B2BRolloutGate $gate */
    $gate = ObjectManager::getInstance(B2BRolloutGate::class);

    if (str_starts_with($action, 'prepare_')) {
        $kind = substr($action, 8);
        if (!in_array($kind, ['group', 'price_list', 'quote'], true)) throw new InvalidArgumentException('unknown_b2b_prepare:' . $kind);
        $token = strtoupper(bin2hex(random_bytes(6)));
        $activeToken = $token;
        r43_b2b_cleanup($token);
        $before = r43_b2b_rollout_snapshot($gate);
        $activeRolloutBefore = $before;
        $gate->setMode(B2BService::CAPABILITY, CommerceRolloutGateInterface::MODE_ALLOWLIST, ['website:0']);
        $payload = [
            'ok' => true,
            ...$base,
            'token' => $token,
            'rollout_before' => $before,
            'group_id' => r43_b2b_group_id($token),
            'group_code' => 'r43_' . strtolower($token),
            'website_id' => 0,
            'list_id' => r43_b2b_list_id($token),
            'customer_id' => r43_b2b_customer_id($token),
            'sku' => 'R43-SKU-' . $token,
            'amount_minor' => 7430,
            'retail_amount_minor' => 10000,
            'order_ref' => r43_b2b_order_ref($token),
        ];
        if ($kind !== 'group') {
            /** @var B2BService $service */
            $service = ObjectManager::getInstance(B2BService::class);
            $service->seedGroup($payload['group_id'], 0, $payload['group_code']);
            if ($kind === 'quote') {
                $service->assignCustomer($payload['customer_id'], $payload['group_id']);
                $service->seedPriceList($payload['list_id'], $payload['group_id'], 0, 1, [$payload['sku'] => $payload['amount_minor']]);
            }
        }
        r43_b2b_output($payload);
    }

    if ($action === 'inspect_group') {
        $token = r43_b2b_token($input['token'] ?? '');
        /** @var CustomerGroupRecord $group */
        $group = r43_b2b_model(CustomerGroupRecord::class);
        $group->where(CustomerGroupRecord::schema_fields_GROUP_ID, r43_b2b_group_id($token))->find()->fetch();
        $ok = $group->getId() && (string)$group->getData(CustomerGroupRecord::schema_fields_CODE) === 'r43_' . strtolower($token);
        r43_b2b_output(['ok' => (bool)$ok, ...$base, 'group_id' => (string)$group->getData(CustomerGroupRecord::schema_fields_GROUP_ID)], $ok ? 0 : 1);
    }

    if ($action === 'inspect_price_list') {
        $token = r43_b2b_token($input['token'] ?? '');
        /** @var PriceListRecord $list */
        $list = r43_b2b_model(PriceListRecord::class);
        $list->where(PriceListRecord::schema_fields_LIST_ID, r43_b2b_list_id($token))->find()->fetch();
        /** @var PriceListItemRecord $item */
        $item = r43_b2b_model(PriceListItemRecord::class);
        $item->where(PriceListItemRecord::schema_fields_LIST_ID, r43_b2b_list_id($token))->where(PriceListItemRecord::schema_fields_SKU, 'R43-SKU-' . $token)->find()->fetch();
        $ok = $list->getId() && $item->getId() && (int)$item->getData(PriceListItemRecord::schema_fields_AMOUNT_MINOR) === 7430;
        r43_b2b_output(['ok' => (bool)$ok, ...$base, 'list_id' => r43_b2b_list_id($token), 'amount_minor' => (int)$item->getData(PriceListItemRecord::schema_fields_AMOUNT_MINOR)], $ok ? 0 : 1);
    }

    if ($action === 'inspect_quote') {
        $token = r43_b2b_token($input['token'] ?? '');
        /** @var B2BQuoteTokenRecord $quote */
        $quote = r43_b2b_model(B2BQuoteTokenRecord::class);
        $quote->where(B2BQuoteTokenRecord::schema_fields_CUSTOMER_ID, r43_b2b_customer_id($token))->where(B2BQuoteTokenRecord::schema_fields_CONSUMED_ORDER_REF, r43_b2b_order_ref($token))->find()->fetch();
        /** @var B2BOrderPriceSnapshotRecord $snapshot */
        $snapshot = r43_b2b_model(B2BOrderPriceSnapshotRecord::class);
        $snapshot->where(B2BOrderPriceSnapshotRecord::schema_fields_ORDER_REF, r43_b2b_order_ref($token))->find()->fetch();
        $ok = $quote->getId()
            && $snapshot->getId()
            && (string)$quote->getData(B2BQuoteTokenRecord::schema_fields_STATUS) === 'consumed'
            && (int)$snapshot->getData(B2BOrderPriceSnapshotRecord::schema_fields_AMOUNT_MINOR) === 7430;
        r43_b2b_output(['ok' => (bool)$ok, ...$base, 'token_id' => (string)$quote->getData(B2BQuoteTokenRecord::schema_fields_TOKEN_ID), 'order_ref' => r43_b2b_order_ref($token), 'snapshot_amount_minor' => (int)$snapshot->getData(B2BOrderPriceSnapshotRecord::schema_fields_AMOUNT_MINOR)], $ok ? 0 : 1);
    }

    if ($action === 'cleanup') {
        $token = r43_b2b_token($input['token'] ?? '');
        $cleanupErrors = [];
        try {
            r43_b2b_cleanup($token);
        } catch (Throwable $throwable) {
            $cleanupErrors[] = 'business:' . $throwable->getMessage();
        }
        $rolloutRestore = null;
        try {
            $rolloutRestore = r43_b2b_restore_rollout($gate, (array)($input['rollout_before'] ?? []));
        } catch (Throwable $throwable) {
            $cleanupErrors[] = 'rollout:' . $throwable->getMessage();
        }
        if ($cleanupErrors !== []) {
            throw new RuntimeException('r43_b2b_cleanup_failed:' . implode('|', $cleanupErrors));
        }
        $cleaned = !r43_b2b_exists(CustomerGroupRecord::class, CustomerGroupRecord::schema_fields_GROUP_ID, r43_b2b_group_id($token))
            && !r43_b2b_exists(CustomerGroupMembershipRecord::class, CustomerGroupMembershipRecord::schema_fields_GROUP_ID, r43_b2b_group_id($token))
            && !r43_b2b_exists(PriceListRecord::class, PriceListRecord::schema_fields_LIST_ID, r43_b2b_list_id($token))
            && !r43_b2b_exists(PriceListItemRecord::class, PriceListItemRecord::schema_fields_LIST_ID, r43_b2b_list_id($token))
            && !r43_b2b_exists(B2BQuoteTokenRecord::class, B2BQuoteTokenRecord::schema_fields_CUSTOMER_ID, r43_b2b_customer_id($token))
            && !r43_b2b_exists(B2BOrderPriceSnapshotRecord::class, B2BOrderPriceSnapshotRecord::schema_fields_ORDER_REF, r43_b2b_order_ref($token));
        r43_b2b_output(['ok' => $cleaned, ...$base, 'cleaned' => $cleaned, 'rollout_restore' => $rolloutRestore], $cleaned ? 0 : 1);
    }
    throw new InvalidArgumentException('unknown_action:' . $action);
} catch (Throwable $throwable) {
    if (is_string($activeToken) && $activeToken !== '') {
        try { r43_b2b_cleanup($activeToken); } catch (Throwable) {}
        try {
            if (is_array($activeRolloutBefore)) {
                /** @var B2BRolloutGate $recoveryGate */
                $recoveryGate = ObjectManager::getInstance(B2BRolloutGate::class);
                r43_b2b_restore_rollout($recoveryGate, $activeRolloutBefore);
            }
        } catch (Throwable) {}
    }
    r43_b2b_output(['ok' => false, 'error' => $throwable->getMessage()], 1);
}
