<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Framework\Database\Model;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\FulfillmentProgressLedger;
use Weline\Order\Model\FulfillmentUnit;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderInvoice;
use Weline\Order\Model\OrderItem;
use Weline\Order\Model\RefundCase;
use Weline\Payment\Model\PaymentOutbox;

/**
 * Narrow backend application boundary for high-risk Order operations.
 *
 * This class validates and canonicalizes browser commands. Durable writes,
 * locking, CAS and idempotency remain owned by the existing domain services.
 */
final class OrderTradeAdminCommandService
{
    /** @var (\Closure(string,int,int,string,string):array<string,mixed>)|null */
    private readonly ?\Closure $shipmentCommand;
    /** @var (\Closure(string,string,int,array,int,string):array<string,mixed>)|null */
    private readonly ?\Closure $refundCommand;
    /** @var (\Closure(string):array<string,mixed>)|null */
    private readonly ?\Closure $invoiceCommand;

    public function __construct(
        private readonly ?ObjectManager $objectManager = null,
        private readonly ?WarehouseFulfillmentService $fulfillments = null,
        private readonly ?OrderRefundCoordinator $refunds = null,
        private readonly ?PaymentEffectConsumer $paymentEffects = null,
        ?callable $shipmentCommand = null,
        ?callable $refundCommand = null,
        ?callable $invoiceCommand = null,
    ) {
        $this->shipmentCommand = $shipmentCommand !== null
            ? \Closure::fromCallable($shipmentCommand)
            : null;
        $this->refundCommand = $refundCommand !== null
            ? \Closure::fromCallable($refundCommand)
            : null;
        $this->invoiceCommand = $invoiceCommand !== null
            ? \Closure::fromCallable($invoiceCommand)
            : null;
    }

    /** @return array<string,mixed> */
    public function ship(
        string $unitUuid,
        int $quantityMinor,
        int $expectedVersion,
        string $idempotencyKey,
    ): array {
        $unitUuid = $this->uuid($unitUuid, 'fulfillment_unit_uuid');
        $idempotencyKey = $this->idempotencyKey($idempotencyKey);
        if ($quantityMinor <= 0 || $expectedVersion < 0) {
            throw new OrderTradeAdminCommandException(
                WarehouseFulfillmentService::ERROR_OVER_FULFILL,
            );
        }
        $requestHash = hash('sha256', $this->json([
            'command' => 'order.fulfillment.partial-ship.v1',
            'fulfillment_unit_uuid' => $unitUuid,
            'qty_minor' => $quantityMinor,
            'expected_version' => $expectedVersion,
        ]));

        try {
            $result = $this->shipmentCommand !== null
                ? ($this->shipmentCommand)(
                    $unitUuid,
                    $quantityMinor,
                    $expectedVersion,
                    $idempotencyKey,
                    $requestHash,
                )
                : $this->fulfillmentService()->partialShip(
                    $unitUuid,
                    $quantityMinor,
                    $expectedVersion,
                    $idempotencyKey,
                    $requestHash,
                );
        } catch (WarehouseFulfillmentConflictException $exception) {
            throw new OrderTradeAdminCommandException(
                $exception->errorCode(),
                $exception->getMessage(),
                $exception,
            );
        }

        return $result + ['request_hash' => $requestHash];
    }

    /** @return array<string,mixed> */
    public function refund(
        string $orderUuid,
        string $itemUuid,
        int $quantityMinor,
        int $shippingRefundMinor,
        string $reason,
        string $idempotencyKey,
    ): array {
        $orderUuid = $this->uuid($orderUuid, 'order_uuid');
        $itemUuid = $this->uuid($itemUuid, 'item_uuid');
        $idempotencyKey = $this->idempotencyKey($idempotencyKey);
        if ($quantityMinor <= 0 || $shippingRefundMinor < 0) {
            throw new OrderTradeAdminCommandException(
                OrderRefundCoordinator::ERROR_QTY_EXCEEDS,
            );
        }
        $reason = trim($reason);
        if (mb_strlen($reason, 'UTF-8') > 255) {
            throw new OrderTradeAdminCommandException('refund_reason_too_long');
        }
        $items = [[
            'item_uuid' => $itemUuid,
            'qty_minor' => $quantityMinor,
        ]];
        $result = $this->refundCommand !== null
            ? ($this->refundCommand)(
                $orderUuid,
                $idempotencyKey,
                0,
                $items,
                $shippingRefundMinor,
                $reason,
            )
            : $this->refundCoordinator()->requestRefund(
                $orderUuid,
                $idempotencyKey,
                0,
                $items,
                $shippingRefundMinor,
                $reason,
            );
        if (empty($result['ok'])) {
            throw new OrderTradeAdminCommandException(
                trim((string)($result['error_code'] ?? 'refund_transaction_failed'))
                    ?: 'refund_transaction_failed',
                trim((string)($result['message'] ?? '')),
            );
        }

        return $result;
    }

    /** @return array<string,mixed> */
    public function invoice(string $outboxCode): array
    {
        $outboxCode = $this->boundedCode($outboxCode, 96, 'invoice_outbox_code_invalid');
        $result = $this->invoiceCommand !== null
            ? ($this->invoiceCommand)($outboxCode)
            : $this->paymentEffectConsumer()->processOne($outboxCode);
        if (empty($result['ok'])) {
            throw new OrderTradeAdminCommandException(
                trim((string)($result['error_code'] ?? 'invoice_effect_failed'))
                    ?: 'invoice_effect_failed',
            );
        }

        return $result;
    }

    /** @return array{order_id:int,order_uuid:string,order_number:string} */
    public function shipmentContext(string $unitUuid): array
    {
        $unitUuid = $this->uuid($unitUuid, 'fulfillment_unit_uuid');
        $unit = $this->newModel(FulfillmentUnit::class)
            ->where(FulfillmentUnit::schema_fields_FULFILLMENT_UNIT_UUID, $unitUuid)
            ->find()
            ->fetch();
        if (!$unit instanceof FulfillmentUnit || !$unit->getId()) {
            throw new OrderTradeAdminCommandException(
                WarehouseFulfillmentService::ERROR_UNIT_NOT_FOUND,
            );
        }

        return $this->orderContext((string)$unit->getData(
            FulfillmentUnit::schema_fields_ORDER_UUID,
        ));
    }

    /** @return array{order_id:int,order_uuid:string,order_number:string} */
    public function refundContext(string $orderUuid): array
    {
        return $this->orderContext($this->uuid($orderUuid, 'order_uuid'));
    }

    /** @return array{order_id:int,order_uuid:string,order_number:string} */
    public function invoiceContext(string $outboxCode): array
    {
        $outboxCode = $this->boundedCode($outboxCode, 96, 'invoice_outbox_code_invalid');
        $outbox = $this->newModel(PaymentOutbox::class)
            ->where(PaymentOutbox::schema_fields_OUTBOX_CODE, $outboxCode)
            ->find()
            ->fetch();
        if (!$outbox instanceof PaymentOutbox || !$outbox->getId()
            || (string)$outbox->getData(PaymentOutbox::schema_fields_EFFECT_TYPE)
                !== InvoiceService::EFFECT_TYPE
        ) {
            throw new OrderTradeAdminCommandException('invoice_effect_outbox_not_found');
        }
        $payload = $this->decode((string)$outbox->getData(
            PaymentOutbox::schema_fields_PAYLOAD_JSON,
        ));
        if ((string)($payload['payable_type'] ?? '') !== 'order') {
            throw new OrderTradeAdminCommandException(InvoiceService::ERROR_PAYABLE_TYPE);
        }

        return $this->orderContext((string)($payload['payable_id'] ?? ''));
    }

    /** @return list<array<string,mixed>> */
    public function shipmentCandidates(int $limit = 50): array
    {
        $rows = $this->newModel(FulfillmentUnit::class)
            ->where(FulfillmentUnit::schema_fields_STATUS, [
                FulfillmentUnit::STATUS_PENDING,
                FulfillmentUnit::STATUS_PARTIAL,
            ], 'IN')
            ->order(FulfillmentUnit::schema_fields_ID, 'DESC')
            ->limit($this->limit($limit))
            ->select()
            ->fetchArray();
        $result = [];
        foreach ($rows as $row) {
            try {
                $order = $this->orderContext((string)($row[
                    FulfillmentUnit::schema_fields_ORDER_UUID
                ] ?? ''));
            } catch (OrderTradeAdminCommandException) {
                continue;
            }
            $total = (int)($row[FulfillmentUnit::schema_fields_QTY_MINOR] ?? 0);
            $fulfilled = (int)($row[
                FulfillmentUnit::schema_fields_FULFILLED_QTY_MINOR
            ] ?? 0);
            $result[] = $order + [
                'fulfillment_unit_uuid' => (string)($row[
                    FulfillmentUnit::schema_fields_FULFILLMENT_UNIT_UUID
                ] ?? ''),
                'warehouse_id' => (int)($row[
                    FulfillmentUnit::schema_fields_WAREHOUSE_ID
                ] ?? 0),
                'qty_minor' => $total,
                'fulfilled_qty_minor' => $fulfilled,
                'remaining_qty_minor' => max(0, $total - $fulfilled),
                'fulfillment_version' => (int)($row[
                    FulfillmentUnit::schema_fields_FULFILLMENT_VERSION
                ] ?? 0),
                'status' => (string)($row[FulfillmentUnit::schema_fields_STATUS] ?? ''),
            ];
        }

        return $result;
    }

    /** @return list<array<string,mixed>> */
    public function shipmentProgress(int $limit = 50): array
    {
        $rows = $this->newModel(FulfillmentProgressLedger::class)
            ->order(FulfillmentProgressLedger::schema_fields_ID, 'DESC')
            ->limit($this->limit($limit))
            ->select()
            ->fetchArray();
        $result = [];
        foreach ($rows as $row) {
            try {
                $order = $this->orderContext((string)($row[
                    FulfillmentProgressLedger::schema_fields_ORDER_UUID
                ] ?? ''));
            } catch (OrderTradeAdminCommandException) {
                continue;
            }
            $result[] = $order + $row;
        }

        return $result;
    }

    /** @return list<array<string,mixed>> */
    public function refundCandidates(int $limit = 50): array
    {
        $orders = $this->newModel(Order::class)
            ->where(Order::schema_fields_PAYMENT_STATUS, Order::PAYMENT_STATUS_PAID)
            ->order(Order::schema_fields_ID, 'DESC')
            ->limit($this->limit($limit))
            ->select()
            ->fetchArray();
        $result = [];
        foreach ($orders as $orderRow) {
            $orderId = (int)($orderRow[Order::schema_fields_ID] ?? 0);
            $orderUuid = (string)($orderRow[Order::schema_fields_ORDER_UUID] ?? '');
            if ($orderId <= 0 || $orderUuid === '') {
                continue;
            }
            $items = $this->newModel(OrderItem::class)
                ->where(OrderItem::schema_fields_ORDER_ID, $orderId)
                ->order(OrderItem::schema_fields_ID, 'ASC')
                ->select()
                ->fetchArray();
            foreach ($items as $item) {
                $qtyMinor = (int)($item[OrderItem::schema_fields_QTY_MINOR] ?? 0);
                if ($qtyMinor <= 0) {
                    continue;
                }
                $result[] = [
                    'order_id' => $orderId,
                    'order_uuid' => $orderUuid,
                    'order_number' => (string)($orderRow[
                        Order::schema_fields_ORDER_NUMBER
                    ] ?? ''),
                    'item_uuid' => (string)($item[OrderItem::schema_fields_ITEM_UUID] ?? ''),
                    'product_name' => (string)($item[
                        OrderItem::schema_fields_PRODUCT_NAME
                    ] ?? ''),
                    'qty_minor' => $qtyMinor,
                    'unit_price_minor' => (int)($item[
                        OrderItem::schema_fields_UNIT_PRICE_MINOR
                    ] ?? 0),
                ];
            }
        }

        return array_slice($result, 0, $this->limit($limit));
    }

    /** @return list<array<string,mixed>> */
    public function refundCases(int $limit = 50): array
    {
        $rows = $this->newModel(RefundCase::class)
            ->order(RefundCase::schema_fields_ID, 'DESC')
            ->limit($this->limit($limit))
            ->select()
            ->fetchArray();
        $result = [];
        foreach ($rows as $row) {
            try {
                $order = $this->orderContext((string)($row[
                    RefundCase::schema_fields_ORDER_UUID
                ] ?? ''));
            } catch (OrderTradeAdminCommandException) {
                continue;
            }
            $result[] = $order + $row;
        }

        return $result;
    }

    /** @return list<array<string,mixed>> */
    public function invoiceCandidates(int $limit = 50): array
    {
        $rows = $this->newModel(PaymentOutbox::class)
            ->where(PaymentOutbox::schema_fields_EFFECT_TYPE, InvoiceService::EFFECT_TYPE)
            ->where(PaymentOutbox::schema_fields_STATUS, [
                PaymentOutbox::STATUS_PENDING,
                PaymentOutbox::STATUS_DONE,
            ], 'IN')
            ->order(PaymentOutbox::schema_fields_ID, 'DESC')
            ->limit($this->limit($limit))
            ->select()
            ->fetchArray();
        $result = [];
        foreach ($rows as $row) {
            try {
                $payload = $this->decode((string)($row[
                    PaymentOutbox::schema_fields_PAYLOAD_JSON
                ] ?? ''));
                if ((string)($payload['payable_type'] ?? '') !== 'order') {
                    continue;
                }
                $order = $this->orderContext((string)($payload['payable_id'] ?? ''));
            } catch (OrderTradeAdminCommandException) {
                continue;
            }
            $result[] = $order + [
                'outbox_code' => (string)($row[
                    PaymentOutbox::schema_fields_OUTBOX_CODE
                ] ?? ''),
                'effect_key' => (string)($row[
                    PaymentOutbox::schema_fields_EFFECT_KEY
                ] ?? ''),
                'attempt_code' => (string)($row[
                    PaymentOutbox::schema_fields_ATTEMPT_CODE
                ] ?? ''),
                'status' => (string)($row[PaymentOutbox::schema_fields_STATUS] ?? ''),
            ];
        }

        return $result;
    }

    /** @return list<array<string,mixed>> */
    public function invoices(int $limit = 50): array
    {
        $rows = $this->newModel(OrderInvoice::class)
            ->order(OrderInvoice::schema_fields_ID, 'DESC')
            ->limit($this->limit($limit))
            ->select()
            ->fetchArray();
        $result = [];
        foreach ($rows as $row) {
            $orderId = (int)($row[OrderInvoice::schema_fields_ORDER_ID] ?? 0);
            $order = $this->newModel(Order::class)->load($orderId);
            if (!$order instanceof Order || !$order->getId()) {
                continue;
            }
            $result[] = [
                'order_id' => $orderId,
                'order_uuid' => (string)$order->getData(Order::schema_fields_ORDER_UUID),
                'order_number' => (string)$order->getData(Order::schema_fields_ORDER_NUMBER),
            ] + $row;
        }

        return $result;
    }

    /** @return array{order_id:int,order_uuid:string,order_number:string} */
    private function orderContext(string $orderUuid): array
    {
        $orderUuid = trim($orderUuid);
        if ($orderUuid === '') {
            throw new OrderTradeAdminCommandException('order_admin_order_not_found');
        }
        $order = $this->newModel(Order::class)
            ->where(Order::schema_fields_ORDER_UUID, $orderUuid)
            ->find()
            ->fetch();
        if (!$order instanceof Order || !$order->getId()) {
            throw new OrderTradeAdminCommandException('order_admin_order_not_found');
        }

        return [
            'order_id' => (int)$order->getId(),
            'order_uuid' => (string)$order->getData(Order::schema_fields_ORDER_UUID),
            'order_number' => (string)$order->getData(Order::schema_fields_ORDER_NUMBER),
        ];
    }

    private function fulfillmentService(): WarehouseFulfillmentService
    {
        return $this->fulfillments
            ?? $this->manager()->getInstance(WarehouseFulfillmentService::class);
    }

    private function refundCoordinator(): OrderRefundCoordinator
    {
        return $this->refunds
            ?? $this->manager()->getInstance(OrderRefundCoordinator::class);
    }

    private function paymentEffectConsumer(): PaymentEffectConsumer
    {
        return $this->paymentEffects
            ?? $this->manager()->getInstance(PaymentEffectConsumer::class);
    }

    private function manager(): ObjectManager
    {
        return $this->objectManager ?? ObjectManager::getInstance();
    }

    /** @template T of Model @param class-string<T> $class @return T */
    private function newModel(string $class): Model
    {
        return $this->manager()->getInstance($class, [], false);
    }

    private function uuid(string $value, string $field): string
    {
        $value = strtolower(trim($value));
        if (preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
            $value,
        ) !== 1) {
            throw new OrderTradeAdminCommandException($field . '_invalid');
        }

        return $value;
    }

    private function idempotencyKey(string $value): string
    {
        return $this->boundedCode($value, 128, 'order_admin_idempotency_key_invalid');
    }

    private function boundedCode(string $value, int $maxLength, string $error): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxLength
            || preg_match('/^[A-Za-z0-9._:-]+$/D', $value) !== 1
        ) {
            throw new OrderTradeAdminCommandException($error);
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private function decode(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new OrderTradeAdminCommandException(
                'order_admin_payload_invalid',
                previous: $exception,
            );
        }
        if (!is_array($decoded)) {
            throw new OrderTradeAdminCommandException('order_admin_payload_invalid');
        }

        return $decoded;
    }

    /** @param array<string,mixed> $value */
    private function json(array $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    private function limit(int $limit): int
    {
        return max(1, min(100, $limit));
    }
}
