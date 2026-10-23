<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Kernel;

final class StageResult
{
    public const OK = 'ok';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';
    public const WARNING = 'warning';

    public function __construct(
        private string $status,
        private string $message = '',
        private array $meta = [],
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function meta(): array
    {
        return $this->meta;
    }

    public function isFatal(): bool
    {
        return $this->status === self::FAILED;
    }

    public function toArray(): array
    {
        return ['status' => $this->status, 'message' => $this->message, 'meta' => $this->meta];
    }
}
