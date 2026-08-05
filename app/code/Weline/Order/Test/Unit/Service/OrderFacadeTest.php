<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Inventory\Api\Data\WarehouseAssignment;
use Weline\Inventory\Api\DefaultWarehouseResolverInterface;
use Weline\Order\Api\Data\CreateCheckoutGroupCommand;
use Weline\Order\Service\OrderFacade;
use Weline\Order\Service\OrderFacadeConflictException;
use Weline\Order\Service\WarehouseFulfillmentService;

/**
 * TEST-P2D-01 / TEST-P2D-02（Facade 层）：幂等与拆单守恒.
 */
final class OrderFacadeTest extends TestCase
{
    public function testIdempotentCreateSameHashAndConflictOnDifferentHash(): void
    {
        $facade = OrderFacade::forTesting();
        $cmd = $this->command('idem-1', hash('sha256', 'body-a'), [
            ['name' => 'A', 'qty_minor' => 1, 'unit_price_minor' => 1000, 'sku' => 'A'],
        ]);

        $a = $facade->create($cmd);
        $b = $facade->create($cmd);
        self::assertTrue($b->replayed);
        self::assertSame($a->checkoutGroupUuid, $b->checkoutGroupUuid);
        self::assertSame($a->orderUuids, $b->orderUuids);
        self::assertSame(1, $facade->groupCount());
        self::assertSame(1, $facade->writeCount());

        $conflict = $this->command('idem-1', hash('sha256', 'body-b'), [
            ['name' => 'A', 'qty_minor' => 1, 'unit_price_minor' => 1000, 'sku' => 'A'],
        ]);
        try {
            $facade->create($conflict);
            self::fail('hash conflict expected');
        } catch (OrderFacadeConflictException $e) {
            self::assertSame(OrderFacade::ERROR_HASH_CONFLICT, $e->errorCode());
        }
        self::assertSame(1, $facade->groupCount());
    }

    public function testPlanIsPureComputeWithZeroWrites(): void
    {
        $facade = OrderFacade::forTesting();
        $cmd = $this->command('plan-only', hash('sha256', 'plan'), [
            ['name' => 'A', 'qty_minor' => 2, 'unit_price_minor' => 500, 'split_key' => 'v1'],
            ['name' => 'B', 'qty_minor' => 1, 'unit_price_minor' => 300, 'split_key' => 'v2'],
        ], shippingAmountMinor: 200);

        $before = $facade->writeCount();
        $plan = $facade->plan($cmd);
        self::assertSame($before, $facade->writeCount());
        self::assertSame(0, $facade->groupCount());
        self::assertSame(2, $plan->totals['order_count']);
        self::assertSame(1300, $plan->totals['subtotal_minor']);
        self::assertSame(200, $plan->totals['shipping_amount_minor']);
        self::assertSame(1500, $plan->totals['grand_total_minor']);
        self::assertSame(0, $plan->shippingChargeOwnerIndex);
        self::assertTrue($plan->orders[0]['is_shipping_charge_owner']);
        self::assertSame(200, $plan->orders[0]['shipping_amount_minor']);
        self::assertSame(0, $plan->orders[1]['shipping_amount_minor']);
    }

    public function testSplitRequiredCreatesMultiOrderGroupWithConservedTotals(): void
    {
        $facade = OrderFacade::forTesting();
        $cmd = $this->command('split-1', hash('sha256', 'split'), [
            [
                'name' => 'VendorA Item',
                'qty_minor' => 1,
                'unit_price_minor' => 1000,
                'split_key' => 'vendor-a',
                'requires_shipping' => true,
                'offer_id' => 1,
            ],
            [
                'name' => 'VendorB Item',
                'qty_minor' => 2,
                'unit_price_minor' => 250,
                'split_key' => 'vendor-b',
                'requires_shipping' => true,
                'offer_id' => 2,
            ],
            [
                'name' => 'Digital',
                'qty_minor' => 1,
                'unit_price_minor' => 100,
                'split_key' => 'digital',
                'requires_shipping' => false,
                'offer_id' => 3,
            ],
        ], shippingAmountMinor: 150);

        $plan = $facade->plan($cmd);
        $result = $facade->create($cmd);
        self::assertCount(3, $result->orderUuids);
        self::assertSame($plan->totals['grand_total_minor'], $result->totals['grand_total_minor']);
        self::assertSame($plan->totals['subtotal_minor'], $result->totals['subtotal_minor']);
        self::assertSame($plan->totals['shipping_amount_minor'], $result->totals['shipping_amount_minor']);

        $shipSum = 0;
        $subSum = 0;
        $owners = 0;
        foreach ($result->orderUuids as $uuid) {
            $read = $facade->get($uuid);
            self::assertSame($result->checkoutGroupUuid, $read->checkoutGroupUuid);
            self::assertSame(OrderFacade::STATUS_PENDING, $read->status);
            $shipSum += (int)$read->money['shipping_amount_minor'];
            $subSum += (int)$read->money['subtotal_minor'];
            if ($read->isShippingChargeOwner) {
                $owners++;
                self::assertSame($result->shippingChargeOwnerOrderUuid, $read->orderUuid);
            }
        }
        self::assertSame(1, $owners);
        self::assertSame(150, $shipSum);
        self::assertSame(1000 + 500 + 100, $subSum);
        self::assertSame(0, $facade->get($result->orderUuids[0])->websiteId);
    }

    public function testWebsiteZeroIsValidScope(): void
    {
        $facade = OrderFacade::forTesting();
        $cmd = new CreateCheckoutGroupCommand(
            idempotencyKey: 'z0',
            requestHash: hash('sha256', 'z0'),
            websiteId: 0,
            storeId: 0,
            lines: [['name' => 'Z', 'qty_minor' => 1, 'unit_price_minor' => 1]],
        );
        $result = $facade->create($cmd);
        self::assertNotSame('', $result->checkoutGroupUuid);
        self::assertSame(0, $facade->get($result->orderUuids[0])->websiteId);
    }

    public function testFulfillmentSourceFollowsDurableWarehouseWriterFlag(): void
    {
        foreach ([false, true] as $writerEnabled) {
            $resolver = new class($writerEnabled) implements DefaultWarehouseResolverInterface {
                public function __construct(private readonly bool $writerEnabled)
                {
                }

                public function resolveDefault(int $websiteId, int $storeId): WarehouseAssignment
                {
                    return new WarehouseAssignment(
                        88,
                        $websiteId,
                        'DEFAULT',
                        'normal',
                        'logical',
                        $this->writerEnabled,
                    );
                }
            };
            $facade = OrderFacade::forTesting(defaultWarehouseResolver: $resolver);
            $command = new CreateCheckoutGroupCommand(
                idempotencyKey: 'writer-' . (int) $writerEnabled,
                requestHash: hash('sha256', 'writer-' . (int) $writerEnabled),
                websiteId: 0,
                storeId: 7,
                lines: [[
                    'name' => 'Physical',
                    'qty_minor' => 1,
                    'unit_price_minor' => 100,
                    'offer_id' => 701,
                    'requires_shipping' => true,
                ]],
            );

            $result = $facade->create($command);
            $unit = $facade->getGroup(
                $result->checkoutGroupUuid,
            )['orders'][0]['fulfillment_units'][0];
            self::assertSame(88, $unit['warehouse_id']);
            self::assertSame(
                $writerEnabled
                    ? WarehouseFulfillmentService::SOURCE_WAREHOUSE
                    : WarehouseFulfillmentService::SOURCE_LEGACY_DEFAULT,
                $unit['warehouse_source'],
            );
        }
    }

    public function testReadProjectionPreservesCustomerOwnerForPaymentEligibility(): void
    {
        $facade = OrderFacade::forTesting();
        $command = new CreateCheckoutGroupCommand(
            idempotencyKey: 'customer-owner',
            requestHash: hash('sha256', 'customer-owner'),
            websiteId: 0,
            storeId: 1,
            customerId: 42,
            currency: 'CNY',
            lines: [['name' => 'Owner Item', 'qty_minor' => 1, 'unit_price_minor' => 100]],
        );

        $result = $facade->create($command);
        $read = $facade->get($result->orderUuids[0]);

        self::assertSame(42, $read->customerId);
        self::assertSame(42, $read->toArray()['customer_id']);
    }

    public function testCommandValidationRejectsNonCanonicalHashCurrencyAndOverflow(): void
    {
        $facade = OrderFacade::forTesting();

        foreach ([
            new CreateCheckoutGroupCommand(
                idempotencyKey: 'bad-hash',
                requestHash: 'not-a-sha256',
                lines: [['name' => 'A', 'qty_minor' => 1, 'unit_price_minor' => 1]],
            ),
            new CreateCheckoutGroupCommand(
                idempotencyKey: 'bad-currency',
                requestHash: hash('sha256', 'bad-currency'),
                currency: 'cny',
                lines: [['name' => 'A', 'qty_minor' => 1, 'unit_price_minor' => 1]],
            ),
        ] as $command) {
            try {
                $facade->plan($command);
                self::fail('invalid command must fail before planning');
            } catch (OrderFacadeConflictException $exception) {
                self::assertSame(OrderFacade::ERROR_INVALID_COMMAND, $exception->errorCode());
            }
        }

        $overflow = new CreateCheckoutGroupCommand(
            idempotencyKey: 'overflow',
            requestHash: hash('sha256', 'overflow'),
            lines: [[
                'name' => 'A',
                'qty_minor' => PHP_INT_MAX,
                'unit_price_minor' => 2,
            ]],
        );
        try {
            $facade->plan($overflow);
            self::fail('overflow must fail before a plan is returned');
        } catch (OrderFacadeConflictException $exception) {
            self::assertSame(OrderFacade::ERROR_AMOUNT_OVERFLOW, $exception->errorCode());
        }
        self::assertSame(0, $facade->writeCount());
    }

    /**
     * @param list<array<string,mixed>> $lines
     */
    private function command(
        string $key,
        string $hash,
        array $lines,
        int $shippingAmountMinor = 0,
    ): CreateCheckoutGroupCommand {
        return new CreateCheckoutGroupCommand(
            idempotencyKey: $key,
            requestHash: $hash,
            websiteId: 0,
            storeId: 1,
            currency: 'CNY',
            lines: $lines,
            shippingAmountMinor: $shippingAmountMinor,
        );
    }
}
