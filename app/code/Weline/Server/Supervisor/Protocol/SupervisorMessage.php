<?php
declare(strict_types=1);

namespace Weline\Server\Supervisor\Protocol;

use Weline\Server\Supervisor\Lease\SlotLease;

final class SupervisorMessage
{
    public const TYPE_HELLO = 'hello';
    public const TYPE_LEASE_ASSIGN = 'lease_assign';
    public const TYPE_READY = 'ready';
    public const TYPE_READY_ACK = 'ready_ack';
    public const TYPE_HEARTBEAT = 'heartbeat';
    public const TYPE_POOL_SNAPSHOT = 'pool_snapshot';
    public const TYPE_POOL_SNAPSHOT_ACK = 'pool_snapshot_ack';
    public const TYPE_CHANNEL_REJECT = 'channel_reject';

    public static function encode(array $payload): string
    {
        return \json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode(string $message): array
    {
        $decoded = \json_decode(\rtrim($message, "\r\n"), true);

        return \is_array($decoded) ? $decoded : [];
    }

    public static function hello(
        string $instance,
        string $channel,
        string $role,
        string $slotId,
        int $pid,
        string $launchNonce,
        string $msgId
    ): string {
        return self::encode([
            'type' => self::TYPE_HELLO,
            'msg_id' => $msgId,
            'instance' => $instance,
            'channel' => $channel,
            'role' => $role,
            'slot_id' => $slotId,
            'pid' => $pid,
            'launch_nonce' => $launchNonce,
        ]);
    }

    public static function leaseAssign(SlotLease $lease, string $msgId = '', string $channel = ''): string
    {
        $payload = [
            'type' => self::TYPE_LEASE_ASSIGN,
            'slot_id' => $lease->slotId,
            'lease_id' => $lease->leaseId,
            'generation' => $lease->generation,
            'role' => $lease->role,
        ];
        if ($msgId !== '') {
            $payload['msg_id'] = $msgId;
        }
        if ($channel !== '') {
            $payload['channel'] = $channel;
        }

        return self::encode($payload);
    }

    public static function ready(string $slotId, string $leaseId, int $generation, int $port, string $msgId, string $channel = ''): string
    {
        $payload = [
            'type' => self::TYPE_READY,
            'msg_id' => $msgId,
            'slot_id' => $slotId,
            'lease_id' => $leaseId,
            'generation' => $generation,
            'listen' => [
                'port' => $port,
            ],
        ];
        if ($channel !== '') {
            $payload['channel'] = $channel;
        }

        return self::encode($payload);
    }

    public static function readyAck(
        SlotLease $lease,
        string $msgId = '',
        bool $accepted = true,
        string $reason = '',
        int $poolSnapshotVersion = 0,
        string $channel = ''
    ): string
    {
        $payload = [
            'type' => self::TYPE_READY_ACK,
            'accepted' => $accepted,
            'slot_id' => $lease->slotId,
            'lease_id' => $lease->leaseId,
            'generation' => $lease->generation,
            'state' => $lease->state,
        ];
        if ($msgId !== '') {
            $payload['msg_id'] = $msgId;
        }
        if ($reason !== '') {
            $payload['reason'] = $reason;
        }
        if ($poolSnapshotVersion > 0) {
            $payload['pool_snapshot_version'] = $poolSnapshotVersion;
        }
        if ($channel !== '') {
            $payload['channel'] = $channel;
        }

        return self::encode($payload);
    }

    public static function readyNack(
        string $slotId,
        string $leaseId,
        int $generation,
        string $msgId,
        string $reason,
        string $channel = ''
    ): string {
        $payload = [
            'type' => self::TYPE_READY_ACK,
            'accepted' => false,
            'slot_id' => $slotId,
            'lease_id' => $leaseId,
            'generation' => $generation,
            'msg_id' => $msgId,
            'reason' => $reason,
        ];
        if ($channel !== '') {
            $payload['channel'] = $channel;
        }

        return self::encode($payload);
    }

    public static function heartbeat(string $slotId, string $leaseId, int $generation, int $seq, string $msgId = '', string $channel = ''): string
    {
        $payload = [
            'type' => self::TYPE_HEARTBEAT,
            'slot_id' => $slotId,
            'lease_id' => $leaseId,
            'generation' => $generation,
            'seq' => $seq,
            'timestamp' => \time(),
        ];
        if ($msgId !== '') {
            $payload['msg_id'] = $msgId;
        }
        if ($channel !== '') {
            $payload['channel'] = $channel;
        }

        return self::encode($payload);
    }

    /**
     * @param array<int, array<string, int|string>> $workers
     */
    public static function poolSnapshot(array $workers, int $version, string $scope = 'business', string $msgId = '', string $channel = ''): string
    {
        $payload = [
            'type' => self::TYPE_POOL_SNAPSHOT,
            'scope' => $scope,
            'version' => $version,
            'workers' => $workers,
        ];
        if ($msgId !== '') {
            $payload['msg_id'] = $msgId;
        }
        if ($channel !== '') {
            $payload['channel'] = $channel;
        }

        return self::encode($payload);
    }

    public static function poolSnapshotAck(int $version, bool $accepted = true, string $scope = 'business', string $msgId = '', string $channel = ''): string
    {
        $payload = [
            'type' => self::TYPE_POOL_SNAPSHOT_ACK,
            'scope' => $scope,
            'version' => $version,
            'accepted' => $accepted,
        ];
        if ($msgId !== '') {
            $payload['msg_id'] = $msgId;
        }
        if ($channel !== '') {
            $payload['channel'] = $channel;
        }

        return self::encode($payload);
    }

    public static function channelReject(string $msgId, string $expectedChannel, string $receivedChannel): string
    {
        return self::encode([
            'type' => self::TYPE_CHANNEL_REJECT,
            'msg_id' => $msgId,
            'expected_channel' => $expectedChannel,
            'received_channel' => $receivedChannel,
        ]);
    }
}
