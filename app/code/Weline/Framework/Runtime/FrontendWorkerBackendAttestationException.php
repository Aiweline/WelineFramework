<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * Stable cross-module failure contract for backend Worker page attestation.
 */
final class FrontendWorkerBackendAttestationException extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly int $httpStatus,
        string $message,
        ?\Throwable $previous = null,
    ) {
        if (\preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $reason) !== 1) {
            throw new \InvalidArgumentException('Worker backend attestation failure reason is invalid.');
        }
        if ($httpStatus < 400 || $httpStatus > 599) {
            throw new \InvalidArgumentException('Worker backend attestation HTTP status is invalid.');
        }

        parent::__construct($message, 0, $previous);
    }
}
