<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable\Runner;

use Throwable;

/**
 * Terminal result reported by the Runner adapter to durable task storage.
 */
final class RuntimeRunnerExecutionResult
{
    public const COMPLETED = 'completed';
    public const STOPPED = 'stopped';
    public const STALE_FENCE = 'stale_fence';
    public const FAILED = 'failed';

    private const ALLOWED_STATUSES = [self::COMPLETED, self::STOPPED, self::STALE_FENCE, self::FAILED];

    public function __construct(
        public readonly string $status,
        public readonly array $result = [],
        public readonly string $errorCode = '',
        public readonly string $errorMessage = '',
    ) {
        if (!in_array($this->status, self::ALLOWED_STATUSES, true)) {
            throw new \InvalidArgumentException('Unknown Runtime Runner execution result status.');
        }
    }

    public static function completed(array $result = []): self
    {
        return new self(self::COMPLETED, $result);
    }

    public static function stopped(): self
    {
        return new self(self::STOPPED);
    }

    public static function staleFence(): self
    {
        return new self(self::STALE_FENCE);
    }

    public static function failed(Throwable $_throwable): self
    {
        return new self(
            self::FAILED,
            errorCode: 'runner_exception',
            // Do not persist a stack trace or arbitrary exception payload;
            // exception messages frequently contain provider or credential data.
            errorMessage: 'The resumable task Runner failed.',
        );
    }
}
