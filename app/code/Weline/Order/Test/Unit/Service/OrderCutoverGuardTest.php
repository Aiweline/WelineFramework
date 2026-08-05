<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Event;
use Weline\Order\Api\Data\CreateCheckoutGroupCommand;
use Weline\Order\Api\Data\CreateCheckoutGroupResult;
use Weline\Order\Api\Data\OrderPlan;
use Weline\Order\Api\Data\OrderReadResult;
use Weline\Order\Api\OrderFacadeInterface;
use Weline\Order\Observer\AssertLegacyCheckoutWriter;
use Weline\Order\Service\OrderCompatibilityReader;
use Weline\Order\Service\OrderCutoverGate;
use Weline\Order\Service\OrderFacade;
use Weline\Order\Service\OrderFacadeConflictException;
use Weline\Order\Service\OrderShadowComparator;
use Weline\Order\Service\OrderWriterGuard;

/**
 * TEST-P2D-05：compat reader / writer guard / pure OrderPlan shadow.
 */
final class OrderCutoverGuardTest extends TestCase
{
    public function testShadowCompareEqualWithZeroNewWrites(): void
    {
        $guard = new OrderWriterGuard();
        $guard->gate()->setMode(OrderCutoverGate::MODE_SHADOW);
        $facade = OrderFacade::forTesting(writerGuard: $guard);
        $cmp = new OrderShadowComparator($facade, $guard);

        $cmd = new CreateCheckoutGroupCommand(
            idempotencyKey: 'shadow-1',
            requestHash: hash('sha256', 'shadow-1'),
            websiteId: 0,
            storeId: 1,
            lines: [
                ['name' => 'A', 'qty_minor' => 1, 'unit_price_minor' => 1000, 'split_key' => 'v1', 'requires_shipping' => true],
                ['name' => 'B', 'qty_minor' => 2, 'unit_price_minor' => 250, 'split_key' => 'v2', 'requires_shipping' => true],
            ],
            shippingAmountMinor: 150,
        );

        $legacyFacts = 0;
        $effects = [
            'dml' => 0,
            'lock' => 0,
            'reservation' => 0,
            'outbox' => 0,
            'cache' => 0,
        ];
        $result = $cmp->compare(
            $cmd,
            static function (CreateCheckoutGroupCommand $c) use (&$legacyFacts): OrderPlan {
                // Simulate the legacy path producing its single expected fact.
                $legacyFacts++;
                return OrderFacade::forTesting()->plan($c);
            },
            static fn (): array => $effects,
        );

        self::assertTrue($result['equal']);
        self::assertSame([], $result['diff']);
        self::assertSame(0, $result['new_writes']);
        self::assertSame($effects, $result['new_effects']);
        self::assertSame(OrderCutoverGate::MODE_SHADOW, $result['mode']);
        self::assertSame(1, $legacyFacts);

        // New create blocked in shadow
        try {
            $facade->create($cmd);
            self::fail('shadow must block new create');
        } catch (OrderFacadeConflictException $e) {
            self::assertSame(OrderWriterGuard::ERROR_NEW_BLOCKED, $e->errorCode());
        }
        self::assertSame(0, $facade->writeCount());
    }

    public function testOnModeBlocksLegacyAndAllowsNew(): void
    {
        $gate = new OrderCutoverGate();
        $gate->setMode(OrderCutoverGate::MODE_ON, productionOnToken: 'mig-token-test');
        $guard = new OrderWriterGuard($gate);

        try {
            $guard->assertLegacyCheckoutWritable('website:0');
            self::fail('legacy must be blocked');
        } catch (OrderFacadeConflictException $e) {
            self::assertSame(OrderWriterGuard::ERROR_LEGACY_BLOCKED, $e->errorCode());
        }

        $facade = OrderFacade::forTesting(writerGuard: $guard);
        $cmd = new CreateCheckoutGroupCommand(
            idempotencyKey: 'on-1',
            requestHash: hash('sha256', 'on-1'),
            websiteId: 0,
            storeId: 0,
            lines: [['name' => 'X', 'qty_minor' => 1, 'unit_price_minor' => 10]],
        );
        $created = $facade->create($cmd);
        self::assertNotSame('', $created->checkoutGroupUuid);
    }

    public function testExecuteCutoverRequiresTokenAndLegacyRollbackForbidden(): void
    {
        $gate = new OrderCutoverGate();
        try {
            $gate->executeCutover(['watermark' => 1]);
            self::fail('cutover without token must fail');
        } catch (OrderFacadeConflictException $e) {
            self::assertSame(OrderCutoverGate::ERROR_CUTOVER_NOT_AUTHORIZED, $e->errorCode());
        }
        $ok = $gate->executeCutover([
            'watermark' => 9,
            'production_on_token' => 'mig-token',
        ]);
        self::assertTrue($ok['ok']);
        self::assertTrue($gate->isCutoverApplied());
        self::assertFalse($gate->legacyWritable());
        try {
            $gate->forbidLegacyWriterRollback();
            self::fail('rollback legacy forbidden');
        } catch (OrderFacadeConflictException $e) {
            self::assertSame(OrderCutoverGate::ERROR_ROLLBACK_LEGACY, $e->errorCode());
        }
    }

    public function testCompatibilityReaderPrefersNewButKeepsLegacyReadable(): void
    {
        $facade = OrderFacade::forTesting();
        $cmd = new CreateCheckoutGroupCommand(
            idempotencyKey: 'compat-1',
            requestHash: hash('sha256', 'compat-1'),
            websiteId: 0,
            storeId: 0,
            lines: [['name' => 'N', 'qty_minor' => 1, 'unit_price_minor' => 5]],
        );
        $created = $facade->create($cmd);
        $uuid = $created->orderUuids[0];

        $reader = OrderCompatibilityReader::forTesting($facade);
        $reader->seedLegacy('LEGACY-1', [
            'order_number' => 'LEGACY-1',
            'status' => 'pending',
            'currency' => 'CNY',
            'website_id' => 0,
            'store_id' => 0,
            'subtotal' => 1.0,
            'shipping_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 1.0,
        ]);

        $new = $reader->readUnified($uuid, OrderCompatibilityReader::SOURCE_NEW);
        self::assertNotNull($new);
        self::assertSame(OrderCompatibilityReader::SOURCE_NEW, $new['source']);

        $legacy = $reader->readUnified('LEGACY-1', OrderCompatibilityReader::SOURCE_NEW);
        self::assertNotNull($legacy);
        self::assertSame(OrderCompatibilityReader::SOURCE_LEGACY, $legacy['source']);
    }

    public function testShadowComparatorRejectsFalseGreenItemAndSideEffect(): void
    {
        $guard = new OrderWriterGuard();
        $guard->gate()->setMode(OrderCutoverGate::MODE_SHADOW);
        $facade = OrderFacade::forTesting(writerGuard: $guard);
        $cmp = new OrderShadowComparator($facade, $guard);
        $cmd = new CreateCheckoutGroupCommand(
            idempotencyKey: 'shadow-false-green',
            requestHash: hash('sha256', 'shadow-false-green'),
            websiteId: 0,
            storeId: 0,
            lines: [['name' => 'SKU-A', 'qty_minor' => 1, 'unit_price_minor' => 100]],
        );

        $snapshotCalls = 0;
        $result = $cmp->compare(
            $cmd,
            static function (CreateCheckoutGroupCommand $command): array {
                $legacy = OrderFacade::forTesting()->plan($command)->toArray();
                $legacy['orders'][0]['items'][0]['qty_minor'] = 2;
                return $legacy;
            },
            static function () use (&$snapshotCalls): array {
                $snapshotCalls++;
                return [
                    'dml' => 0,
                    'lock' => 0,
                    'reservation' => 0,
                    'outbox' => $snapshotCalls > 1 ? 1 : 0,
                    'cache' => 0,
                ];
            },
        );

        self::assertFalse($result['equal']);
        self::assertContains('orders', $result['diff']);
        self::assertContains('new_side_effect:outbox', $result['diff']);
        self::assertSame(1, $result['new_effects']['outbox']);
    }

    public function testShadowComparatorRequiresShadowMode(): void
    {
        $guard = new OrderWriterGuard();
        $cmp = new OrderShadowComparator(OrderFacade::forTesting(writerGuard: $guard), $guard);
        $cmd = new CreateCheckoutGroupCommand(
            idempotencyKey: 'shadow-mode',
            requestHash: hash('sha256', 'shadow-mode'),
            lines: [['name' => 'SKU', 'qty_minor' => 1, 'unit_price_minor' => 1]],
        );

        $this->expectException(OrderFacadeConflictException::class);
        $this->expectExceptionMessage('shadow mode');
        $cmp->compare(
            $cmd,
            static fn (CreateCheckoutGroupCommand $command): OrderPlan => OrderFacade::forTesting()->plan($command),
            static fn (): array => [
                'dml' => 0,
                'lock' => 0,
                'reservation' => 0,
                'outbox' => 0,
                'cache' => 0,
            ],
        );
    }

    public function testCompatibilityListIncludesNewOnlyOrder(): void
    {
        $facade = OrderFacade::forTesting();
        $cmd = new CreateCheckoutGroupCommand(
            idempotencyKey: 'compat-list',
            requestHash: hash('sha256', 'compat-list'),
            lines: [['name' => 'N', 'qty_minor' => 1, 'unit_price_minor' => 5]],
        );
        $created = $facade->create($cmd);

        $rows = OrderCompatibilityReader::forTesting($facade)->listReadable(
            legacyRows: [[
                'order_number' => 'LEGACY-LIST',
                'currency' => 'CNY',
                'subtotal' => '1.00',
                'total_amount' => '1.00',
            ]],
            newOrderUuids: [$created->orderUuids[0]],
        );

        self::assertCount(2, $rows);
        self::assertSame(
            [OrderCompatibilityReader::SOURCE_LEGACY, OrderCompatibilityReader::SOURCE_NEW],
            array_values(array_unique(array_column($rows, 'source'))),
        );
    }

    public function testCheckoutObserverBlocksOnModeAndUnknownAllowlistSubject(): void
    {
        $gate = new OrderCutoverGate();
        $gate->setMode(OrderCutoverGate::MODE_ON, productionOnToken: 'test-token');
        $observer = new AssertLegacyCheckoutWriter(new OrderWriterGuard($gate));
        $event = new Event(['data' => ['data' => ['website_id' => 0]]]);

        try {
            $observer->execute($event);
            self::fail('on mode must block legacy checkout writer');
        } catch (OrderFacadeConflictException $e) {
            self::assertSame(OrderWriterGuard::ERROR_LEGACY_BLOCKED, $e->errorCode());
        }

        $allowlistGate = new OrderCutoverGate();
        $allowlistGate->setMode(OrderCutoverGate::MODE_ALLOWLIST, ['website:1']);
        $observer = new AssertLegacyCheckoutWriter(new OrderWriterGuard($allowlistGate));
        $missingScopeEvent = new Event(['data' => ['data' => []]]);
        $this->expectException(OrderFacadeConflictException::class);
        $observer->execute($missingScopeEvent);
    }

    public function testCompatibilityReaderDoesNotHideNewReaderFailure(): void
    {
        $facade = new class implements OrderFacadeInterface {
            public function plan(CreateCheckoutGroupCommand $command): OrderPlan
            {
                throw new \LogicException('unused');
            }

            public function create(CreateCheckoutGroupCommand $command): CreateCheckoutGroupResult
            {
                throw new \LogicException('unused');
            }

            public function get(string $orderUuid): OrderReadResult
            {
                throw new OrderFacadeConflictException(
                    'order_reader_unavailable',
                    'reader unavailable',
                );
            }

            public function notifyOrderPaid(string $orderUuid, array $context = []): void
            {
            }
        };
        $reader = new OrderCompatibilityReader($facade);
        $reader->seedLegacy('SAME-KEY', ['order_number' => 'SAME-KEY']);

        try {
            $reader->readUnified('SAME-KEY');
            self::fail('new reader failure must not silently fall back to legacy');
        } catch (OrderFacadeConflictException $e) {
            self::assertSame('order_reader_unavailable', $e->errorCode());
        }
    }
}
