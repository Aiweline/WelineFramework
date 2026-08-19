<?php

declare(strict_types=1);

namespace Weline\Order\Test\Integration;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Api\Data\CreateCheckoutGroupCommand;
use Weline\Order\Api\Data\OrderPaidContext;
use Weline\Order\Api\OrderFacadeInterface;
use Weline\Order\Api\OrderPostPaymentHookInterface;
use Weline\Order\Model\CheckoutGroup;
use Weline\Order\Model\DisplayNumberRegistry;
use Weline\Order\Model\FulfillmentUnit;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderInvoice;
use Weline\Order\Model\OrderItem;
use Weline\Order\Service\InvoiceService;
use Weline\Order\Service\OrderFacade;
use Weline\Order\Service\OrderFacadeConflictException;
use Weline\Order\Service\OrderPaidStateHook;
use Weline\Payment\Api\Data\PaymentEffectRecord;
use Weline\Tax\Service\TaxEngine;

/**
 * PostgreSQL/local configured DB smoke for TEST-P2D-01.
 *
 * All writes are enclosed by the framework transaction coordinator and rolled
 * back before the test returns.
 */
final class OrderFacadeDatabaseIntegrationTest extends TestCase
{
    public function testPublishedInterfaceResolvesToFacade(): void
    {
        $facade = ObjectManager::getInstance(OrderFacadeInterface::class);
        self::assertInstanceOf(OrderFacade::class, $facade);
        self::assertInstanceOf(OrderPaidStateHook::class, $facade->postPaymentHook());
        self::assertInstanceOf(
            OrderPaidStateHook::class,
            ObjectManager::getInstance(OrderPostPaymentHookInterface::class),
        );
    }

    public function testConfiguredDatabaseCreateReplayConflictReadAndRollback(): void
    {
        /** @var CheckoutGroup $transaction */
        $transaction = ObjectManager::getInstance(CheckoutGroup::class);
        $transaction->beginTransaction();
        $key = 'p2d001-' . bin2hex(random_bytes(8));
        $hash = hash('sha256', $key);
        $groupUuid = null;
        $orderUuids = [];

        try {
            $hook = new class implements OrderPostPaymentHookInterface {
                public ?OrderPaidContext $received = null;

                public function afterOrderPaid(OrderPaidContext $context): void
                {
                    $this->received = $context;
                }
            };
            $facade = new OrderFacade(postPaymentHook: $hook);
            $command = new CreateCheckoutGroupCommand(
                idempotencyKey: $key,
                requestHash: $hash,
                websiteId: 0,
                storeId: 0,
                currency: 'CNY',
                lines: [
                    ['name' => 'DB A', 'qty_minor' => 1, 'unit_price_minor' => 100, 'split_key' => 'a'],
                    ['name' => 'DB B', 'qty_minor' => 2, 'unit_price_minor' => 100, 'split_key' => 'b'],
                ],
                shippingMethod: 'integration',
                shippingAmountMinor: 50,
            );

            $created = $facade->create($command);
            $replayed = $facade->create($command);
            $groupUuid = $created->checkoutGroupUuid;
            $orderUuids = $created->orderUuids;

            self::assertFalse($created->replayed);
            self::assertTrue($replayed->replayed);
            self::assertSame($created->checkoutGroupUuid, $replayed->checkoutGroupUuid);
            self::assertSame($created->orderUuids, $replayed->orderUuids);
            self::assertCount(2, $created->orderUuids);
            self::assertSame(350, $created->totals['grand_total_minor']);

            foreach ($created->orderUuids as $orderUuid) {
                $read = $facade->get($orderUuid);
                self::assertSame($created->checkoutGroupUuid, $read->checkoutGroupUuid);
                self::assertSame(OrderFacade::STATUS_PENDING, $read->status);
            }
            $facade->notifyOrderPaid(
                $created->orderUuids[0],
                ['payment_attempt_id' => 'configured-db'],
            );
            self::assertInstanceOf(OrderPaidContext::class, $hook->received);
            self::assertSame($created->orderUuids[0], $hook->received?->orderUuid);
            self::assertSame(150, $hook->received?->money->grandTotalMinor);
            self::assertSame(
                'configured-db',
                $hook->received?->metadata['payment_attempt_id'] ?? null,
            );

            try {
                $facade->create(new CreateCheckoutGroupCommand(
                    idempotencyKey: $key,
                    requestHash: hash('sha256', $key . '-different'),
                    websiteId: 0,
                    storeId: 0,
                    currency: 'CNY',
                    lines: [['name' => 'DB A', 'qty_minor' => 1, 'unit_price_minor' => 100]],
                ));
                self::fail('different hash must conflict');
            } catch (OrderFacadeConflictException $exception) {
                self::assertSame(OrderFacade::ERROR_HASH_CONFLICT, $exception->errorCode());
            }

            self::assertTrue($this->groupExists($key));
            self::assertSame(2, $this->orderCount($created->checkoutGroupUuid));
            self::assertSame(2, $this->displayNumberCount($created->orderUuids));
            self::assertSame(2, $this->fulfillmentCount($created->checkoutGroupUuid));
            $group = $facade->getGroup($created->checkoutGroupUuid);
            self::assertCount(1, $group['orders'][0]['fulfillment_units']);
            self::assertSame(50, $group['orders'][0]['snapshots']['shipping']['amount_minor']);
            self::assertSame('stub_zero', $group['orders'][0]['snapshots']['tax']['mode']);
        } finally {
            $transaction->rollBack();
        }

        self::assertFalse($this->groupExists($key));
        if ($groupUuid !== null) {
            self::assertSame(0, $this->orderCount($groupUuid));
        }
        self::assertSame(0, $this->displayNumberCount($orderUuids));
        if ($groupUuid !== null) {
            self::assertSame(0, $this->fulfillmentCount($groupUuid));
        }
    }

    public function testTaxSnapshotsRoundTripToItemsAndInvoiceAndRollback(): void
    {
        /** @var Order $connectionOwner */
        $connectionOwner = ObjectManager::getInstance(Order::class);
        /** @var WriteIntentTransactionCoordinatorInterface $transactions */
        $transactions = ObjectManager::getInstance(
            WriteIntentTransactionCoordinatorInterface::class,
        );
        $key = 'p3b002-' . bin2hex(random_bytes(8));
        $groupUuid = null;
        $orderUuids = [];
        $effectKey = null;

        try {
            $transactions->runWrite(
                $connectionOwner->getConnection(),
                function () use ($key, &$groupUuid, &$orderUuids, &$effectKey): void {
                    $engine = TaxEngine::forTesting();
                    $tax = $engine->calculate([
                        'website_id' => 0,
                        'store_id' => 0,
                        'currency' => 'CNY',
                        'jurisdiction_key' => 'CN|',
                        'rule_schema_version' => TaxEngine::SCHEMA_VERSION,
                        'lines' => [
                            [
                                'line_id' => 'p3b-db-line-a',
                                'tax_class_code' => 'standard',
                                'taxable_amount_minor' => 1000,
                            ],
                            [
                                'line_id' => 'p3b-db-line-b',
                                'tax_class_code' => 'reduced',
                                'taxable_amount_minor' => 2000,
                            ],
                        ],
                    ]);
                    $taxSnapshot = array_merge($tax, [
                        'mode' => 'engine',
                        'engine' => $tax['source'],
                        'note' => 'server_calculated_tax',
                    ]);
                    $command = new CreateCheckoutGroupCommand(
                        idempotencyKey: $key,
                        requestHash: hash('sha256', $key),
                        websiteId: 0,
                        storeId: 0,
                        currency: 'CNY',
                        lines: [
                            [
                                'line_uuid' => 'p3b-db-line-a',
                                'name' => 'Tax DB A',
                                'qty_minor' => 1,
                                'unit_price_minor' => 1000,
                                'split_key' => 'a',
                                'requires_shipping' => true,
                                'tax_class_code' => 'standard',
                            ],
                            [
                                'line_uuid' => 'p3b-db-line-b',
                                'name' => 'Tax DB B',
                                'qty_minor' => 1,
                                'unit_price_minor' => 2000,
                                'split_key' => 'b',
                                'requires_shipping' => false,
                                'tax_class_code' => 'reduced',
                            ],
                        ],
                        shippingMethod: 'integration',
                        shippingAmountMinor: 500,
                        options: [
                            'tax_mode' => 'engine',
                            'tax_amount_minor' => $tax['tax_amount_minor'],
                            'tax_snapshot' => $taxSnapshot,
                        ],
                    );
                    $facade = new OrderFacade();
                    $created = $facade->create($command);
                    $groupUuid = $created->checkoutGroupUuid;
                    $orderUuids = $created->orderUuids;

                    self::assertSame(310, $created->totals['tax_amount_minor']);
                    self::assertCount(2, $orderUuids);
                    $readTax = 0;
                    $itemTax = 0;
                    foreach ($orderUuids as $orderUuid) {
                        $read = (new OrderFacade())->get($orderUuid);
                        self::assertSame('engine', $read->tax['mode']);
                        $readTax += (int) $read->tax['tax_amount_minor'];
                        $items = (new OrderItem())
                            ->where(OrderItem::schema_fields_ORDER_UUID, $orderUuid)
                            ->select()
                            ->fetch();
                        self::assertCount(1, $items->getItems());
                        /** @var OrderItem $item */
                        $item = $items->getItems()[0];
                        $lineSnapshot = json_decode(
                            (string) $item->getData(OrderItem::schema_fields_TAX_SNAPSHOT_JSON),
                            true,
                            512,
                            JSON_THROW_ON_ERROR,
                        );
                        self::assertSame(
                            $lineSnapshot['tax_amount_minor'],
                            (int) round(
                                ((float) $item->getData(OrderItem::schema_fields_TAX_AMOUNT)) * 100,
                            ),
                        );
                        $itemTax += (int) $lineSnapshot['tax_amount_minor'];
                    }
                    self::assertSame(310, $readTax);
                    self::assertSame(310, $itemTax);

                    $invoiceOrderUuid = $orderUuids[0];
                    /** @var Order $order */
                    $order = (new Order())
                        ->where(Order::schema_fields_ORDER_UUID, $invoiceOrderUuid)
                        ->find()
                        ->fetch();
                    $order->setData(Order::schema_fields_STATUS, Order::STATUS_PAID)
                        ->setData(Order::schema_fields_PAYMENT_STATUS, Order::PAYMENT_STATUS_PAID)
                        ->save();
                    $attempt = 'p3b-invoice-' . bin2hex(random_bytes(4));
                    $effectKey = InvoiceService::effectKeyForAttempt($attempt);
                    $effect = new PaymentEffectRecord(
                        outboxCode: 'outbox-' . $attempt,
                        effectKey: $effectKey,
                        intentCode: 'intent-' . $attempt,
                        attemptCode: $attempt,
                        effectType: InvoiceService::EFFECT_TYPE,
                        payableType: 'order',
                        payableId: $invoiceOrderUuid,
                        schemaVersion: '1',
                    );
                    /** @var InvoiceService $invoices */
                    $invoices = ObjectManager::getInstance(InvoiceService::class);
                    $first = $invoices->ensureFromPaymentEffect($effect, 'normal');
                    $replay = $invoices->ensureFromPaymentEffect($effect, 'normal');

                    self::assertFalse($first['replayed']);
                    self::assertTrue($replay['replayed']);
                    self::assertSame($first['invoice_id'], $replay['invoice_id']);
                    self::assertSame($first['tax_snapshot'], $replay['tax_snapshot']);
                    self::assertSame(
                        $facade->get($invoiceOrderUuid)->tax,
                        $first['tax_snapshot'],
                    );
                    /** @var OrderInvoice $invoice */
                    $invoice = (new OrderInvoice())
                        ->where(OrderInvoice::schema_fields_EFFECT_KEY, $effectKey)
                        ->find()
                        ->fetch();
                    self::assertTrue((bool) $invoice->getId());
                    self::assertSame(
                        $first['tax_amount_minor'],
                        (int) $invoice->getData(OrderInvoice::schema_fields_TAX_AMOUNT_MINOR),
                    );

                    throw new \RuntimeException('p3b002_force_rollback');
                },
            );
            self::fail('transaction must roll back');
        } catch (\RuntimeException $e) {
            self::assertSame('p3b002_force_rollback', $e->getMessage());
        }

        self::assertFalse($this->groupExists($key));
        if ($groupUuid !== null) {
            self::assertSame(0, $this->orderCount($groupUuid));
        }
        self::assertSame(0, $this->displayNumberCount($orderUuids));
        if ($effectKey !== null) {
            $invoice = (new OrderInvoice())
                ->where(OrderInvoice::schema_fields_EFFECT_KEY, $effectKey)
                ->find()
                ->fetch();
            self::assertFalse((bool) $invoice->getId());
        }
    }

    private function groupExists(string $idempotencyKey): bool
    {
        $row = (new CheckoutGroup())
            ->where(CheckoutGroup::schema_fields_IDEMPOTENCY_KEY, $idempotencyKey)
            ->find()
            ->fetch();

        return $row instanceof CheckoutGroup && (bool)$row->getId();
    }

    private function orderCount(string $checkoutGroupUuid): int
    {
        $rows = (new Order())
            ->where(Order::schema_fields_CHECKOUT_GROUP_UUID, $checkoutGroupUuid)
            ->select()
            ->fetch();

        return count($rows->getItems());
    }

    /** @param list<string> $orderUuids */
    private function displayNumberCount(array $orderUuids): int
    {
        $total = 0;
        foreach ($orderUuids as $orderUuid) {
            $row = (new DisplayNumberRegistry())
                ->where(DisplayNumberRegistry::schema_fields_ENTITY_UUID, $orderUuid)
                ->find()
                ->fetch();
            if ($row instanceof DisplayNumberRegistry && $row->getId()) {
                $total++;
            }
        }

        return $total;
    }

    private function fulfillmentCount(string $checkoutGroupUuid): int
    {
        $rows = (new FulfillmentUnit())
            ->where(FulfillmentUnit::schema_fields_CHECKOUT_GROUP_UUID, $checkoutGroupUuid)
            ->select()
            ->fetch();

        return count($rows->getItems());
    }
}
