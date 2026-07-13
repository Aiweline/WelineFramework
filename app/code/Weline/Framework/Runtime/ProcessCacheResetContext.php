<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

final readonly class ProcessCacheResetContext
{
    public const REASON_CACHE_CLEAR = 'cache_clear';
    public const REASON_MEMORY_PRESSURE = 'memory_pressure';

    public function __construct(
        public string $reason,
        public bool $aggressive = false,
    ) {
    }

    public function isExplicitCacheClear(): bool
    {
        return $this->reason === self::REASON_CACHE_CLEAR;
    }
}
