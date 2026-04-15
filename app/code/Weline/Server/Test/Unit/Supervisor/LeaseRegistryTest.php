<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Supervisor;

use PHPUnit\Framework\TestCase;
use Weline\Server\Supervisor\Lease\LeaseRegistry;
use Weline\Server\Supervisor\Lease\SlotLease;

final class LeaseRegistryTest extends TestCase
{
    public function testAssigningSameSlotReplacesPreviousLeaseWithNextGeneration(): void
    {
        $registry = new LeaseRegistry(static fn(string $slotId, int $generation): string => "{$slotId}-lease-{$generation}");

        $first = $registry->assign('worker#1', 'worker', 101, 18081, 'launch-a', 100.0);
        $second = $registry->assign('worker#1', 'worker', 202, 18081, 'launch-b', 200.0);

        self::assertSame('worker#1-lease-1', $first->leaseId);
        self::assertSame(1, $first->generation);
        self::assertSame('worker#1-lease-2', $second->leaseId);
        self::assertSame(2, $second->generation);
        self::assertFalse($registry->isCurrent('worker#1', $first->leaseId, $first->generation));
        self::assertTrue($registry->isCurrent('worker#1', $second->leaseId, $second->generation));
        self::assertSame($second, $registry->get('worker#1'));
    }

    public function testReadyAndHeartbeatOnlyAcceptCurrentLease(): void
    {
        $registry = new LeaseRegistry(static fn(string $slotId, int $generation): string => "{$slotId}-lease-{$generation}");

        $old = $registry->assign('dispatcher#1', 'dispatcher', 111, 443, 'old', 100.0);
        $current = $registry->assign('dispatcher#1', 'dispatcher', 222, 443, 'new', 200.0);

        self::assertNull($registry->markReady('dispatcher#1', $old->leaseId, $old->generation, 443, 210.0));
        $ready = $registry->markReady('dispatcher#1', $current->leaseId, $current->generation, 443, 220.0);

        self::assertInstanceOf(SlotLease::class, $ready);
        self::assertSame(SlotLease::STATE_READY, $ready->state);
        self::assertSame(220.0, $ready->updatedAt);

        self::assertNull($registry->heartbeat('dispatcher#1', $old->leaseId, $old->generation, 1, 230.0));
        $heartbeat = $registry->heartbeat('dispatcher#1', $current->leaseId, $current->generation, 7, 240.0);

        self::assertInstanceOf(SlotLease::class, $heartbeat);
        self::assertSame(7, $heartbeat->heartbeatSeq);
        self::assertSame(240.0, $heartbeat->updatedAt);
    }

    public function testReleaseOnlyAcceptsCurrentLease(): void
    {
        $registry = new LeaseRegistry(static fn(string $slotId, int $generation): string => "{$slotId}-lease-{$generation}");

        $old = $registry->assign('worker#2', 'worker');
        $current = $registry->assign('worker#2', 'worker');

        self::assertFalse($registry->release('worker#2', $old->leaseId, $old->generation));
        self::assertNotNull($registry->get('worker#2'));
        self::assertTrue($registry->release('worker#2', $current->leaseId, $current->generation));
        self::assertNull($registry->get('worker#2'));
    }
}
