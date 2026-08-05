<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Api\Data\TaxSnapshot;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderInvoice;
use Weline\Payment\Api\Data\PaymentEffectRecord;

/**
 * Invoice service.
 *
 * A paid Order has at most one minimal Invoice. The post-payment path is
 * driven by a deterministic Payment effect and must run inside the owning
 * Payment outbox write transaction.
 */
class InvoiceService
{
    public const EFFECT_TYPE = 'invoice:create:v1';
    public const ERROR_TRANSACTION_REQUIRED = 'invoice_effect_transaction_required';
    public const ERROR_PAYABLE_TYPE = 'invoice_effect_payable_type_invalid';
    public const ERROR_ORDER_NOT_FOUND = 'invoice_effect_order_not_found';
    public const ERROR_ORDER_NOT_PAID = 'invoice_effect_order_not_paid';
    public const ERROR_AMOUNT_SNAPSHOT = 'invoice_money_snapshot_invalid';
    public const ERROR_IDENTITY_CONFLICT = 'invoice_effect_identity_conflict';

    public function __construct(
        private readonly ObjectManager $objectManager,
        private readonly OrderService $orderService,
        private readonly WriteIntentTransactionCoordinatorInterface $transactions,
    ) {
    }

    public static function effectKeyForAttempt(string $attemptCode): string
    {
        $attemptCode = trim($attemptCode);
        if ($attemptCode === '') {
            throw new \InvalidArgumentException('invoice_attempt_code_required');
        }

        return 'attempt:' . $attemptCode . ':' . self::EFFECT_TYPE;
    }

    /**
     * Idempotently create or bind the only Invoice for a paid Order.
     *
     * @return array{ok:true,replayed:bool,invoice_id:int,invoice_number:string,order_id:int,order_uuid:string,effect_key:string,amount_minor:int,tax_amount_minor:int,tax_snapshot:array<string,mixed>,resource_mode:string}
     */
    public function ensureFromPaymentEffect(
        PaymentEffectRecord $effect,
        string $resourceMode,
    ): array {
        if ($effect->effectType !== self::EFFECT_TYPE) {
            throw new \InvalidArgumentException('invoice_effect_type_invalid');
        }
        if ($effect->payableType !== 'order') {
            throw new \RuntimeException(self::ERROR_PAYABLE_TYPE);
        }

        $order = $this->loadOrderForUpdate($effect->payableId);
        $this->assertEffectTransaction($order);
        $this->assertPaid($order);

        $orderId = (int)$order->getId();
        $orderUuid = (string)$order->getData(Order::schema_fields_ORDER_UUID);
        $amountMinor = $this->frozenGrandTotalMinor($order);
        $tax = $this->frozenTaxSnapshot($order);
        $existing = $this->loadInvoiceByOrder($orderId);
        if ($existing->getId()) {
            $existingEffect = trim((string)$existing->getData(OrderInvoice::schema_fields_EFFECT_KEY));
            if ($existingEffect !== '' && !hash_equals($existingEffect, $effect->effectKey)) {
                throw new \RuntimeException(self::ERROR_IDENTITY_CONFLICT);
            }
            $needsSave = false;
            if ($existingEffect === '') {
                $existing->setData(OrderInvoice::schema_fields_EFFECT_KEY, $effect->effectKey)
                    ->setData(OrderInvoice::schema_fields_ATTEMPT_CODE, $effect->attemptCode)
                    ->setData(OrderInvoice::schema_fields_AMOUNT_MINOR, $amountMinor)
                    ->setData(OrderInvoice::schema_fields_RESOURCE_MODE, $resourceMode);
                $needsSave = true;
            }
            $existingAmount = $existing->getData(OrderInvoice::schema_fields_AMOUNT_MINOR);
            if ($existingAmount === null || $existingAmount === '') {
                $existing->setData(OrderInvoice::schema_fields_AMOUNT_MINOR, $amountMinor);
                $needsSave = true;
            } elseif ((int) $existingAmount !== $amountMinor) {
                throw new \RuntimeException(self::ERROR_IDENTITY_CONFLICT);
            }
            $existingTaxJson = trim((string) $existing->getData(
                OrderInvoice::schema_fields_TAX_SNAPSHOT_JSON,
            ));
            if ($existingTaxJson === '') {
                $existing->setData(
                    OrderInvoice::schema_fields_TAX_AMOUNT_MINOR,
                    $tax->taxAmountMinor,
                )->setData(
                    OrderInvoice::schema_fields_TAX_SNAPSHOT_JSON,
                    $this->encodeTaxSnapshot($tax),
                );
                $needsSave = true;
            } else {
                $existingTax = TaxSnapshot::fromArray($this->decodeSnapshot($existingTaxJson));
                if ($existingTax->toArray() !== $tax->toArray()
                    || (int) $existing->getData(OrderInvoice::schema_fields_TAX_AMOUNT_MINOR)
                        !== $tax->taxAmountMinor
                ) {
                    throw new \RuntimeException(self::ERROR_IDENTITY_CONFLICT);
                }
            }
            if ($needsSave) {
                $existing->save();
            }

            return $this->result($existing, $orderUuid, $amountMinor, $tax, $resourceMode, true);
        }

        /** @var OrderInvoice $invoice */
        $invoice = $this->newModel(OrderInvoice::class);
        $invoice->setData([
            OrderInvoice::schema_fields_ORDER_ID => $orderId,
            OrderInvoice::schema_fields_INVOICE_NUMBER
                => OrderInvoice::deterministicInvoiceNumber($effect->effectKey),
            OrderInvoice::schema_fields_EFFECT_KEY => $effect->effectKey,
            OrderInvoice::schema_fields_ATTEMPT_CODE => $effect->attemptCode,
            OrderInvoice::schema_fields_AMOUNT => $this->minorToMajor($amountMinor),
            OrderInvoice::schema_fields_AMOUNT_MINOR => $amountMinor,
            OrderInvoice::schema_fields_TAX_AMOUNT_MINOR => $tax->taxAmountMinor,
            OrderInvoice::schema_fields_TAX_SNAPSHOT_JSON => $this->encodeTaxSnapshot($tax),
            OrderInvoice::schema_fields_RESOURCE_MODE => $resourceMode,
            OrderInvoice::schema_fields_STATUS => OrderInvoice::STATUS_ISSUED,
            OrderInvoice::schema_fields_ISSUED_AT => date('Y-m-d H:i:s'),
            OrderInvoice::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
        ])->save();

        return $this->result($invoice, $orderUuid, $amountMinor, $tax, $resourceMode, false);
    }

    /**
     * Legacy/manual invoice entry. It preserves the historical controller API
     * while enforcing the new one-invoice-per-order invariant.
     */
    public function generateInvoice(int $orderId): OrderInvoice
    {
        $order = $this->orderService->getOrder($orderId);
        $connection = $order->getConnection();

        return $this->transactions->runWrite(
            $connection,
            function () use ($orderId, $order): OrderInvoice {
                $existing = $this->loadInvoiceByOrder($orderId);
                if ($existing->getId()) {
                    throw new \RuntimeException(__('订单已生成发票'));
                }

                /** @var OrderInvoice $invoice */
                $invoice = $this->newModel(OrderInvoice::class);
                $amountMinor = $this->frozenGrandTotalMinor($order);
                $tax = $this->frozenTaxSnapshot($order);
                $invoice->setData([
                    OrderInvoice::schema_fields_ORDER_ID => $orderId,
                    OrderInvoice::schema_fields_INVOICE_NUMBER => $invoice->generateInvoiceNumber(),
                    OrderInvoice::schema_fields_AMOUNT => $this->minorToMajor($amountMinor),
                    OrderInvoice::schema_fields_AMOUNT_MINOR => $amountMinor,
                    OrderInvoice::schema_fields_TAX_AMOUNT_MINOR => $tax->taxAmountMinor,
                    OrderInvoice::schema_fields_TAX_SNAPSHOT_JSON
                        => $this->encodeTaxSnapshot($tax),
                    OrderInvoice::schema_fields_STATUS => OrderInvoice::STATUS_ISSUED,
                    OrderInvoice::schema_fields_RESOURCE_MODE => 'normal',
                    OrderInvoice::schema_fields_ISSUED_AT => date('Y-m-d H:i:s'),
                    OrderInvoice::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
                ])->save();

                return $invoice;
            },
        );
    }

    public function printInvoice(int $invoiceId): OrderInvoice
    {
        /** @var OrderInvoice $invoice */
        $invoice = $this->newModel(OrderInvoice::class)->load($invoiceId);
        if (!$invoice->getId()) {
            throw new \RuntimeException(__('发票不存在'));
        }

        return $invoice;
    }

    /** @return list<OrderInvoice> */
    public function getInvoiceList(int $orderId): array
    {
        $collection = $this->newModel(OrderInvoice::class)
            ->where(OrderInvoice::schema_fields_ORDER_ID, $orderId)
            ->order(OrderInvoice::schema_fields_CREATED_AT, 'DESC')
            ->select()
            ->fetch();

        return \is_object($collection) && method_exists($collection, 'getItems')
            ? array_values(array_filter(
                $collection->getItems(),
                static fn (mixed $item): bool => $item instanceof OrderInvoice,
            ))
            : [];
    }

    private function loadOrderForUpdate(string $orderUuid): Order
    {
        $orderUuid = trim($orderUuid);
        if ($orderUuid === '') {
            throw new \RuntimeException(self::ERROR_ORDER_NOT_FOUND);
        }
        /** @var Order $order */
        $order = $this->newModel(Order::class)
            ->where(Order::schema_fields_ORDER_UUID, $orderUuid);
        if (!$this->isSqlite($order)) {
            $order->additional('FOR UPDATE');
        }
        $order->find()->fetch();
        if (!$order->getId()
            || (string)$order->getData(Order::schema_fields_ORDER_UUID) !== $orderUuid
        ) {
            throw new \RuntimeException(self::ERROR_ORDER_NOT_FOUND);
        }

        return $order;
    }

    private function loadInvoiceByOrder(int $orderId): OrderInvoice
    {
        /** @var OrderInvoice $invoice */
        $invoice = $this->newModel(OrderInvoice::class)
            ->where(OrderInvoice::schema_fields_ORDER_ID, $orderId);
        if (!$this->isSqlite($invoice)) {
            $invoice->additional('FOR UPDATE');
        }
        $invoice->find()->fetch();

        return $invoice;
    }

    private function assertEffectTransaction(Order $order): void
    {
        if (!$this->transactions->isActive($order->getConnection())
            || !$this->transactions->isWriteIntent($order->getConnection())
        ) {
            throw new \LogicException(self::ERROR_TRANSACTION_REQUIRED);
        }
    }

    private function assertPaid(Order $order): void
    {
        $status = (string)$order->getData(Order::schema_fields_STATUS);
        $paymentStatus = (string)$order->getData(Order::schema_fields_PAYMENT_STATUS);
        if ($status !== Order::STATUS_PAID
            && $paymentStatus !== Order::PAYMENT_STATUS_PAID
        ) {
            throw new \RuntimeException(self::ERROR_ORDER_NOT_PAID);
        }
    }

    private function frozenGrandTotalMinor(Order $order): int
    {
        $snapshot = json_decode(
            (string)$order->getData(Order::schema_fields_MONEY_SNAPSHOT_JSON),
            true,
        );
        $value = \is_array($snapshot) ? ($snapshot['grand_total_minor'] ?? null) : null;
        if (\is_int($value) && $value >= 0) {
            return $value;
        }
        if (\is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1) {
            $minor = (int)$value;
            if ((string)$minor === $value) {
                return $minor;
            }
        }

        throw new \RuntimeException(self::ERROR_AMOUNT_SNAPSHOT);
    }

    private function frozenTaxSnapshot(Order $order): TaxSnapshot
    {
        $money = $this->decodeSnapshot(
            (string) $order->getData(Order::schema_fields_MONEY_SNAPSHOT_JSON),
        );
        $taxAmount = $money['tax_amount_minor'] ?? null;
        if (is_string($taxAmount) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $taxAmount) === 1) {
            $taxAmount = (int) $taxAmount;
        }
        if (!is_int($taxAmount) || $taxAmount < 0) {
            throw new \RuntimeException(self::ERROR_AMOUNT_SNAPSHOT);
        }

        $taxJson = trim((string) $order->getData(Order::schema_fields_TAX_SNAPSHOT_JSON));
        $tax = $taxJson === ''
            ? TaxSnapshot::legacyFrozen(
                $taxAmount,
                (string) $order->getData(Order::schema_fields_CURRENCY),
                (int) $order->getData(Order::schema_fields_WEBSITE_ID),
                (int) $order->getData(Order::schema_fields_STORE_ID),
            )
            : TaxSnapshot::fromArray($this->decodeSnapshot($taxJson));
        if ($tax->taxAmountMinor !== $taxAmount) {
            throw new \RuntimeException(self::ERROR_AMOUNT_SNAPSHOT);
        }

        return $tax;
    }

    /** @return array{ok:true,replayed:bool,invoice_id:int,invoice_number:string,order_id:int,order_uuid:string,effect_key:string,amount_minor:int,tax_amount_minor:int,tax_snapshot:array<string,mixed>,resource_mode:string} */
    private function result(
        OrderInvoice $invoice,
        string $orderUuid,
        int $amountMinor,
        TaxSnapshot $tax,
        string $resourceMode,
        bool $replayed,
    ): array {
        return [
            'ok' => true,
            'replayed' => $replayed,
            'invoice_id' => (int)$invoice->getId(),
            'invoice_number' => (string)$invoice->getData(
                OrderInvoice::schema_fields_INVOICE_NUMBER,
            ),
            'order_id' => (int)$invoice->getData(OrderInvoice::schema_fields_ORDER_ID),
            'order_uuid' => $orderUuid,
            'effect_key' => (string)$invoice->getData(OrderInvoice::schema_fields_EFFECT_KEY),
            'amount_minor' => $amountMinor,
            'tax_amount_minor' => $tax->taxAmountMinor,
            'tax_snapshot' => $tax->toArray(),
            'resource_mode' => $resourceMode,
        ];
    }

    private function encodeTaxSnapshot(TaxSnapshot $tax): string
    {
        return json_encode(
            $tax->toArray(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string,mixed> */
    private function decodeSnapshot(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(self::ERROR_AMOUNT_SNAPSHOT, previous: $e);
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException(self::ERROR_AMOUNT_SNAPSHOT);
        }

        return $decoded;
    }

    private function minorToMajor(int $minor): string
    {
        if ($minor < 0) {
            throw new \RuntimeException(self::ERROR_AMOUNT_SNAPSHOT);
        }

        return intdiv($minor, 100) . '.' . str_pad((string)($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * @template T of Model
     * @param class-string<T> $class
     * @return T
     */
    private function newModel(string $class): Model
    {
        return $this->objectManager->getInstance($class, [], false);
    }

    private function isSqlite(Model $model): bool
    {
        return strtolower((string)$model->getConnection()
            ->getConnector()
            ->getConfigProvider()
            ->getDbType()) === 'sqlite';
    }
}
