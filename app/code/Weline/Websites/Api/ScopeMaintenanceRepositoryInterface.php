<?php

declare(strict_types=1);

namespace Weline\Websites\Api;

use Weline\Framework\Runtime\ScopeIdentity;

interface ScopeMaintenanceRepositoryInterface
{
    /**
     * @return array{scope_key:string,enabled:bool,reason:string,generation:int,since:int}
     */
    public function status(ScopeIdentity $scope): array;

    /**
     * @return array{scope_key:string,enabled:bool,reason:string,generation:int,since:int}
     */
    public function setMaintenance(
        ScopeIdentity $scope,
        bool $enabled,
        string $reason,
        int $now,
        string $actor = 'system',
    ): array;

    public function registerToken(
        ScopeIdentity $scope,
        string $tokenHash,
        string $kid,
        int $generation,
        int $issuedAt,
        int $expiresAt,
        string $actor = 'system',
    ): void;

    /**
     * @return array{scope_key:string,token_hash:string,kid:string,generation:int,issued_at:int,expires_at:int,revoked:bool}|null
     */
    public function tokenStatus(string $tokenHash): ?array;

    public function revokeToken(string $tokenHash, int $now, string $actor = 'system'): bool;

    public function revokeAllForScope(
        ScopeIdentity $scope,
        int $now,
        string $actor = 'system',
    ): void;

    /**
     * @return list<array<string,mixed>>
     */
    public function auditForScope(ScopeIdentity $scope): array;
}
