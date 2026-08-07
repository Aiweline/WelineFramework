<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

/** One active plus one max-generation pending aggregate, with bounded history. */
final class NamespaceInvalidationOperationQueue
{
    private const HISTORY_LIMIT = 256;
    private const HISTORY_TTL_SEC = 600.0;
    private const PENDING_MEMBER_LIMIT = 256;

    /** @var array<string,mixed>|null */
    private ?array $active = null;

    /** @var array<string,mixed>|null */
    private ?array $pending = null;

    /** @var array<string,array<string,mixed>> */
    private array $history = [];

    /** @var array<string,array{payload_hash:string,state:string,primary_id:string}> */
    private array $known = [];

    public function __construct(private readonly NamespaceInvalidationProtocol $protocol)
    {
    }

    /**
     * @param array<string,mixed> $frame
     * @return array{success:bool,accepted:bool,duplicate:bool,completed:bool,operation_id:string,state:string,error_code:string,message:string,primary_operation_id:string}
     */
    public function accept(array $frame, int $clientId, string $msgId): array
    {
        $this->pruneHistory();
        $frame = $this->protocol->validateFrame($frame);
        $operationId = $frame['operation_id'];
        $payloadHash = $this->protocol->payloadHash($frame);
        $known = $this->known[$operationId] ?? null;
        if ($known !== null) {
            if (!\hash_equals($known['payload_hash'], $payloadHash)) {
                return $this->rejected($operationId, 'operation_conflict', (string)__('操作 ID 已用于其他负载。'));
            }
            return [
                'success' => true,
                'accepted' => true,
                'duplicate' => true,
                'completed' => \in_array($known['state'], ['completed', 'failed'], true),
                'operation_id' => $operationId,
                'state' => $known['state'],
                'error_code' => '',
                'message' => (string)__('缓存命名空间失效操作已接收。'),
                'primary_operation_id' => $known['primary_id'],
            ];
        }

        $member = [
            'operation_id' => $operationId,
            'payload_hash' => $payloadHash,
            'client_id' => $clientId,
            'msg_id' => $msgId,
            'accepted_at' => self::wallClockSeconds(),
            'accepted_monotonic' => self::monotonicSeconds(),
        ];
        if ($this->pending === null) {
            $this->pending = $this->newAggregate($frame, $member);
        } else {
            $merged = $this->mergePending($this->pending, $frame, $member);
            if ($merged === null) {
                return $this->rejected($operationId, 'queue_capacity_exceeded', (string)__('待处理的缓存命名空间失效操作超出容量限制。'));
            }
            $this->pending = $merged;
        }
        $primaryId = (string)$this->pending['frame']['operation_id'];
        $this->known[$operationId] = [
            'payload_hash' => $payloadHash,
            'state' => 'queued',
            'primary_id' => $primaryId,
        ];

        return [
            'success' => true,
            'accepted' => true,
            'duplicate' => false,
            'completed' => false,
            'operation_id' => $operationId,
            'state' => 'queued',
            'error_code' => '',
            'message' => (string)__('缓存命名空间失效操作已接收。'),
            'primary_operation_id' => $primaryId,
        ];
    }

    public function hasPending(): bool
    {
        return $this->pending !== null;
    }

    public function hasActive(): bool
    {
        return $this->active !== null;
    }

    /** @return array<string,mixed>|null */
    public function startNext(): ?array
    {
        if ($this->active !== null || $this->pending === null) {
            return null;
        }
        $this->active = $this->pending;
        $this->pending = null;
        $this->active['state'] = 'running';
        $this->active['started_at'] = self::wallClockSeconds();
        $this->active['started_monotonic'] = self::monotonicSeconds();
        foreach ($this->active['members'] as $member) {
            $id = (string)$member['operation_id'];
            if (isset($this->known[$id])) {
                $this->known[$id]['state'] = 'running';
            }
        }

        return $this->active;
    }

    /** @return array<string,mixed>|null */
    public function active(): ?array
    {
        return $this->active;
    }

    /** @param array<int,array<string,mixed>> $targets */
    public function setActiveTargets(array $targets): void
    {
        if ($this->active === null) {
            throw new \LogicException((string)__('没有正在执行的缓存命名空间失效操作。'));
        }
        $this->active['targets'] = $targets;
        $this->active['acks'] = [];
        $this->active['failures'] = [];
    }

    /** @param array<string,mixed> $ack */
    public function acknowledge(int $clientId, array $ack): void
    {
        if ($this->active === null || !isset($this->active['targets'][$clientId])) {
            return;
        }
        if (isset($this->active['acks'][$clientId]) || isset($this->active['failures'][$clientId])) {
            return;
        }
        $this->active['acks'][$clientId] = $ack;
    }

    /** @param array<string,mixed> $details */
    public function failTarget(int $clientId, string $errorCode, array $details = []): void
    {
        if ($this->active === null || !isset($this->active['targets'][$clientId])) {
            return;
        }
        if (isset($this->active['acks'][$clientId]) || isset($this->active['failures'][$clientId])) {
            return;
        }
        $this->active['failures'][$clientId] = ['error_code' => $errorCode] + $details;
    }

    /** @return array<string,mixed>|null */
    public function finish(): ?array
    {
        if ($this->active === null) {
            return null;
        }
        $operation = $this->active;
        $this->active = null;
        $targets = (array)($operation['targets'] ?? []);
        $acks = (array)($operation['acks'] ?? []);
        $failures = (array)($operation['failures'] ?? []);
        $success = $targets !== [] && \count($acks) === \count($targets) && $failures === [];
        $state = $success ? 'completed' : 'failed';
        $finishedAt = self::wallClockSeconds();
        $finishedMonotonic = self::monotonicSeconds();

        foreach ((array)$operation['members'] as $member) {
            $id = (string)$member['operation_id'];
            $entry = [
                'id' => $id,
                'primary_operation_id' => (string)$operation['frame']['operation_id'],
                'payload_hash' => (string)$member['payload_hash'],
                'state' => $state,
                'success' => $success,
                'authority_clock' => (int)$operation['frame']['authority_clock'],
                'changes' => $operation['frame']['changes'],
                'expected' => \count($targets),
                'acked' => \count($acks),
                'acks' => \array_values($acks),
                'failures' => \array_values($failures),
                'accepted_at' => (float)$member['accepted_at'],
                'started_at' => (float)($operation['started_at'] ?? 0.0),
                'finished_at' => $finishedAt,
                'finished_monotonic' => $finishedMonotonic,
            ];
            $this->history[$id] = $entry;
            $this->known[$id] = [
                'payload_hash' => (string)$member['payload_hash'],
                'state' => $state,
                'primary_id' => (string)$operation['frame']['operation_id'],
            ];
        }
        $this->pruneHistory();

        return [
            'success' => $success,
            'state' => $state,
            'primary_operation_id' => (string)$operation['frame']['operation_id'],
            'member_operation_ids' => \array_values(\array_map(
                static fn(array $member): string => (string)$member['operation_id'],
                $operation['members'],
            )),
            'expected' => \count($targets),
            'acked' => \count($acks),
            'acks' => \array_values($acks),
            'failures' => \array_values($failures),
        ];
    }

    /** @return array<string,mixed>|null */
    public function status(string $operationId): ?array
    {
        $this->pruneHistory();
        if (isset($this->history[$operationId])) {
            return $this->history[$operationId];
        }
        foreach ([$this->active, $this->pending] as $operation) {
            if (!\is_array($operation)) {
                continue;
            }
            foreach ((array)$operation['members'] as $member) {
                if ((string)$member['operation_id'] === $operationId) {
                    return [
                        'id' => $operationId,
                        'primary_operation_id' => (string)$operation['frame']['operation_id'],
                        'state' => (string)$operation['state'],
                        'success' => false,
                        'completed' => false,
                        'authority_clock' => (int)$operation['frame']['authority_clock'],
                        'changes' => $operation['frame']['changes'],
                        'expected' => \count((array)($operation['targets'] ?? [])),
                        'acked' => \count((array)($operation['acks'] ?? [])),
                        'failures' => \array_values((array)($operation['failures'] ?? [])),
                    ];
                }
            }
        }

        return null;
    }

    /** @return array{active:?array,pending:?array,history:array<string,array<string,mixed>>} */
    public function snapshot(): array
    {
        $this->pruneHistory();
        return [
            'active' => $this->summarize($this->active),
            'pending' => $this->summarize($this->pending),
            'history' => \array_map(
                static fn(array $entry): array => [
                    'id' => (string)$entry['id'],
                    'primary_operation_id' => (string)$entry['primary_operation_id'],
                    'state' => (string)$entry['state'],
                    'success' => (bool)$entry['success'],
                    'authority_clock' => (int)$entry['authority_clock'],
                    'expected' => (int)$entry['expected'],
                    'acked' => (int)$entry['acked'],
                    'finished_at' => (float)$entry['finished_at'],
                ],
                $this->history,
            ),
        ];
    }

    /** @param array<string,mixed> $frame @param array<string,mixed> $member @return array<string,mixed> */
    private function newAggregate(array $frame, array $member): array
    {
        return [
            'frame' => $frame,
            'members' => [$member],
            'state' => 'queued',
            'queued_at' => self::wallClockSeconds(),
            'queued_monotonic' => self::monotonicSeconds(),
            'started_at' => null,
            'started_monotonic' => null,
            'targets' => [],
            'acks' => [],
            'failures' => [],
        ];
    }

    /**
     * @param array<string,mixed> $pending
     * @param array<string,mixed> $incomingFrame
     * @param array<string,mixed> $member
     * @return array<string,mixed>|null
     */
    private function mergePending(array $pending, array $incomingFrame, array $member): ?array
    {
        if (\count((array)$pending['members']) >= self::PENDING_MEMBER_LIMIT) {
            return null;
        }
        $byNamespace = [];
        foreach (\array_merge($pending['frame']['changes'], $incomingFrame['changes']) as $change) {
            $namespace = (string)$change['namespace'];
            $byNamespace[$namespace] = \max((int)($byNamespace[$namespace] ?? 0), (int)$change['generation']);
        }
        if (\count($byNamespace) > NamespaceInvalidationProtocol::MAX_CHANGES) {
            return null;
        }
        \ksort($byNamespace, \SORT_STRING);
        $changes = [];
        foreach ($byNamespace as $namespace => $generation) {
            $changes[] = ['namespace' => $namespace, 'generation' => $generation];
        }
        $pending['frame']['authority_clock'] = \max(
            (int)$pending['frame']['authority_clock'],
            (int)$incomingFrame['authority_clock'],
        );
        $pending['frame']['changes'] = $changes;
        $pending['frame'] = $this->protocol->validateFrame($pending['frame']);
        $pending['members'][] = $member;

        return $pending;
    }

    /** @return array<string,mixed>|null */
    private function summarize(?array $operation): ?array
    {
        if ($operation === null) {
            return null;
        }

        return [
            'id' => (string)$operation['frame']['operation_id'],
            'state' => (string)$operation['state'],
            'authority_clock' => (int)$operation['frame']['authority_clock'],
            'changes' => $operation['frame']['changes'],
            'member_operation_ids' => \array_values(\array_map(
                static fn(array $member): string => (string)$member['operation_id'],
                $operation['members'],
            )),
            'expected' => \count((array)($operation['targets'] ?? [])),
            'acked' => \count((array)($operation['acks'] ?? [])),
            'failures' => \array_values((array)($operation['failures'] ?? [])),
        ];
    }

    private function pruneHistory(): void
    {
        $cutoff = self::monotonicSeconds() - self::HISTORY_TTL_SEC;
        foreach ($this->history as $id => $entry) {
            if ((float)($entry['finished_monotonic'] ?? 0.0) >= $cutoff) {
                continue;
            }
            unset($this->history[$id], $this->known[$id]);
        }
        while (\count($this->history) > self::HISTORY_LIMIT) {
            $id = \array_key_first($this->history);
            if ($id === null) {
                break;
            }
            unset($this->history[$id], $this->known[$id]);
        }
    }

    private static function monotonicSeconds(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }

    private static function wallClockSeconds(): float
    {
        return (float)\time();
    }

    /** @return array{success:bool,accepted:bool,duplicate:bool,completed:bool,operation_id:string,state:string,error_code:string,message:string,primary_operation_id:string} */
    private function rejected(string $operationId, string $errorCode, string $message): array
    {
        return [
            'success' => false,
            'accepted' => false,
            'duplicate' => false,
            'completed' => false,
            'operation_id' => $operationId,
            'state' => 'rejected',
            'error_code' => $errorCode,
            'message' => $message,
            'primary_operation_id' => '',
        ];
    }
}
