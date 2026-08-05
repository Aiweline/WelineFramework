<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Async\Exception;

class NonRetryableAsyncEventException extends \RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
