<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Async;

final readonly class AsyncEventDispatchResult
{
    public function __construct(
        public string $status,
        public ?int $outboxId = null,
        public int $targetCount = 0,
        public string $reason = '',
    ) {
    }
}
