<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Websites\Api\ScopeMaintenanceRepositoryInterface;

/**
 * Durable Scope maintenance gate.
 *
 * Every request reads the authoritative repository. Store A and Store B are
 * independent, and preview authorization never grants write access.
 */
final class ScopeMaintenanceGate
{
    public function __construct(
        private readonly ScopeMaintenanceRepositoryInterface $repository,
    ) {
    }

    /**
     * @return array{scope_key:string,enabled:bool,reason:string,generation:int,since:int}
     */
    public function enable(
        ScopeIdentity $scope,
        string $reason = '',
        ?int $now = null,
        string $actor = 'system',
    ): array {
        return $this->repository->setMaintenance(
            $scope,
            true,
            $reason,
            $now ?? time(),
            $actor,
        );
    }

    /**
     * @return array{scope_key:string,enabled:bool,reason:string,generation:int,since:int}
     */
    public function disable(
        ScopeIdentity $scope,
        ?int $now = null,
        string $actor = 'system',
    ): array {
        return $this->repository->setMaintenance(
            $scope,
            false,
            '',
            $now ?? time(),
            $actor,
        );
    }

    public function isMaintenance(ScopeIdentity $scope): bool
    {
        return $this->maintenanceScope($scope) !== null;
    }

    public function status(ScopeIdentity $scope): array
    {
        // N4: prefer storefront.render_context.v1 when Installer already froze
        // this scope's maintenance snapshot (no parallel bag / no second SQL).
        try {
            if (\class_exists(\Weline\Framework\Runtime\StorefrontRenderContextReader::class)) {
                $bag = \Weline\Framework\Runtime\StorefrontRenderContextReader::maintenance();
                if (\is_array($bag)
                    && ($bag['scope_key'] ?? '') === $scope->canonicalKey()
                ) {
                    return [
                        'scope_key' => (string)($bag['scope_key'] ?? $scope->canonicalKey()),
                        'enabled' => (bool)($bag['enabled'] ?? false),
                        'reason' => (string)($bag['reason'] ?? ''),
                        'generation' => (int)($bag['generation'] ?? 0),
                        'since' => (int)($bag['since'] ?? 0),
                    ];
                }
            }
        } catch (\Throwable) {
        }

        // Request memo for parent candidates walked by maintenanceScope().
        try {
            if (\class_exists(\Weline\Framework\Cache\Service\StorefrontScopeHotCache::class)
                && \class_exists(\Weline\Framework\Manager\ObjectManager::class)
            ) {
                /** @var \Weline\Framework\Cache\Service\StorefrontScopeHotCache $hot */
                $hot = \Weline\Framework\Manager\ObjectManager::getInstance(
                    \Weline\Framework\Cache\Service\StorefrontScopeHotCache::class
                );
                $memo = $hot->rememberForRequest(
                    'scope_maintenance',
                    $scope->canonicalKey(),
                    fn (): array => $this->repository->status($scope),
                );
                if (\is_array($memo)
                    && \array_key_exists('enabled', $memo)
                ) {
                    return [
                        'scope_key' => (string)($memo['scope_key'] ?? $scope->canonicalKey()),
                        'enabled' => (bool)($memo['enabled'] ?? false),
                        'reason' => (string)($memo['reason'] ?? ''),
                        'generation' => (int)($memo['generation'] ?? 0),
                        'since' => (int)($memo['since'] ?? 0),
                    ];
                }
            }
        } catch (\Throwable) {
        }

        return $this->repository->status($scope);
    }

    /**
     * Return the most-specific active maintenance Scope inherited by a request.
     */
    public function maintenanceScope(ScopeIdentity $scope): ?ScopeIdentity
    {
        foreach ($this->candidates($scope) as $candidate) {
            if ($this->status($candidate)['enabled']) {
                return $candidate;
            }
        }
        return null;
    }

    public function assertWritable(ScopeIdentity $scope, bool $hasValidPreviewToken = false): void
    {
        if (!$this->isMaintenance($scope)) {
            return;
        }
        if ($hasValidPreviewToken) {
            throw new \RuntimeException('scope_maintenance_preview_readonly');
        }
        throw new \RuntimeException('scope_maintenance_blocked');
    }

    public function assertReadable(ScopeIdentity $scope, bool $hasValidPreviewToken = false): void
    {
        if (!$this->isMaintenance($scope)) {
            return;
        }
        if ($hasValidPreviewToken) {
            return;
        }
        throw new \RuntimeException('scope_maintenance_blocked');
    }

    /**
     * @return list<ScopeIdentity>
     */
    private function candidates(ScopeIdentity $scope): array
    {
        if ($scope->isGlobal() || $scope->websiteId === null || $scope->websiteCode === null) {
            throw new \InvalidArgumentException('scope_maintenance_global_not_supported');
        }
        $candidates = [$scope];
        if ($scope->scopeKind === ScopeIdentity::KIND_CHANNEL) {
            $candidates[] = ScopeIdentity::store(
                $scope->websiteId,
                $scope->websiteCode,
                (string)$scope->storeCode,
                (string)$scope->storeMode,
                $scope->contextVersion,
            );
        }
        if ($scope->scopeKind !== ScopeIdentity::KIND_WEBSITE) {
            $candidates[] = ScopeIdentity::website(
                $scope->websiteId,
                $scope->websiteCode,
                $scope->contextVersion,
            );
        }
        return $candidates;
    }
}
