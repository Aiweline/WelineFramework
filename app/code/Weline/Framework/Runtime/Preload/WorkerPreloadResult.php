<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Preload;

final class WorkerPreloadResult
{
    private function __construct(
        private string $provider,
        private string $phase,
        private string $status,
        private int $items,
        private float $durationMs,
        private int $memoryDelta,
        private string $message = '',
        private array $details = []
    ) {
    }

    public static function warmed(
        string $provider,
        string $phase,
        int $items,
        float $durationMs,
        int $memoryDelta,
        array $details = []
    ): self {
        return new self($provider, $phase, 'warmed', $items, $durationMs, $memoryDelta, '', $details);
    }

    public static function skipped(string $provider, string $phase, string $message = ''): self
    {
        return new self($provider, $phase, 'skipped', 0, 0.0, 0, $message);
    }

    public static function failed(
        string $provider,
        string $phase,
        string $message,
        float $durationMs,
        int $memoryDelta
    ): self {
        return new self($provider, $phase, 'failed', 0, $durationMs, $memoryDelta, $message);
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function phase(): string
    {
        return $this->phase;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function items(): int
    {
        return $this->items;
    }

    public function durationMs(): float
    {
        return $this->durationMs;
    }

    public function memoryDelta(): int
    {
        return $this->memoryDelta;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function details(): array
    {
        return $this->details;
    }
}
