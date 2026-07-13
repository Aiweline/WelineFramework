<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

/**
 * Terminal runner outcome. It is persisted once and replayed on reconnect;
 * reconnecting never creates another terminal result.
 */
final class TaskResult
{
    public readonly ResumableTaskStatus $status;
    /** @var array<string|int, mixed> */
    public readonly array $data;
    public readonly ?string $errorCode;
    public readonly string $terminalReason;

    /**
     * @param array<string|int, mixed> $data
     */
    public function __construct(
        ResumableTaskStatus $status,
        array $data = [],
        ?string $errorCode = null,
        string $terminalReason = '',
    ) {
        if (!$status->isTerminal()) {
            throw new \InvalidArgumentException('Task result status must be terminal.');
        }
        if ($errorCode !== null && (\trim($errorCode) === '' || \strlen($errorCode) > 128)) {
            throw new \InvalidArgumentException('Task result error code is invalid.');
        }
        if (\strlen($terminalReason) > 2_000) {
            throw new \InvalidArgumentException('Task result terminal reason is too long.');
        }

        $this->status = $status;
        $this->data = CheckpointCodec::normalize($data);
        $this->errorCode = $errorCode;
        $this->terminalReason = $terminalReason;
    }

    /**
     * @param array<string|int, mixed> $data
     */
    public static function completed(array $data = []): self
    {
        return new self(ResumableTaskStatus::COMPLETED, $data);
    }

    /**
     * @param array<string|int, mixed> $data
     */
    public static function failed(string $errorCode, string $reason = '', array $data = []): self
    {
        return new self(ResumableTaskStatus::FAILED, $data, $errorCode, $reason);
    }

    /**
     * @param array<string|int, mixed> $data
     */
    public static function cancelled(string $reason = '', array $data = []): self
    {
        return new self(ResumableTaskStatus::CANCELLED, $data, null, $reason);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'data' => $this->data,
            'error_code' => $this->errorCode,
            'terminal_reason' => $this->terminalReason,
        ];
    }
}
