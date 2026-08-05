<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Security;

/**
 * Explicit test double. Production wiring must provide a durable repository.
 */
final class InMemorySecurityPolicyLkgRepository implements SecurityPolicyLkgRepositoryInterface
{
    /** @var array<string, array{schema_version:string,scope_key:string,digest:string,verified_at:string}> */
    private array $records = [];

    public function find(string $schemaVersion, string $scopeKey): ?array
    {
        return $this->records[$this->key($schemaVersion, $scopeKey)] ?? null;
    }

    public function save(
        string $schemaVersion,
        string $scopeKey,
        string $digest,
        string $verifiedAt,
    ): array {
        $row = [
            'schema_version' => $schemaVersion,
            'scope_key' => $scopeKey,
            'digest' => $digest,
            'verified_at' => $verifiedAt,
        ];
        $this->records[$this->key($schemaVersion, $scopeKey)] = $row;

        return $row;
    }

    public function delete(string $schemaVersion, string $scopeKey): void
    {
        unset($this->records[$this->key($schemaVersion, $scopeKey)]);
    }

    private function key(string $schemaVersion, string $scopeKey): string
    {
        return $schemaVersion . "\0" . $scopeKey;
    }
}
