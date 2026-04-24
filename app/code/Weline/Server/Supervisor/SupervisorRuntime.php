<?php
declare(strict_types=1);

namespace Weline\Server\Supervisor;

use Weline\Server\Supervisor\Endpoint\ControlEndpoint;
use Weline\Server\Supervisor\Endpoint\ControlEndpointResolver;
use Weline\Server\Supervisor\Lease\SlotLease;
use Weline\Server\Supervisor\Protocol\SupervisorMessage;

final class SupervisorRuntime
{
    private int $slotSnapshotVersion = 0;
    private int $workerPoolSnapshotVersion = 0;
    private ?ControlEndpoint $resolvedEndpoint = null;

    public function __construct(
        private readonly string $instanceName,
        private readonly string $channelId,
        private readonly ControlEndpointResolver $endpointResolver,
        private readonly Supervisor $supervisor = new Supervisor(),
    ) {
    }

    public function supervisor(): Supervisor
    {
        return $this->supervisor;
    }

    public function endpoint(?string $osFamily = null): ControlEndpoint
    {
        if ($osFamily === null && $this->resolvedEndpoint instanceof ControlEndpoint) {
            return $this->resolvedEndpoint;
        }

        $endpoint = $this->endpointResolver->resolve($this->instanceName, $osFamily);
        if ($osFamily === null) {
            $this->resolvedEndpoint = $endpoint;
        }

        return $endpoint;
    }

    public function handle(array $message): ?string
    {
        $msgId = (string)($message['msg_id'] ?? '');
        $receivedChannel = (string)($message['channel'] ?? '');
        if ($receivedChannel !== $this->channelId) {
            return SupervisorMessage::channelReject($msgId, $this->channelId, $receivedChannel);
        }

        $before = $this->structuralDigest();
        $poolBefore = $this->workerPoolDigest();
        $response = $this->supervisor->handle($message);
        $after = $this->structuralDigest();
        $poolAfter = $this->workerPoolDigest();

        if ($after !== $before) {
            $this->slotSnapshotVersion++;
        }
        if ($poolAfter !== $poolBefore) {
            $this->workerPoolSnapshotVersion++;
        }
        if (($message['type'] ?? '') === SupervisorMessage::TYPE_READY) {
            $slotId = (string)($message['slot_id'] ?? '');
            $lease = $slotId !== '' ? $this->supervisor->leases()->get($slotId) : null;
            if ($lease instanceof SlotLease && $lease->role === 'worker' && $lease->state === SlotLease::STATE_READY) {
                $msgId = (string)($message['msg_id'] ?? '');
                $response = SupervisorMessage::readyAck(
                    lease: $lease,
                    msgId: $msgId,
                    poolSnapshotVersion: $this->workerPoolSnapshotVersion,
                    channel: $this->channelId,
                );
            }
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function slotSnapshot(): array
    {
        $slots = [];
        foreach ($this->supervisor->leases()->all() as $lease) {
            $slots[] = $lease->toArray();
        }

        \usort($slots, static fn(array $left, array $right): int => \strcmp((string)$left['slot_id'], (string)$right['slot_id']));

        return [
            'instance' => $this->instanceName,
            'channel' => $this->channelId,
            'version' => $this->slotSnapshotVersion,
            'slots' => $slots,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function workerPoolSnapshot(string $scope = 'business'): array
    {
        $snapshot = $this->supervisor->buildWorkerPoolSnapshot($this->workerPoolSnapshotVersion, $scope);
        $snapshot['channel'] = $this->channelId;

        return $snapshot;
    }

    private function structuralDigest(): string
    {
        $rows = [];
        foreach ($this->supervisor->leases()->all() as $slotId => $lease) {
            $rows[] = $slotId
                . '|'
                . $lease->leaseId
                . '|'
                . $lease->generation
                . '|'
                . $lease->state
                . '|'
                . $lease->port;
        }
        \sort($rows, \SORT_STRING);

        return \implode("\n", $rows);
    }

    private function workerPoolDigest(): string
    {
        $rows = [];
        foreach ($this->supervisor->leases()->all() as $slotId => $lease) {
            if ($lease->role !== 'worker' || $lease->state !== SlotLease::STATE_READY) {
                continue;
            }
            $rows[] = $slotId
                . '|'
                . $lease->leaseId
                . '|'
                . $lease->generation
                . '|'
                . $lease->port;
        }
        \sort($rows, \SORT_STRING);

        return \implode("\n", $rows);
    }
}
