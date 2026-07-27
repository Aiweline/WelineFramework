<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

final class FrontendWorkerBackendAuthorizationException extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly int $httpStatus,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
