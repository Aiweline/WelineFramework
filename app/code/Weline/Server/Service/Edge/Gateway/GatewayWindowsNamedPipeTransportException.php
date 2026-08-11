<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Classifies native named-pipe availability failures without weakening local
 * helper, filesystem, digest, or protocol integrity failures.
 */
final class GatewayWindowsNamedPipeTransportException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly bool $retryable,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }
}
