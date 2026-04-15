<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Supervisor;

use PHPUnit\Framework\TestCase;
use Weline\Server\Supervisor\Endpoint\ControlEndpointResolver;
use Weline\Server\Supervisor\Lease\LeaseRegistry;
use Weline\Server\Supervisor\Protocol\SupervisorMessage;
use Weline\Server\Supervisor\Supervisor;
use Weline\Server\Supervisor\SupervisorRuntime;

final class SupervisorRuntimeTest extends TestCase
{
    public function testRuntimeCachesResolvedEndpointAndUsesStablePerInstanceResolution(): void
    {
        $runtime = $this->createRuntime('default');

        $linuxFirst = $runtime->endpoint('Linux');
        $linuxSecond = $runtime->endpoint('Linux');
        $windows = $runtime->endpoint('Windows');

        self::assertTrue($linuxFirst->isUnix());
        self::assertSame($linuxFirst->address, $linuxSecond->address);
        self::assertTrue($windows->isTcp());
    }

    public function testHelloAndReadyAdvanceSnapshotVersionsAndProducePoolVersionedAck(): void
    {
        $runtime = $this->createRuntime('default');

        $leaseAssign = SupervisorMessage::decode((string)$runtime->handle([
            'type' => SupervisorMessage::TYPE_HELLO,
            'msg_id' => 'hello-1',
            'instance' => 'default',
            'channel' => 'channel-default',
            'role' => 'worker',
            'slot_id' => 'worker#1',
            'pid' => 2001,
            'port' => 18081,
            'launch_nonce' => 'launch-1',
        ]));

        $slotSnapshot = $runtime->slotSnapshot();
        self::assertSame(1, $slotSnapshot['version']);
        self::assertSame('channel-default', $slotSnapshot['channel']);
        self::assertCount(1, $slotSnapshot['slots']);
        self::assertSame('worker#1', $slotSnapshot['slots'][0]['slot_id']);

        $readyAck = SupervisorMessage::decode((string)$runtime->handle([
            'type' => SupervisorMessage::TYPE_READY,
            'msg_id' => 'ready-1',
            'channel' => 'channel-default',
            'slot_id' => 'worker#1',
            'lease_id' => $leaseAssign['lease_id'],
            'generation' => $leaseAssign['generation'],
            'listen' => ['port' => 18081],
        ]));

        self::assertSame(SupervisorMessage::TYPE_READY_ACK, $readyAck['type']);
        self::assertTrue($readyAck['accepted']);
        self::assertSame(1, $readyAck['pool_snapshot_version']);
        self::assertSame('channel-default', $readyAck['channel']);

        $slotSnapshot = $runtime->slotSnapshot();
        self::assertSame(2, $slotSnapshot['version']);
        self::assertSame('ready', $slotSnapshot['slots'][0]['state']);

        $poolSnapshot = $runtime->workerPoolSnapshot();
        self::assertSame(1, $poolSnapshot['version']);
        self::assertSame('channel-default', $poolSnapshot['channel']);
        self::assertCount(1, $poolSnapshot['workers']);
        self::assertSame('worker#1', $poolSnapshot['workers'][0]['slot_id']);
    }

    public function testStaleReadyDoesNotAdvanceSnapshotVersions(): void
    {
        $runtime = $this->createRuntime('default');

        $old = SupervisorMessage::decode((string)$runtime->handle([
            'type' => SupervisorMessage::TYPE_HELLO,
            'msg_id' => 'hello-old',
            'instance' => 'default',
            'channel' => 'channel-default',
            'role' => 'dispatcher',
            'slot_id' => 'dispatcher#1',
            'pid' => 3001,
        ]));
        $runtime->handle([
            'type' => SupervisorMessage::TYPE_HELLO,
            'msg_id' => 'hello-new',
            'instance' => 'default',
            'channel' => 'channel-default',
            'role' => 'dispatcher',
            'slot_id' => 'dispatcher#1',
            'pid' => 3002,
        ]);

        $slotSnapshotBefore = $runtime->slotSnapshot();
        $poolSnapshotBefore = $runtime->workerPoolSnapshot();

        $readyAck = SupervisorMessage::decode((string)$runtime->handle([
            'type' => SupervisorMessage::TYPE_READY,
            'msg_id' => 'ready-old',
            'channel' => 'channel-default',
            'slot_id' => 'dispatcher#1',
            'lease_id' => $old['lease_id'],
            'generation' => $old['generation'],
            'listen' => ['port' => 443],
        ]));

        self::assertFalse($readyAck['accepted']);

        $slotSnapshotAfter = $runtime->slotSnapshot();
        $poolSnapshotAfter = $runtime->workerPoolSnapshot();

        self::assertSame($slotSnapshotBefore['version'], $slotSnapshotAfter['version']);
        self::assertSame($poolSnapshotBefore['version'], $poolSnapshotAfter['version']);
    }

    public function testHeartbeatUpdatesSlotStateWithoutChangingSnapshotVersion(): void
    {
        $runtime = $this->createRuntime('default');

        $leaseAssign = SupervisorMessage::decode((string)$runtime->handle([
            'type' => SupervisorMessage::TYPE_HELLO,
            'msg_id' => 'hello-1',
            'instance' => 'default',
            'channel' => 'channel-default',
            'role' => 'worker',
            'slot_id' => 'worker#1',
            'pid' => 2001,
        ]));

        $before = $runtime->slotSnapshot();
        $runtime->handle([
            'type' => SupervisorMessage::TYPE_HEARTBEAT,
            'channel' => 'channel-default',
            'slot_id' => 'worker#1',
            'lease_id' => $leaseAssign['lease_id'],
            'generation' => $leaseAssign['generation'],
            'seq' => 3,
        ]);
        $after = $runtime->slotSnapshot();

        self::assertSame($before['version'], $after['version']);
        self::assertSame(3, $after['slots'][0]['heartbeat_seq']);
    }

    public function testMismatchedChannelIsRejectedWithoutMutatingSnapshots(): void
    {
        $runtime = $this->createRuntime('default');

        $before = $runtime->slotSnapshot();
        $response = SupervisorMessage::decode((string)$runtime->handle([
            'type' => SupervisorMessage::TYPE_HELLO,
            'msg_id' => 'hello-wrong-channel',
            'instance' => 'default',
            'channel' => 'channel-other',
            'role' => 'worker',
            'slot_id' => 'worker#1',
            'pid' => 2001,
        ]));
        $after = $runtime->slotSnapshot();

        self::assertSame(SupervisorMessage::TYPE_CHANNEL_REJECT, $response['type']);
        self::assertSame('hello-wrong-channel', $response['msg_id']);
        self::assertSame('channel-default', $response['expected_channel']);
        self::assertSame('channel-other', $response['received_channel']);
        self::assertSame($before, $after);
    }

    private function createRuntime(string $instanceName): SupervisorRuntime
    {
        return new SupervisorRuntime(
            instanceName: $instanceName,
            channelId: 'channel-' . $instanceName,
            endpointResolver: new ControlEndpointResolver('/srv/weline', 27000, 1000),
            supervisor: new Supervisor(new LeaseRegistry(
                static fn(string $slotId, int $generation): string => "{$slotId}-lease-{$generation}"
            )),
        );
    }
}
