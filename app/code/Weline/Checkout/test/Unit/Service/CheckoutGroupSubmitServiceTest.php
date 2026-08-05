<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Checkout\Service\CheckoutGroupSubmitService;
use Weline\Checkout\Service\CheckoutV2ConflictException;
use Weline\Checkout\Service\ShippingAllocationService;
use Weline\Inventory\Api\Data\AvailabilityResult;
use Weline\Inventory\Api\Data\ReservationResult;
use Weline\Inventory\Api\Data\WarehouseAssignment;
use Weline\Inventory\Api\DefaultWarehouseResolverInterface;
use Weline\Inventory\Api\InventoryCapabilityInterface;
use Weline\Inventory\Api\WarehouseInventoryCapabilityInterface;
use Weline\Inventory\Model\Warehouse;
use Weline\Order\Service\OrderFacade;
use Weline\Shipping\Api\Quote\ShippingQuote;
use Weline\Shipping\Api\Quote\ShippingQuoteRequest;
use Weline\Shipping\Api\Quote\ShippingQuoteServiceInterface;
use Weline\Shipping\Service\ScopedShippingQuoteService;
use Weline\Shipping\Service\ShippingQuoteConflictException;

/**
 * TEST-P2E-04 .. TEST-P2E-08.
 */
final class CheckoutGroupSubmitServiceTest extends TestCase
{
    private function rates(): array
    {
        return [
            'std' => ['amount_minor' => 1500, 'label' => 'Standard', 'currencies' => ['CNY']],
            'exp' => ['amount_minor' => 3000, 'label' => 'Express', 'currencies' => ['CNY', 'USD']],
        ];
    }

    private function submitter(string $configVersion = '1'): CheckoutGroupSubmitService
    {
        $quotes = ScopedShippingQuoteService::forTesting($this->rates(), $configVersion);
        return CheckoutGroupSubmitService::forTesting($quotes);
    }

    public function testMissingCurrencyAndBlockedComboFailClosed(): void
    {
        $svc = $this->submitter();
        try {
            $svc->freezeAndQuote(
                lines: [['name' => 'A', 'qty_minor' => 1, 'unit_price_minor' => 100, 'requires_shipping' => true]],
                address: ['country' => 'CN'],
                scope: ['website_id' => 0, 'store_id' => 1],
                serviceCode: 'std',
                currency: '',
            );
            self::fail('missing currency');
        } catch (CheckoutV2ConflictException $e) {
            self::assertSame(CheckoutGroupSubmitService::ERROR_CURRENCY, $e->errorCode());
        }

        try {
            $svc->freezeAndQuote(
                lines: [
                    ['name' => 'A', 'qty_minor' => 1, 'unit_price_minor' => 100, 'split_key' => 'v1', 'requires_shipping' => true, 'legal_entity' => 'A'],
                    ['name' => 'B', 'qty_minor' => 1, 'unit_price_minor' => 100, 'split_key' => 'v2', 'requires_shipping' => true, 'legal_entity' => 'B'],
                ],
                address: ['country' => 'CN'],
                scope: ['website_id' => 0, 'store_id' => 1],
                serviceCode: 'std',
                currency: 'CNY',
            );
            self::fail('blocked combo');
        } catch (CheckoutV2ConflictException $e) {
            self::assertSame(ShippingAllocationService::ERROR_BLOCKED_COMBO, $e->errorCode());
        }

        $quotes = ScopedShippingQuoteService::forTesting($this->rates());
        try {
            $quotes->listOptions(new \Weline\Shipping\Api\Quote\ShippingQuoteRequest(
                scope: [],
                address: [],
                lines: [['requires_shipping' => true]],
                currency: 'EUR',
            ));
            self::fail('unsupported currency template');
        } catch (ShippingQuoteConflictException $e) {
            self::assertSame(ScopedShippingQuoteService::ERROR_TEMPLATE, $e->errorCode());
        }
    }

    public function testConfigVersionChangeRequiresRequote(): void
    {
        $quotes = ScopedShippingQuoteService::forTesting($this->rates(), '1');
        $svc = CheckoutGroupSubmitService::forTesting($quotes);
        $frozen = $svc->freezeAndQuote(
            lines: [['name' => 'A', 'qty_minor' => 1, 'unit_price_minor' => 100, 'requires_shipping' => true]],
            address: ['country' => 'CN'],
            scope: ['website_id' => 0, 'store_id' => 0],
            serviceCode: 'std',
            currency: 'CNY',
            configVersion: '1',
        );
        try {
            $svc->submit($frozen['quote_token'], 'idem-v', expectedConfigVersion: '2');
            self::fail('old token vs new config');
        } catch (CheckoutV2ConflictException $e) {
            self::assertSame(CheckoutGroupSubmitService::ERROR_QUOTE_TOKEN, $e->errorCode());
        }
    }

    public function testSingleQuoteOwnerHundredPercentAndLargestRemainder(): void
    {
        $svc = $this->submitter();
        $frozen = $svc->freezeAndQuote(
            lines: [
                [
                    'name' => 'ShipA',
                    'qty_minor' => 1,
                    'unit_price_minor' => 1000,
                    'split_key' => 'vendor-a',
                    'requires_shipping' => true,
                    'line_uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
                ],
                [
                    'name' => 'ShipB',
                    'qty_minor' => 1,
                    'unit_price_minor' => 500,
                    'split_key' => 'vendor-b',
                    'requires_shipping' => true,
                    'line_uuid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
                ],
                [
                    'name' => 'Digital',
                    'qty_minor' => 1,
                    'unit_price_minor' => 200,
                    'split_key' => 'digital',
                    'requires_shipping' => false,
                    'line_uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
                ],
            ],
            address: ['country' => 'CN'],
            scope: ['website_id' => 0, 'store_id' => 1],
            serviceCode: 'std',
            currency: 'CNY',
        );

        self::assertSame(1, $svc->quoteCallCount());
        // ksort: digital, vendor-a, vendor-b → first shippable is vendor-a index 1
        self::assertSame(1, $frozen['allocation']['owner_index']);
        self::assertSame(1500, $frozen['allocation']['group_shipping_minor']);
        self::assertSame([0, 1500, 0], $frozen['allocation']['order_shipping_minor']);

        $itemShip = $frozen['allocation']['owner_item_shipping_minor'];
        self::assertSame(1500, array_sum($itemShip));

        $result = $svc->submit($frozen['quote_token'], 'idem-split');
        self::assertCount(3, $result->orderUuids);
        self::assertSame(1500, $result->totals['shipping_amount_minor']);
        self::assertSame(0, $result->totals['tax_amount_minor']);
        self::assertSame(1, $svc->quoteCallCount()); // no second quote on submit
    }

    public function testClientShippingTaxRejectedAndTaxStubZero(): void
    {
        $svc = $this->submitter();
        try {
            $svc->freezeAndQuote(
                lines: [['name' => 'A', 'qty_minor' => 1, 'unit_price_minor' => 100, 'requires_shipping' => true]],
                address: [],
                scope: ['website_id' => 0, 'store_id' => 0],
                serviceCode: 'std',
                currency: 'CNY',
                clientHints: ['shipping_amount_minor' => 1],
            );
            self::fail('client shipping');
        } catch (CheckoutV2ConflictException $e) {
            self::assertSame(CheckoutGroupSubmitService::ERROR_CLIENT_MONEY, $e->errorCode());
        }

        $frozen = $svc->freezeAndQuote(
            lines: [['name' => 'A', 'qty_minor' => 1, 'unit_price_minor' => 100, 'requires_shipping' => true]],
            address: [],
            scope: ['website_id' => 0, 'store_id' => 0],
            serviceCode: 'std',
            currency: 'CNY',
        );
        self::assertSame(CheckoutGroupSubmitService::TAX_STUB_MODE, $frozen['tax']['mode']);
        self::assertSame(0, $frozen['tax']['tax_amount_minor']);

        try {
            $svc->submit($frozen['quote_token'], 'idem-tax', clientHints: ['tax_amount_minor' => 99]);
            self::fail('client tax');
        } catch (CheckoutV2ConflictException $e) {
            self::assertSame(CheckoutGroupSubmitService::ERROR_CLIENT_MONEY, $e->errorCode());
        }
    }

    public function testVirtualOnlyZeroShippingAndMixedUsesCompat(): void
    {
        $svc = $this->submitter();
        $virtual = $svc->freezeAndQuote(
            lines: [
                ['name' => 'E', 'qty_minor' => 1, 'unit_price_minor' => 100, 'requires_shipping' => false, 'split_key' => 'd1'],
                ['name' => 'F', 'qty_minor' => 1, 'unit_price_minor' => 200, 'requires_shipping' => false, 'split_key' => 'd2'],
            ],
            address: [],
            scope: ['website_id' => 0, 'store_id' => 0],
            serviceCode: '',
            currency: 'CNY',
        );
        self::assertSame(0, $virtual['quote']['amount_minor']);
        self::assertSame('none', $virtual['quote']['service_code']);
        self::assertNull($virtual['allocation']['owner_index']);

        $mixed = $svc->freezeAndQuote(
            lines: [
                ['name' => 'Phys', 'qty_minor' => 1, 'unit_price_minor' => 100, 'requires_shipping' => true, 'split_key' => 'p'],
                ['name' => 'Dig', 'qty_minor' => 1, 'unit_price_minor' => 50, 'requires_shipping' => false, 'split_key' => 'd'],
            ],
            address: [],
            scope: ['website_id' => 0, 'store_id' => 0],
            serviceCode: 'std',
            currency: 'CNY',
        );
        self::assertSame(1500, $mixed['quote']['amount_minor']);
        self::assertNotNull($mixed['allocation']['owner_index']);
        $result = $svc->submit($mixed['quote_token'], 'idem-mix');
        self::assertCount(2, $result->orderUuids);
    }

    public function testOwnerItemRemainderExact(): void
    {
        $alloc = new ShippingAllocationService();
        $orders = [[
            'split_key' => 'o',
            'requires_shipping' => true,
            'items' => [
                ['line_uuid' => 'u1', 'row_total_minor' => 100, 'qty_minor' => 1, 'requires_shipping' => true],
                ['line_uuid' => 'u2', 'row_total_minor' => 100, 'qty_minor' => 1, 'requires_shipping' => true],
                ['line_uuid' => 'u3', 'row_total_minor' => 100, 'qty_minor' => 1, 'requires_shipping' => true],
            ],
        ]];
        $r = $alloc->allocate($orders, 100);
        self::assertSame(100, array_sum($r['owner_item_shipping_minor']));
        self::assertSame(100, $r['order_shipping_minor'][0]);
    }

    public function testInventoryReservationIsFrozenIntoOrderAndSameKeyReplays(): void
    {
        $inventory = new class implements InventoryCapabilityInterface {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function getAvailability(int $websiteId, int $storeId, int $offerId): AvailabilityResult
            {
                return new AvailabilityResult(
                    $websiteId,
                    $storeId,
                    $offerId,
                    self::STRATEGY_STRICT,
                    10,
                    0,
                    10,
                    true,
                    1,
                );
            }

            public function reserve(
                int $websiteId,
                int $storeId,
                int $offerId,
                int $quantityMinor,
                string $idempotencyKey,
                string $requestHash,
            ): ReservationResult {
                $this->calls[] = compact(
                    'websiteId',
                    'storeId',
                    'offerId',
                    'quantityMinor',
                    'idempotencyKey',
                    'requestHash',
                );

                return new ReservationResult(
                    reservationUuid: 'res-0001',
                    state: 'active',
                    quantityMinor: $quantityMinor,
                    idempotencyKey: $idempotencyKey,
                    requestHash: $requestHash,
                );
            }

            public function release(string $reservationUuid): void
            {
            }
        };
        $quotes = ScopedShippingQuoteService::forTesting($this->rates());
        $orders = OrderFacade::forTesting();
        $svc = CheckoutGroupSubmitService::forTesting(
            $quotes,
            orderFacade: $orders,
            inventory: $inventory,
        );
        $frozen = $svc->freezeAndQuote(
            lines: [[
                'line_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                'offer_id' => 901,
                'product_id' => 90,
                'name' => 'Reserved',
                'qty_minor' => 2,
                'unit_price_minor' => 499,
                'requires_shipping' => true,
            ]],
            address: ['country' => 'CN'],
            scope: ['website_id' => 0, 'store_id' => 3],
            serviceCode: 'std',
            currency: 'CNY',
            cartHash: 'cart-authoritative',
        );

        $first = $svc->submit($frozen['quote_token'], 'idem-reserve');
        self::assertFalse($first->replayed);
        self::assertCount(1, $inventory->calls);
        self::assertSame(901, $inventory->calls[0]['offerId']);
        self::assertSame(2, $inventory->calls[0]['quantityMinor']);
        self::assertSame(
            'res-0001',
            $orders->get($first->orderUuids[0])->items[0]['reservation_uuid'],
        );

        $session = $svc->getSession($frozen['quote_token']);
        self::assertSame('submitted', $session['state']);
        self::assertSame('idem-reserve', $session['idempotency_key']);
        self::assertSame('res-0001', $session['reservations'][0]['reservation_uuid']);

        $replayed = $svc->submit($frozen['quote_token'], 'idem-reserve');
        self::assertTrue($replayed->replayed);
        self::assertSame($first->checkoutGroupUuid, $replayed->checkoutGroupUuid);
        self::assertCount(1, $inventory->calls);

        try {
            $svc->submit($frozen['quote_token'], 'different-key');
            self::fail('submitted quote token must reject another idempotency key');
        } catch (CheckoutV2ConflictException $e) {
            self::assertSame(CheckoutGroupSubmitService::ERROR_QUOTE_TOKEN, $e->errorCode());
        }
        self::assertCount(1, $inventory->calls);
    }

    public function testWarehouseWriterFlagBindsReservationAndOrderSource(): void
    {
        $inventory = new class implements InventoryCapabilityInterface {
            public function getAvailability(
                int $websiteId,
                int $storeId,
                int $offerId,
            ): AvailabilityResult {
                return new AvailabilityResult(
                    $websiteId,
                    $storeId,
                    $offerId,
                    self::STRATEGY_STRICT,
                    10,
                    0,
                    10,
                    true,
                    1,
                );
            }

            public function reserve(
                int $websiteId,
                int $storeId,
                int $offerId,
                int $quantityMinor,
                string $idempotencyKey,
                string $requestHash,
            ): ReservationResult {
                return new ReservationResult(
                    'res-warehouse-1',
                    'active',
                    $quantityMinor,
                    $idempotencyKey,
                    $requestHash,
                );
            }

            public function release(string $reservationUuid): void
            {
            }
        };
        $resolver = new class implements DefaultWarehouseResolverInterface {
            public function resolveDefault(int $websiteId, int $storeId): WarehouseAssignment
            {
                return new WarehouseAssignment(
                    88,
                    $websiteId,
                    'DEFAULT',
                    Warehouse::MODE_NORMAL,
                    Warehouse::TYPE_LOGICAL,
                    true,
                );
            }
        };
        $warehouse = new class implements WarehouseInventoryCapabilityInterface {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function assignReservationWarehouse(
                string $reservationUuid,
                int $websiteId,
                int $storeId,
                int $warehouseId,
                string $idempotencyKey,
                string $requestHash,
            ): ReservationResult {
                $this->calls[] = compact(
                    'reservationUuid',
                    'websiteId',
                    'storeId',
                    'warehouseId',
                    'idempotencyKey',
                    'requestHash',
                );

                return new ReservationResult(
                    $reservationUuid,
                    'active',
                    2,
                    $idempotencyKey,
                    $requestHash,
                );
            }

            public function returnCommittedToWarehouse(
                int $websiteId,
                int $storeId,
                int $warehouseId,
                int $offerId,
                int $quantityMinor,
                string $idempotencyKey,
                string $requestHash,
            ): void {
            }
        };
        $quotes = ScopedShippingQuoteService::forTesting($this->rates());
        $orders = OrderFacade::forTesting(defaultWarehouseResolver: $resolver);
        $service = CheckoutGroupSubmitService::forTesting(
            $quotes,
            orderFacade: $orders,
            inventory: $inventory,
            defaultWarehouseResolver: $resolver,
            warehouseInventory: $warehouse,
        );
        $frozen = $service->freezeAndQuote(
            lines: [[
                'line_uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                'offer_id' => 902,
                'product_id' => 91,
                'name' => 'Warehouse Reserved',
                'qty_minor' => 2,
                'unit_price_minor' => 599,
                'requires_shipping' => true,
            ]],
            address: ['country' => 'CN'],
            scope: ['website_id' => 0, 'store_id' => 7],
            serviceCode: 'std',
            currency: 'CNY',
            cartHash: 'cart-warehouse',
        );

        $result = $service->submit($frozen['quote_token'], 'idem-warehouse');
        self::assertCount(1, $warehouse->calls);
        self::assertSame('res-warehouse-1', $warehouse->calls[0]['reservationUuid']);
        self::assertSame(88, $warehouse->calls[0]['warehouseId']);
        $session = $service->getSession($frozen['quote_token']);
        self::assertSame(88, $session['reservations'][0]['warehouse_id']);
        self::assertSame('warehouse', $session['reservations'][0]['warehouse_source']);
        $unit = $orders->getGroup(
            $result->checkoutGroupUuid,
        )['orders'][0]['fulfillment_units'][0];
        self::assertSame(88, $unit['warehouse_id']);
        self::assertSame('warehouse', $unit['warehouse_source']);
    }

    public function testIdentityClientFactsAndLiveConfigDriftFailClosed(): void
    {
        $delegate = ScopedShippingQuoteService::forTesting($this->rates(), '1');
        $quotes = new class($delegate) implements ShippingQuoteServiceInterface {
            public string $version = '1';

            public function __construct(
                private readonly ShippingQuoteServiceInterface $delegate,
            ) {
            }

            public function activeConfigVersion(): string
            {
                return $this->version;
            }

            public function listOptions(ShippingQuoteRequest $request): array
            {
                return $this->delegate->listOptions($request);
            }

            public function quote(ShippingQuoteRequest $request, string $serviceCode): ShippingQuote
            {
                return $this->delegate->quote($request, $serviceCode);
            }
        };
        $svc = CheckoutGroupSubmitService::forTesting($quotes);

        try {
            $svc->freezeAndQuote(
                lines: [['name' => 'A', 'qty_minor' => 1, 'unit_price_minor' => 100, 'requires_shipping' => true]],
                address: [],
                scope: ['website_id' => 0, 'store_id' => 0],
                serviceCode: 'std',
                currency: 'CNY',
                clientHints: ['website_id' => 99],
            );
            self::fail('browser scope fact must be rejected');
        } catch (CheckoutV2ConflictException $e) {
            self::assertSame(CheckoutGroupSubmitService::ERROR_CLIENT_FACT, $e->errorCode());
        }

        $frozen = $svc->freezeAndQuote(
            lines: [['name' => 'A', 'qty_minor' => 1, 'unit_price_minor' => 100, 'requires_shipping' => true]],
            address: [],
            scope: ['website_id' => 0, 'store_id' => 0],
            serviceCode: 'std',
            currency: 'CNY',
            customerId: 9,
        );
        try {
            $svc->submit($frozen['quote_token'], 'identity-mismatch');
            self::fail('current customer must match frozen customer');
        } catch (CheckoutV2ConflictException $e) {
            self::assertSame(CheckoutGroupSubmitService::ERROR_IDENTITY, $e->errorCode());
        }

        $quotes->version = '2';
        try {
            $svc->submit($frozen['quote_token'], 'config-drift', customerId: 9);
            self::fail('active config drift must invalidate quote token');
        } catch (CheckoutV2ConflictException $e) {
            self::assertSame(CheckoutGroupSubmitService::ERROR_QUOTE_TOKEN, $e->errorCode());
        }
    }
}
