<?php

declare(strict_types=1);

/**
 * R4.3 shipment/refund/invoice fixture.
 *
 * Only prerequisites, PostgreSQL inspection and token-owned cleanup live here.
 * The three decisive commands are intentionally absent and must be submitted
 * through the rendered backend workbenches by Playwright.
 */

use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Acl\Api\Authorization\ObjectScopeGrantStoreInterface;
use Weline\Acl\Model\ObjectScopeGrant;
use Weline\Acl\Model\Role;
use Weline\Acl\Model\RoleAccess;
use Weline\Backend\Api\User\BackendUserAdministrationInterface;
use Weline\Backend\Model\Backend\Acl\UserRole;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Connector as PgsqlConnector;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Order\Model\FulfillmentProgressLedger;
use Weline\Order\Model\FulfillmentUnit;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderInvoice;
use Weline\Order\Model\OrderItem;
use Weline\Order\Model\RefundCase;
use Weline\Order\Model\RefundOutbox;
use Weline\Order\Service\InvoiceService;
use Weline\Payment\Model\PaymentAttempt;
use Weline\Payment\Model\PaymentIntent;
use Weline\Payment\Model\PaymentLedger;
use Weline\Payment\Model\PaymentOutbox;
use Weline\Payment\Model\PaymentRefund;
use Weline\Queue\Model\Queue;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

const R43_TRADE_GRANT_VERSION = 43;
const R43_TRADE_ROLE_PREFIX = 'e2e_r43_order_trade_';
const R43_TRADE_USER_PREFIX = 'e2e_r43_ot_';
const R43_TRADE_ROLE_SOURCES = [
    'Weline_Backend::dashboard',
    'Weline_Backend::business_operations',
    'Weline_Backend::order_group',
    'Weline_Order::shipment_manage',
    'Weline_Order::shipment_execute',
    'Weline_Order::refund_manage',
    'Weline_Order::refund_execute',
    'Weline_Order::invoice_manage',
    'Weline_Order::invoice_execute',
];

/** @return array<string,mixed> */
function r43_trade_input(): array
{
    $decoded = json_decode((string)stream_get_contents(STDIN), true);
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new InvalidArgumentException('stdin_must_be_json_object');
    }

    return $decoded;
}

/** @param array<string,mixed> $payload */
function r43_trade_output(array $payload, int $code = 0): never
{
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ), "\n";
    exit($code);
}

/** @return array{database:string,connector:string} */
function r43_trade_guard(): array
{
    if ((string)getenv('WELINE_E2E_ISOLATED_DB') !== '1') {
        throw new RuntimeException('r43_trade_requires_WELINE_E2E_ISOLATED_DB_1');
    }
    $env = require dirname(__DIR__, 7) . '/app/etc/env.php';
    $db = is_array($env['db']['master'] ?? null)
        ? $env['db']['master']
        : (is_array($env['db'] ?? null) ? $env['db'] : []);
    $database = strtolower(trim((string)($db['database'] ?? '')));
    if (preg_match('/^mig_clone_[a-z0-9_]+$/D', $database) !== 1) {
        throw new RuntimeException('r43_trade_requires_mig_clone_database:' . $database);
    }
    $probe = ObjectManager::getInstance(SystemConfig::class, [], false);
    $connector = $probe->getConnection()->getConnector();
    if (!$connector instanceof PgsqlConnector) {
        throw new RuntimeException('r43_trade_requires_postgresql:' . get_class($connector));
    }

    return ['database' => $database, 'connector' => get_class($connector)];
}

/** @param class-string $class */
function r43_trade_model(string $class): object
{
    return ObjectManager::getInstance($class, [], false);
}

/** @param array<string,mixed> $where @return list<array<string,mixed>> */
function r43_trade_rows(object $model, array $where = []): array
{
    $query = $model->clear();
    foreach ($where as $field => $value) {
        $query->where((string)$field, $value);
    }
    $rows = $query->select()->fetchArray();

    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

/** @param array<string,mixed> $where */
function r43_trade_delete(object $model, array $where): int
{
    $rows = r43_trade_rows($model, $where);
    if ($rows === []) {
        return 0;
    }
    $query = $model->clear();
    foreach ($where as $field => $value) {
        $query->where((string)$field, $value);
    }
    $query->delete()->fetch();

    return count($rows);
}

function r43_trade_uuid(string $seed): string
{
    $hex = substr(hash('sha256', $seed), 0, 32);
    $hex[12] = '4';
    $hex[16] = '8';

    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
        . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-'
        . substr($hex, 20, 12);
}

/** @return array<string,string> */
function r43_trade_identity(string $rawToken): array
{
    $token = strtolower(preg_replace('/[^a-z0-9]/', '', $rawToken) ?: '');
    if ($token === '') {
        throw new InvalidArgumentException('r43_trade_token_required');
    }
    $suffix = substr(hash('sha256', $token), 0, 16);

    return [
        'token' => $token,
        'suffix' => $suffix,
        'order_uuid' => r43_trade_uuid('order:' . $suffix),
        'item_uuid' => r43_trade_uuid('item:' . $suffix),
        'unit_uuid' => r43_trade_uuid('unit:' . $suffix),
        'order_number' => 'R43-TRADE-' . strtoupper($suffix),
        'intent_code' => 'r43_int_' . $suffix,
        'attempt_code' => 'r43_att_' . $suffix,
        'invoice_outbox_code' => 'r43_po_' . $suffix,
        'shipment_idempotency_key' => 'r43.ship.' . $suffix,
        'refund_idempotency_key' => 'r43.refund.' . $suffix,
    ];
}

/** @return array{id:int,website_id:int,website_code:string,code:string,store_mode:string} */
function r43_trade_store(): array
{
    $store = r43_trade_model(Store::class)
        ->where(Store::schema_fields_STATUS, 1)
        ->where(Store::schema_fields_LIFECYCLE_STATUS, Store::LIFECYCLE_ACTIVE)
        ->order(Store::schema_fields_IS_DEFAULT, 'DESC')
        ->order(Store::schema_fields_ID, 'ASC')
        ->find()
        ->fetch();
    if (!$store instanceof Store || !$store->getId()) {
        throw new RuntimeException('r43_trade_active_store_required');
    }

    $websiteId = (int)$store->getData(Store::schema_fields_WEBSITE_ID);
    $website = r43_trade_model(Website::class)->load($websiteId);
    $websiteCode = $website instanceof Website
        ? strtolower(trim((string)$website->getData(Website::schema_fields_CODE)))
        : '';
    if ($websiteCode === '' && $websiteId === 0) {
        $websiteCode = 'default';
    }
    if ($websiteCode === '') {
        throw new RuntimeException('r43_trade_active_website_required');
    }

    return [
        'id' => (int)$store->getId(),
        'website_id' => $websiteId,
        'website_code' => $websiteCode,
        'code' => strtolower((string)$store->getData(Store::schema_fields_CODE)),
        'store_mode' => strtolower(trim((string)$store->getData(Store::schema_fields_STORE_MODE)))
            ?: Store::MODE_NORMAL,
    ];
}

/** @param array<string,string> $id @return array{role_name:string,username:string,role_id:int,user_id:int} */
function r43_trade_cleanup_admin_identity(array $id): array
{
    $roleName = R43_TRADE_ROLE_PREFIX . $id['suffix'];
    $username = R43_TRADE_USER_PREFIX . $id['suffix'];
    $roleRows = r43_trade_rows(r43_trade_model(Role::class), [
        Role::schema_fields_ROLE_NAME => $roleName,
    ]);
    $roleId = (int)($roleRows[0][Role::schema_fields_ROLE_ID] ?? 0);
    $userRows = r43_trade_rows(r43_trade_model(BackendUser::class), [
        BackendUser::schema_fields_username => $username,
    ]);
    $userId = (int)($userRows[0][BackendUser::schema_fields_ID] ?? 0);
    if (($roleId > 0 && $roleId <= 1) || ($userId > 0 && $userId <= 1)) {
        throw new RuntimeException('r43_trade_refuses_protected_admin_identity');
    }

    if ($userId > 1) {
        r43_trade_delete(r43_trade_model(UserRole::class), [
            UserRole::schema_fields_USER_ID => $userId,
        ]);
        r43_trade_delete(r43_trade_model(BackendUser::class), [
            BackendUser::schema_fields_ID => $userId,
        ]);
    }
    if ($roleId > 1) {
        r43_trade_delete(r43_trade_model(ObjectScopeGrant::class), [
            ObjectScopeGrant::schema_fields_ROLE_ID => $roleId,
        ]);
        r43_trade_delete(r43_trade_model(RoleAccess::class), [
            RoleAccess::schema_fields_ROLE_ID => $roleId,
        ]);
        r43_trade_delete(r43_trade_model(UserRole::class), [
            UserRole::schema_fields_ROLE_ID => $roleId,
        ]);
        r43_trade_delete(r43_trade_model(Role::class), [
            Role::schema_fields_ROLE_ID => $roleId,
        ]);
    }
    try {
        w_cache('acl')->clear();
    } catch (Throwable) {
        // Fresh browser sessions still make this best-effort cache clear safe.
    }

    return [
        'role_name' => $roleName,
        'username' => $username,
        'role_id' => $roleId,
        'user_id' => $userId,
    ];
}

/**
 * @param array<string,string> $id
 * @param array{id:int,website_id:int,website_code:string,code:string,store_mode:string} $store
 * @return array{role_id:int,user_id:int,username:string,password:string,grant_version:int}
 */
function r43_trade_prepare_admin_identity(array $id, array $store): array
{
    r43_trade_cleanup_admin_identity($id);
    $roleName = R43_TRADE_ROLE_PREFIX . $id['suffix'];
    $username = R43_TRADE_USER_PREFIX . $id['suffix'];
    $password = 'R43Order!' . substr(hash('sha256', $id['token']), 0, 16);

    /** @var Role $role */
    $role = r43_trade_model(Role::class);
    $role->setRoleName($roleName)
        ->setRoleDescription('R4.3 isolated Order trade browser role')
        ->save(true);
    $roleId = (int)$role->getId();
    if ($roleId <= 1) {
        throw new RuntimeException('r43_trade_role_not_persisted');
    }

    $accessRows = [];
    foreach (R43_TRADE_ROLE_SOURCES as $sourceId) {
        $accessRows[] = [
            RoleAccess::schema_fields_ROLE_ID => $roleId,
            RoleAccess::schema_fields_SOURCE_ID => $sourceId,
        ];
    }
    r43_trade_model(RoleAccess::class)->insert($accessRows, [
        RoleAccess::schema_fields_ROLE_ID,
        RoleAccess::schema_fields_SOURCE_ID,
    ])->fetch();

    r43_trade_model(ObjectScopeGrant::class)->setData([
        ObjectScopeGrant::schema_fields_ROLE_ID => $roleId,
        ObjectScopeGrant::schema_fields_IS_ALL_SITES => 0,
        ObjectScopeGrant::schema_fields_SCOPE_KIND => ScopeIdentity::KIND_STORE,
        ObjectScopeGrant::schema_fields_WEBSITE_ID => $store['website_id'],
        ObjectScopeGrant::schema_fields_WEBSITE_CODE => $store['website_code'],
        ObjectScopeGrant::schema_fields_STORE_CODE => $store['code'],
        ObjectScopeGrant::schema_fields_CHANNEL_CODE => null,
        ObjectScopeGrant::schema_fields_ACTIONS => json_encode([
            ObjectAction::LIST,
            ObjectAction::VIEW,
            ObjectAction::FULFILL,
            ObjectAction::REFUND,
            ObjectAction::UPDATE,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ObjectScopeGrant::schema_fields_GRANT_VERSION => R43_TRADE_GRANT_VERSION,
    ])->save(true);

    /** @var BackendUserAdministrationInterface $users */
    $users = ObjectManager::getInstance(BackendUserAdministrationInterface::class);
    $record = $users->save(null, $username, $username . '@example.test', $password);
    $userId = (int)$record->getId();
    if ($userId <= 1) {
        throw new RuntimeException('r43_trade_backend_user_not_persisted');
    }
    $users->assignRole($userId, $roleId);
    $users->setState($userId, true, false);

    /** @var ObjectScopeGrantStoreInterface $grantStore */
    $grantStore = ObjectManager::getInstance(ObjectScopeGrantStoreInterface::class);
    $grants = $grantStore->findByRole($roleId);
    if (count($grants) !== 1
        || !$grants[0]->covers(ScopeIdentity::store(
            $store['website_id'],
            $store['website_code'],
            $store['code'],
            $store['store_mode'],
        ))
    ) {
        throw new RuntimeException('r43_trade_object_grant_not_hydrated');
    }
    try {
        w_cache('acl')->clear();
    } catch (Throwable) {
        // Fresh browser sessions still make this best-effort cache clear safe.
    }

    return [
        'role_id' => $roleId,
        'user_id' => $userId,
        'username' => $username,
        'password' => $password,
        'grant_version' => R43_TRADE_GRANT_VERSION,
    ];
}

/** @param array<string,string> $id @return array<string,mixed> */
function r43_trade_prepare(array $id): array
{
    r43_trade_cleanup($id);
    $store = r43_trade_store();
    $now = date('Y-m-d H:i:s');
    $money = json_encode([
        'subtotal_minor' => 1000,
        'shipping_amount_minor' => 0,
        'tax_amount_minor' => 0,
        'discount_amount_minor' => 0,
        'grand_total_minor' => 1000,
        'currency' => 'CNY',
        'precision' => 2,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    /** @var Order $order */
    $order = r43_trade_model(Order::class);
    $order->setData([
        Order::schema_fields_ORDER_NUMBER => $id['order_number'],
        Order::schema_fields_CUSTOMER_ID => null,
        Order::schema_fields_STATUS => Order::STATUS_PAID,
        Order::schema_fields_STATE => Order::STATUS_PAID,
        Order::schema_fields_GRAND_TOTAL => '10.00',
        Order::schema_fields_SUBTOTAL => '10.00',
        Order::schema_fields_SHIPPING_AMOUNT => '0.00',
        Order::schema_fields_TAX_AMOUNT => '0.00',
        Order::schema_fields_DISCOUNT_AMOUNT => '0.00',
        Order::schema_fields_CURRENCY => 'CNY',
        Order::schema_fields_SOURCE_APP => 'r43_e2e',
        Order::schema_fields_SOURCE_MODULE => 'Weline_Order',
        Order::schema_fields_BUSINESS_CODE => 'r43-trade-' . $id['suffix'],
        Order::schema_fields_BUSINESS_NAME => 'R43 trade command fixture',
        Order::schema_fields_PAYMENT_STATUS => Order::PAYMENT_STATUS_PAID,
        Order::schema_fields_FULFILLMENT_STATUS => Order::FULFILLMENT_STATUS_PENDING,
        Order::schema_fields_SHIPPING_ADDRESS => '{}',
        Order::schema_fields_BILLING_ADDRESS => '{}',
        Order::schema_fields_CUSTOMER_EMAIL => 'r43-' . $id['suffix'] . '@example.test',
        Order::schema_fields_CUSTOMER_NAME => 'R43 Trade Buyer',
        Order::schema_fields_CUSTOMER_PHONE => '13800138000',
        Order::schema_fields_SHIPPING_METHOD => 'r43_sandbox_shipping',
        Order::schema_fields_PAYMENT_METHOD => 'fake_card',
        Order::schema_fields_NOTES => 'R43 owned fixture ' . $id['token'],
        Order::schema_fields_CREATED_AT => $now,
        Order::schema_fields_UPDATED_AT => $now,
        Order::schema_fields_ORDER_UUID => $id['order_uuid'],
        Order::schema_fields_CHECKOUT_GROUP_UUID => null,
        Order::schema_fields_WEBSITE_ID => $store['website_id'],
        Order::schema_fields_STORE_ID => $store['id'],
        Order::schema_fields_MONEY_SNAPSHOT_JSON => $money,
        Order::schema_fields_CATALOG_SNAPSHOT_JSON => '{}',
        Order::schema_fields_SCOPE_SNAPSHOT_JSON => json_encode([
            'website_id' => $store['website_id'],
            'store_id' => $store['id'],
            'store_code' => $store['code'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        Order::schema_fields_TAX_SNAPSHOT_JSON => null,
        Order::schema_fields_SHIPPING_SNAPSHOT_JSON => '{}',
        Order::schema_fields_IS_SHIPPING_CHARGE_OWNER => 0,
        Order::schema_fields_SPLIT_KEY => 'r43-trade',
        Order::schema_fields_STATE_VERSION => 0,
    ])->save();
    $orderId = (int)$order->getId();
    if ($orderId <= 0) {
        throw new RuntimeException('r43_trade_order_not_persisted');
    }

    r43_trade_model(OrderItem::class)->setData([
        OrderItem::schema_fields_ORDER_ID => $orderId,
        OrderItem::schema_fields_PRODUCT_ID => null,
        OrderItem::schema_fields_PRODUCT_SKU => 'R43-' . strtoupper($id['suffix']),
        OrderItem::schema_fields_PRODUCT_NAME => 'R43 Refundable Product',
        OrderItem::schema_fields_PRODUCT_TYPE => 'simple',
        OrderItem::schema_fields_SOURCE_APP => 'r43_e2e',
        OrderItem::schema_fields_SOURCE_MODULE => 'Weline_Order',
        OrderItem::schema_fields_BUSINESS_CODE => 'r43-item-' . $id['suffix'],
        OrderItem::schema_fields_BUSINESS_NAME => 'R43 refundable item',
        OrderItem::schema_fields_QTY_ORDERED => '2.00',
        OrderItem::schema_fields_QTY_SHIPPED => '0.00',
        OrderItem::schema_fields_QTY_REFUNDED => '0.00',
        OrderItem::schema_fields_QTY_CANCELLED => '0.00',
        OrderItem::schema_fields_PRICE => '5.00',
        OrderItem::schema_fields_ROW_TOTAL => '10.00',
        OrderItem::schema_fields_DISCOUNT_AMOUNT => '0.00',
        OrderItem::schema_fields_TAX_AMOUNT => '0.00',
        OrderItem::schema_fields_CREATED_AT => $now,
        OrderItem::schema_fields_UPDATED_AT => $now,
        OrderItem::schema_fields_ITEM_UUID => $id['item_uuid'],
        OrderItem::schema_fields_ORDER_UUID => $id['order_uuid'],
        OrderItem::schema_fields_OFFER_ID => null,
        OrderItem::schema_fields_QTY_MINOR => 2,
        OrderItem::schema_fields_UNIT_PRICE_MINOR => 500,
        OrderItem::schema_fields_CATALOG_LINE_SNAPSHOT_JSON => '{}',
        OrderItem::schema_fields_TAX_SNAPSHOT_JSON => null,
    ])->save();

    r43_trade_model(FulfillmentUnit::class)->setData([
        FulfillmentUnit::schema_fields_FULFILLMENT_UNIT_UUID => $id['unit_uuid'],
        FulfillmentUnit::schema_fields_ORDER_UUID => $id['order_uuid'],
        FulfillmentUnit::schema_fields_CHECKOUT_GROUP_UUID => null,
        FulfillmentUnit::schema_fields_STATUS => FulfillmentUnit::STATUS_PENDING,
        FulfillmentUnit::schema_fields_WAREHOUSE_ID => 43,
        FulfillmentUnit::schema_fields_WAREHOUSE_SOURCE => 'warehouse',
        FulfillmentUnit::schema_fields_ALLOCATIONS_JSON => json_encode([[
            'item_uuid' => $id['item_uuid'],
            'qty_minor' => 2,
        ]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        FulfillmentUnit::schema_fields_QTY_MINOR => 2,
        FulfillmentUnit::schema_fields_FULFILLED_QTY_MINOR => 0,
        FulfillmentUnit::schema_fields_FULFILLMENT_VERSION => 0,
        FulfillmentUnit::schema_fields_CREATED_AT => $now,
        FulfillmentUnit::schema_fields_UPDATED_AT => $now,
    ])->save();

    $scope = $store['website_id'] . '.' . $store['id'] . '.default';
    r43_trade_model(PaymentIntent::class)->setData([
        PaymentIntent::schema_fields_INTENT_CODE => $id['intent_code'],
        PaymentIntent::schema_fields_ENVIRONMENT => 'sandbox',
        PaymentIntent::schema_fields_PAYABLE_TYPE => 'order',
        PaymentIntent::schema_fields_PAYABLE_ID => $id['order_uuid'],
        PaymentIntent::schema_fields_METHOD_CODE => 'fake_card',
        PaymentIntent::schema_fields_PROVIDER_CODE => 'fake',
        PaymentIntent::schema_fields_MERCHANT_ACCOUNT => 'r43_sandbox',
        PaymentIntent::schema_fields_SCOPE => $scope,
        PaymentIntent::schema_fields_AMOUNT_MINOR => 1000,
        PaymentIntent::schema_fields_CURRENCY_CODE => 'CNY',
        PaymentIntent::schema_fields_PRECISION => 2,
        PaymentIntent::schema_fields_STATUS => PaymentIntent::STATUS_CAPTURED,
        PaymentIntent::schema_fields_ACTIVE_FLAG => 0,
        PaymentIntent::schema_fields_ACTIVE_GUARD => null,
        PaymentIntent::schema_fields_REQUEST_HASH => hash('sha256', 'intent:' . $id['suffix']),
        PaymentIntent::schema_fields_IDEMPOTENCY_KEY => 'r43.intent.' . $id['suffix'],
        PaymentIntent::schema_fields_CREATED_AT => $now,
        PaymentIntent::schema_fields_UPDATED_AT => $now,
    ])->save();

    r43_trade_model(PaymentAttempt::class)->setData([
        PaymentAttempt::schema_fields_ATTEMPT_CODE => $id['attempt_code'],
        PaymentAttempt::schema_fields_INTENT_CODE => $id['intent_code'],
        PaymentAttempt::schema_fields_ENVIRONMENT => 'sandbox',
        PaymentAttempt::schema_fields_PAYABLE_TYPE => 'order',
        PaymentAttempt::schema_fields_PAYABLE_ID => $id['order_uuid'],
        PaymentAttempt::schema_fields_METHOD_CODE => 'fake_card',
        PaymentAttempt::schema_fields_PROVIDER_CODE => 'fake',
        PaymentAttempt::schema_fields_MERCHANT_ACCOUNT => 'r43_sandbox',
        PaymentAttempt::schema_fields_SCOPE => $scope,
        PaymentAttempt::schema_fields_PAYMENT_CURRENCY_CODE => 'CNY',
        PaymentAttempt::schema_fields_AMOUNT_MINOR => 1000,
        PaymentAttempt::schema_fields_PRECISION => 2,
        PaymentAttempt::schema_fields_STATUS => PaymentAttempt::STATUS_SUCCEEDED,
        PaymentAttempt::schema_fields_NONTERMINAL_GUARD => null,
        PaymentAttempt::schema_fields_VERSION => 1,
        PaymentAttempt::schema_fields_CAS_TOKEN => 'r43-captured-' . $id['suffix'],
        PaymentAttempt::schema_fields_USER_CONFIRMED => 1,
        PaymentAttempt::schema_fields_IDEMPOTENCY_KEY => 'r43.attempt.' . $id['suffix'],
        PaymentAttempt::schema_fields_PROVIDER_REFERENCE => 'r43_tx_' . $id['suffix'],
        PaymentAttempt::schema_fields_PROVIDER_REFERENCE_GUARD => hash(
            'sha256',
            'r43_tx_' . $id['suffix'],
        ),
        PaymentAttempt::schema_fields_PROVIDER_REQUEST_KEY => 'r43.submit.' . $id['suffix'],
        PaymentAttempt::schema_fields_CREATED_AT => $now,
        PaymentAttempt::schema_fields_CLOSED_AT => $now,
    ])->save();

    $effectKey = InvoiceService::effectKeyForAttempt($id['attempt_code']);
    r43_trade_model(PaymentOutbox::class)->setData([
        PaymentOutbox::schema_fields_OUTBOX_CODE => $id['invoice_outbox_code'],
        PaymentOutbox::schema_fields_EFFECT_KEY => $effectKey,
        PaymentOutbox::schema_fields_INBOX_CODE => null,
        PaymentOutbox::schema_fields_INTENT_CODE => $id['intent_code'],
        PaymentOutbox::schema_fields_ATTEMPT_CODE => $id['attempt_code'],
        PaymentOutbox::schema_fields_EFFECT_TYPE => InvoiceService::EFFECT_TYPE,
        PaymentOutbox::schema_fields_STATUS => PaymentOutbox::STATUS_PENDING,
        PaymentOutbox::schema_fields_PAYLOAD_JSON => json_encode([
            'payable_type' => 'order',
            'payable_id' => $id['order_uuid'],
            'schema_version' => '1',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        PaymentOutbox::schema_fields_CREATED_AT => $now,
    ])->save();

    $admin = r43_trade_prepare_admin_identity($id, $store);

    return $id + [
        'order_id' => $orderId,
        'store_id' => $store['id'],
        'website_id' => $store['website_id'],
        'invoice_effect_key' => $effectKey,
        'admin' => $admin,
    ];
}

/** @param array<string,string> $id @return array<string,mixed> */
function r43_trade_inspect(array $id): array
{
    $orderRows = r43_trade_rows(r43_trade_model(Order::class), [
        Order::schema_fields_ORDER_UUID => $id['order_uuid'],
    ]);
    $orderId = (int)($orderRows[0][Order::schema_fields_ID] ?? 0);
    $unitRows = r43_trade_rows(r43_trade_model(FulfillmentUnit::class), [
        FulfillmentUnit::schema_fields_FULFILLMENT_UNIT_UUID => $id['unit_uuid'],
    ]);
    $ledgerRows = r43_trade_rows(r43_trade_model(FulfillmentProgressLedger::class), [
        FulfillmentProgressLedger::schema_fields_UNIT_UUID => $id['unit_uuid'],
    ]);
    $caseRows = r43_trade_rows(r43_trade_model(RefundCase::class), [
        RefundCase::schema_fields_ORDER_UUID => $id['order_uuid'],
    ]);
    $paymentRefundRows = r43_trade_rows(r43_trade_model(PaymentRefund::class), [
        PaymentRefund::schema_fields_PAYABLE_ID => $id['order_uuid'],
    ]);
    $refundOutboxRows = [];
    foreach ($caseRows as $case) {
        $refundOutboxRows = array_merge($refundOutboxRows, r43_trade_rows(
            r43_trade_model(RefundOutbox::class),
            [RefundOutbox::schema_fields_REFUND_CASE_UUID => (string)($case[
                RefundCase::schema_fields_REFUND_CASE_UUID
            ] ?? '')],
        ));
    }
    $providerRefundOutboxRows = array_values(array_filter(
        $refundOutboxRows,
        static fn(array $row): bool => (string)($row[
            RefundOutbox::schema_fields_OPERATION
        ] ?? '') === RefundOutbox::OPERATION_PROVIDER_REFUND,
    ));
    $invoiceRows = $orderId > 0 ? r43_trade_rows(r43_trade_model(OrderInvoice::class), [
        OrderInvoice::schema_fields_ORDER_ID => $orderId,
    ]) : [];
    $paymentOutboxRows = r43_trade_rows(r43_trade_model(PaymentOutbox::class), [
        PaymentOutbox::schema_fields_OUTBOX_CODE => $id['invoice_outbox_code'],
    ]);
    $queueCount = 0;
    foreach ($refundOutboxRows as $row) {
        $queueCount += count(r43_trade_rows(r43_trade_model(Queue::class), [
            Queue::schema_fields_BIZ_KEY => (string)($row[
                RefundOutbox::schema_fields_EFFECT_KEY
            ] ?? ''),
        ]));
    }
    $providerQueueCount = 0;
    foreach ($providerRefundOutboxRows as $row) {
        $providerQueueCount += count(r43_trade_rows(r43_trade_model(Queue::class), [
            Queue::schema_fields_BIZ_KEY => (string)($row[
                RefundOutbox::schema_fields_EFFECT_KEY
            ] ?? ''),
        ]));
    }

    return [
        'order_count' => count($orderRows),
        'fulfillment' => $unitRows[0] ?? null,
        'shipment_ledger_count' => count($ledgerRows),
        'shipment_ledger' => $ledgerRows[0] ?? null,
        'refund_case_count' => count($caseRows),
        'refund_case' => $caseRows[0] ?? null,
        'payment_refund_count' => count($paymentRefundRows),
        'payment_refund' => $paymentRefundRows[0] ?? null,
        'refund_outbox_count' => count($refundOutboxRows),
        'refund_outbox' => $refundOutboxRows[0] ?? null,
        'refund_queue_count' => $queueCount,
        'refund_provider_outbox_count' => count($providerRefundOutboxRows),
        'refund_provider_outbox' => $providerRefundOutboxRows[0] ?? null,
        'refund_provider_queue_count' => $providerQueueCount,
        'invoice_count' => count($invoiceRows),
        'invoice' => $invoiceRows[0] ?? null,
        'invoice_outbox_status' => (string)($paymentOutboxRows[0][
            PaymentOutbox::schema_fields_STATUS
        ] ?? ''),
    ];
}

/** @param array<string,string> $id @return array<string,mixed> */
function r43_trade_cleanup(array $id): array
{
    if (!str_starts_with($id['order_number'], 'R43-TRADE-')
        || !str_starts_with($id['intent_code'], 'r43_int_')
    ) {
        throw new RuntimeException('r43_trade_cleanup_identity_rejected');
    }
    $orderRows = r43_trade_rows(r43_trade_model(Order::class), [
        Order::schema_fields_ORDER_UUID => $id['order_uuid'],
    ]);
    $orderId = (int)($orderRows[0][Order::schema_fields_ID] ?? 0);
    $caseRows = r43_trade_rows(r43_trade_model(RefundCase::class), [
        RefundCase::schema_fields_ORDER_UUID => $id['order_uuid'],
    ]);
    $caseUuids = array_values(array_filter(array_map(
        static fn(array $row): string => (string)($row[
            RefundCase::schema_fields_REFUND_CASE_UUID
        ] ?? ''),
        $caseRows,
    )));
    $refundOutboxRows = [];
    foreach ($caseUuids as $caseUuid) {
        $refundOutboxRows = array_merge($refundOutboxRows, r43_trade_rows(
            r43_trade_model(RefundOutbox::class),
            [RefundOutbox::schema_fields_REFUND_CASE_UUID => $caseUuid],
        ));
    }
    foreach ($refundOutboxRows as $row) {
        $bizKey = (string)($row[RefundOutbox::schema_fields_EFFECT_KEY] ?? '');
        if ($bizKey === '' || !str_starts_with($bizKey, 'refund:')) {
            continue;
        }
        $queues = r43_trade_rows(r43_trade_model(Queue::class), [
            Queue::schema_fields_BIZ_KEY => $bizKey,
        ]);
        foreach ($queues as $queue) {
            w_query('queue', 'delete', [
                'queue_id' => (int)($queue[Queue::schema_fields_ID] ?? 0),
                'force' => true,
                'owner' => 'r43-order-trade-fixture',
                'reason' => 'isolated R4.3 cleanup',
            ]);
        }
    }

    foreach ($caseUuids as $caseUuid) {
        r43_trade_delete(r43_trade_model(RefundOutbox::class), [
            RefundOutbox::schema_fields_REFUND_CASE_UUID => $caseUuid,
        ]);
    }
    r43_trade_delete(r43_trade_model(PaymentLedger::class), [
        PaymentLedger::schema_fields_INTENT_CODE => $id['intent_code'],
    ]);
    r43_trade_delete(r43_trade_model(PaymentRefund::class), [
        PaymentRefund::schema_fields_PAYABLE_ID => $id['order_uuid'],
    ]);
    r43_trade_delete(r43_trade_model(RefundCase::class), [
        RefundCase::schema_fields_ORDER_UUID => $id['order_uuid'],
    ]);
    if ($orderId > 0) {
        r43_trade_delete(r43_trade_model(OrderInvoice::class), [
            OrderInvoice::schema_fields_ORDER_ID => $orderId,
        ]);
    }
    r43_trade_delete(r43_trade_model(PaymentOutbox::class), [
        PaymentOutbox::schema_fields_OUTBOX_CODE => $id['invoice_outbox_code'],
    ]);
    r43_trade_delete(r43_trade_model(FulfillmentProgressLedger::class), [
        FulfillmentProgressLedger::schema_fields_UNIT_UUID => $id['unit_uuid'],
    ]);
    r43_trade_delete(r43_trade_model(FulfillmentUnit::class), [
        FulfillmentUnit::schema_fields_FULFILLMENT_UNIT_UUID => $id['unit_uuid'],
    ]);
    r43_trade_delete(r43_trade_model(PaymentAttempt::class), [
        PaymentAttempt::schema_fields_ATTEMPT_CODE => $id['attempt_code'],
    ]);
    r43_trade_delete(r43_trade_model(PaymentIntent::class), [
        PaymentIntent::schema_fields_INTENT_CODE => $id['intent_code'],
    ]);
    r43_trade_delete(r43_trade_model(OrderItem::class), [
        OrderItem::schema_fields_ITEM_UUID => $id['item_uuid'],
    ]);
    r43_trade_delete(r43_trade_model(Order::class), [
        Order::schema_fields_ORDER_UUID => $id['order_uuid'],
    ]);
    $adminIdentity = r43_trade_cleanup_admin_identity($id);

    $remaining = [
        'orders' => count(r43_trade_rows(r43_trade_model(Order::class), [
            Order::schema_fields_ORDER_UUID => $id['order_uuid'],
        ])),
        'items' => count(r43_trade_rows(r43_trade_model(OrderItem::class), [
            OrderItem::schema_fields_ITEM_UUID => $id['item_uuid'],
        ])),
        'fulfillment_units' => count(r43_trade_rows(r43_trade_model(FulfillmentUnit::class), [
            FulfillmentUnit::schema_fields_FULFILLMENT_UNIT_UUID => $id['unit_uuid'],
        ])),
        'fulfillment_ledger' => count(r43_trade_rows(r43_trade_model(FulfillmentProgressLedger::class), [
            FulfillmentProgressLedger::schema_fields_UNIT_UUID => $id['unit_uuid'],
        ])),
        'refund_cases' => count(r43_trade_rows(r43_trade_model(RefundCase::class), [
            RefundCase::schema_fields_ORDER_UUID => $id['order_uuid'],
        ])),
        'payment_refunds' => count(r43_trade_rows(r43_trade_model(PaymentRefund::class), [
            PaymentRefund::schema_fields_PAYABLE_ID => $id['order_uuid'],
        ])),
        'payment_ledger' => count(r43_trade_rows(r43_trade_model(PaymentLedger::class), [
            PaymentLedger::schema_fields_INTENT_CODE => $id['intent_code'],
        ])),
        'payment_outbox' => count(r43_trade_rows(r43_trade_model(PaymentOutbox::class), [
            PaymentOutbox::schema_fields_OUTBOX_CODE => $id['invoice_outbox_code'],
        ])),
        'payment_attempts' => count(r43_trade_rows(r43_trade_model(PaymentAttempt::class), [
            PaymentAttempt::schema_fields_ATTEMPT_CODE => $id['attempt_code'],
        ])),
        'payment_intents' => count(r43_trade_rows(r43_trade_model(PaymentIntent::class), [
            PaymentIntent::schema_fields_INTENT_CODE => $id['intent_code'],
        ])),
        'invoices' => $orderId > 0
            ? count(r43_trade_rows(r43_trade_model(OrderInvoice::class), [
                OrderInvoice::schema_fields_ORDER_ID => $orderId,
            ]))
            : 0,
        'backend_roles' => count(r43_trade_rows(r43_trade_model(Role::class), [
            Role::schema_fields_ROLE_NAME => $adminIdentity['role_name'],
        ])),
        'backend_users' => count(r43_trade_rows(r43_trade_model(BackendUser::class), [
            BackendUser::schema_fields_username => $adminIdentity['username'],
        ])),
        'role_access' => $adminIdentity['role_id'] > 0
            ? count(r43_trade_rows(r43_trade_model(RoleAccess::class), [
                RoleAccess::schema_fields_ROLE_ID => $adminIdentity['role_id'],
            ]))
            : 0,
        'object_grants' => $adminIdentity['role_id'] > 0
            ? count(r43_trade_rows(r43_trade_model(ObjectScopeGrant::class), [
                ObjectScopeGrant::schema_fields_ROLE_ID => $adminIdentity['role_id'],
            ]))
            : 0,
        'user_roles_by_user' => $adminIdentity['user_id'] > 0
            ? count(r43_trade_rows(r43_trade_model(UserRole::class), [
                UserRole::schema_fields_USER_ID => $adminIdentity['user_id'],
            ]))
            : 0,
        'user_roles_by_role' => $adminIdentity['role_id'] > 0
            ? count(r43_trade_rows(r43_trade_model(UserRole::class), [
                UserRole::schema_fields_ROLE_ID => $adminIdentity['role_id'],
            ]))
            : 0,
    ];
    $remaining['refund_outbox'] = 0;
    $remaining['queues'] = 0;
    foreach ($caseUuids as $caseUuid) {
        $remaining['refund_outbox'] += count(r43_trade_rows(
            r43_trade_model(RefundOutbox::class),
            [RefundOutbox::schema_fields_REFUND_CASE_UUID => $caseUuid],
        ));
    }
    foreach ($refundOutboxRows as $row) {
        $remaining['queues'] += count(r43_trade_rows(r43_trade_model(Queue::class), [
            Queue::schema_fields_BIZ_KEY => (string)($row[
                RefundOutbox::schema_fields_EFFECT_KEY
            ] ?? ''),
        ]));
    }
    if (array_sum($remaining) !== 0) {
        throw new RuntimeException('r43_trade_cleanup_incomplete:' . json_encode($remaining));
    }

    return ['remaining' => $remaining];
}

try {
    $environment = r43_trade_guard();
    $input = r43_trade_input();
    $id = r43_trade_identity((string)($input['token'] ?? ''));
    $action = trim((string)($input['action'] ?? ''));
    if ($action === 'prepare') {
        try {
            $fixture = r43_trade_prepare($id);
        } catch (Throwable $throwable) {
            try {
                r43_trade_cleanup($id);
            } catch (Throwable) {
                // Preserve the original prepare failure; the next run is still
                // protected by token-scoped cleanup before inserting anything.
            }
            throw $throwable;
        }
        r43_trade_output([
            'ok' => true,
            'environment' => $environment,
            'fixture' => $fixture,
        ]);
    }
    if ($action === 'inspect') {
        r43_trade_output(['ok' => true, 'data' => r43_trade_inspect($id)]);
    }
    if ($action === 'cleanup') {
        r43_trade_output(['ok' => true, 'data' => r43_trade_cleanup($id)]);
    }
    throw new InvalidArgumentException('unsupported action:' . $action);
} catch (Throwable $throwable) {
    r43_trade_output([
        'ok' => false,
        'error' => $throwable->getMessage(),
        'class' => $throwable::class,
    ], 1);
}
