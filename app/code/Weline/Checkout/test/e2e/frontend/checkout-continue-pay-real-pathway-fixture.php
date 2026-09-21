<?php

declare(strict_types=1);

/**
 * Real continue-pay pathway fixture (acceptance_real_business_pathway).
 *
 * stdin JSON:
 * - {"action":"prepare_cancel_continue","order_uuid":"..."}
 * - {"action":"verify_pathway","order_uuid":"...","quote_token":"...","idempotency_key":"..."}
 */

use Weline\Checkout\Api\CheckoutSessionStoreInterface;
use Weline\Checkout\Service\CheckoutPaymentRecoveryStateService;
use Weline\Checkout\Service\ContinuePaymentRecoveryUrlBuilder;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;
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

try {
    $input = cpay_input();
    $action = trim((string)($input['action'] ?? ''));
    $om = ObjectManager::getInstance();

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

        cpay_output([
            'ok' => true,
            'data' => [
                'order_uuid' => $orderUuid,
                'order_number' => trim((string)$order->getData(Order::schema_fields_ORDER_NUMBER)),
                'order_status' => strtolower(trim((string)$order->getData(Order::schema_fields_STATUS))),
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
