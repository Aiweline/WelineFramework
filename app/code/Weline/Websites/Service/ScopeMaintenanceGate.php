<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Websites\Api\ScopeMaintenanceRepositoryInterface;

/**
 * Durable Scope maintenance gate.
 *
 * Reads prefer RenderContext → process bag → request memo → repository.
 * Store A and Store B are independent; preview never grants write access.
 * Toggle always clears the process bag for that scope (and parent candidates).
 */
final class ScopeMaintenanceGate
{
    private const PROCESS_BAG_MAX = 256;

    /** @var array<string, array{scope_key:string,enabled:bool,reason:string,generation:int,since:int}> */
    private static array $processStatusByScopeKey = [];

    public function __construct(
        private readonly ScopeMaintenanceRepositoryInterface $repository,
    ) {
    }

    public static function clearProcessCache(): void
    {
        self::$processStatusByScopeKey = [];
        \Weline\Framework\Cache\Service\ScopeSharedMemo::purgeProcessPrefix('scope_maintenance|');
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
        $status = $this->repository->setMaintenance(
            $scope,
            true,
            $reason,
            $now ?? time(),
            $actor,
        );
        self::forgetProcessScopeTree($scope);

        return $status;
    }

    /**
     * @return array{scope_key:string,enabled:bool,reason:string,generation:int,since:int}
     */
    public function disable(
        ScopeIdentity $scope,
        ?int $now = null,
        string $actor = 'system',
    ): array {
        $status = $this->repository->setMaintenance(
            $scope,
            false,
            '',
            $now ?? time(),
            $actor,
        );
        self::forgetProcessScopeTree($scope);

        return $status;
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

        $key = $scope->canonicalKey();
        if (isset(self::$processStatusByScopeKey[$key])) {
            return self::$processStatusByScopeKey[$key];
        }

        $normalized = \Weline\Framework\Cache\Service\ScopeSharedMemo::rememberScoped(
            'website',
            'scope_maintenance',
            function () use ($scope, $key): array {
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
                            $key,
                            fn (): array => $this->repository->status($scope),
                        );
                        if (\is_array($memo) && \array_key_exists('enabled', $memo)) {
                            return [
                                'scope_key' => (string)($memo['scope_key'] ?? $key),
                                'enabled' => (bool)($memo['enabled'] ?? false),
                                'reason' => (string)($memo['reason'] ?? ''),
                                'generation' => (int)($memo['generation'] ?? 0),
                                'since' => (int)($memo['since'] ?? 0),
                            ];
                        }
                    }
                } catch (\Throwable) {
                }

                $status = $this->repository->status($scope);

                return [
                    'scope_key' => (string)($status['scope_key'] ?? $key),
                    'enabled' => (bool)($status['enabled'] ?? false),
                    'reason' => (string)($status['reason'] ?? ''),
                    'generation' => (int)($status['generation'] ?? 0),
                    'since' => (int)($status['since'] ?? 0),
                ];
            },
            $scope,
            120,
        );
        if (!\is_array($normalized) || !\array_key_exists('enabled', $normalized)) {
            $status = $this->repository->status($scope);
            $normalized = [
                'scope_key' => (string)($status['scope_key'] ?? $key),
                'enabled' => (bool)($status['enabled'] ?? false),
                'reason' => (string)($status['reason'] ?? ''),
                'generation' => (int)($status['generation'] ?? 0),
                'since' => (int)($status['since'] ?? 0),
            ];
        }
        self::rememberProcessStatus($key, $normalized);

        return $normalized;
    }

    /**
     * @param array{scope_key:string,enabled:bool,reason:string,generation:int,since:int} $status
     */
    private static function rememberProcessStatus(string $key, array $status): void
    {
        if (!isset(self::$processStatusByScopeKey[$key])
            && \count(self::$processStatusByScopeKey) >= self::PROCESS_BAG_MAX
        ) {
            $first = \array_key_first(self::$processStatusByScopeKey);
            if ($first !== null) {
                unset(self::$processStatusByScopeKey[$first]);
            }
        }
        self::$processStatusByScopeKey[$key] = $status;
    }

    private static function forgetProcessScopeTree(ScopeIdentity $scope): void
    {
        try {
            foreach (self::candidateScopes($scope) as $candidate) {
                unset(self::$processStatusByScopeKey[$candidate->canonicalKey()]);
                \Weline\Framework\Cache\Service\ScopeSharedMemo::forgetScoped(
                    'website',
                    'scope_maintenance',
                    $candidate,
                );
            }
        } catch (\Throwable) {
            unset(self::$processStatusByScopeKey[$scope->canonicalKey()]);
            \Weline\Framework\Cache\Service\ScopeSharedMemo::forgetScoped(
                'website',
                'scope_maintenance',
                $scope,
            );
        }
    }

    /** @return list<ScopeIdentity> */
    private static function candidateScopes(ScopeIdentity $scope): array
    {
        if ($scope->isGlobal() || $scope->websiteId === null || $scope->websiteCode === null) {
            return [$scope];
        }
        $scopes = [$scope];
        if ($scope->scopeKind === ScopeIdentity::KIND_CHANNEL) {
            $scopes[] = ScopeIdentity::store(
                $scope->websiteId,
                $scope->websiteCode,
                (string)$scope->storeCode,
                (string)$scope->storeMode,
                $scope->contextVersion,
            );
        }
        if ($scope->scopeKind !== ScopeIdentity::KIND_WEBSITE) {
            $scopes[] = ScopeIdentity::website(
                $scope->websiteId,
                $scope->websiteCode,
                $scope->contextVersion,
            );
        }

        return $scopes;
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
