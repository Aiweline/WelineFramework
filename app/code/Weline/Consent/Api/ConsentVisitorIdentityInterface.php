<?php

declare(strict_types=1);

namespace Weline\Consent\Api;

interface ConsentVisitorIdentityInterface
{
    /**
     * Resolve the trusted server-issued browser identifier, issuing it when absent or invalid.
     */
    public function resolveOrIssue(): string;

    /**
     * Browser callers must never select another visitor identity.
     *
     * @param array<string,mixed> $params
     */
    public function assertNoClientOverride(array $params): void;
}
