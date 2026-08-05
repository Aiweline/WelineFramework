<?php

declare(strict_types=1);

use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\CheckoutGroup;
use Weline\Order\Model\DisplayNumberRegistry;
use Weline\Order\Model\FulfillmentUnit;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderItem;
use Weline\Payment\Model\PaymentAllocation;
use Weline\Payment\Model\PaymentAttempt;
use Weline\Payment\Model\PaymentCheckoutSession;
use Weline\Payment\Model\PaymentIdempotency;
use Weline\Payment\Model\PaymentIntent;
use Weline\Payment\Model\PaymentLedger;
use Weline\Payment\Model\PaymentLock;
use Weline\Payment\Model\PaymentMethod;
use Weline\Payment\Model\PaymentMethodConfig;
use Weline\Payment\Model\PaymentOutbox;
use Weline\Payment\Model\PaymentProviderCommandOutbox;
use Weline\Payment\Model\PaymentRefund;
use Weline\Payment\Model\PaymentWebhookInbox;
use Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider\FakeProvider;
use Weline\Payment\Service\PaymentMethodManager;
use Weline\Subscription\Model\Subscription;
use Weline\Subscription\Model\SubscriptionBillingAttempt;
use Weline\Subscription\Model\SubscriptionMissedWatermark;
use Weline\Subscription\Model\SubscriptionPeriod;
use Weline\Subscription\Model\SubscriptionSchedulerLease;
use Weline\Subscription\Service\SubscriptionRolloutGate;
use Weline\Subscription\Service\SubscriptionService;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;
use Weline\Test\E2E\Framework\IsolatedSystemConfigPreimage;
use Weline\Websites\Model\Store;

require dirname(__DIR__, 7) . '/app/bootstrap.php';
require_once BP . 'tests/e2e/framework/isolated-system-config-preimage.php';

/** @return array<string,mixed> */
function r43_subscription_input(): array
{
    $decoded = json_decode((string)file_get_contents('php://stdin'), true);
    if (!is_array($decoded) || array_is_list($decoded)) throw new InvalidArgumentException('stdin_must_be_json_object');
    return $decoded;
}

/** @param array<string,mixed> $payload */
function r43_subscription_output(array $payload, int $code = 0): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit($code);
}

/** @template T of object @param class-string<T> $class @return T */
function r43_subscription_model(string $class): object
{
    return ObjectManager::getInstance($class, [], false);
}

/** @return array{connector:string,database:string} */
function r43_subscription_assert_isolated_pgsql(): array
{
    if (getenv('WELINE_E2E_ISOLATED_DB') !== '1') throw new RuntimeException('r43_subscription_requires_isolated_database_opt_in');
    /** @var Subscription $model */
    $model = r43_subscription_model(Subscription::class);
    $connectorObject = $model->getConnection()->getConnector();
    $connector = get_class($connectorObject);
    if (!str_contains(strtolower($connector), 'pgsql') && !str_contains(strtolower($connector), 'postgres')) throw new RuntimeException('r43_subscription_requires_postgresql:' . $connector);
    $database = (string)$connectorObject->getConfigProvider()->getDatabase();
    if (!str_starts_with($database, 'mig_clone_')) throw new RuntimeException('r43_subscription_requires_migration_clone:' . $database);
    return ['connector' => $connector, 'database' => $database];
}

function r43_subscription_token(mixed $value): string
{
    $token = strtoupper(trim((string)$value));
    if (preg_match('/^[A-F0-9]{12}$/D', $token) !== 1) throw new InvalidArgumentException('invalid_r43_subscription_token');
    return $token;
}

function r43_subscription_id(string $token): string
{
    return 'sub_r43_' . strtolower($token);
}

/** @return array{store_id:int,website_id:int} */
function r43_subscription_active_store(): array
{
    /** @var Store $store */
    $store = r43_subscription_model(Store::class);
    $rows = $store->where(Store::schema_fields_STATUS, 1)
        ->where(Store::schema_fields_LIFECYCLE_STATUS, Store::LIFECYCLE_ACTIVE)
        ->order(Store::schema_fields_IS_DEFAULT, 'DESC')
        ->order(Store::schema_fields_ID, 'ASC')
        ->select()
        ->fetchArray();
    foreach ($rows as $row) {
        $storeId = (int)($row[Store::schema_fields_ID] ?? 0);
        if ($storeId <= 0) {
            continue;
        }

        return [
            'store_id' => $storeId,
            'website_id' => (int)($row[Store::schema_fields_WEBSITE_ID] ?? 0),
        ];
    }

    throw new RuntimeException('r43_subscription_active_store_required');
}

/** @return array{mode:string,subjects:list<string>,audit_before:array<string,mixed>} */
function r43_subscription_rollout_snapshot(SubscriptionRolloutGate $gate): array
{
    $configuration = $gate->configuration();
    return [
        'mode' => (string)$configuration['mode'],
        'subjects' => array_values(array_keys((array)$configuration['allowlist'])),
        'audit_before' => IsolatedSystemConfigPreimage::capture(
            'Weline_Subscription',
            'frontend',
            [SubscriptionRolloutGate::CONFIG_MODE, SubscriptionRolloutGate::CONFIG_ALLOWLIST],
        ),
    ];
}

/** @param array<string,mixed> $snapshot @return array<string,mixed> */
function r43_subscription_restore_rollout(SubscriptionRolloutGate $gate, array $snapshot): array
{
    $mode = (string)($snapshot['mode'] ?? '');
    $subjects = array_values(array_unique(array_map('strval', (array)($snapshot['subjects'] ?? []))));
    sort($subjects, SORT_STRING);
    if (!in_array($mode, CommerceRolloutGateInterface::MODES, true)) {
        throw new RuntimeException('r43_subscription_rollout_snapshot_mode_invalid');
    }
    $gate->setMode(
        SubscriptionService::CAPABILITY,
        $mode,
        $subjects,
        $mode === CommerceRolloutGateInterface::MODE_ON ? 'r43-e2e-official-restore' : '',
    );
    $configuration = $gate->configuration();
    $restoredSubjects = array_values(array_keys((array)($configuration['allowlist'] ?? [])));
    sort($restoredSubjects, SORT_STRING);
    if ((string)($configuration['mode'] ?? '') !== $mode || $restoredSubjects !== $subjects) {
        throw new RuntimeException('r43_subscription_rollout_business_restore_mismatch');
    }
    $audit = IsolatedSystemConfigPreimage::assertMonotonicAfterOfficialRestore(
        (array)($snapshot['audit_before'] ?? []),
        'Weline_Subscription',
        'frontend',
        [SubscriptionRolloutGate::CONFIG_MODE, SubscriptionRolloutGate::CONFIG_ALLOWLIST],
    );
    return [
        'business_state_restored' => true,
        'mode' => $mode,
        'subjects' => $subjects,
        'logical_hash' => hash('sha256', json_encode([$mode, $subjects], JSON_THROW_ON_ERROR)),
        'audit' => $audit,
    ];
}

/** @return array<string,mixed> */
function r43_subscription_payment_method_snapshot(): array
{
    /** @var PaymentMethod $method */
    $method = r43_subscription_model(PaymentMethod::class);
    $method->load(PaymentMethod::schema_fields_CODE, 'fake_card');
    $methodRow = $method->getId() ? $method->getData() : null;
    /** @var PaymentMethodConfig $configs */
    $configs = r43_subscription_model(PaymentMethodConfig::class);
    $configRows = $configs->where(PaymentMethodConfig::schema_fields_METHOD_CODE, 'fake_card')->select()->fetchArray();
    $snapshot = [
        'method_row' => is_array($methodRow) ? $methodRow : null,
        'config_rows' => array_values(array_filter(is_array($configRows) ? $configRows : [], 'is_array')),
    ];
    return $snapshot + ['hash' => r43_subscription_payment_method_hash($snapshot)];
}

/** @param array<string,mixed> $snapshot */
function r43_subscription_payment_method_hash(array $snapshot): string
{
    $method = is_array($snapshot['method_row'] ?? null) ? $snapshot['method_row'] : null;
    if (is_array($method)) ksort($method, SORT_STRING);
    $configs = array_values(array_filter((array)($snapshot['config_rows'] ?? []), 'is_array'));
    foreach ($configs as &$row) ksort($row, SORT_STRING);
    unset($row);
    usort($configs, static fn(array $left, array $right): int => strcmp(
        json_encode($left, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        json_encode($right, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ));
    return hash('sha256', json_encode(
        ['method_row' => $method, 'config_rows' => $configs],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ));
}

/** @param array<string,mixed> $snapshot */
function r43_subscription_prepare_payment_method(array $snapshot): void
{
    if ((string)($snapshot['hash'] ?? '') === '') {
        throw new RuntimeException('r43_subscription_payment_method_preimage_missing');
    }
    /** @var PaymentMethodManager $manager */
    $manager = ObjectManager::getInstance(PaymentMethodManager::class);
    /** @var FakeProvider $provider */
    $provider = ObjectManager::getInstance(FakeProvider::class);
    $method = $manager->registerProvider($provider, ['source_module' => 'Weline_Payment']);
    $method->setData(PaymentMethod::schema_fields_IS_ACTIVE, 1)->save();
    $route = $manager->resolveProviderRoute('fake_card', [
        'scope' => 'default.default.default',
        'environment' => 'sandbox',
    ]);
    if (!($route['provider'] ?? null) instanceof FakeProvider) {
        throw new RuntimeException('r43_subscription_fake_provider_unavailable');
    }
}

/** @param array<string,mixed> $snapshot @return array{preimage_hash:string,restored_hash:string} */
function r43_subscription_restore_payment_method(array $snapshot): array
{
    $expected = (string)($snapshot['hash'] ?? '');
    if ($expected === '' || !hash_equals($expected, r43_subscription_payment_method_hash($snapshot))) {
        throw new RuntimeException('r43_subscription_payment_method_preimage_invalid');
    }
    /** @var PaymentMethodConfig $configs */
    $configs = r43_subscription_model(PaymentMethodConfig::class);
    /** @var PaymentMethod $method */
    $method = r43_subscription_model(PaymentMethod::class);
    $connection = $method->getConnection();
    $configs->setConnection($connection);
    $configs->where(PaymentMethodConfig::schema_fields_METHOD_CODE, 'fake_card');
    $configs->getQuery()->delete()->fetch();
    $method->where(PaymentMethod::schema_fields_CODE, 'fake_card');
    $method->getQuery()->delete()->fetch();

    $methodRow = is_array($snapshot['method_row'] ?? null) ? $snapshot['method_row'] : null;
    if (is_array($methodRow)) {
        /** @var PaymentMethod $restoreMethod */
        $restoreMethod = r43_subscription_model(PaymentMethod::class);
        $restoreMethod->setConnection($connection);
        $restoreMethod->reset()->getQuery()->insert([$methodRow], array_keys($methodRow))->fetch();
    }
    $configRows = array_values(array_filter((array)($snapshot['config_rows'] ?? []), 'is_array'));
    foreach ($configRows as $configRow) {
        /** @var PaymentMethodConfig $restoreConfig */
        $restoreConfig = r43_subscription_model(PaymentMethodConfig::class);
        $restoreConfig->setConnection($connection);
        $restoreConfig->reset()->getQuery()->insert([$configRow], array_keys($configRow))->fetch();
    }
    $restored = r43_subscription_payment_method_snapshot();
    $actual = (string)$restored['hash'];
    if (!hash_equals($expected, $actual)) {
        throw new RuntimeException('r43_subscription_payment_method_restore_mismatch');
    }
    return ['preimage_hash' => $expected, 'restored_hash' => $actual];
}

/** @return list<array<string,mixed>> */
function r43_subscription_attempts(string $subscriptionId): array
{
    /** @var SubscriptionBillingAttempt $attempt */
    $attempt = r43_subscription_model(SubscriptionBillingAttempt::class);
    $rows = $attempt->where(SubscriptionBillingAttempt::schema_fields_SUBSCRIPTION_ID, $subscriptionId)->select()->fetchArray();
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

function r43_subscription_delete_where(string $class, string $field, string|int $value): void
{
    $model = r43_subscription_model($class);
    $model->where($field, $value);
    $model->getQuery()->delete()->fetch();
}

function r43_subscription_exists(string $class, string $field, string|int $value): bool
{
    $model = r43_subscription_model($class);
    $model->where($field, $value)->find()->fetch();
    return (bool)$model->getId();
}

/** @param array<string,mixed> $attempt */
function r43_subscription_cleanup_payment(array $attempt): void
{
    $orderRef = trim((string)($attempt[SubscriptionBillingAttempt::schema_fields_ORDER_REF] ?? ''));
    $intentCode = trim((string)($attempt[SubscriptionBillingAttempt::schema_fields_PAYMENT_INTENT_CODE] ?? ''));
    $attemptCode = trim((string)($attempt[SubscriptionBillingAttempt::schema_fields_PAYMENT_ATTEMPT_CODE] ?? ''));
    foreach ([PaymentProviderCommandOutbox::class, PaymentOutbox::class, PaymentWebhookInbox::class] as $class) {
        if ($attemptCode !== '') r43_subscription_delete_where($class, $class::schema_fields_ATTEMPT_CODE, $attemptCode);
        if ($intentCode !== '') r43_subscription_delete_where($class, $class::schema_fields_INTENT_CODE, $intentCode);
    }
    foreach ([PaymentRefund::class, PaymentAllocation::class, PaymentLedger::class, PaymentLock::class, PaymentIdempotency::class, PaymentAttempt::class] as $class) {
        if ($orderRef !== '') r43_subscription_delete_where($class, $class::schema_fields_PAYABLE_ID, $orderRef);
    }
    if ($orderRef !== '') r43_subscription_delete_where(PaymentCheckoutSession::class, PaymentCheckoutSession::schema_fields_PAYABLE_ID, $orderRef);
    if ($orderRef !== '') r43_subscription_delete_where(PaymentIntent::class, PaymentIntent::schema_fields_PAYABLE_ID, $orderRef);
}

function r43_subscription_cleanup_order(string $orderRef): void
{
    if ($orderRef === '') return;
    /** @var Order $order */
    $order = r43_subscription_model(Order::class);
    $order->where(Order::schema_fields_ORDER_UUID, $orderRef)->find()->fetch();
    if (!$order->getId()) return;
    $groupUuid = (string)$order->getData(Order::schema_fields_CHECKOUT_GROUP_UUID);
    r43_subscription_delete_where(FulfillmentUnit::class, FulfillmentUnit::schema_fields_ORDER_UUID, $orderRef);
    r43_subscription_delete_where(OrderItem::class, OrderItem::schema_fields_ORDER_UUID, $orderRef);
    r43_subscription_delete_where(DisplayNumberRegistry::class, DisplayNumberRegistry::schema_fields_ENTITY_UUID, $orderRef);
    r43_subscription_delete_where(Order::class, Order::schema_fields_ORDER_UUID, $orderRef);
    if ($groupUuid !== '') r43_subscription_delete_where(CheckoutGroup::class, CheckoutGroup::schema_fields_CHECKOUT_GROUP_UUID, $groupUuid);
}

function r43_subscription_cleanup(string $token): void
{
    $subscriptionId = r43_subscription_id($token);
    foreach (r43_subscription_attempts($subscriptionId) as $attempt) {
        r43_subscription_cleanup_payment($attempt);
        r43_subscription_cleanup_order((string)($attempt[SubscriptionBillingAttempt::schema_fields_ORDER_REF] ?? ''));
    }
    foreach ([SubscriptionBillingAttempt::class, SubscriptionMissedWatermark::class, SubscriptionSchedulerLease::class, SubscriptionPeriod::class, Subscription::class] as $class) {
        r43_subscription_delete_where($class, $class::schema_fields_SUBSCRIPTION_ID, $subscriptionId);
    }
}

$activeToken = null;
$activeRolloutBefore = null;
$activePaymentMethodBefore = null;
try {
    $input = r43_subscription_input();
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $isolation = r43_subscription_assert_isolated_pgsql();
    $base = ['connector' => $isolation['connector'], 'database' => $isolation['database']];
    /** @var SubscriptionRolloutGate $gate */
    $gate = ObjectManager::getInstance(SubscriptionRolloutGate::class);

    if ($action === 'prepare_subscription' || $action === 'prepare_renewal') {
        $token = strtoupper(bin2hex(random_bytes(6)));
        $activeToken = $token;
        r43_subscription_cleanup($token);
        $before = r43_subscription_rollout_snapshot($gate);
        $activeRolloutBefore = $before;
        $store = r43_subscription_active_store();
        $gate->setMode(
            SubscriptionService::CAPABILITY,
            CommerceRolloutGateInterface::MODE_ALLOWLIST,
            ['website:' . $store['website_id']],
        );
        $payload = [
            'ok' => true,
            ...$base,
            'token' => $token,
            'rollout_before' => $before,
            'subscription_id' => r43_subscription_id($token),
            'customer_id' => 'r43-customer-' . strtolower($token),
            'website_id' => $store['website_id'],
            'store_id' => $store['store_id'],
            'provider_code' => 'interval_monthly',
            'plan_code' => 'r43-plan-' . strtolower($token),
            'idempotency_key' => 'r43-subscription-' . strtolower($token),
        ];
        if ($action === 'prepare_renewal') {
            $paymentMethodBefore = r43_subscription_payment_method_snapshot();
            $activePaymentMethodBefore = $paymentMethodBefore;
            r43_subscription_prepare_payment_method($paymentMethodBefore);
            $payload['payment_method_before'] = $paymentMethodBefore;
            $payload['payment_method_code'] = 'fake_card';
            /** @var SubscriptionService $service */
            $service = ObjectManager::getInstance(SubscriptionService::class);
            $service->create([
                'subscription_id' => $payload['subscription_id'],
                'customer_id' => $payload['customer_id'],
                'website_id' => $payload['website_id'],
                'store_id' => $payload['store_id'],
                'provider_code' => 'interval_monthly',
                'plan_code' => $payload['plan_code'],
                'idempotency_key' => $payload['idempotency_key'],
                'environment' => 'sandbox',
            ]);
            $payload['worker_id'] = 'r43-worker-' . strtolower($token);
        }
        r43_subscription_output($payload);
    }

    if ($action === 'inspect_subscription') {
        $token = r43_subscription_token($input['token'] ?? '');
        $subscriptionId = r43_subscription_id($token);
        /** @var Subscription $subscription */
        $subscription = r43_subscription_model(Subscription::class);
        $subscription->where(Subscription::schema_fields_SUBSCRIPTION_ID, $subscriptionId)->find()->fetch();
        /** @var SubscriptionPeriod $period */
        $period = r43_subscription_model(SubscriptionPeriod::class);
        $period->where(SubscriptionPeriod::schema_fields_SUBSCRIPTION_ID, $subscriptionId)->where(SubscriptionPeriod::schema_fields_PERIOD_INDEX, 1)->find()->fetch();
        $ok = $subscription->getId() && $period->getId() && (string)$subscription->getData(Subscription::schema_fields_ENVIRONMENT) === 'sandbox';
        r43_subscription_output(['ok' => (bool)$ok, ...$base, 'subscription_id' => $subscriptionId, 'period_key' => (string)$period->getData(SubscriptionPeriod::schema_fields_PERIOD_KEY)], $ok ? 0 : 1);
    }

    if ($action === 'inspect_renewal') {
        $token = r43_subscription_token($input['token'] ?? '');
        $subscriptionId = r43_subscription_id($token);
        $attempts = r43_subscription_attempts($subscriptionId);
        $attempt = $attempts[0] ?? null;
        $orderRef = trim((string)($attempt[SubscriptionBillingAttempt::schema_fields_ORDER_REF] ?? ''));
        $intentCode = trim((string)($attempt[SubscriptionBillingAttempt::schema_fields_PAYMENT_INTENT_CODE] ?? ''));
        $paymentAttemptCode = trim((string)($attempt[SubscriptionBillingAttempt::schema_fields_PAYMENT_ATTEMPT_CODE] ?? ''));
        /** @var PaymentIntent $paymentIntent */
        $paymentIntent = r43_subscription_model(PaymentIntent::class);
        if ($intentCode !== '') {
            $paymentIntent->where(PaymentIntent::schema_fields_INTENT_CODE, $intentCode)->find()->fetch();
        }
        /** @var PaymentAttempt $paymentAttempt */
        $paymentAttempt = r43_subscription_model(PaymentAttempt::class);
        if ($paymentAttemptCode !== '') {
            $paymentAttempt->where(PaymentAttempt::schema_fields_ATTEMPT_CODE, $paymentAttemptCode)->find()->fetch();
        }
        /** @var SubscriptionPeriod $period */
        $period = r43_subscription_model(SubscriptionPeriod::class);
        $period->where(SubscriptionPeriod::schema_fields_SUBSCRIPTION_ID, $subscriptionId)->where(SubscriptionPeriod::schema_fields_PERIOD_INDEX, 1)->find()->fetch();
        $ok = is_array($attempt)
            && $orderRef !== ''
            && $period->getId()
            && trim((string)$period->getData(SubscriptionPeriod::schema_fields_ORDER_REF)) === $orderRef
            && (string)$attempt[SubscriptionBillingAttempt::schema_fields_STATUS] === 'succeeded'
            && (string)$attempt[SubscriptionBillingAttempt::schema_fields_PAYMENT_STATUS] === 'succeeded'
            && $paymentIntent->getId()
            && (string)$paymentIntent->getData(PaymentIntent::schema_fields_PAYABLE_ID) === $orderRef
            && (string)$paymentIntent->getData(PaymentIntent::schema_fields_ENVIRONMENT) === 'sandbox'
            && (string)$paymentIntent->getData(PaymentIntent::schema_fields_METHOD_CODE) === 'fake_card'
            && (string)$paymentIntent->getData(PaymentIntent::schema_fields_STATUS) === 'succeeded'
            && $paymentAttempt->getId()
            && (string)$paymentAttempt->getData(PaymentAttempt::schema_fields_INTENT_CODE) === $intentCode
            && (string)$paymentAttempt->getData(PaymentAttempt::schema_fields_PAYABLE_ID) === $orderRef
            && (string)$paymentAttempt->getData(PaymentAttempt::schema_fields_ENVIRONMENT) === 'sandbox'
            && (string)$paymentAttempt->getData(PaymentAttempt::schema_fields_METHOD_CODE) === 'fake_card'
            && (string)$paymentAttempt->getData(PaymentAttempt::schema_fields_STATUS) === PaymentAttempt::STATUS_SUCCEEDED;
        r43_subscription_output([
            'ok' => (bool)$ok,
            ...$base,
            'subscription_id' => $subscriptionId,
            'attempt' => $attempt,
            'period_status' => (string)$period->getData(SubscriptionPeriod::schema_fields_STATUS),
            'payment_intent' => [
                'intent_code' => $intentCode,
                'status' => (string)$paymentIntent->getData(PaymentIntent::schema_fields_STATUS),
                'environment' => (string)$paymentIntent->getData(PaymentIntent::schema_fields_ENVIRONMENT),
                'method_code' => (string)$paymentIntent->getData(PaymentIntent::schema_fields_METHOD_CODE),
                'payable_id' => (string)$paymentIntent->getData(PaymentIntent::schema_fields_PAYABLE_ID),
            ],
            'payment_attempt' => [
                'attempt_code' => $paymentAttemptCode,
                'status' => (string)$paymentAttempt->getData(PaymentAttempt::schema_fields_STATUS),
                'environment' => (string)$paymentAttempt->getData(PaymentAttempt::schema_fields_ENVIRONMENT),
                'method_code' => (string)$paymentAttempt->getData(PaymentAttempt::schema_fields_METHOD_CODE),
                'payable_id' => (string)$paymentAttempt->getData(PaymentAttempt::schema_fields_PAYABLE_ID),
            ],
        ], $ok ? 0 : 1);
    }

    if ($action === 'cleanup') {
        $token = r43_subscription_token($input['token'] ?? '');
        $subscriptionId = r43_subscription_id($token);
        $attempts = r43_subscription_attempts($subscriptionId);
        $obligations = [];
        foreach ($attempts as $attempt) {
            $orderRef = trim((string)($attempt[SubscriptionBillingAttempt::schema_fields_ORDER_REF] ?? ''));
            $groupUuid = '';
            if ($orderRef !== '') {
                /** @var Order $order */
                $order = r43_subscription_model(Order::class);
                $order->where(Order::schema_fields_ORDER_UUID, $orderRef)->find()->fetch();
                $groupUuid = $order->getId() ? (string)$order->getData(Order::schema_fields_CHECKOUT_GROUP_UUID) : '';
            }
            $obligations[] = [
                'order_ref' => $orderRef,
                'group_uuid' => $groupUuid,
                'intent_code' => trim((string)($attempt[SubscriptionBillingAttempt::schema_fields_PAYMENT_INTENT_CODE] ?? '')),
                'attempt_code' => trim((string)($attempt[SubscriptionBillingAttempt::schema_fields_PAYMENT_ATTEMPT_CODE] ?? '')),
            ];
        }
        $cleanupErrors = [];
        try {
            r43_subscription_cleanup($token);
        } catch (Throwable $throwable) {
            $cleanupErrors[] = 'business:' . $throwable->getMessage();
        }
        $paymentMethodRestore = null;
        if (is_array($input['payment_method_before'] ?? null)) {
            try {
                $paymentMethodRestore = r43_subscription_restore_payment_method((array)$input['payment_method_before']);
            } catch (Throwable $throwable) {
                $cleanupErrors[] = 'payment_method:' . $throwable->getMessage();
            }
        }
        $rolloutRestore = null;
        try {
            $rolloutRestore = r43_subscription_restore_rollout($gate, (array)($input['rollout_before'] ?? []));
        } catch (Throwable $throwable) {
            $cleanupErrors[] = 'rollout:' . $throwable->getMessage();
        }
        if ($cleanupErrors !== []) {
            throw new RuntimeException('r43_subscription_cleanup_failed:' . implode('|', $cleanupErrors));
        }
        $cleaned = true;
        foreach ([SubscriptionBillingAttempt::class, SubscriptionMissedWatermark::class, SubscriptionSchedulerLease::class, SubscriptionPeriod::class, Subscription::class] as $class) {
            $cleaned = $cleaned && !r43_subscription_exists($class, $class::schema_fields_SUBSCRIPTION_ID, $subscriptionId);
        }
        foreach ($obligations as $obligation) {
            $orderRef = (string)$obligation['order_ref'];
            if ($orderRef !== '') {
                $cleaned = $cleaned
                    && !r43_subscription_exists(Order::class, Order::schema_fields_ORDER_UUID, $orderRef)
                    && !r43_subscription_exists(OrderItem::class, OrderItem::schema_fields_ORDER_UUID, $orderRef)
                    && !r43_subscription_exists(FulfillmentUnit::class, FulfillmentUnit::schema_fields_ORDER_UUID, $orderRef)
                    && !r43_subscription_exists(DisplayNumberRegistry::class, DisplayNumberRegistry::schema_fields_ENTITY_UUID, $orderRef)
                    && !r43_subscription_exists(PaymentIntent::class, PaymentIntent::schema_fields_PAYABLE_ID, $orderRef)
                    && !r43_subscription_exists(PaymentAttempt::class, PaymentAttempt::schema_fields_PAYABLE_ID, $orderRef)
                    && !r43_subscription_exists(PaymentLedger::class, PaymentLedger::schema_fields_PAYABLE_ID, $orderRef)
                    && !r43_subscription_exists(PaymentAllocation::class, PaymentAllocation::schema_fields_PAYABLE_ID, $orderRef)
                    && !r43_subscription_exists(PaymentLock::class, PaymentLock::schema_fields_PAYABLE_ID, $orderRef)
                    && !r43_subscription_exists(PaymentCheckoutSession::class, PaymentCheckoutSession::schema_fields_PAYABLE_ID, $orderRef)
                    && !r43_subscription_exists(PaymentIdempotency::class, PaymentIdempotency::schema_fields_PAYABLE_ID, $orderRef);
            }
            if ((string)$obligation['group_uuid'] !== '') {
                $cleaned = $cleaned && !r43_subscription_exists(CheckoutGroup::class, CheckoutGroup::schema_fields_CHECKOUT_GROUP_UUID, (string)$obligation['group_uuid']);
            }
            foreach ([PaymentProviderCommandOutbox::class, PaymentOutbox::class, PaymentWebhookInbox::class] as $class) {
                if ((string)$obligation['intent_code'] !== '') {
                    $cleaned = $cleaned && !r43_subscription_exists($class, $class::schema_fields_INTENT_CODE, (string)$obligation['intent_code']);
                }
                if ((string)$obligation['attempt_code'] !== '') {
                    $cleaned = $cleaned && !r43_subscription_exists($class, $class::schema_fields_ATTEMPT_CODE, (string)$obligation['attempt_code']);
                }
            }
        }
        r43_subscription_output([
            'ok' => $cleaned,
            ...$base,
            'cleaned' => $cleaned,
            'obligations' => $obligations,
            'payment_method_restore' => $paymentMethodRestore,
            'rollout_restore' => $rolloutRestore,
        ], $cleaned ? 0 : 1);
    }
    throw new InvalidArgumentException('unknown_action:' . $action);
} catch (Throwable $throwable) {
    if (is_string($activeToken) && $activeToken !== '') {
        try { r43_subscription_cleanup($activeToken); } catch (Throwable) {}
        try {
            if (is_array($activePaymentMethodBefore)) {
                r43_subscription_restore_payment_method($activePaymentMethodBefore);
            }
        } catch (Throwable) {}
        try {
            if (is_array($activeRolloutBefore)) {
                /** @var SubscriptionRolloutGate $recoveryGate */
                $recoveryGate = ObjectManager::getInstance(SubscriptionRolloutGate::class);
                r43_subscription_restore_rollout($recoveryGate, $activeRolloutBefore);
            }
        } catch (Throwable) {}
    }
    r43_subscription_output(['ok' => false, 'error' => $throwable->getMessage()], 1);
}
