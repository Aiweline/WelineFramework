<?php
declare(strict_types=1);

namespace Weline\Server\Supervisor;

use Weline\Server\Supervisor\Lease\LeaseRegistry;
use Weline\Server\Supervisor\Lease\SlotLease;
use Weline\Server\Supervisor\Protocol\SupervisorMessage;

final class Supervisor
{
    public function __construct(
        private readonly LeaseRegistry $leases = new LeaseRegistry(),
    ) {
    }

    public function leases(): LeaseRegistry
    {
        return $this->leases;
    }

    /**
     * @return array<int, array<string, int|string>>
     */
    public function buildWorkerPoolSnapshot(int $version, string $scope = 'business'): array
    {
        $workers = [];
        foreach ($this->leases->all() as $lease) {
            if ($lease->role !== 'worker' || $lease->state !== SlotLease::STATE_READY) {
                continue;
            }

            $workers[] = [
                'slot_id' => $lease->slotId,
                'lease_id' => $lease->leaseId,
                'generation' => $lease->generation,
                'port' => $lease->port,
                'state' => $lease->state,
            ];
        }

        \usort($workers, static fn(array $left, array $right): int => \strcmp((string)$left['slot_id'], (string)$right['slot_id']));

        return [
            'type' => SupervisorMessage::TYPE_POOL_SNAPSHOT,
            'scope' => $scope,
            'version' => $version,
            'workers' => $workers,
        ];
    }

    public function handle(array $message): ?string
    {
        return match ((string)($message['type'] ?? '')) {
            SupervisorMessage::TYPE_HELLO => $this->handleHello($message),
            SupervisorMessage::TYPE_READY => $this->handleReady($message),
            SupervisorMessage::TYPE_HEARTBEAT => $this->handleHeartbeat($message),
            SupervisorMessage::TYPE_LEASE_RELEASE => $this->handleLeaseRelease($message),
            default => null,
        };
    }

    private function handleHello(array $message): string
    {
        $slotId = $this->requireString($message, 'slot_id');
        $role = $this->requireString($message, 'role');
        $channel = (string)($message['channel'] ?? '');
        $pid = (int)($message['pid'] ?? 0);
        $port = (int)($message['port'] ?? 0);
        $launchNonce = (string)($message['launch_nonce'] ?? '');
        $msgId = (string)($message['msg_id'] ?? '');
        $leaseId = (string)($message['lease_id'] ?? '');
        $generation = (int)($message['generation'] ?? 0);

        $lease = $this->leases->assign(
            slotId: $slotId,
            role: $role,
            pid: $pid,
            port: $port,
            launchNonce: $launchNonce,
            leaseId: $leaseId,
            generation: $generation,
        );

        return SupervisorMessage::leaseAssign($lease, $msgId, $channel);
    }

    private function handleReady(array $message): string
    {
        $slotId = $this->requireString($message, 'slot_id');
        $leaseId = $this->requireString($message, 'lease_id');
        $generation = (int)($message['generation'] ?? 0);
        $msgId = (string)($message['msg_id'] ?? '');
        $channel = (string)($message['channel'] ?? '');
        $listen = \is_array($message['listen'] ?? null) ? $message['listen'] : [];
        $port = (int)($listen['port'] ?? $message['port'] ?? 0);

        if ($generation <= 0 || $port <= 0) {
            return SupervisorMessage::readyNack($slotId, $leaseId, $generation, $msgId, 'invalid_ready_payload', $channel);
        }

        $lease = $this->leases->markReady($slotId, $leaseId, $generation, $port);
        if (!$lease instanceof SlotLease) {
            return SupervisorMessage::readyNack($slotId, $leaseId, $generation, $msgId, 'stale_or_unknown_lease', $channel);
        }

        return SupervisorMessage::readyAck($lease, $msgId, channel: $channel);
    }

    private function handleHeartbeat(array $message): ?string
    {
        $slotId = $this->requireString($message, 'slot_id');
        $leaseId = $this->requireString($message, 'lease_id');
        $generation = (int)($message['generation'] ?? 0);
        $seq = (int)($message['seq'] ?? 0);

        if ($generation <= 0 || $seq <= 0) {
            return null;
        }

        $this->leases->heartbeat($slotId, $leaseId, $generation, $seq);

        return null;
    }

    private function handleLeaseRelease(array $message): string
    {
        $slotId = $this->requireString($message, 'slot_id');
        $leaseId = $this->requireString($message, 'lease_id');
        $generation = (int)($message['generation'] ?? 0);
        $msgId = (string)($message['msg_id'] ?? '');
        $channel = (string)($message['channel'] ?? '');

        if ($generation <= 0) {
            return SupervisorMessage::leaseReleaseAck(
                $slotId,
                $leaseId,
                $generation,
                false,
                $msgId,
                'invalid_release_payload',
                $channel
            );
        }

        $released = $this->leases->release($slotId, $leaseId, $generation);

        return SupervisorMessage::leaseReleaseAck(
            $slotId,
            $leaseId,
            $generation,
            $released,
            $msgId,
            $released ? '' : 'stale_or_unknown_lease',
            $channel
        );
    }

    private function requireString(array $message, string $key): string
    {
        $value = (string)($message[$key] ?? '');
        if ($value === '') {
            throw new \InvalidArgumentException("Missing required supervisor message field: {$key}");
        }

        return $value;
    }
}
