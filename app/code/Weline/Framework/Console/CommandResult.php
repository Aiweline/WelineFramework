<?php

declare(strict_types=1);

namespace Weline\Framework\Console;

/**
 * Explicit CLI lifecycle result.
 *
 * Commands normally keep returning legacy null/int values. This result is for
 * commands that must either bypass post-execution hooks or retain a resource
 * lease until those hooks and the footer have completed.
 */
final class CommandResult
{
    private ?\Closure $finalizer;
    private bool $finalized = false;

    private function __construct(
        private readonly int $exitCode,
        private readonly bool $skipPostExecution,
        ?callable $finalizer = null,
    ) {
        $this->finalizer = $finalizer === null ? null : \Closure::fromCallable($finalizer);
    }

    public static function shortCircuit(int $exitCode = 0): self
    {
        return new self(self::normalizeExitCode($exitCode), true);
    }

    public static function deferFinalizer(callable $finalizer, int $exitCode = 0): self
    {
        return new self(self::normalizeExitCode($exitCode), false, $finalizer);
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    public function shouldSkipPostExecution(): bool
    {
        return $this->skipPostExecution;
    }

    public function finalize(): void
    {
        if ($this->finalized) {
            return;
        }
        $this->finalized = true;
        $finalizer = $this->finalizer;
        $this->finalizer = null;
        if ($finalizer !== null) {
            $finalizer();
        }
    }

    public function __destruct()
    {
        try {
            $this->finalize();
        } catch (\Throwable) {
            // Destructors must not turn shutdown into a fatal error. Explicit
            // callers still receive finalizer failures from finalize().
        }
    }

    private function __clone()
    {
    }

    private static function normalizeExitCode(int $exitCode): int
    {
        return max(0, min(255, $exitCode));
    }
}
