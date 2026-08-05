<?php

declare(strict_types=1);

namespace Weline\Inventory\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Inventory\Cron\ReservationExpiry;
use Weline\Inventory\Model\InventoryLedger;
use Weline\Inventory\Model\Reservation;
use Weline\Inventory\Service\ControllableClock;
use Weline\Inventory\Service\InventoryConflictException;
use Weline\Inventory\Service\LeaseCoordinator;
use Weline\Inventory\Service\ReservationService;
use Weline\Inventory\Service\SystemClock;

/**
 * TEST-P2B-03, TEST-P2B-04, TEST-P2B-05 and TEST-P2B-06:
 * idempotency / lease CAS / 2h 上限 / 排队不续租。
 */
final class ReservationLeaseTest extends TestCase
{
    public function testIdempotentReserveCommitReleaseAndHashConflict(): void
    {
        $svc = ReservationService::forTesting();
        $inv = $svc->inventory();
        $inv->setOnHand(0, 0, 5001, 5, 'set-p2b03', hash('sha256', 'set-p2b03'));

        $hash = hash('sha256', 'reserve-same');
        $a = $svc->reserve(0, 0, 5001, 2, 'idem-r', $hash, 'attempt-a');
        $b = $svc->reserve(0, 0, 5001, 2, 'idem-r', $hash, 'attempt-a');
        self::assertTrue($b['reservation']->replayed);
        self::assertSame($a['reservation']->reservationUuid, $b['reservation']->reservationUuid);

        try {
            $svc->reserve(0, 0, 5001, 2, 'idem-r', $hash, 'attempt-drift');
            self::fail('lease owner conflict expected');
        } catch (InventoryConflictException $e) {
            self::assertSame('inventory_lease_payload_conflict', $e->errorCode());
        }
        try {
            $svc->reserve(
                0,
                0,
                5001,
                2,
                'idem-r',
                $hash,
                'attempt-a',
                queuedOrder: true,
            );
            self::fail('queued payload conflict expected');
        } catch (InventoryConflictException $e) {
            self::assertSame('inventory_lease_payload_conflict', $e->errorCode());
        }

        try {
            $svc->reserve(0, 0, 5001, 2, 'idem-r', hash('sha256', 'other'), 'attempt-a');
            self::fail('hash conflict expected');
        } catch (InventoryConflictException $e) {
            self::assertSame('inventory_request_hash_conflict', $e->errorCode());
        }

        $uuid = $a['reservation']->reservationUuid;
        $commitHash = hash('sha256', 'commit-1');
        $svc->commit($uuid, 'idem-c', $commitHash);
        $svc->commit($uuid, 'idem-c', $commitHash);
        try {
            $svc->commit($uuid, 'idem-c', hash('sha256', 'commit-other'));
            self::fail('commit hash conflict expected');
        } catch (InventoryConflictException $e) {
            self::assertSame('inventory_request_hash_conflict', $e->errorCode());
        }

        $row = $inv->getReservation($uuid);
        self::assertSame(Reservation::STATE_COMMITTED, $row['state']);
        $ledger = $inv->listLedgerEvents(0, 0, 5001);
        $commits = array_values(array_filter(
            $ledger,
            static fn(array $e): bool => $e['event_type'] === InventoryLedger::TYPE_COMMIT,
        ));
        self::assertCount(1, $commits);

        $inv->setOnHand(0, 0, 5002, 2, 'set-rel', hash('sha256', 'set-rel'));
        $r2 = $svc->reserve(0, 0, 5002, 1, 'idem-rel', hash('sha256', 'rel'), 'attempt-b');
        $svc->release($r2['reservation']->reservationUuid);
        $svc->release($r2['reservation']->reservationUuid);
        self::assertSame(2, $inv->getAvailability(0, 0, 5002)->availableMinor);
        $releases = array_values(array_filter(
            $inv->listLedgerEvents(0, 0, 5002),
            static fn(array $e): bool => $e['event_type'] === InventoryLedger::TYPE_RELEASE,
        ));
        self::assertCount(1, $releases);
    }

    public function testConcurrentRenewOnlyOneCasWins(): void
    {
        $clock = new ControllableClock(new \DateTimeImmutable('2026-07-24 10:00:00'));
        $svc = ReservationService::forTesting($clock);
        $svc->inventory()->setOnHand(0, 1, 5101, 3, 'set-cas', hash('sha256', 'set-cas'));
        $out = $svc->reserve(0, 1, 5101, 1, 'cas-r', hash('sha256', 'cas-r'), 'owner-1');
        $uuid = $out['reservation']->reservationUuid;
        self::assertSame(1, $out['lease']['lease_version']);

        $ok = 0;
        $fail = 0;
        for ($i = 0; $i < 2; $i++) {
            try {
                $svc->renew($uuid, 'owner-1', 1);
                $ok++;
            } catch (InventoryConflictException $e) {
                self::assertSame('inventory_lease_version_conflict', $e->errorCode());
                $fail++;
            }
        }
        self::assertSame(1, $ok);
        self::assertSame(1, $fail);
        $row = $svc->inventory()->getReservation($uuid);
        self::assertSame(2, (int)$row['lease_version']);
        $max = new \DateTimeImmutable((string)$row['lease_max_expires_at']);
        $exp = new \DateTimeImmutable((string)$row['lease_expires_at']);
        self::assertTrue($exp <= $max);
    }

    public function testHardCapRequiresReconciliationAndCronExpires(): void
    {
        $started = new \DateTimeImmutable('2026-07-24 12:00:00');
        $clock = new ControllableClock($started);
        $svc = ReservationService::forTesting($clock);
        $inv = $svc->inventory();
        $inv->setOnHand(0, 1, 5201, 2, 'set-cap', hash('sha256', 'set-cap'));
        $out = $svc->reserve(
            0,
            1,
            5201,
            1,
            'cap-r',
            hash('sha256', 'cap-r'),
            'owner-cap',
            false,
            $started,
        );
        $uuid = $out['reservation']->reservationUuid;
        $max = new \DateTimeImmutable((string)$out['lease']['lease_max_expires_at']);
        self::assertSame($started->modify(LeaseCoordinator::MAX_LEASE)->format('Y-m-d H:i:s'), $max->format('Y-m-d H:i:s'));

        // Within window: renew ok
        $clock->advance('+25 minutes');
        $renewed = $svc->renew($uuid, 'owner-cap', 1);
        self::assertFalse($renewed['reconciliation_required']);
        self::assertSame(2, $renewed['lease_version']);

        // Jump past 2h hard max
        $clock->set($started->modify('+2 hours'));
        try {
            $svc->renew($uuid, 'owner-cap', 2);
            self::fail('hard cap expected');
        } catch (InventoryConflictException $e) {
            self::assertSame('inventory_lease_reconciliation_required', $e->errorCode());
            self::assertTrue((bool)($e->context()['reconciliation_required'] ?? false));
        }

        // Force lease_expires_at into the past for Cron (still reserved until Cron)
        $inv->patchReservation($uuid, [
            'lease_expires_at' => $clock->now()->modify('-1 minute')->format('Y-m-d H:i:s'),
        ], expectedLeaseVersion: 2);
        $cron = new ReservationExpiry($inv);
        $cron->setClock($clock);
        $msg = $cron->execute();
        self::assertStringContainsString('expired=1', $msg);
        self::assertSame(Reservation::STATE_EXPIRED, $inv->getReservation($uuid)['state']);
        self::assertSame(2, $inv->getAvailability(0, 1, 5201)->availableMinor);

        // No resurrection
        try {
            $svc->renew($uuid, 'owner-cap', 2);
            self::fail('no resurrect');
        } catch (InventoryConflictException $e) {
            self::assertSame('inventory_lease_invalid_state', $e->errorCode());
        }
    }

    public function testQueuedOrderCannotRenew(): void
    {
        $clock = new ControllableClock(new \DateTimeImmutable('2026-07-24 15:00:00'));
        $svc = ReservationService::forTesting($clock);
        $svc->inventory()->setOnHand(0, 2, 5301, 5, 'set-q', hash('sha256', 'set-q'));
        $out = $svc->reserve(
            0,
            2,
            5301,
            1,
            'q-r',
            hash('sha256', 'q-r'),
            'queue-attempt',
            queuedOrder: true,
        );
        self::assertTrue($out['lease']['queued_order']);
        try {
            $svc->renew($out['reservation']->reservationUuid, 'queue-attempt', 1);
            self::fail('queued renew forbidden');
        } catch (InventoryConflictException $e) {
            self::assertSame('inventory_lease_queue_no_renew', $e->errorCode());
        }

        // Must re-check availability and reserve again when turn arrives
        $again = $svc->reserve(
            0,
            2,
            5301,
            1,
            'q-r-2',
            hash('sha256', 'q-r-2'),
            'queue-attempt-2',
            queuedOrder: false,
        );
        self::assertFalse($again['reservation']->replayed);
        self::assertSame(1, $again['lease']['lease_version']);
        $svc->renew($again['reservation']->reservationUuid, 'queue-attempt-2', 1);
    }

    public function testFutureAndExhaustedAttemptCannotReserve(): void
    {
        $now = new \DateTimeImmutable('2026-07-24 18:00:00', new \DateTimeZone('UTC'));
        $clock = new ControllableClock($now);
        $svc = ReservationService::forTesting($clock);
        $inventory = $svc->inventory();
        $inventory->setOnHand(
            0,
            4,
            5401,
            2,
            'set-invalid-start',
            hash('sha256', 'set-invalid-start'),
        );

        try {
            $svc->reserve(
                0,
                4,
                5401,
                1,
                'future-start',
                hash('sha256', 'future-start'),
                'attempt-future',
                attemptStartedAt: $now->modify('+1 minute'),
            );
            self::fail('Future attempt start must fail before reserve.');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame(0, $inventory->getAvailability(0, 4, 5401)->reservedMinor);

        try {
            $svc->reserve(
                0,
                4,
                5401,
                1,
                'exhausted-start',
                hash('sha256', 'exhausted-start'),
                'attempt-exhausted',
                attemptStartedAt: $now->modify('-2 hours'),
            );
            self::fail('Exhausted attempt must require reconciliation before reserve.');
        } catch (InventoryConflictException $exception) {
            self::assertSame(
                'inventory_lease_reconciliation_required',
                $exception->errorCode(),
            );
        }
        self::assertSame(0, $inventory->getAvailability(0, 4, 5401)->reservedMinor);

        $svc->reserve(
            0,
            4,
            5401,
            1,
            'explicit-start',
            hash('sha256', 'explicit-start'),
            'attempt-explicit',
            attemptStartedAt: $now->modify('-10 minutes'),
        );
        try {
            $svc->reserve(
                0,
                4,
                5401,
                1,
                'explicit-start',
                hash('sha256', 'explicit-start'),
                'attempt-explicit',
                attemptStartedAt: $now->modify('-9 minutes'),
            );
            self::fail('Explicit attempt start drift must fail replay.');
        } catch (InventoryConflictException $exception) {
            self::assertSame('inventory_lease_payload_conflict', $exception->errorCode());
        }

        $this->expectException(\InvalidArgumentException::class);
        $svc->reserve(
            0,
            4,
            5401,
            1,
            'owner-too-long',
            hash('sha256', 'owner-too-long'),
            str_repeat('a', 65),
        );
    }

    public function testExpiryCasSkipsRenewedReservationAndCountsOnce(): void
    {
        $clock = new ControllableClock(new \DateTimeImmutable(
            '2026-07-24 20:00:00',
            new \DateTimeZone('UTC'),
        ));
        $svc = ReservationService::forTesting($clock);
        $inventory = $svc->inventory();
        $inventory->setOnHand(0, 5, 5501, 1, 'set-expiry-cas', hash('sha256', 'set-expiry-cas'));
        $out = $svc->reserve(
            0,
            5,
            5501,
            1,
            'expiry-cas',
            hash('sha256', 'expiry-cas'),
            'attempt-expiry',
        );
        $uuid = $out['reservation']->reservationUuid;
        self::assertTrue($inventory->patchReservation(
            $uuid,
            [
                'lease_version' => 2,
                'lease_expires_at' => '2026-07-24 19:59:00',
            ],
            expectedLeaseVersion: 1,
            expectedState: Reservation::STATE_RESERVED,
        ));
        $observed = $inventory->listExpiredReservations('2026-07-24 20:00:00');
        self::assertCount(1, $observed);

        self::assertTrue($inventory->patchReservation(
            $uuid,
            [
                'lease_version' => 3,
                'lease_expires_at' => '2026-07-24 20:30:00',
            ],
            expectedLeaseVersion: 2,
            expectedState: Reservation::STATE_RESERVED,
        ));
        self::assertFalse($inventory->expire($uuid, 2, '2026-07-24 20:00:00'));
        self::assertSame(1, $inventory->getAvailability(0, 5, 5501)->reservedMinor);

        self::assertTrue($inventory->patchReservation(
            $uuid,
            [
                'lease_version' => 4,
                'lease_expires_at' => '2026-07-24 19:59:00',
            ],
            expectedLeaseVersion: 3,
            expectedState: Reservation::STATE_RESERVED,
        ));
        $cron = new ReservationExpiry($inventory);
        $cron->setClock($clock);
        self::assertSame(
            'expired=1;skipped=0;errors=0;scanned=1',
            $cron->execute(),
        );
        self::assertSame(
            'expired=0;skipped=0;errors=0;scanned=0',
            $cron->execute(),
        );
        self::assertSame(1, $inventory->getAvailability(0, 5, 5501)->availableMinor);
    }

    public function testSystemClockUsesUtc(): void
    {
        self::assertSame('UTC', (new SystemClock())->now()->getTimezone()->getName());
    }
}
