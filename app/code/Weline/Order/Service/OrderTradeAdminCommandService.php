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
use Weline\Payment\Model\PaymentRefund;

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
        array $logistics = [],
    ): array {
        $unitUuid = $this->uuid($unitUuid, 'fulfillment_unit_uuid');
        $idempotencyKey = $this->idempotencyKey($idempotencyKey);
        if ($quantityMinor <= 0 || $expectedVersion < 0) {
            throw new OrderTradeAdminCommandException(
                WarehouseFulfillmentService::ERROR_OVER_FULFILL,
            );
        }
        $trackingNumber = trim((string)($logistics['tracking_number'] ?? ''));
        $carrier = trim((string)($logistics['carrier'] ?? ''));
        $notifyCustomer = !empty($logistics['notify_customer']);
        if ($trackingNumber === '') {
            throw new OrderTradeAdminCommandException('shipment_tracking_required');
        }
        if (mb_strlen($trackingNumber, 'UTF-8') > 100) {
            throw new OrderTradeAdminCommandException('shipment_tracking_too_long');
        }
        if (mb_strlen($carrier, 'UTF-8') > 100) {
            throw new OrderTradeAdminCommandException('shipment_carrier_too_long');
        }
        $requestHash = hash('sha256', $this->json([
            'command' => 'order.fulfillment.partial-ship.v1',
            'fulfillment_unit_uuid' => $unitUuid,
            'qty_minor' => $quantityMinor,
            'expected_version' => $expectedVersion,
            'tracking_number' => $trackingNumber,
            'carrier' => $carrier,
            'notify_customer' => $notifyCustomer ? 1 : 0,
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

        $result = $result + ['request_hash' => $requestHash];
        // Injected shipmentCommand is for unit tests / custom adapters; skip side effects.
        if (empty($result['replayed']) && $this->shipmentCommand === null) {
            $context = $this->shipmentContext($unitUuid);
            $result['logistics'] = $this->attachShipmentLogistics(
                (int)$context['order_id'],
                $trackingNumber,
                $carrier,
                $notifyCustomer,
                (string)($result['status'] ?? ''),
            );
        }

        return $result;
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
            $unitUuid = (string)($row[
                FulfillmentUnit::schema_fields_FULFILLMENT_UNIT_UUID
            ] ?? '');
            $warehouseId = (int)($row[
                FulfillmentUnit::schema_fields_WAREHOUSE_ID
            ] ?? 0);
            $warehouseSource = (string)($row[
                FulfillmentUnit::schema_fields_WAREHOUSE_SOURCE
            ] ?? '');
            $result[] = $order + [
                'fulfillment_unit_uuid' => $unitUuid,
                'unit_short' => $this->shortUuid($unitUuid),
                'warehouse_id' => $warehouseId,
                'warehouse_source' => $warehouseSource,
                'warehouse_label' => $this->warehouseLabel($warehouseId, $warehouseSource),
                'qty_minor' => $total,
                'fulfilled_qty_minor' => $fulfilled,
                'remaining_qty_minor' => max(0, $total - $fulfilled),
                'fulfillment_version' => (int)($row[
                    FulfillmentUnit::schema_fields_FULFILLMENT_VERSION
                ] ?? 0),
                'status' => (string)($row[FulfillmentUnit::schema_fields_STATUS] ?? ''),
                'status_label' => $this->fulfillmentStatusLabel(
                    (string)($row[FulfillmentUnit::schema_fields_STATUS] ?? ''),
                ),
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
    public function refundCandidates(int $limit = 50, int $orderId = 0): array
    {
        $orders = $this->newModel(Order::class)
            ->where(Order::schema_fields_PAYMENT_STATUS, Order::PAYMENT_STATUS_PAID);
        if ($orderId > 0) {
            $orders->where(Order::schema_fields_ID, $orderId);
        } else {
            $orders->order(Order::schema_fields_ID, 'DESC')
                ->limit($this->limit($limit));
        }
        $orders = $orders->select()->fetchArray();
        $result = [];
        foreach ($orders as $orderRow) {
            $rowOrderId = (int)($orderRow[Order::schema_fields_ID] ?? 0);
            $orderUuid = (string)($orderRow[Order::schema_fields_ORDER_UUID] ?? '');
            if ($rowOrderId <= 0 || $orderUuid === '') {
                continue;
            }
            $occupiedQty = $this->occupiedRefundQtyByItem($orderUuid);
            $items = $this->newModel(OrderItem::class)
                ->where(OrderItem::schema_fields_ORDER_UUID, $orderUuid)
                ->order(OrderItem::schema_fields_ID, 'ASC')
                ->select()
                ->fetchArray();
            if ($items === []) {
                // 兼容历史脏数据：仅写了 order_id、未写 order_uuid 的行。
                $items = $this->newModel(OrderItem::class)
                    ->where(OrderItem::schema_fields_ORDER_ID, $rowOrderId)
                    ->order(OrderItem::schema_fields_ID, 'ASC')
                    ->select()
                    ->fetchArray();
            }
            foreach ($items as $item) {
                $qtyMinor = (int)($item[OrderItem::schema_fields_QTY_MINOR] ?? 0);
                if ($qtyMinor <= 0) {
                    continue;
                }
                $itemUuid = (string)($item[OrderItem::schema_fields_ITEM_UUID] ?? '');
                $remaining = $qtyMinor - (int)($occupiedQty[$itemUuid] ?? 0);
                if ($remaining <= 0) {
                    continue;
                }
                $result[] = [
                    'order_id' => $rowOrderId,
                    'order_uuid' => $orderUuid,
                    'order_number' => (string)($orderRow[
                        Order::schema_fields_ORDER_NUMBER
                    ] ?? ''),
                    'item_uuid' => $itemUuid,
                    'product_name' => (string)($item[
                        OrderItem::schema_fields_PRODUCT_NAME
                    ] ?? ''),
                    'qty_minor' => $remaining,
                    'unit_price_minor' => (int)($item[
                        OrderItem::schema_fields_UNIT_PRICE_MINOR
                    ] ?? 0),
                    'expected_grant_version' => (int)($orderRow[
                        Order::schema_fields_STATE_VERSION
                    ] ?? 0),
                ];
            }
        }

        return array_slice($result, 0, $this->limit($limit));
    }

    /** @return list<array<string,mixed>> */
    public function refundCases(int $limit = 50, int $orderId = 0): array
    {
        $model = $this->newModel(RefundCase::class);
        if ($orderId > 0) {
            $order = $this->newModel(Order::class)
                ->where(Order::schema_fields_ID, $orderId)
                ->find()
                ->fetch();
            if (!$order instanceof Order || !$order->getId()) {
                return [];
            }
            $model->where(
                RefundCase::schema_fields_ORDER_UUID,
                (string)$order->getData(Order::schema_fields_ORDER_UUID),
            );
        }
        $rows = $model->order(RefundCase::schema_fields_ID, 'DESC')
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
            $paymentRefundCode = (string)($row[
                RefundCase::schema_fields_PAYMENT_REFUND_CODE
            ] ?? '');
            $payment = [
                'payment_method' => (string)($order['payment_method'] ?? ''),
                'payment_refund_status' => '',
                'channel_status' => '',
                'provider_refund_id' => '',
            ];
            if ($paymentRefundCode !== '') {
                $refund = $this->newModel(PaymentRefund::class)
                    ->where(PaymentRefund::schema_fields_REFUND_CODE, $paymentRefundCode)
                    ->find()
                    ->fetch();
                if ($refund instanceof PaymentRefund && $refund->getId()) {
                    $payment['payment_method'] = (string)($refund->getData(
                        PaymentRefund::schema_fields_METHOD_CODE,
                    ) ?: $payment['payment_method']);
                    $payment['payment_refund_status'] = (string)$refund->getData(
                        PaymentRefund::schema_fields_STATUS,
                    );
                    $payment['channel_status'] = (string)$refund->getData(
                        PaymentRefund::schema_fields_CHANNEL_STATUS,
                    );
                    $payment['provider_refund_id'] = (string)$refund->getData(
                        PaymentRefund::schema_fields_PROVIDER_REFUND_ID,
                    );
                }
            }
            $result[] = $order + $payment + $row;
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
            'payment_method' => (string)$order->getData(Order::schema_fields_PAYMENT_METHOD),
            'payment_status' => (string)$order->getData(Order::schema_fields_PAYMENT_STATUS),
        ];
    }

    /**
     * Persist platform logistics + optional customer email after a successful partial ship.
     *
     * @return array{tracking_number:string,carrier:string,notify_customer:bool,shipment_id:int,mail_sent:bool}
     */
    private function attachShipmentLogistics(
        int $orderId,
        string $trackingNumber,
        string $carrier,
        bool $notifyCustomer,
        string $unitStatus,
    ): array {
        $order = $this->newModel(Order::class)->load($orderId);
        if (!$order instanceof Order || !$order->getId()) {
            throw new OrderTradeAdminCommandException('order_admin_order_not_found');
        }

        /** @var \Weline\Order\Model\OrderShipment $shipment */
        $shipment = $this->newModel(\Weline\Order\Model\OrderShipment::class)->reset();
        $shipment->setData(\Weline\Order\Model\OrderShipment::schema_fields_ORDER_ID, $orderId);
        $shipment->setData(
            \Weline\Order\Model\OrderShipment::schema_fields_TRACKING_NUMBER,
            $trackingNumber,
        );
        $shipment->setData(\Weline\Order\Model\OrderShipment::schema_fields_CARRIER, $carrier);
        $shipment->setData(
            \Weline\Order\Model\OrderShipment::schema_fields_STATUS,
            \Weline\Order\Model\OrderShipment::STATUS_SHIPPED,
        );
        $shipment->setData(
            \Weline\Order\Model\OrderShipment::schema_fields_SHIPPED_AT,
            date('Y-m-d H:i:s'),
        );
        $shipment->save();

        $fullyShipped = $unitStatus === FulfillmentUnit::STATUS_SHIPPED
            && !$this->orderHasOpenFulfillmentUnits(
                (string)$order->getData(Order::schema_fields_ORDER_UUID),
            );
        $order->setData(
            Order::schema_fields_FULFILLMENT_STATUS,
            $fullyShipped
                ? Order::FULFILLMENT_STATUS_SHIPPED
                : Order::FULFILLMENT_STATUS_PARTIAL,
        );
        $order->save();

        if ($fullyShipped) {
            try {
                /** @var OrderStateMachine $stateMachine */
                $stateMachine = $this->manager()->getInstance(OrderStateMachine::class);
                // Mail goes through order_shipped; keep status transition silent to avoid double send.
                $stateMachine->transition(
                    $orderId,
                    Order::STATUS_FULFILLED,
                    (string)__('订单已发货'),
                    false,
                );
                $order = $this->newModel(Order::class)->load($orderId) ?: $order;
            } catch (\Throwable) {
                // Logistics row already saved; status can be reconciled later.
            }
        }

        $mailSent = false;
        try {
            $events = $this->manager()->getInstance(
                \Weline\Framework\Event\EventsManager::class,
            );
            $events->dispatch('Weline_Order::order_shipped', [
                'order' => $order,
                'order_id' => $orderId,
                'shipment' => $shipment,
                'notify_customer' => $notifyCustomer,
                'tracking_number' => $trackingNumber,
                'carrier' => $carrier,
            ]);
            $mailSent = $notifyCustomer;
        } catch (\Throwable) {
            $mailSent = false;
        }

        return [
            'tracking_number' => $trackingNumber,
            'carrier' => $carrier,
            'notify_customer' => $notifyCustomer,
            'shipment_id' => (int)$shipment->getId(),
            'mail_sent' => $mailSent,
        ];
    }

    private function orderHasOpenFulfillmentUnits(string $orderUuid): bool
    {
        $orderUuid = trim($orderUuid);
        if ($orderUuid === '') {
            return false;
        }
        $open = $this->newModel(FulfillmentUnit::class)
            ->where(FulfillmentUnit::schema_fields_ORDER_UUID, $orderUuid)
            ->where(FulfillmentUnit::schema_fields_STATUS, [
                FulfillmentUnit::STATUS_PENDING,
                FulfillmentUnit::STATUS_PARTIAL,
            ], 'IN')
            ->find()
            ->fetch();

        return $open instanceof FulfillmentUnit && (bool)$open->getId();
    }

    private function shortUuid(string $uuid): string
    {
        $uuid = trim($uuid);
        if ($uuid === '') {
            return '';
        }
        $parts = explode('-', $uuid);
        $tail = (string)end($parts);

        return $tail !== '' ? $tail : substr($uuid, -8);
    }

    private function warehouseLabel(int $warehouseId, string $warehouseSource = ''): string
    {
        if ($warehouseId <= 0) {
            return (string)__('平台履约（未绑仓库）');
        }
        try {
            if (!class_exists(\Weline\Inventory\Model\Warehouse::class)) {
                return (string)__('仓库 #%{1}', [$warehouseId]);
            }
            /** @var \Weline\Inventory\Model\Warehouse $warehouse */
            $warehouse = $this->newModel(\Weline\Inventory\Model\Warehouse::class)
                ->load($warehouseId);
            if ($warehouse instanceof \Weline\Inventory\Model\Warehouse && $warehouse->getId()) {
                $name = trim((string)$warehouse->getData(
                    \Weline\Inventory\Model\Warehouse::schema_fields_NAME,
                ));
                if ($name !== '') {
                    return $name;
                }
            }
        } catch (\Throwable) {
            // Soft resolve only.
        }

        $source = strtolower(trim($warehouseSource));
        if ($source === WarehouseFulfillmentService::SOURCE_LEGACY_DEFAULT) {
            return (string)__('仓库 #%{1}（默认）', [$warehouseId]);
        }

        return (string)__('仓库 #%{1}', [$warehouseId]);
    }

    private function fulfillmentStatusLabel(string $status): string
    {
        return match (strtolower(trim($status))) {
            FulfillmentUnit::STATUS_PENDING => (string)__('待发货'),
            FulfillmentUnit::STATUS_PARTIAL => (string)__('部分发货'),
            FulfillmentUnit::STATUS_SHIPPED => (string)__('已发完'),
            default => $status !== '' ? $status : (string)__('未知'),
        };
    }

    /**
     * @return array<string,int>
     */
    private function occupiedRefundQtyByItem(string $orderUuid): array
    {
        $map = [];
        $rows = $this->newModel(RefundCase::class)
            ->where(RefundCase::schema_fields_ORDER_UUID, $orderUuid)
            ->select()
            ->fetchArray();
        if (!\is_array($rows)) {
            return $map;
        }
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $status = (string)($row[RefundCase::schema_fields_STATUS] ?? '');
            if (\in_array($status, [
                RefundCase::STATUS_FAILED,
                RefundCase::STATUS_CANCELLED,
            ], true)) {
                continue;
            }
            $items = json_decode((string)($row[RefundCase::schema_fields_ITEMS_JSON] ?? ''), true);
            if (!\is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (!\is_array($item)) {
                    continue;
                }
                $itemUuid = trim((string)($item['item_uuid'] ?? ''));
                if ($itemUuid === '') {
                    continue;
                }
                $map[$itemUuid] = (int)($map[$itemUuid] ?? 0) + (int)($item['qty_minor'] ?? 0);
            }
        }

        return $map;
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
