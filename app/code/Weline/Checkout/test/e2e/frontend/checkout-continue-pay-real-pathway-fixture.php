<?php

declare(strict_types=1);

/**
 * Real continue-pay pathway fixture (acceptance_real_business_pathway).
 *
 * stdin JSON:
 * - {"action":"seed_unpaid_pathway"}
 * - {"action":"cleanup_seed","order_uuid":"...","quote_token":"..."}
 * - {"action":"prepare_cancel_continue","order_uuid":"..."}
 * - {"action":"verify_pathway","order_uuid":"...","quote_token":"...","idempotency_key":"..."}
 *
 * seed_unpaid_pathway 直接 ORM 写 Order + CheckoutSession，不依赖 Shipping Zone。
 */

use Weline\Checkout\Api\CheckoutSessionStoreInterface;
use Weline\Checkout\Model\CheckoutSession;
use Weline\Checkout\Service\CheckoutPaymentRecoveryStateService;
use Weline\Checkout\Service\ContinuePaymentRecoveryUrlBuilder;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderItem;
use Weline\Payment\Model\PaymentTransaction;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

/** @return array<string, mixed> */
function cpay_input(): array
{
    $raw = stream_get_contents(STDIN);
    $decoded = json_decode($raw !== false && trim($raw) !== '' ? $raw : '{}', true);

    return is_array($decoded) ? $decoded : [];
}

/** @param array<string, mixed> $payload */
function cpay_output(array $payload, int $exitCode = 0): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit($exitCode);
}

function cpay_uuid(): string
{
    $hex = bin2hex(random_bytes(16));

    return substr($hex, 0, 8) . '-'
        . substr($hex, 8, 4) . '-'
        . substr($hex, 12, 4) . '-'
        . substr($hex, 16, 4) . '-'
        . substr($hex, 20, 12);
}

function cpay_token(string $prefix): string
{
    return $prefix . strtolower(bin2hex(random_bytes(8)));
}

try {
    $input = cpay_input();
    $action = trim((string)($input['action'] ?? ''));
    $om = ObjectManager::getInstance();

    if ($action === 'seed_unpaid_pathway') {
        $orderUuid = cpay_uuid();
        $quoteToken = cpay_token('qt_cpay_');
        $idempotencyKey = cpay_token('idem_cpay_');
        $orderNumber = 'CPAY-' . strtoupper(bin2hex(random_bytes(4))) . '-' . date('His');
        $now = gmdate('Y-m-d H:i:s');
        $withTx = !isset($input['with_payment_transaction'])
            || filter_var($input['with_payment_transaction'], FILTER_VALIDATE_BOOLEAN);

        /** @var Order $order */
        $order = $om->getInstance(Order::class);
        $order->clear()->clearData()->setData([
            Order::schema_fields_ORDER_NUMBER => $orderNumber,
            Order::schema_fields_ORDER_UUID => $orderUuid,
            Order::schema_fields_STATUS => Order::STATUS_PENDING,
            Order::schema_fields_STATE => Order::STATUS_PENDING,
            Order::schema_fields_GRAND_TOTAL => 1.00,
            Order::schema_fields_SUBTOTAL => 1.00,
            Order::schema_fields_SHIPPING_AMOUNT => 0.00,
            Order::schema_fields_TAX_AMOUNT => 0.00,
            Order::schema_fields_DISCOUNT_AMOUNT => 0.00,
            Order::schema_fields_CURRENCY => 'CNY',
            Order::schema_fields_SOURCE_APP => 'e2e',
            Order::schema_fields_SOURCE_MODULE => 'Weline_Checkout',
            Order::schema_fields_BUSINESS_CODE => 'continue_pay_seed',
            Order::schema_fields_BUSINESS_NAME => 'continue-pay unpaid pathway seed',
            Order::schema_fields_PAYMENT_STATUS => Order::PAYMENT_STATUS_PENDING,
            Order::schema_fields_FULFILLMENT_STATUS => Order::FULFILLMENT_STATUS_PENDING,
            Order::schema_fields_PAYMENT_METHOD => 'fake_card',
            Order::schema_fields_CUSTOMER_EMAIL => 'cpay-seed@example.test',
            Order::schema_fields_CUSTOMER_NAME => 'Continue Pay Seed',
            Order::schema_fields_WEBSITE_ID => 0,
            Order::schema_fields_STORE_ID => 0,
            Order::schema_fields_CHECKOUT_ENTRY => 'checkout',
            Order::schema_fields_CREATED_AT => $now,
            Order::schema_fields_UPDATED_AT => $now,
        ])->save();
        if (!$order->getId()) {
            throw new RuntimeException('order_insert_failed');
        }
        $orderId = (int)$order->getId();

        /** @var OrderItem $orderItem */
        $orderItem = $om->getInstance(OrderItem::class);
        $itemUuid = cpay_uuid();
        $orderItem->clear()->clearData()->setData([
            OrderItem::schema_fields_ORDER_ID => $orderId,
            OrderItem::schema_fields_ORDER_UUID => $orderUuid,
            OrderItem::schema_fields_ITEM_UUID => $itemUuid,
            OrderItem::schema_fields_PRODUCT_ID => 0,
            OrderItem::schema_fields_PRODUCT_SKU => 'CPAY-SEED-SKU',
            OrderItem::schema_fields_PRODUCT_NAME => 'Continue Pay Seed Line Item',
            OrderItem::schema_fields_PRODUCT_TYPE => 'simple',
            OrderItem::schema_fields_SOURCE_APP => 'e2e',
            OrderItem::schema_fields_SOURCE_MODULE => 'Weline_Checkout',
            OrderItem::schema_fields_BUSINESS_CODE => 'continue_pay_seed',
            OrderItem::schema_fields_BUSINESS_NAME => 'continue-pay unpaid pathway seed',
            OrderItem::schema_fields_QTY_ORDERED => 1.00,
            OrderItem::schema_fields_QTY_SHIPPED => 0.00,
            OrderItem::schema_fields_QTY_REFUNDED => 0.00,
            OrderItem::schema_fields_QTY_CANCELLED => 0.00,
            OrderItem::schema_fields_PRICE => 1.00,
            OrderItem::schema_fields_ROW_TOTAL => 1.00,
            OrderItem::schema_fields_DISCOUNT_AMOUNT => 0.00,
            OrderItem::schema_fields_TAX_AMOUNT => 0.00,
            OrderItem::schema_fields_QTY_MINOR => 100,
            OrderItem::schema_fields_UNIT_PRICE_MINOR => 100,
            OrderItem::schema_fields_CREATED_AT => $now,
            OrderItem::schema_fields_UPDATED_AT => $now,
        ])->save();
        if (!$orderItem->getId()) {
            throw new RuntimeException('order_item_insert_failed');
        }

        $groupUuid = cpay_uuid();
        /** @var CheckoutSessionStoreInterface $sessions */
        $sessions = $om->getInstance(CheckoutSessionStoreInterface::class);
        $sessions->put($quoteToken, [
            'state' => CheckoutSession::STATE_SUBMITTED,
            'idempotency_key' => $idempotencyKey,
            'currency' => 'CNY',
            'config_version' => '1',
            'checkout_entry' => 'checkout',
            'submitted_result' => [
                'checkout_group_uuid' => $groupUuid,
                'order_uuids' => [$orderUuid],
                'currency' => 'CNY',
                'totals' => ['grand_total_minor' => 100],
                'orders' => [[
                    'order_uuid' => $orderUuid,
                    'status' => 'pending',
                ]],
                'replayed' => false,
            ],
            'payment_result' => [
                'paid' => false,
                'outcome' => 'pending',
                'status' => 'pending',
                'recoverable' => true,
                'transactions' => [
                    [
                        'method_code' => 'fake_card',
                        'status' => 'pending',
                        'order_uuid' => $orderUuid,
                    ],
                ],
            ],
        ], gmdate('Y-m-d H:i:s', time() + CheckoutSession::TTL_SUBMITTED_SUCCESS_SECONDS));

        $transactionNo = null;
        if ($withTx) {
            $transactionNo = 'TX-CPAY-' . strtoupper(bin2hex(random_bytes(6)));
            /** @var PaymentTransaction $tx */
            $tx = $om->getInstance(PaymentTransaction::class);
            $tx->clear()->clearData()->setData([
                PaymentTransaction::schema_fields_ORDER_ID => $orderUuid,
                PaymentTransaction::schema_fields_METHOD_CODE => 'fake_card',
                PaymentTransaction::schema_fields_TRANSACTION_NO => $transactionNo,
                PaymentTransaction::schema_fields_AMOUNT => '1.00',
                PaymentTransaction::schema_fields_CURRENCY => 'CNY',
                PaymentTransaction::schema_fields_STATUS => PaymentTransaction::STATUS_PENDING,
                PaymentTransaction::schema_fields_SCOPE => 'default.default.default',
                PaymentTransaction::schema_fields_CREATED_AT => $now,
                PaymentTransaction::schema_fields_UPDATED_AT => $now,
            ])->save();
            if (!$tx->getId()) {
                throw new RuntimeException('payment_transaction_insert_failed');
            }
        }

        cpay_output([
            'ok' => true,
            'data' => [
                'order_uuid' => $orderUuid,
                'order_number' => $orderNumber,
                'quote_token' => $quoteToken,
                'idempotency_key' => $idempotencyKey,
                'transaction_no' => $transactionNo,
                'order_item_count' => 1,
            ],
        ]);
    }

    if ($action === 'cleanup_seed') {
        $orderUuid = trim((string)($input['order_uuid'] ?? ''));
        $quoteToken = trim((string)($input['quote_token'] ?? ''));
        if ($orderUuid === '' && $quoteToken === '') {
            throw new RuntimeException('cleanup_requires_order_uuid_or_quote_token');
        }

        /** @var CheckoutSessionStoreInterface $sessions */
        $sessions = $om->getInstance(CheckoutSessionStoreInterface::class);
        if ($quoteToken === '' && $orderUuid !== '') {
            $quoteToken = trim((string)($sessions->findSubmittedTokenByOrderUuid($orderUuid) ?? ''));
        }

        $deleted = [
            'session' => false,
            'transactions' => 0,
            'order_items' => 0,
            'order' => false,
        ];

        if ($quoteToken !== '') {
            $deleted['session'] = $sessions->delete($quoteToken);
        }

        if ($orderUuid !== '') {
            $txs = (new PaymentTransaction())->reset()
                ->where(PaymentTransaction::schema_fields_ORDER_ID, $orderUuid)
                ->select()
                ->fetch()
                ->getItems();
            foreach ($txs as $tx) {
                if ($tx instanceof PaymentTransaction && $tx->getId()) {
                    (new PaymentTransaction())->reset()
                        ->where(PaymentTransaction::schema_fields_ID, (int)$tx->getId())
                        ->delete()
                        ->fetch();
                    $deleted['transactions']++;
                }
            }

            $items = (new OrderItem())->reset()
                ->where(OrderItem::schema_fields_ORDER_UUID, $orderUuid)
                ->select()
                ->fetch()
                ->getItems();
            foreach ($items as $item) {
                if ($item instanceof OrderItem && $item->getId()) {
                    (new OrderItem())->reset()
                        ->where(OrderItem::schema_fields_ID, (int)$item->getId())
                        ->delete()
                        ->fetch();
                    $deleted['order_items']++;
                }
            }

            /** @var Order $order */
            $order = $om->getInstance(Order::class);
            $order->clear()->reset()
                ->where(Order::schema_fields_ORDER_UUID, $orderUuid)
                ->find()
                ->fetch();
            if ($order->getId()) {
                (new Order())->reset()
                    ->where(Order::schema_fields_ORDER_UUID, $orderUuid)
                    ->delete()
                    ->fetch();
                $deleted['order'] = true;
            }
        }

        cpay_output([
            'ok' => true,
            'data' => [
                'order_uuid' => $orderUuid,
                'quote_token' => $quoteToken,
                'deleted' => $deleted,
            ],
        ]);
    }

    if ($action === 'prepare_cancel_continue') {
        $orderUuid = trim((string)($input['order_uuid'] ?? ''));
        if ($orderUuid === '') {
            throw new RuntimeException('order_uuid_required');
        }

        /** @var Order $order */
        $order = $om->getInstance(Order::class);
        $order->clear()->reset()
            ->where(Order::schema_fields_ORDER_UUID, $orderUuid)
            ->find()
            ->fetch();
        if (!$order->getId()) {
            throw new RuntimeException('order_not_found');
        }
        $status = strtolower(trim((string)$order->getData(Order::schema_fields_STATUS)));
        $orderNumber = trim((string)$order->getData(Order::schema_fields_ORDER_NUMBER));

        /** @var CheckoutSessionStoreInterface $sessions */
        $sessions = $om->getInstance(CheckoutSessionStoreInterface::class);
        $quoteToken = trim((string)($sessions->findSubmittedTokenByOrderUuid($orderUuid) ?? ''));
        if ($quoteToken === '') {
            throw new RuntimeException('checkout_token_missing_for_order');
        }
        $session = $sessions->get($quoteToken);
        if (!is_array($session)) {
            throw new RuntimeException('checkout_session_missing');
        }
        $idempotencyKey = trim((string)($session['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            throw new RuntimeException('idempotency_key_missing');
        }

        /** @var CheckoutPaymentRecoveryStateService $recovery */
        $recovery = $om->getInstance(CheckoutPaymentRecoveryStateService::class);
        $marked = $recovery->markBrowserCancel($quoteToken, $idempotencyKey, [
            'payment_method' => 'fake_card',
            'method_code' => 'fake_card',
        ]);
        if (!$marked && !$recovery->canRetry($quoteToken, $idempotencyKey)) {
            // Already failed+recoverable is ok.
            $payment = $recovery->get($quoteToken, $idempotencyKey);
            if (!is_array($payment) || strtolower((string)($payment['outcome'] ?? '')) !== 'failed') {
                throw new RuntimeException('mark_browser_cancel_failed');
            }
        }

        /** @var ContinuePaymentRecoveryUrlBuilder $builder */
        $builder = $om->getInstance(ContinuePaymentRecoveryUrlBuilder::class);
        $built = $builder->build($quoteToken, $orderUuid, 0);
        if (empty($built['reachable']) || trim((string)($built['continue_pay_url'] ?? '')) === '') {
            // Relative fallback for same-origin e2e proxy.
            $hash = $builder->buildHash([
                'quote_token' => $quoteToken,
                'idempotency_key' => $idempotencyKey,
                'payment_method' => 'fake_card',
                'order_uuid' => $orderUuid,
                'outcome' => 'failed',
                'recoverable' => '1',
            ]);
            $built = [
                'continue_pay_url' => '/checkout' . $hash,
                'reachable' => true,
                'quote_token' => $quoteToken,
                'idempotency_key' => $idempotencyKey,
                'payment_method' => 'fake_card',
            ];
        }

        $txCount = (new PaymentTransaction())->reset()
            ->where(PaymentTransaction::schema_fields_ORDER_ID, $orderUuid)
            ->select()
            ->fetch()
            ->getItems();

        cpay_output([
            'ok' => true,
            'data' => [
                'order_uuid' => $orderUuid,
                'order_number' => $orderNumber,
                'order_status' => $status,
                'quote_token' => $quoteToken,
                'idempotency_key' => $idempotencyKey,
                'continue_pay_url' => (string)$built['continue_pay_url'],
                'can_retry' => $recovery->canRetry($quoteToken, $idempotencyKey),
                'history_count' => count($recovery->history($quoteToken, $idempotencyKey)),
                'transaction_count_before_resume' => count($txCount),
            ],
        ]);
    }

    if ($action === 'verify_pathway') {
        $orderUuid = trim((string)($input['order_uuid'] ?? ''));
        $quoteToken = trim((string)($input['quote_token'] ?? ''));
        $idempotencyKey = trim((string)($input['idempotency_key'] ?? ''));
        if ($orderUuid === '' || $quoteToken === '' || $idempotencyKey === '') {
            throw new RuntimeException('verify_params_required');
        }

        /** @var Order $order */
        $order = $om->getInstance(Order::class);
        $order->clear()->reset()
            ->where(Order::schema_fields_ORDER_UUID, $orderUuid)
            ->find()
            ->fetch();
        if (!$order->getId()) {
            throw new RuntimeException('order_not_found');
        }

        $txs = (new PaymentTransaction())->reset()
            ->where(PaymentTransaction::schema_fields_ORDER_ID, $orderUuid)
            ->order(PaymentTransaction::schema_fields_ID, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
        $txRows = [];
        foreach ($txs as $tx) {
            $data = $tx instanceof PaymentTransaction ? $tx->getData() : (array)$tx;
            $txRows[] = [
                'transaction_no' => (string)($data[PaymentTransaction::schema_fields_TRANSACTION_NO] ?? ''),
                'status' => (string)($data[PaymentTransaction::schema_fields_STATUS] ?? ''),
                'method_code' => (string)($data[PaymentTransaction::schema_fields_METHOD_CODE] ?? ''),
            ];
        }

        /** @var CheckoutPaymentRecoveryStateService $recovery */
        $recovery = $om->getInstance(CheckoutPaymentRecoveryStateService::class);
        $history = $recovery->history($quoteToken, $idempotencyKey);
        $latest = $recovery->get($quoteToken, $idempotencyKey);

        $itemRows = (new OrderItem())->reset()
            ->where(OrderItem::schema_fields_ORDER_UUID, $orderUuid)
            ->select()
            ->fetch()
            ->getItems();

        cpay_output([
            'ok' => true,
            'data' => [
                'order_uuid' => $orderUuid,
                'order_number' => trim((string)$order->getData(Order::schema_fields_ORDER_NUMBER)),
                'order_status' => strtolower(trim((string)$order->getData(Order::schema_fields_STATUS))),
                'order_item_count' => count($itemRows),
                'transaction_count' => count($txRows),
                'transactions' => $txRows,
                'history_count' => count($history),
                'latest_outcome' => is_array($latest) ? (string)($latest['outcome'] ?? '') : '',
            ],
        ]);
    }

    throw new RuntimeException('unknown_action:' . $action);
} catch (Throwable $e) {
    cpay_output([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 1);
}
