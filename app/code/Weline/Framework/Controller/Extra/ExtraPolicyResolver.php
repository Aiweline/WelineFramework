<?php

declare(strict_types=1);

namespace Weline\Framework\Controller\Extra;

use Weline\Framework\Cache\CachePolicy;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolution;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Runtime\ScopeIdentity;

/** 请求期通过全局 HotCache 冻结快照，再按显式 Scope 解释策略。 */
final class ExtraPolicyResolver
{
    public function __construct(private readonly ExtraCollector $collector)
    {
    }

    public function fpcSnapshot(): array
    {
        $hot = ObjectManager::getInstance(StorefrontScopeHotCache::class);
        return $hot->rememberForRequest('framework.fpc_policy_snapshot', 'snapshot.v1', fn(): array =>
            $hot->rememberPolicy(new CachePolicy(
                resource: 'framework.fpc_policy_snapshot', pool: 'router', scope: 'global',
                vary: [], dependencies: ['global/fpc-policy'], freshTtlSeconds: 3600, staleTtlSeconds: 0,
            ), 'snapshot.v1', function (): array {
                $resolution = ObjectManager::getInstance(RuntimeProviderResolver::class)
                    ->resolveDetailed(FpcPolicySnapshotProviderInterface::class);
                if ($resolution->status === RuntimeProviderResolution::CONFIGURED_UNAVAILABLE) {
                    throw new \RuntimeException('FPC policy snapshot provider unavailable');
                }
                if ($resolution->provider instanceof FpcPolicySnapshotProviderInterface) {
                    $snapshot = $resolution->provider->snapshot();
                    if ($snapshot !== [] && (($snapshot['schema_version'] ?? '') !== 'fpc-policy-snapshot.v1'
                        || !is_array($snapshot['declarations'] ?? null)
                        || !is_array($snapshot['overrides'] ?? null)
                        || !is_array($snapshot['scope_chains'] ?? null))) {
                        throw new \UnexpectedValueException('Invalid FPC policy snapshot');
                    }
                    if ($snapshot !== []) {
                        return $snapshot;
                    }
                }
                $rows = [];
                foreach ($this->collector->loadSidecar() ?? [] as $row) {
                    if (($row['type'] ?? '') !== 'fpc') {
                        continue;
                    }
                    $id = FpcPolicySnapshot::declarationId($row);
                    $rows[$id] = ['declaration_id' => $id] + $row;
                }
                ksort($rows, SORT_STRING);
                return ['schema_version' => 'fpc-policy-snapshot.v1', 'source_version' => 0,
                    'revision' => hash('sha256', json_encode($rows)), 'compiled_at' => '',
                    'declarations' => $rows, 'overrides' => [], 'scope_chains' => []];
            })
        );
    }

    public function resolveFpcForPath(string $path, ScopeIdentity $scope): ?array
    {
        return FpcPolicySnapshot::resolve($this->fpcSnapshot(), $path, $scope);
    }

    public function resolveForPath(string $path, string $type = 'fpc'): ?array
    {
        $rows = $type === 'fpc' ? $this->fpcSnapshot()['declarations'] : ($this->collector->loadSidecar() ?? []);
        return FpcPolicySnapshot::matching($rows, $path, strtolower(trim($type)))[0]['row'] ?? null;
    }

    public function namespacesForPath(string $path, string $type = 'fpc'): array
    {
        $rows = $type === 'fpc' ? $this->fpcSnapshot()['declarations'] : ($this->collector->loadSidecar() ?? []);
        $namespaces = [];
        foreach (FpcPolicySnapshot::matching($rows, $path, $type) as $match) {
            array_push($namespaces, ...(array)($match['row']['namespaces'] ?? []));
        }
        return array_values(array_unique(array_filter(array_map('strval', $namespaces))));
    }
}
