<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Supervisor;

use PHPUnit\Framework\TestCase;
use Weline\Server\Supervisor\Lease\LeaseRegistry;
use Weline\Server\Supervisor\Lease\SlotLease;
use Weline\Server\Supervisor\Protocol\SupervisorMessage;
use Weline\Server\Supervisor\Supervisor;

final class SupervisorTest extends TestCase
{
    public function testHelloAssignsLeaseAndReadyAckMarksLeaseReady(): void
    {
        $supervisor = $this->createSupervisor();

        $leaseAssign = SupervisorMessage::decode((string)$supervisor->handle([
            'type' => SupervisorMessage::TYPE_HELLO,
            'msg_id' => 'hello-1',
            'instance' => 'default',
            'channel' => 'channel-default',
            'role' => 'worker',
            'slot_id' => 'worker#1',
            'pid' => 1001,
            'port' => 18081,
            'launch_nonce' => 'launch-1',
        ]));

        self::assertSame(SupervisorMessage::TYPE_LEASE_ASSIGN, $leaseAssign['type']);
        self::assertSame('hello-1', $leaseAssign['msg_id']);
        self::assertSame('channel-default', $leaseAssign['channel']);
        self::assertSame('worker#1-lease-1', $leaseAssign['lease_id']);
        self::assertSame(1, $leaseAssign['generation']);

        $readyAck = SupervisorMessage::decode((string)$supervisor->handle([
            'type' => SupervisorMessage::TYPE_READY,
            'msg_id' => 'ready-1',
            'channel' => 'channel-default',
            'slot_id' => 'worker#1',
            'lease_id' => 'worker#1-lease-1',
            'generation' => 1,
            'listen' => ['port' => 18081],
        ]));

        self::assertSame(SupervisorMessage::TYPE_READY_ACK, $readyAck['type']);
        self::assertSame('ready-1', $readyAck['msg_id']);
        self::assertSame('channel-default', $readyAck['channel']);
        self::assertTrue($readyAck['accepted']);
        self::assertSame(SlotLease::STATE_READY, $readyAck['state']);

        $lease = $supervisor->leases()->get('worker#1');
        self::assertInstanceOf(SlotLease::class, $lease);
        self::assertSame(SlotLease::STATE_READY, $lease->state);
        self::assertSame(18081, $lease->port);
    }

    public function testReadyFromStaleLeaseIsRejectedAfterNewHelloReplacesSlot(): void
    {
        $supervisor = $this->createSupervisor();

        $first = SupervisorMessage::decode((string)$supervisor->handle([
            'type' => SupervisorMessage::TYPE_HELLO,
            'msg_id' => 'hello-old',
            'instance' => 'default',
            'channel' => 'channel-default',
            'role' => 'dispatcher',
            'slot_id' => 'dispatcher#1',
            'pid' => 1001,
            'port' => 443,
        ]));
        $second = SupervisorMessage::decode((string)$supervisor->handle([
            'type' => SupervisorMessage::TYPE_HELLO,
            'msg_id' => 'hello-new',
            'instance' => 'default',
            'channel' => 'channel-default',
            'role' => 'dispatcher',
            'slot_id' => 'dispatcher#1',
            'pid' => 1002,
            'port' => 443,
        ]));

        self::assertSame('dispatcher#1-lease-1', $first['lease_id']);
        self::assertSame('dispatcher#1-lease-2', $second['lease_id']);

        $readyAck = SupervisorMessage::decode((string)$supervisor->handle([
            'type' => SupervisorMessage::TYPE_READY,
            'msg_id' => 'ready-old',
            'channel' => 'channel-default',
            'slot_id' => 'dispatcher#1',
            'lease_id' => 'dispatcher#1-lease-1',
            'generation' => 1,
            'listen' => ['port' => 443],
        ]));

        self::assertSame(SupervisorMessage::TYPE_READY_ACK, $readyAck['type']);
        self::assertSame('ready-old', $readyAck['msg_id']);
        self::assertFalse($readyAck['accepted']);
        self::assertSame('stale_or_unknown_lease', $readyAck['reason']);

        $current = $supervisor->leases()->get('dispatcher#1');
        self::assertInstanceOf(SlotLease::class, $current);
        self::assertSame('dispatcher#1-lease-2', $current->leaseId);
        self::assertSame(SlotLease::STATE_LEASED, $current->state);
    }

    public function testPortlessRuntimeWatchdogCanBecomeReadyWhileWorkerStillNeedsPort(): void
    {
        $supervisor = $this->createSupervisor();

        $watchdogLease = SupervisorMessage::decode((string)$supervisor->handle([
            'type' => SupervisorMessage::TYPE_HELLO,
            'msg_id' => 'watchdog-hello',
            'instance' => 'default',
            'channel' => 'channel-default',
            'role' => 'runtime_watchdog',
            'slot_id' => 'runtime_watchdog#1',
            'pid' => 3101,
            'launch_nonce' => 'watchdog-launch',
        ]));
        $watchdogReady = SupervisorMessage::decode((string)$supervisor->handle([
            'type' => SupervisorMessage::TYPE_READY,
            'msg_id' => 'watchdog-ready',
            'channel' => 'channel-default',
            'slot_id' => 'runtime_watchdog#1',
            'lease_id' => $watchdogLease['lease_id'],
            'generation' => $watchdogLease['generation'],
            'listen' => ['port' => 0],
        ]));

        self::assertTrue((bool)$watchdogReady['accepted']);
        self::assertSame(SlotLease::STATE_READY, $supervisor->leases()->get('runtime_watchdog#1')?->state);
        self::assertSame(0, $supervisor->leases()->get('runtime_watchdog#1')?->port);

        $workerLease = SupervisorMessage::decode((string)$supervisor->handle([
            'type' => SupervisorMessage::TYPE_HELLO,
            'msg_id' => 'worker-hello',
            'instance' => 'default',
            'channel' => 'channel-default',
            'role' => 'worker',
            'slot_id' => 'worker#9',
            'pid' => 3102,
            'launch_nonce' => 'worker-launch',
        ]));
        $workerReady = SupervisorMessage::decode((string)$supervisor->handle([
            'type' => SupervisorMessage::TYPE_READY,
            'msg_id' => 'worker-ready',
            'channel' => 'channel-default',
            'slot_id' => 'worker#9',
            'lease_id' => $workerLease['lease_id'],
            'generation' => $workerLease['generation'],
            'listen' => ['port' => 0],
        ]));

        self::assertFalse((bool)$workerReady['accepted']);
        self::assertSame('invalid_ready_payload', $workerReady['reason']);
        self::assertSame(SlotLease::STATE_LEASED, $supervisor->leases()->get('worker#9')?->state);
    }

    public function testHeartbeatUpdatesOnlyCurrentLease(): void
    {
        $supervisor = $this->createSupervisor();

        $leaseAssign = SupervisorMessage::decode((string)$supervisor->handle([
            'type' => SupervisorMessage::TYPE_HELLO,
            'msg_id' => 'hello-1',
            'instance' => 'default',
            'channel' => 'channel-default',
            'role' => 'worker',
            'slot_id' => 'worker#2',
            'pid' => 2002,
        ]));

        $supervisor->handle([
            'type' => SupervisorMessage::TYPE_HEARTBEAT,
            'channel' => 'channel-default',
            'slot_id' => 'worker#2',
            'lease_id' => $leaseAssign['lease_id'],
            'generation' => $leaseAssign['generation'],
            'seq' => 9,
        ]);

        $lease = $supervisor->leases()->get('worker#2');
        self::assertInstanceOf(SlotLease::class, $lease);
        self::assertSame(9, $lease->heartbeatSeq);

        $supervisor->handle([
            'type' => SupervisorMessage::TYPE_HEARTBEAT,
            'channel' => 'channel-default',
            'slot_id' => 'worker#2',
            'lease_id' => 'stale-lease',
            'generation' => 1,
            'seq' => 10,
        ]);

        $lease = $supervisor->leases()->get('worker#2');
        self::assertInstanceOf(SlotLease::class, $lease);
        self::assertSame(9, $lease->heartbeatSeq);
    }

    public function testLeaseReleaseOnlyRemovesCurrentLease(): void
    {
        $supervisor = $this->createSupervisor();

        $old = SupervisorMessage::decode((string)$supervisor->handle([
            'type' => SupervisorMessage::TYPE_HELLO,
            'msg_id' => 'hello-old',
            'instance' => 'default',
            'channel' => 'channel-default',
            'role' => 'worker',
            'slot_id' => 'worker#1',
            'pid' => 1001,
            'port' => 18081,
        ]));
        $current = SupervisorMessage::decode((string)$supervisor->handle([
            'type' => SupervisorMessage::TYPE_HELLO,
            'msg_id' => 'hello-new',
            'instance' => 'default',
            'channel' => 'channel-default',
            'role' => 'worker',
            'slot_id' => 'worker#1',
            'pid' => 1002,
            'port' => 18081,
        ]));

        $staleAck = SupervisorMessage::decode((string)$supervisor->handle([
            'type' => SupervisorMessage::TYPE_LEASE_RELEASE,
            'msg_id' => 'release-old',
            'channel' => 'channel-default',
            'slot_id' => 'worker#1',
            'lease_id' => $old['lease_id'],
            'generation' => $old['generation'],
        ]));

        self::assertSame(SupervisorMessage::TYPE_LEASE_RELEASE_ACK, $staleAck['type']);
        self::assertFalse($staleAck['accepted']);
        self::assertSame('stale_or_unknown_lease', $staleAck['reason']);
        self::assertTrue($supervisor->leases()->isCurrent(
            'worker#1',
            (string)$current['lease_id'],
            (int)$current['generation']
        ));

        $releaseAck = SupervisorMessage::decode((string)$supervisor->handle([
            'type' => SupervisorMessage::TYPE_LEASE_RELEASE,
            'msg_id' => 'release-current',
            'channel' => 'channel-default',
            'slot_id' => 'worker#1',
            'lease_id' => $current['lease_id'],
            'generation' => $current['generation'],
        ]));

        self::assertSame(SupervisorMessage::TYPE_LEASE_RELEASE_ACK, $releaseAck['type']);
        self::assertTrue($releaseAck['accepted']);
        self::assertNull($supervisor->leases()->get('worker#1'));
    }

    public function testWorkerPoolSnapshotOnlyContainsReadyWorkersInStableSlotOrder(): void
    {
        $supervisor = $this->createSupervisor();

        $first = SupervisorMessage::decode((string)$supervisor->handle([
            'type' => SupervisorMessage::TYPE_HELLO,
            'msg_id' => 'hello-2',
            'instance' => 'default',
            'role' => 'worker',
            'slot_id' => 'worker#2',
            'pid' => 1002,
            'port' => 18082,
        ]));
        $second = SupervisorMessage::decode((string)$supervisor->handle([
            'type' => SupervisorMessage::TYPE_HELLO,
            'msg_id' => 'hello-1',
            'instance' => 'default',
            'role' => 'worker',
            'slot_id' => 'worker#1',
            'pid' => 1001,
            'port' => 18081,
        ]));
        $supervisor->handle([
            'type' => SupervisorMessage::TYPE_READY,
            'msg_id' => 'ready-2',
            'slot_id' => 'worker#2',
            'lease_id' => $first['lease_id'],
            'generation' => $first['generation'],
            'listen' => ['port' => 18082],
        ]);

        $snapshot = $supervisor->buildWorkerPoolSnapshot(7);

        self::assertSame(SupervisorMessage::TYPE_POOL_SNAPSHOT, $snapshot['type']);
        self::assertSame('business', $snapshot['scope']);
        self::assertSame(7, $snapshot['version']);
        self::assertCount(1, $snapshot['workers']);
        self::assertSame('worker#2', $snapshot['workers'][0]['slot_id']);

        $supervisor->handle([
            'type' => SupervisorMessage::TYPE_READY,
            'msg_id' => 'ready-1',
            'slot_id' => 'worker#1',
            'lease_id' => $second['lease_id'],
            'generation' => $second['generation'],
            'listen' => ['port' => 18081],
        ]);

        $snapshot = $supervisor->buildWorkerPoolSnapshot(8);

        self::assertCount(2, $snapshot['workers']);
        self::assertSame('worker#1', $snapshot['workers'][0]['slot_id']);
        self::assertSame('worker#2', $snapshot['workers'][1]['slot_id']);
    }

    private function createSupervisor(): Supervisor
    {
        return new Supervisor(new LeaseRegistry(
            static fn(string $slotId, int $generation): string => "{$slotId}-lease-{$generation}"
        ));
    }
}
