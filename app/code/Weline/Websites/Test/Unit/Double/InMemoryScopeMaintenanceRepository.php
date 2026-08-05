<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Double;

use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Websites\Api\ScopeMaintenanceRepositoryInterface;

final class InMemoryScopeMaintenanceRepository implements ScopeMaintenanceRepositoryInterface
{
    /** @var array<string,array{scope_key:string,enabled:bool,reason:string,generation:int,since:int}> */
    private array $states = [];

    /** @var array<string,array{scope_key:string,token_hash:string,kid:string,generation:int,issued_at:int,expires_at:int,revoked:bool}> */
    private array $tokens = [];

    /** @var list<array<string,mixed>> */
    private array $audits = [];

    public bool $failReads = false;

    public function status(ScopeIdentity $scope): array
    {
        if ($this->failReads) {
            throw new \RuntimeException('scope_maintenance_store_unavailable');
        }
        return $this->states[$scope->canonicalKey()] ?? [
            'scope_key' => $scope->canonicalKey(),
            'enabled' => false,
            'reason' => '',
            'generation' => 0,
            'since' => 0,
        ];
    }

    public function setMaintenance(
        ScopeIdentity $scope,
        bool $enabled,
        string $reason,
        int $now,
        string $actor = 'system',
    ): array {
        $current = $this->status($scope);
        $state = [
            'scope_key' => $scope->canonicalKey(),
            'enabled' => $enabled,
            'reason' => $enabled ? trim($reason) : '',
            'generation' => $current['generation'] + 1,
            'since' => $enabled ? $now : 0,
        ];
        $this->states[$scope->canonicalKey()] = $state;
        if (!$enabled) {
            foreach ($this->tokens as &$token) {
                if ($token['scope_key'] === $scope->canonicalKey()) {
                    $token['revoked'] = true;
                }
            }
            unset($token);
        }
        $this->audits[] = [
            'scope_key' => $scope->canonicalKey(),
            'action' => $enabled ? 'enabled' : 'disabled',
            'generation' => $state['generation'],
            'actor' => $actor,
            'recorded_at' => $now,
        ];
        return $state;
    }

    public function registerToken(
        ScopeIdentity $scope,
        string $tokenHash,
        string $kid,
        int $generation,
        int $issuedAt,
        int $expiresAt,
        string $actor = 'system',
    ): void {
        $state = $this->status($scope);
        if (!$state['enabled'] || $state['generation'] !== $generation) {
            throw new \RuntimeException('maintenance_preview_generation_conflict');
        }
        $this->tokens[$tokenHash] = [
            'scope_key' => $scope->canonicalKey(),
            'token_hash' => $tokenHash,
            'kid' => $kid,
            'generation' => $generation,
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
            'revoked' => false,
        ];
        $this->audits[] = [
            'scope_key' => $scope->canonicalKey(),
            'action' => 'token_issued',
            'generation' => $generation,
            'token_hash' => $tokenHash,
            'actor' => $actor,
            'recorded_at' => $issuedAt,
        ];
    }

    public function tokenStatus(string $tokenHash): ?array
    {
        if ($this->failReads) {
            throw new \RuntimeException('scope_maintenance_store_unavailable');
        }
        return $this->tokens[$tokenHash] ?? null;
    }

    public function revokeToken(string $tokenHash, int $now, string $actor = 'system'): bool
    {
        if (!isset($this->tokens[$tokenHash])) {
            return false;
        }
        $this->tokens[$tokenHash]['revoked'] = true;
        $this->audits[] = [
            'scope_key' => $this->tokens[$tokenHash]['scope_key'],
            'action' => 'token_revoked',
            'generation' => $this->tokens[$tokenHash]['generation'],
            'token_hash' => $tokenHash,
            'actor' => $actor,
            'recorded_at' => $now,
        ];
        return true;
    }

    public function revokeAllForScope(
        ScopeIdentity $scope,
        int $now,
        string $actor = 'system',
    ): void {
        foreach ($this->tokens as &$token) {
            if ($token['scope_key'] === $scope->canonicalKey()) {
                $token['revoked'] = true;
            }
        }
        unset($token);
        $this->audits[] = [
            'scope_key' => $scope->canonicalKey(),
            'action' => 'tokens_revoked',
            'generation' => $this->status($scope)['generation'],
            'actor' => $actor,
            'recorded_at' => $now,
        ];
    }

    public function auditForScope(ScopeIdentity $scope): array
    {
        return array_values(array_filter(
            $this->audits,
            static fn(array $row): bool => $row['scope_key'] === $scope->canonicalKey(),
        ));
    }
}
