<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

enum TaskEffectState: string
{
    case RESERVED = 'reserved';
    case APPLIED = 'applied';
    case UNKNOWN = 'unknown';

    public function isSafeToRetry(): bool
    {
        return $this === self::RESERVED;
    }
}
