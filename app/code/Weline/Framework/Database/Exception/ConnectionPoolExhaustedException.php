<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Exception;

final class ConnectionPoolExhaustedException extends DatabaseException
{
    /**
     * @param array<string, mixed> $context Credential-free pool diagnostics.
     */
    public function __construct(
        string $message,
        private readonly array $context = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** @return array<string, mixed> */
    public function getContext(): array
    {
        return $this->context;
    }
}
