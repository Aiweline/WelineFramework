<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * Stable cross-module failure contract for storefront Worker Scope providers.
 */
final class FrontendWorkerScopeException extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly int $httpStatus,
        string $message,
        ?\Throwable $previous = null,
    ) {
        if (\preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $reason) !== 1) {
            throw new \InvalidArgumentException('Worker Scope failure reason is invalid.');
        }
        if ($httpStatus < 400 || $httpStatus > 599) {
            throw new \InvalidArgumentException('Worker Scope failure HTTP status is invalid.');
        }

        parent::__construct($message, 0, $previous);
    }
}
