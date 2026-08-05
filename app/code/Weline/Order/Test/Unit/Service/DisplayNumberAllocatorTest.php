<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Api\Data\CreateCheckoutGroupCommand;
use Weline\Order\Api\Data\MoneySnapshot;
use Weline\Order\Api\Data\OrderPaidContext;
use Weline\Order\Api\OrderPostPaymentHookInterface;
use Weline\Order\Model\DisplayNumberRegistry;
use Weline\Order\Service\DisplayNumberAllocator;
use Weline\Order\Service\DisplayNumberLookup;
use Weline\Order\Service\NoopOrderPostPaymentHook;
use Weline\Order\Service\OrderFacade;
use Weline\Order\Service\OrderFacadeConflictException;

/**
 * TEST-P2D-04：kind-qualified display number（DEC-017）.
 */
final class DisplayNumberAllocatorTest extends TestCase
{
    public function testSameDisplayNumberAllowedAcrossKindsAndKindRequiredOnLookup(): void
    {
        $shared = '1234567890';
        $seq = [$shared, $shared, $shared];
        $i = 0;
        $allocator = DisplayNumberAllocator::forTesting(static function () use (&$seq, &$i): int {
            return (int)$seq[$i++];
        });
        // After three seeds we don't need more random draws for this test.
        $lookup = new DisplayNumberLookup($allocator);

        $order = $allocator->seed(0, 1, DisplayNumberRegistry::KIND_ORDER, $shared, 'uuid-order');
        $invoice = $allocator->seed(0, 1, DisplayNumberRegistry::KIND_INVOICE, $shared, 'uuid-invoice');
        $refund = $allocator->seed(0, 1, DisplayNumberRegistry::KIND_REFUND, $shared, 'uuid-refund');

        self::assertSame($shared, $order->displayNumber);
        self::assertSame($shared, $invoice->displayNumber);
        self::assertSame($shared, $refund->displayNumber);
        self::assertSame(DisplayNumberRegistry::KIND_ORDER, $order->numberKind);
        self::assertSame(DisplayNumberRegistry::KIND_INVOICE, $invoice->numberKind);
        self::assertSame(DisplayNumberRegistry::KIND_REFUND, $refund->numberKind);

        self::assertSame('uuid-order', $lookup->find(DisplayNumberRegistry::KIND_ORDER, $shared, 0, 1)->entityUuid);
        self::assertSame('uuid-invoice', $lookup->find(DisplayNumberRegistry::KIND_INVOICE, $shared, 0, 1)->entityUuid);
        self::assertSame('uuid-refund', $lookup->find(DisplayNumberRegistry::KIND_REFUND, $shared, 0, 1)->entityUuid);

        try {
            $lookup->find(null, $shared, 0, 1);
            self::fail('bare-number lookup must fail');
        } catch (OrderFacadeConflictException $e) {
            self::assertSame(DisplayNumberLookup::ERROR_KIND_REQUIRED, $e->errorCode());
        }

        try {
            $lookup->find('', $shared, 0, 1);
            self::fail('empty kind must fail');
        } catch (OrderFacadeConflictException $e) {
            self::assertSame(DisplayNumberLookup::ERROR_KIND_REQUIRED, $e->errorCode());
        }
    }

    public function testUniqueKeyIncludesKindCollisionRetriesThenExhausts(): void
    {
        $fixed = 42;
        $allocator = DisplayNumberAllocator::forTesting(static fn (): int => $fixed);
        $allocator->seed(0, 0, DisplayNumberRegistry::KIND_ORDER, str_pad((string)$fixed, 10, '0', STR_PAD_LEFT), 'first');

        try {
            $allocator->allocate(0, 0, DisplayNumberRegistry::KIND_ORDER, 'second');
            self::fail('same kind+number must exhaust after retries');
        } catch (OrderFacadeConflictException $e) {
            self::assertSame(DisplayNumberAllocator::ERROR_EXHAUSTED, $e->errorCode());
        }

        // Same number, different kind is fine
        $invoice = $allocator->allocate(0, 0, DisplayNumberRegistry::KIND_INVOICE, 'inv');
        self::assertSame(str_pad((string)$fixed, 10, '0', STR_PAD_LEFT), $invoice->displayNumber);
    }

    public function testFacadeCreateAssignsOrderDisplayNumberAndLookup(): void
    {
        $facade = OrderFacade::forTesting();
        $cmd = new CreateCheckoutGroupCommand(
            idempotencyKey: 'dn-1',
            requestHash: hash('sha256', 'dn-1'),
            websiteId: 0,
            storeId: 1,
            lines: [['name' => 'A', 'qty_minor' => 1, 'unit_price_minor' => 100]],
        );
        $result = $facade->create($cmd);
        $read = $facade->get($result->orderUuids[0]);
        self::assertSame(DisplayNumberRegistry::KIND_ORDER, $read->numberKind);
        self::assertNotNull($read->displayNumber);
        self::assertSame(10, strlen((string)$read->displayNumber));
        self::assertSame(
            $read->orderUuid,
            $facade->displayLookup()->find(
                DisplayNumberRegistry::KIND_ORDER,
                (string)$read->displayNumber,
                0,
                1,
            )->entityUuid,
        );
        self::assertSame($read->displayNumber, $result->orders[0]['display_number']);
    }

    public function testMoneySnapshotIsMinorUnitDtoAndPostPaymentHookIsNoop(): void
    {
        $money = (new MoneySnapshot('CNY', 1000, 100, 0))->withComputedGrandTotal();
        self::assertSame(1100, $money->grandTotalMinor);
        self::assertArrayHasKey('subtotal_minor', $money->toArray());

        $hook = new class implements OrderPostPaymentHookInterface {
            public ?OrderPaidContext $received = null;

            public function afterOrderPaid(OrderPaidContext $context): void
            {
                $this->received = $context;
            }
        };
        $facade = new OrderFacade(useMemory: true, postPaymentHook: $hook);
        $cmd = new CreateCheckoutGroupCommand(
            idempotencyKey: 'hook-1',
            requestHash: hash('sha256', 'hook-1'),
            websiteId: 0,
            storeId: 0,
            lines: [['name' => 'B', 'qty_minor' => 1, 'unit_price_minor' => 50]],
        );
        $created = $facade->create($cmd);
        $facade->notifyOrderPaid($created->orderUuids[0], ['payment_attempt_id' => 'attempt-1']);
        self::assertInstanceOf(OrderPaidContext::class, $hook->received);
        self::assertSame($created->orderUuids[0], $hook->received?->orderUuid);
        self::assertSame(50, $hook->received?->money->grandTotalMinor);
        self::assertSame('attempt-1', $hook->received?->metadata['payment_attempt_id'] ?? null);

        $noop = new NoopOrderPostPaymentHook();
        $noop->afterOrderPaid($hook->received);
    }

    public function testCommitFailureRollsBackDisplayNumbers(): void
    {
        $facade = OrderFacade::forTesting();
        $facade->failAfterWritingOrderIndex(0);
        $cmd = new CreateCheckoutGroupCommand(
            idempotencyKey: 'dn-roll',
            requestHash: hash('sha256', 'dn-roll'),
            websiteId: 0,
            storeId: 1,
            lines: [
                ['name' => 'A', 'qty_minor' => 1, 'unit_price_minor' => 100, 'split_key' => 'a'],
                ['name' => 'B', 'qty_minor' => 1, 'unit_price_minor' => 200, 'split_key' => 'b'],
            ],
        );
        try {
            $facade->create($cmd);
            self::fail('expected rollback');
        } catch (OrderFacadeConflictException $e) {
            self::assertSame(OrderFacade::ERROR_COMMIT_FAILED, $e->errorCode());
        }
        self::assertSame(0, $facade->orderCount());
        self::assertSame([], $facade->displayNumbers()->all());
    }
}
