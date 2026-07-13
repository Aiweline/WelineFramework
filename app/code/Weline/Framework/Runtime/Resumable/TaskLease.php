<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

/**
 * A client-side liveness lease, independent from an SSE connection.
 */
final readonly class TaskLease
{
    public function __construct(
        public string $leaseId,
        public string $taskId,
        public TaskOwner $owner,
        public string $subscriptionId,
        public int $lastSeenAt,
        public int $expiresAt,
    ) {
        if (\preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $this->leaseId) !== 1
            || \preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $this->taskId) !== 1) {
            throw new \InvalidArgumentException('Task lease id or task id is invalid.');
        }
        if (\trim($this->subscriptionId) === '') {
            throw new \InvalidArgumentException('Task lease subscription id is required.');
        }
        if ($this->lastSeenAt < 1 || $this->expiresAt < $this->lastSeenAt) {
            throw new \InvalidArgumentException('Task lease timestamps are invalid.');
        }
    }

    public function isExpired(int $now): bool
    {
        return $now >= $this->expiresAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lease_id' => $this->leaseId,
            'task_id' => $this->taskId,
            'owner' => $this->owner->toArray(),
            'subscription_id' => $this->subscriptionId,
            'last_seen_at' => $this->lastSeenAt,
            'expires_at' => $this->expiresAt,
        ];
    }
}
