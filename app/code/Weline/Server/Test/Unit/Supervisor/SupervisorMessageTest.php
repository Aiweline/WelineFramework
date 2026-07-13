<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Supervisor;

use PHPUnit\Framework\TestCase;
use Weline\Server\Supervisor\Lease\SlotLease;
use Weline\Server\Supervisor\Protocol\SupervisorMessage;

final class SupervisorMessageTest extends TestCase
{
    public function testHelloLeaseReadyAndReadyAckMessagesCarryAckCorrelationFields(): void
    {
        $hello = SupervisorMessage::decode(SupervisorMessage::hello(
            instance: 'default',
            channel: 'channel-default',
            role: 'worker',
            slotId: 'worker#1',
            pid: 1234,
            launchNonce: 'launch-1',
            msgId: 'msg-hello-1',
        ));

        self::assertSame(SupervisorMessage::TYPE_HELLO, $hello['type']);
        self::assertSame('msg-hello-1', $hello['msg_id']);
        self::assertSame('channel-default', $hello['channel']);
        self::assertSame('worker#1', $hello['slot_id']);
        self::assertSame('launch-1', $hello['launch_nonce']);

        $lease = new SlotLease(
            slotId: 'worker#1',
            leaseId: 'lease-1',
            generation: 3,
            role: 'worker',
            pid: 1234,
            port: 18081,
            launchNonce: 'launch-1',
        );

        $leaseAssign = SupervisorMessage::decode(SupervisorMessage::leaseAssign($lease, 'msg-hello-1', 'channel-default'));
        self::assertSame(SupervisorMessage::TYPE_LEASE_ASSIGN, $leaseAssign['type']);
        self::assertSame('msg-hello-1', $leaseAssign['msg_id']);
        self::assertSame('channel-default', $leaseAssign['channel']);
        self::assertSame('lease-1', $leaseAssign['lease_id']);
        self::assertSame(3, $leaseAssign['generation']);

        $ready = SupervisorMessage::decode(SupervisorMessage::ready(
            slotId: 'worker#1',
            leaseId: 'lease-1',
            generation: 3,
            port: 18081,
            msgId: 'msg-ready-1',
            channel: 'channel-default',
        ));
        self::assertSame(SupervisorMessage::TYPE_READY, $ready['type']);
        self::assertSame('msg-ready-1', $ready['msg_id']);
        self::assertSame('channel-default', $ready['channel']);
        self::assertSame('lease-1', $ready['lease_id']);
        self::assertSame(18081, $ready['listen']['port']);

        $readyAck = SupervisorMessage::decode(SupervisorMessage::readyAck(
            $lease->markReady(18081),
            'msg-ready-1',
            poolSnapshotVersion: 91,
            channel: 'channel-default',
        ));
        self::assertSame(SupervisorMessage::TYPE_READY_ACK, $readyAck['type']);
        self::assertSame('msg-ready-1', $readyAck['msg_id']);
        self::assertSame('channel-default', $readyAck['channel']);
        self::assertTrue($readyAck['accepted']);
        self::assertSame('ready', $readyAck['state']);
        self::assertSame(91, $readyAck['pool_snapshot_version']);
    }

    public function testHeartbeatCarriesLeaseAndSequence(): void
    {
        $heartbeat = SupervisorMessage::decode(SupervisorMessage::heartbeat(
            slotId: 'dispatcher#1',
            leaseId: 'dispatcher-lease-1',
            generation: 4,
            seq: 9,
            msgId: 'msg-heartbeat-9',
            channel: 'channel-default',
        ));

        self::assertSame(SupervisorMessage::TYPE_HEARTBEAT, $heartbeat['type']);
        self::assertSame('msg-heartbeat-9', $heartbeat['msg_id']);
        self::assertSame('channel-default', $heartbeat['channel']);
        self::assertSame('dispatcher#1', $heartbeat['slot_id']);
        self::assertSame('dispatcher-lease-1', $heartbeat['lease_id']);
        self::assertSame(4, $heartbeat['generation']);
        self::assertSame(9, $heartbeat['seq']);
        self::assertArrayHasKey('timestamp', $heartbeat);
    }

    public function testHelloAuthenticationRejectsTampering(): void
    {
        $hello = SupervisorMessage::decode(SupervisorMessage::hello(
            instance: 'default',
            channel: 'channel-default',
            role: 'worker',
            slotId: 'worker#1',
            pid: 1234,
            launchNonce: 'launch-auth',
            msgId: 'msg-auth',
            leaseId: 'lease-auth',
            generation: 7,
            authSecret: 'unit-secret',
        ));

        self::assertTrue(SupervisorMessage::verifyHelloAuthentication($hello, 'unit-secret'));
        self::assertArrayHasKey('auth_ts', $hello);
        self::assertArrayHasKey('auth_nonce', $hello);
        self::assertArrayHasKey('auth_mac', $hello);

        $hello['slot_id'] = 'worker#2';
        self::assertFalse(SupervisorMessage::verifyHelloAuthentication($hello, 'unit-secret'));
    }

    public function testLeaseReleaseAndAckCarryLeaseIdentity(): void
    {
        $release = SupervisorMessage::decode(SupervisorMessage::leaseRelease(
            slotId: 'worker#1',
            leaseId: 'lease-1',
            generation: 3,
            msgId: 'msg-release-1',
            channel: 'channel-default',
        ));

        self::assertSame(SupervisorMessage::TYPE_LEASE_RELEASE, $release['type']);
        self::assertSame('worker#1', $release['slot_id']);
        self::assertSame('lease-1', $release['lease_id']);
        self::assertSame(3, $release['generation']);
        self::assertSame('msg-release-1', $release['msg_id']);
        self::assertSame('channel-default', $release['channel']);

        $ack = SupervisorMessage::decode(SupervisorMessage::leaseReleaseAck(
            slotId: 'worker#1',
            leaseId: 'lease-1',
            generation: 3,
            accepted: true,
            msgId: 'msg-release-1',
            channel: 'channel-default',
        ));

        self::assertSame(SupervisorMessage::TYPE_LEASE_RELEASE_ACK, $ack['type']);
        self::assertTrue($ack['accepted']);
        self::assertSame('worker#1', $ack['slot_id']);
        self::assertSame('lease-1', $ack['lease_id']);
        self::assertSame(3, $ack['generation']);
        self::assertSame('msg-release-1', $ack['msg_id']);
        self::assertSame('channel-default', $ack['channel']);
    }

    public function testPoolSnapshotAndAckMessagesAreVersioned(): void
    {
        $snapshot = SupervisorMessage::decode(SupervisorMessage::poolSnapshot([
            [
                'slot_id' => 'worker#1',
                'lease_id' => 'lease-1',
                'generation' => 1,
                'port' => 18081,
                'state' => 'ready',
            ],
        ], 5, 'business', 'msg-pool-5', 'channel-default'));

        self::assertSame(SupervisorMessage::TYPE_POOL_SNAPSHOT, $snapshot['type']);
        self::assertSame('business', $snapshot['scope']);
        self::assertSame(5, $snapshot['version']);
        self::assertSame('msg-pool-5', $snapshot['msg_id']);
        self::assertSame('channel-default', $snapshot['channel']);
        self::assertCount(1, $snapshot['workers']);

        $ack = SupervisorMessage::decode(SupervisorMessage::poolSnapshotAck(5, true, 'business', 'msg-pool-5', 'channel-default'));
        self::assertSame(SupervisorMessage::TYPE_POOL_SNAPSHOT_ACK, $ack['type']);
        self::assertSame(5, $ack['version']);
        self::assertTrue($ack['accepted']);
        self::assertSame('msg-pool-5', $ack['msg_id']);
        self::assertSame('channel-default', $ack['channel']);
    }
}
