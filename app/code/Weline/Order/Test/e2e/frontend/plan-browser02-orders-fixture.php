<?php
declare(strict_types=1);

/**
 * TEST-BROWSER-02 fixture：真实顾客 + partial CheckoutGroup（退款分叉 + 发票）
 *
 * stdin JSON: { "action": "prepare"|"cleanup", "token"?: string, "customer_id"?: int, "group_uuid"?: string, "order_ids"?: int[] }
 * stdout JSON: ok + credentials / ids
 */

use Weline\Customer\Service\CustomerAccountService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\CheckoutGroup;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderInvoice;
use Weline\Order\Model\RefundCase;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

const BROWSER02_EMAIL_PREFIX = 'e2e.browser02.';
const BROWSER02_PASSWORD = 'Browser02Pass9';

/**
 * @return array<string, mixed>
 */
function browser02_read_input(): array
{
    $raw = stream_get_contents(STDIN);
    $decoded = json_decode($raw !== false && $raw !== '' ? $raw : '{}', true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<string, mixed> $payload
 */
function browser02_output(array $payload): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

function browser02_fail(string $message): never
{
    browser02_output(['ok' => false, 'error' => $message]);
}

function browser02_uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * @return array<string, mixed>
 */
function browser02_prepare(?string $token): array
{
    $om = ObjectManager::getInstance();
    $token = $token !== null && $token !== '' ? preg_replace('/[^a-zA-Z0-9]/', '', $token) : '';
    if ($token === null || $token === '') {
        $token = substr(bin2hex(random_bytes(6)), 0, 10);
    }
    $email = BROWSER02_EMAIL_PREFIX . strtolower($token) . '@example.test';
    $password = BROWSER02_PASSWORD;

    /** @var CustomerAccountService $accounts */
    $accounts = $om->get(CustomerAccountService::class);
    $existing = $accounts->findByEmail($email);
    if ($existing !== null && $existing->getId()) {
        browser02_cleanup((int) $existing->getId(), null, []);
    }

    $registered = $accounts->register($email, $password, [
        'firstname' => 'E2E',
        'lastname' => 'Browser02',
    ]);
    /** @var \Weline\Customer\Model\Customer $customer */
    $customer = $registered['customer'];
    $customerId = (int) $customer->getId();
    if ($customerId <= 0) {
        browser02_fail('customer register failed');
    }

    $groupUuid = browser02_uuid();
    $orderUuidA = browser02_uuid();
    $orderUuidB = browser02_uuid();
    $websiteId = 0;
    $storeId = 0;
    $now = date('Y-m-d H:i:s');

    /** @var CheckoutGroup $group */
    $group = clone $om->get(CheckoutGroup::class);
    $group->clearData()->setData([
        CheckoutGroup::schema_fields_CHECKOUT_GROUP_UUID => $groupUuid,
        CheckoutGroup::schema_fields_WEBSITE_ID => $websiteId,
        CheckoutGroup::schema_fields_STORE_ID => $storeId,
        CheckoutGroup::schema_fields_CURRENCY => 'CNY',
        CheckoutGroup::schema_fields_STATUS => CheckoutGroup::STATUS_PAID,
        CheckoutGroup::schema_fields_IDEMPOTENCY_KEY => 'e2e-browser02-' . $token,
        CheckoutGroup::schema_fields_REQUEST_HASH => hash('sha256', $groupUuid),
        CheckoutGroup::schema_fields_GRAND_TOTAL_MINOR => 10000,
        CheckoutGroup::schema_fields_CREATED_AT => $now,
        CheckoutGroup::schema_fields_UPDATED_AT => $now,
    ])->save();

    /** @var Order $orderModel */
    $orderModel = clone $om->get(Order::class);
    $orderA = (clone $orderModel)->clearData()->setData([
        Order::schema_fields_ORDER_NUMBER => 'E2E-B02-A-' . $token,
        Order::schema_fields_CUSTOMER_ID => $customerId,
        Order::schema_fields_STATUS => Order::STATUS_PAID,
        Order::schema_fields_STATE => Order::STATUS_PAID,
        Order::schema_fields_GRAND_TOTAL => 70.00,
        Order::schema_fields_SUBTOTAL => 70.00,
        Order::schema_fields_CURRENCY => 'CNY',
        Order::schema_fields_PAYMENT_STATUS => Order::PAYMENT_STATUS_PAID,
        Order::schema_fields_FULFILLMENT_STATUS => Order::FULFILLMENT_STATUS_PARTIAL,
        Order::schema_fields_CUSTOMER_EMAIL => $email,
        Order::schema_fields_CUSTOMER_NAME => 'E2E Browser02',
        Order::schema_fields_ORDER_UUID => $orderUuidA,
        Order::schema_fields_CHECKOUT_GROUP_UUID => $groupUuid,
        Order::schema_fields_WEBSITE_ID => $websiteId,
        Order::schema_fields_STORE_ID => $storeId,
        Order::schema_fields_MONEY_SNAPSHOT_JSON => json_encode([
            'subtotal_minor' => 7000,
            'shipping_amount_minor' => 0,
            'tax_amount_minor' => 0,
            'discount_amount_minor' => 0,
            'grand_total_minor' => 7000,
            'currency' => 'CNY',
        ], JSON_UNESCAPED_SLASHES),
        Order::schema_fields_CREATED_AT => $now,
        Order::schema_fields_UPDATED_AT => $now,
    ]);
    $orderA->save();
    $orderIdA = (int) $orderA->getId();

    $orderB = (clone $orderModel)->clearData()->setData([
        Order::schema_fields_ORDER_NUMBER => 'E2E-B02-B-' . $token,
        Order::schema_fields_CUSTOMER_ID => $customerId,
        Order::schema_fields_STATUS => Order::STATUS_PAID,
        Order::schema_fields_STATE => Order::STATUS_PAID,
        Order::schema_fields_GRAND_TOTAL => 30.00,
        Order::schema_fields_SUBTOTAL => 30.00,
        Order::schema_fields_CURRENCY => 'CNY',
        Order::schema_fields_PAYMENT_STATUS => Order::PAYMENT_STATUS_PAID,
        Order::schema_fields_FULFILLMENT_STATUS => Order::FULFILLMENT_STATUS_PENDING,
        Order::schema_fields_CUSTOMER_EMAIL => $email,
        Order::schema_fields_CUSTOMER_NAME => 'E2E Browser02',
        Order::schema_fields_ORDER_UUID => $orderUuidB,
        Order::schema_fields_CHECKOUT_GROUP_UUID => $groupUuid,
        Order::schema_fields_WEBSITE_ID => $websiteId,
        Order::schema_fields_STORE_ID => $storeId,
        Order::schema_fields_MONEY_SNAPSHOT_JSON => json_encode([
            'subtotal_minor' => 3000,
            'shipping_amount_minor' => 0,
            'tax_amount_minor' => 0,
            'discount_amount_minor' => 0,
            'grand_total_minor' => 3000,
            'currency' => 'CNY',
        ], JSON_UNESCAPED_SLASHES),
        Order::schema_fields_CREATED_AT => $now,
        Order::schema_fields_UPDATED_AT => $now,
    ]);
    $orderB->save();
    $orderIdB = (int) $orderB->getId();

    if ($orderIdA <= 0 || $orderIdB <= 0) {
        browser02_fail('order insert failed');
    }

    /** @var RefundCase $refund */
    $refund = clone $om->get(RefundCase::class);
    $refund->clearData()->setData([
        RefundCase::schema_fields_REFUND_CASE_UUID => browser02_uuid(),
        RefundCase::schema_fields_ORDER_UUID => $orderUuidA,
        RefundCase::schema_fields_IDEMPOTENCY_KEY => 'e2e-browser02-refund-' . $token,
        RefundCase::schema_fields_REQUEST_HASH => hash('sha256', $orderUuidA . '|' . $token),
        RefundCase::schema_fields_AMOUNT_MINOR => 2000,
        RefundCase::schema_fields_CURRENCY => 'CNY',
        RefundCase::schema_fields_ITEMS_JSON => '[]',
        RefundCase::schema_fields_SHIPPING_REFUND_MINOR => 0,
        RefundCase::schema_fields_STATUS => RefundCase::STATUS_SUBMITTED,
        RefundCase::schema_fields_CUSTOMER_VIEW => 'processing',
        RefundCase::schema_fields_VERSION => 0,
        RefundCase::schema_fields_REASON => 'e2e browser02 pending refund',
        RefundCase::schema_fields_STEPS_JSON => '{}',
        RefundCase::schema_fields_CREATED_AT => $now,
        RefundCase::schema_fields_UPDATED_AT => $now,
    ])->save();

    /** @var OrderInvoice $invoice */
    $invoice = clone $om->get(OrderInvoice::class);
    foreach ([$orderIdA => 'A', $orderIdB => 'B'] as $orderId => $suffix) {
        (clone $invoice)->clearData()->setData([
            OrderInvoice::schema_fields_ORDER_ID => $orderId,
            OrderInvoice::schema_fields_INVOICE_NUMBER => 'INV-E2E-B02-' . $suffix . '-' . $token,
            OrderInvoice::schema_fields_AMOUNT => $orderId === $orderIdA ? 70.00 : 30.00,
            OrderInvoice::schema_fields_AMOUNT_MINOR => $orderId === $orderIdA ? 7000 : 3000,
            OrderInvoice::schema_fields_RESOURCE_MODE => 'normal',
            OrderInvoice::schema_fields_STATUS => OrderInvoice::STATUS_ISSUED,
            OrderInvoice::schema_fields_ISSUED_AT => $now,
            OrderInvoice::schema_fields_CREATED_AT => $now,
        ])->save();
    }

    return [
        'ok' => true,
        'token' => $token,
        'customer_id' => $customerId,
        'email' => $email,
        'password' => $password,
        'group_uuid' => $groupUuid,
        'order_ids' => [$orderIdA, $orderIdB],
        'order_uuids' => [$orderUuidA, $orderUuidB],
        'order_numbers' => [
            'E2E-B02-A-' . $token,
            'E2E-B02-B-' . $token,
        ],
        'website_id' => $websiteId,
    ];
}

/**
 * @param list<int> $orderIds
 */
function browser02_cleanup(int $customerId, ?string $groupUuid, array $orderIds): array
{
    $om = ObjectManager::getInstance();
    $orderUuids = [];

    if ($orderIds === [] && $customerId > 0) {
        /** @var Order $order */
        $order = clone $om->get(Order::class);
        $rows = $order->reset()
            ->where(Order::schema_fields_CUSTOMER_ID, $customerId)
            ->select()
            ->fetchArray();
        foreach ($rows as $row) {
            $orderIds[] = (int) ($row[Order::schema_fields_ID] ?? 0);
            $orderUuid = trim((string)($row[Order::schema_fields_ORDER_UUID] ?? ''));
            if ($orderUuid !== '') {
                $orderUuids[] = $orderUuid;
            }
            if ($groupUuid === null || $groupUuid === '') {
                $candidate = (string) ($row[Order::schema_fields_CHECKOUT_GROUP_UUID] ?? '');
                if ($candidate !== '') {
                    $groupUuid = $candidate;
                }
            }
        }
        $orderIds = array_values(array_filter($orderIds));
    }

    if ($orderIds !== []) {
        if ($orderUuids === []) {
            /** @var Order $orderLookup */
            $orderLookup = clone $om->get(Order::class);
            $rows = $orderLookup->reset()
                ->where(Order::schema_fields_ID, $orderIds, 'IN')
                ->select()
                ->fetchArray();
            foreach ($rows as $row) {
                $orderUuid = trim((string)($row[Order::schema_fields_ORDER_UUID] ?? ''));
                if ($orderUuid !== '') {
                    $orderUuids[] = $orderUuid;
                }
            }
        }

        if ($orderUuids !== []) {
            /** @var RefundCase $refund */
            $refund = clone $om->get(RefundCase::class);
            $refund->reset()
                ->where(RefundCase::schema_fields_ORDER_UUID, $orderUuids, 'IN')
                ->delete()
                ->fetch();
        }

        /** @var OrderInvoice $invoice */
        $invoice = clone $om->get(OrderInvoice::class);
        $invoice->reset()
            ->where(OrderInvoice::schema_fields_ORDER_ID, $orderIds, 'IN')
            ->delete()
            ->fetch();

        /** @var Order $order */
        $order = clone $om->get(Order::class);
        $order->reset()
            ->where(Order::schema_fields_ID, $orderIds, 'IN')
            ->delete()
            ->fetch();
    }

    if ($groupUuid !== null && $groupUuid !== '') {
        /** @var CheckoutGroup $group */
        $group = clone $om->get(CheckoutGroup::class);
        $group->reset()
            ->where(CheckoutGroup::schema_fields_CHECKOUT_GROUP_UUID, $groupUuid)
            ->delete()
            ->fetch();
    }

    if ($customerId > 1) {
        /** @var \Weline\Customer\Model\Customer $customer */
        $customer = clone $om->get(\Weline\Customer\Model\Customer::class);
        $customer->reset()
            ->where(\Weline\Customer\Model\Customer::schema_fields_ID, $customerId)
            ->delete()
            ->fetch();
    }

    return ['ok' => true, 'cleaned_customer_id' => $customerId, 'cleaned_order_ids' => $orderIds];
}

$input = browser02_read_input();
$action = (string) ($input['action'] ?? '');
try {
    if ($action === 'prepare') {
        browser02_output(browser02_prepare(isset($input['token']) ? (string) $input['token'] : null));
    }
    if ($action === 'cleanup') {
        browser02_output(browser02_cleanup(
            (int) ($input['customer_id'] ?? 0),
            isset($input['group_uuid']) ? (string) $input['group_uuid'] : null,
            array_map('intval', (array) ($input['order_ids'] ?? [])),
        ));
    }
    browser02_fail('unknown action');
} catch (Throwable $e) {
    browser02_fail($e->getMessage());
}
