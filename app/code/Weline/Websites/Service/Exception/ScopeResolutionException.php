<?php

declare(strict_types=1);

namespace Weline\Websites\Service\Exception;

final class ScopeResolutionException extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $httpStatus = 400,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
