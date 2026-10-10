<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

final readonly class ProcessCacheResetContext
{
    public const REASON_CACHE_CLEAR = 'cache_clear';
    public const REASON_MEMORY_PRESSURE = 'memory_pressure';

    public const SURFACE_CONTENT = 'content';
    public const SURFACE_REGISTRY = 'registry';
    public const SURFACE_ALL = 'all';

    public const TIER_SOFT = 'soft';
    public const TIER_HARD = 'hard';

    public string $surface;
    public string $tier;

    public function __construct(
        public string $reason,
        public bool $aggressive = false,
        ?string $surface = null,
        ?string $tier = null,
    ) {
        if ($surface !== null && $tier !== null) {
            $this->surface = $surface;
            $this->tier = $tier;

            return;
        }
        // Two-arg derivation (frozen): CACHE_CLEAR or aggressive → ALL+HARD;
        // MEMORY_PRESSURE && !aggressive → CONTENT+SOFT; else conservative CONTENT+SOFT.
        if ($this->reason === self::REASON_CACHE_CLEAR || $this->aggressive) {
            $this->surface = self::SURFACE_ALL;
            $this->tier = self::TIER_HARD;
        } else {
            $this->surface = self::SURFACE_CONTENT;
            $this->tier = self::TIER_SOFT;
        }
    }

    public function isExplicitCacheClear(): bool
    {
        return $this->reason === self::REASON_CACHE_CLEAR;
    }

    public function isSoftContent(): bool
    {
        return $this->reason === self::REASON_MEMORY_PRESSURE
            && !$this->aggressive
            && $this->surface === self::SURFACE_CONTENT
            && $this->tier === self::TIER_SOFT;
    }

    public function allowsRegistryClear(): bool
    {
        return $this->aggressive
            || $this->isExplicitCacheClear()
            || $this->surface === self::SURFACE_REGISTRY
            || $this->surface === self::SURFACE_ALL
            || $this->tier === self::TIER_HARD;
    }
}
