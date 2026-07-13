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
    public const TYPE_LEASE_RELEASE = 'lease_release';
    public const TYPE_LEASE_RELEASE_ACK = 'lease_release_ack';
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
        string $msgId,
        string $leaseId = '',
        int $generation = 0,
        string $authSecret = '',
    ): string {
        $payload = [
            'type' => self::TYPE_HELLO,
            'msg_id' => $msgId,
            'instance' => $instance,
            'channel' => $channel,
            'role' => $role,
            'slot_id' => $slotId,
            'pid' => $pid,
            'launch_nonce' => $launchNonce,
        ];
        if ($leaseId !== '') {
            $payload['lease_id'] = $leaseId;
        }
        if ($generation > 0) {
            $payload['generation'] = $generation;
        }
        if ($authSecret !== '') {
            $payload['auth_ts'] = \time();
            $payload['auth_nonce'] = \bin2hex(\random_bytes(16));
            $payload['auth_mac'] = \hash_hmac('sha256', self::helloAuthenticationPayload($payload), $authSecret);
        }

        return self::encode($payload);
    }

    /** @param array<string, mixed> $message */
    public static function verifyHelloAuthentication(
        array $message,
        string $authSecret,
        int $now = 0,
        int $maxClockSkewSec = 30,
    ): bool {
        if ($authSecret === '') {
            return true;
        }
        $timestamp = (int)($message['auth_ts'] ?? 0);
        $nonce = (string)($message['auth_nonce'] ?? '');
        $receivedMac = \strtolower((string)($message['auth_mac'] ?? ''));
        $now = $now > 0 ? $now : \time();
        if ($timestamp <= 0
            || \abs($now - $timestamp) > \max(1, $maxClockSkewSec)
            || \preg_match('/^[a-f0-9]{32}$/', $nonce) !== 1
            || \preg_match('/^[a-f0-9]{64}$/', $receivedMac) !== 1) {
            return false;
        }

        $expectedMac = \hash_hmac('sha256', self::helloAuthenticationPayload($message), $authSecret);

        return \hash_equals($expectedMac, $receivedMac);
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

    /**
     * @param array<string, mixed> $readiness
     */
    public static function ready(
        string $slotId,
        string $leaseId,
        int $generation,
        int $port,
        string $msgId,
        string $channel = '',
        array $readiness = [],
    ): string {
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
        foreach ([
            'readiness_protocol_version',
            'readiness_capabilities',
            'topology',
            'policy_digest',
            'container_registry_digest',
            'warmup_state',
            'homepage_fpc',
            'dynamic_first_render',
            'listen_capabilities',
        ] as $field) {
            if (\array_key_exists($field, $readiness)) {
                $payload[$field] = $readiness[$field];
            }
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

    public static function leaseRelease(
        string $slotId,
        string $leaseId,
        int $generation,
        string $msgId = '',
        string $channel = ''
    ): string {
        $payload = [
            'type' => self::TYPE_LEASE_RELEASE,
            'slot_id' => $slotId,
            'lease_id' => $leaseId,
            'generation' => $generation,
        ];
        if ($msgId !== '') {
            $payload['msg_id'] = $msgId;
        }
        if ($channel !== '') {
            $payload['channel'] = $channel;
        }

        return self::encode($payload);
    }

    public static function leaseReleaseAck(
        string $slotId,
        string $leaseId,
        int $generation,
        bool $accepted,
        string $msgId = '',
        string $reason = '',
        string $channel = ''
    ): string {
        $payload = [
            'type' => self::TYPE_LEASE_RELEASE_ACK,
            'accepted' => $accepted,
            'slot_id' => $slotId,
            'lease_id' => $leaseId,
            'generation' => $generation,
        ];
        if ($msgId !== '') {
            $payload['msg_id'] = $msgId;
        }
        if ($reason !== '') {
            $payload['reason'] = $reason;
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

    public static function channelReject(
        string $msgId,
        string $expectedChannel,
        string $receivedChannel,
        string $reason = 'channel_mismatch',
        string $expectedInstance = '',
        string $receivedInstance = '',
    ): string
    {
        $payload = [
            'type' => self::TYPE_CHANNEL_REJECT,
            'msg_id' => $msgId,
            'expected_channel' => $expectedChannel,
            'received_channel' => $receivedChannel,
            'reason' => $reason,
        ];
        if ($expectedInstance !== '' || $receivedInstance !== '') {
            $payload['expected_instance'] = $expectedInstance;
            $payload['received_instance'] = $receivedInstance;
        }

        return self::encode($payload);
    }

    /** @param array<string, mixed> $message */
    private static function helloAuthenticationPayload(array $message): string
    {
        return \implode("\n", [
            (string)($message['instance'] ?? ''),
            (string)($message['channel'] ?? ''),
            (string)($message['role'] ?? ''),
            (string)($message['slot_id'] ?? ''),
            (string)(int)($message['pid'] ?? 0),
            (string)($message['launch_nonce'] ?? ''),
            (string)($message['lease_id'] ?? ''),
            (string)(int)($message['generation'] ?? 0),
            (string)(int)($message['auth_ts'] ?? 0),
            (string)($message['auth_nonce'] ?? ''),
        ]);
    }
}
