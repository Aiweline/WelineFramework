<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

/**
 * Immutable, handler-authorized task start specification.
 *
 * It is intentionally data-only: runtime state, Request, Session, closures,
 * credentials, and Processer instances must never cross this boundary.
 */
final readonly class TaskStartRequest
{
    /**
     * @param array<string|int,mixed> $input
     */
    public function __construct(
        public array $input,
        public string $businessKey,
        public TaskPolicy $policy,
    ) {
        if (trim($this->businessKey) === '' || strlen($this->businessKey) > 191) {
            throw new \InvalidArgumentException('Resumable task start business key is invalid.');
        }
    }
}
