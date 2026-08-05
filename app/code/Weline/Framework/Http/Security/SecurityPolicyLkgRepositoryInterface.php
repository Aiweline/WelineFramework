<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Security;

/**
 * Verified security-policy LKG persistence boundary.
 *
 * Production implementations must be shared across WLS workers and survive
 * process restart. A process-local implementation is suitable only for tests.
 */
interface SecurityPolicyLkgRepositoryInterface
{
    /**
     * @return array{schema_version:string,scope_key:string,digest:string,verified_at:string}|null
     */
    public function find(string $schemaVersion, string $scopeKey): ?array;

    /**
     * @return array{schema_version:string,scope_key:string,digest:string,verified_at:string}
     */
    public function save(
        string $schemaVersion,
        string $scopeKey,
        string $digest,
        string $verifiedAt,
    ): array;

    public function delete(string $schemaVersion, string $scopeKey): void;
}
