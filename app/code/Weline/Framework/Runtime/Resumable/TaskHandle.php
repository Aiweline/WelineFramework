<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

/**
 * Public response returned when a browser starts or attaches to a task.
 */
final readonly class TaskHandle
{
    public function __construct(
        public string $taskId,
        public string $leaseId,
        public ResumableTaskStatus $status,
        public int $leaseExpiresAt,
        public string $streamChannel = 'runtime_task.events',
    ) {
        self::assertIdentifier($this->taskId, 'task id');
        self::assertIdentifier($this->leaseId, 'lease id');
        if ($this->leaseExpiresAt < 1) {
            throw new \InvalidArgumentException('Task handle lease expiry must be a Unix timestamp.');
        }
        if (\trim($this->streamChannel) === '') {
            throw new \InvalidArgumentException('Task handle stream channel is required.');
        }
    }

    /**
     * @return array{task_id:string, lease_id:string, status:string, lease_expires_at:int, stream_channel:string}
     */
    public function toArray(): array
    {
        return [
            'task_id' => $this->taskId,
            'lease_id' => $this->leaseId,
            'status' => $this->status->value,
            'lease_expires_at' => $this->leaseExpiresAt,
            'stream_channel' => $this->streamChannel,
        ];
    }

    private static function assertIdentifier(string $value, string $label): void
    {
        if (\preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $value) !== 1) {
            throw new \InvalidArgumentException("Task handle {$label} is invalid.");
        }
    }
}
