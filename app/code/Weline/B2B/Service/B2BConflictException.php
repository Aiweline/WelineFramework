<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

final class B2BConflictException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $errorCode,
        string $message = '',
        public readonly array $context = [],
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $errorCode, $code, $previous);
    }
}
