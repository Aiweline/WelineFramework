<?php

declare(strict_types=1);

namespace Weline\Inventory\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Inventory\Service\InventoryAvailabilityCalculator;
use Weline\Inventory\Service\InventoryConflictException;
use Weline\Inventory\Service\InventoryService;

/**
 * TEST-P2B-01 / TEST-P2B-02（逻辑层）：strict 并发预占；四策略 availability/reserve。
 */
final class InventoryServiceTest extends TestCase
{
    public function testStrictConcurrentReserveOnlyOneSucceeds(): void
    {
        $svc = InventoryService::forTesting();
        $svc->setOnHand(0, 1, 1001, 1, 'set-1', hash('sha256', 'set-1'), 'strict');

        $ok = 0;
        $fail = 0;
        for ($i = 0; $i < 2; $i++) {
            try {
                $svc->reserve(0, 1, 1001, 1, 'r-' . $i, hash('sha256', 'r-' . $i));
                $ok++;
            } catch (InventoryConflictException $e) {
                self::assertSame('inventory_insufficient', $e->errorCode());
                $fail++;
            }
        }
        self::assertSame(1, $ok);
        self::assertSame(1, $fail);
        $avail = $svc->getAvailability(0, 1, 1001);
        self::assertSame(0, $avail->availableMinor);
        self::assertSame(1, $avail->reservedMinor);
        self::assertFalse($avail->sellable);
    }

    public function testFourStrategiesAvailabilityAndReserve(): void
    {
        $calc = new InventoryAvailabilityCalculator();
        self::assertSame(0, $calc->availableMinor('strict', 1, 1));
        self::assertSame(2, $calc->availableMinor('oversell', 1, 1, oversellAllowance: 2));
        self::assertSame(5, $calc->availableMinor('preorder', 0, 0, preorderAllowance: 5));
        self::assertSame(PHP_INT_MAX, $calc->availableMinor('unlimited', 0, 99));

        $svc = InventoryService::forTesting();

        $svc->setOnHand(0, 2, 2001, 1, 's-strict', hash('sha256', 's-strict'), 'strict');
        self::assertTrue($svc->getAvailability(0, 2, 2001)->sellable);
        $svc->reserve(0, 2, 2001, 1, 'rs', hash('sha256', 'rs'));
        try {
            $svc->reserve(0, 2, 2001, 1, 'rs2', hash('sha256', 'rs2'));
            self::fail('strict should block');
        } catch (InventoryConflictException $e) {
            self::assertSame('inventory_insufficient', $e->errorCode());
        }

        $svc->setOnHand(0, 2, 2002, 0, 's-over', hash('sha256', 's-over'), 'oversell', oversellAllowance: 2);
        self::assertSame(2, $svc->getAvailability(0, 2, 2002)->availableMinor);
        $svc->reserve(0, 2, 2002, 2, 'ro', hash('sha256', 'ro'));
        self::assertSame(0, $svc->getAvailability(0, 2, 2002)->availableMinor);

        $svc->setOnHand(0, 2, 2003, 0, 's-pre', hash('sha256', 's-pre'), 'preorder', null, 3);
        self::assertSame(3, $svc->getAvailability(0, 2, 2003)->availableMinor);
        $svc->reserve(0, 2, 2003, 1, 'rp', hash('sha256', 'rp'));
        self::assertSame(2, $svc->getAvailability(0, 2, 2003)->availableMinor);

        $svc->setOnHand(0, 2, 2004, 0, 's-unl', hash('sha256', 's-unl'), 'unlimited');
        self::assertTrue($svc->getAvailability(0, 2, 2004)->sellable);
        $svc->reserve(0, 2, 2004, 1000, 'ru', hash('sha256', 'ru'));
        self::assertSame(PHP_INT_MAX, $svc->getAvailability(0, 2, 2004)->availableMinor);
    }

    public function testReserveIdempotentSameHash(): void
    {
        $svc = InventoryService::forTesting();
        $svc->setOnHand(0, 0, 3001, 5, 'set', hash('sha256', 'set'));
        $hash = hash('sha256', 'same');
        $a = $svc->reserve(0, 0, 3001, 2, 'idem-1', $hash);
        $b = $svc->reserve(0, 0, 3001, 2, 'idem-1', $hash);
        self::assertTrue($b->replayed);
        self::assertSame($a->reservationUuid, $b->reservationUuid);
        self::assertSame(2, $svc->getAvailability(0, 0, 3001)->reservedMinor);
    }

    public function testReserveHashConflict(): void
    {
        $svc = InventoryService::forTesting();
        $svc->setOnHand(0, 0, 3002, 5, 'set2', hash('sha256', 'set2'));
        $svc->reserve(0, 0, 3002, 1, 'idem-2', hash('sha256', 'h1'));
        $this->expectException(InventoryConflictException::class);
        $svc->reserve(0, 0, 3002, 1, 'idem-2', hash('sha256', 'h2'));
    }

    public function testReleaseRestoresAvailability(): void
    {
        $svc = InventoryService::forTesting();
        $svc->setOnHand(0, 3, 4001, 1, 'set3', hash('sha256', 'set3'));
        $r = $svc->reserve(0, 3, 4001, 1, 'rel-1', hash('sha256', 'rel-1'));
        self::assertSame(0, $svc->getAvailability(0, 3, 4001)->availableMinor);
        $svc->release($r->reservationUuid);
        self::assertSame(1, $svc->getAvailability(0, 3, 4001)->availableMinor);
    }

    public function testRejectsNegativeWebsite(): void
    {
        $svc = InventoryService::forTesting();
        $this->expectException(\InvalidArgumentException::class);
        $svc->ensureStock(-1, 0, 1);
    }

    public function testSetOnHandReplayDoesNotMutateProjectionAgain(): void
    {
        $svc = InventoryService::forTesting();
        $hash = hash('sha256', 'set-replay');

        $first = $svc->setOnHand(
            0,
            4,
            5001,
            8,
            'set-replay',
            $hash,
            'oversell',
            2,
            0,
        );
        $replayed = $svc->setOnHand(
            0,
            4,
            5001,
            8,
            'set-replay',
            $hash,
            'oversell',
            2,
            0,
        );

        self::assertSame($first->stockVersion, $replayed->stockVersion);
        self::assertSame(1, $replayed->stockVersion);
        self::assertSame(10, $replayed->availableMinor);
        $events = $svc->listLedgerEvents(0, 4, 5001);
        self::assertCount(1, $events);
        self::assertSame('oversell', $events[0]['strategy']);
        self::assertSame(2, $events[0]['oversell_allowance']);
    }

    public function testInvalidConfigurationFailsBeforeProjectionMutation(): void
    {
        $svc = InventoryService::forTesting();
        $svc->setOnHand(0, 5, 5002, 3, 'valid-config', hash('sha256', 'valid-config'));

        try {
            $svc->setOnHand(
                0,
                5,
                5002,
                9,
                'invalid-config',
                hash('sha256', 'invalid-config'),
                'unknown',
            );
            self::fail('Unknown strategy must fail.');
        } catch (\InvalidArgumentException) {
        }

        $after = $svc->getAvailability(0, 5, 5002);
        self::assertSame(3, $after->onHandMinor);
        self::assertSame(1, $after->stockVersion);
        self::assertCount(1, $svc->listLedgerEvents(0, 5, 5002));

        $this->expectException(\InvalidArgumentException::class);
        $svc->ensureStock(0, 5, 5003, 'strict', -1);
    }

    public function testReservationReplayIsBoundToScopeAndQuantity(): void
    {
        $svc = InventoryService::forTesting();
        $svc->setOnHand(0, 6, 6001, 3, 'set-payload', hash('sha256', 'set-payload'));
        $hash = hash('sha256', 'reserve-payload');
        $svc->reserve(0, 6, 6001, 1, 'reserve-payload', $hash);

        try {
            $svc->reserve(0, 6, 6001, 2, 'reserve-payload', $hash);
            self::fail('Quantity drift must fail.');
        } catch (InventoryConflictException $exception) {
            self::assertSame('inventory_request_payload_conflict', $exception->errorCode());
        }

        try {
            $svc->reserve(0, 7, 6001, 1, 'reserve-payload', $hash);
            self::fail('Scope drift must fail.');
        } catch (InventoryConflictException $exception) {
            self::assertSame('inventory_request_payload_conflict', $exception->errorCode());
        }
        self::assertSame(1, $svc->getAvailability(0, 6, 6001)->reservedMinor);
    }

    public function testCheckedMinorArithmeticRejectsOverflow(): void
    {
        $calculator = new InventoryAvailabilityCalculator();
        try {
            $calculator->availableMinor('oversell', PHP_INT_MAX, 0, 1);
            self::fail('Availability addition overflow must fail.');
        } catch (\InvalidArgumentException) {
        }

        $svc = InventoryService::forTesting();
        $svc->setOnHand(0, 8, 8001, 0, 'set-unlimited', hash('sha256', 'set-unlimited'), 'unlimited');
        $svc->reserve(0, 8, 8001, PHP_INT_MAX, 'reserve-max', hash('sha256', 'reserve-max'));

        try {
            $svc->reserve(0, 8, 8001, 1, 'reserve-overflow', hash('sha256', 'reserve-overflow'));
            self::fail('Reserved minor overflow must fail.');
        } catch (InventoryConflictException $exception) {
            self::assertSame('inventory_quantity_overflow', $exception->errorCode());
        }
        self::assertSame(PHP_INT_MAX, $svc->getAvailability(0, 8, 8001)->reservedMinor);
    }
}
