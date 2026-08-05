<?php

declare(strict_types=1);

namespace Weline\Order\Test\Integration;

use PHPUnit\Framework\TestCase;
use Weline\Order\Model\DisplayNumberRegistry;
use Weline\Order\Service\DisplayNumberAllocator;
use Weline\Order\Service\DisplayNumberLookup;
use Weline\Order\Service\OrderFacadeConflictException;

final class DisplayNumberDatabaseIntegrationTest extends TestCase
{
    public function testKindQualifiedLookupSeedReplayAndCollisionExhaustion(): void
    {
        $transaction = new DisplayNumberRegistry();
        $transaction->beginTransaction();
        $number = (string)random_int(1_000_000_000, 9_999_999_999);
        $allocator = new DisplayNumberAllocator(
            useMemory: false,
            randomInt: static fn (): int => (int)$number,
            registryModel: $transaction,
        );
        $lookup = new DisplayNumberLookup($allocator);

        try {
            $allocator->seed(0, 0, DisplayNumberRegistry::KIND_ORDER, $number, 'order-' . $number);
            $allocator->seed(0, 0, DisplayNumberRegistry::KIND_INVOICE, $number, 'invoice-' . $number);
            $allocator->seed(0, 0, DisplayNumberRegistry::KIND_REFUND, $number, 'refund-' . $number);

            self::assertSame(
                'order-' . $number,
                $lookup->find(DisplayNumberRegistry::KIND_ORDER, $number)->entityUuid,
            );
            self::assertSame(
                'invoice-' . $number,
                $lookup->find(DisplayNumberRegistry::KIND_INVOICE, $number)->entityUuid,
            );
            self::assertSame(
                'refund-' . $number,
                $lookup->find(DisplayNumberRegistry::KIND_REFUND, $number)->entityUuid,
            );

            $replayed = $allocator->seed(
                0,
                0,
                DisplayNumberRegistry::KIND_ORDER,
                $number,
                'order-' . $number,
            );
            self::assertSame('order-' . $number, $replayed->entityUuid);

            try {
                $allocator->allocate(
                    0,
                    0,
                    DisplayNumberRegistry::KIND_ORDER,
                    'other-' . $number,
                );
                self::fail('same kind and number must exhaust after five attempts');
            } catch (OrderFacadeConflictException $exception) {
                self::assertSame(
                    DisplayNumberAllocator::ERROR_EXHAUSTED,
                    $exception->errorCode(),
                );
            }

            try {
                $lookup->find(null, $number);
                self::fail('bare-number lookup must fail');
            } catch (OrderFacadeConflictException $exception) {
                self::assertSame(
                    DisplayNumberLookup::ERROR_KIND_REQUIRED,
                    $exception->errorCode(),
                );
            }
        } finally {
            $transaction->rollBack();
        }
    }
}
