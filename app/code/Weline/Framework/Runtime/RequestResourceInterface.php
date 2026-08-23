<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * A request-owned resource that must be released explicitly under a persistent runtime.
 * Implementations must make close() idempotent and must not expose secrets in resourceKind().
 */
interface RequestResourceInterface
{
    public function resourceKind(): string;

    public function close(): void;

    public function isClosed(): bool;
}
