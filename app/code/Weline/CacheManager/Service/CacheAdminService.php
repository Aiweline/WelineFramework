<?php

declare(strict_types=1);

namespace Weline\CacheManager\Service;

use Weline\CacheManager\Model\Cache as CacheRecord;
use Weline\Cron\Api\Task\CronTaskCatalogInterface;
use Weline\Cron\Api\Task\CronTaskRecord;
use Weline\Framework\App\Env;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Runtime\RuntimeRoutingPolicyInterface;
use Weline\Framework\Runtime\SharedStateAdminProviderInterface;

final class CacheAdminService
{
    private bool $sharedStateResolved = false;

    private ?SharedStateAdminProviderInterface $sharedStateAdminService = null;

    public function __construct(
        private readonly CacheManager $cacheManager,
        private readonly ?RuntimeProviderResolver $runtimeProviderResolver = null,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function buildDashboardData(array $filters = []): array
    {
        $records = $this->loadRecordMap();
        $runtime = $this->buildRuntimeContext();
        $allItems = [];

        foreach ($this->cacheManager->getPoolIdentities() as $identity) {
            $allItems[] = $this->buildPoolItem(
                $identity,
                $records[$identity] ?? [],
                $runtime['cache_namespaces'] ?? []
            );
        }

        $this->sortItems($allItems);
        $filteredItems = $this->applyFilters($allItems, $filters);

        return [
            'items' => $filteredItems,
            'summary' => $this->buildSummary($allItems, $filteredItems, $runtime),
            'runtime' => $runtime,
            'cron_tasks' => $this->loadCronTasks(),
        ];
    }

    /**
     * @param array<string> $identities
     * @return array{updated:int,enabled:bool,identities:array<string>}
     */
    public function updateStatuses(array $identities, bool $enabled): array
    {
        $identities = \array_values(\array_unique(\array_filter(
            \array_map(static fn($identity): string => (string)$identity, $identities),
            static fn(string $identity): bool => $identity !== ''
        )));

        if (empty($identities)) {
            throw new \InvalidArgumentException((string)__('参数 identity 不能为空'));
        }

        $cacheEnv = (array)Env::getInstance()->getConfig('cache');
        $cacheEnv['pools'] = \is_array($cacheEnv['pools'] ?? null) ? $cacheEnv['pools'] : [];
        $cacheEnv['status'] = \is_array($cacheEnv['status'] ?? null) ? $cacheEnv['status'] : [];

        foreach ($identities as $identity) {
            if (!$this->cacheManager->hasPool($identity)) {
                throw new \RuntimeException((string)__('缓存池 %{1} 不存在', $identity));
            }

            if (!isset($cacheEnv['pools'][$identity]) || !\is_array($cacheEnv['pools'][$identity])) {
                $cacheEnv['pools'][$identity] = [];
            }

            $cacheEnv['pools'][$identity]['enabled'] = $enabled;
            $cacheEnv['status'][$identity] = $enabled ? 1 : 0;
            $this->upsertCacheRecord($identity, [
                CacheRecord::schema_fields_Status => $enabled ? 1 : 0,
            ]);
        }

        if (!Env::getInstance()->setConfig('cache', $cacheEnv)) {
            throw new \RuntimeException((string)__('env 配置写入失败，请检查 app/etc/env.php 权限'));
        }

        $this->dispatchRuntimeCacheSync($identities[0], 'clear', 'cache-status-updated');

        return [
            'updated' => \count($identities),
            'enabled' => $enabled,
            'identities' => $identities,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function clearPool(string $identity, bool $force = false): array
    {
        if ($identity === '') {
            throw new \InvalidArgumentException((string)__('参数 identity 不能为空'));
        }

        if (!$this->cacheManager->hasPool($identity)) {
            throw new \RuntimeException((string)__('缓存池 %{1} 不存在', $identity));
        }

        $pool = $this->cacheManager->pool($identity);
        if ($pool->isPermanent() && !$force) {
            throw new \RuntimeException((string)__('缓存池 %{1} 为持久缓存，需要强制清理（force=1）', $identity));
        }

        $stats = $pool->getStats();
        $namespace = 'cache:' . $identity;
        $sharedNamespacePresent = $this->hasCacheNamespace($namespace);
        $cleared = $pool->clear();
        $sharedNamespaceCleared = false;

        if ($cleared) {
            if ($this->isWlsMemoryAdapter($stats['adapter'] ?? '')) {
                $sharedNamespaceCleared = $sharedNamespacePresent;
            } elseif ($sharedNamespacePresent) {
                $sharedNamespaceCleared = $this->clearSharedMemoryNamespace($namespace);
            }
        }

        return [
            'identity' => $identity,
            'cleared' => $cleared,
            'shared_namespace_cleared' => $sharedNamespaceCleared,
            'force' => $force,
            'permanent' => (bool)($stats['permanent'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function clearAll(bool $force = false): array
    {
        $identities = $this->cacheManager->getPoolIdentities();
        $namespaceMap = $this->getCacheNamespaces();
        $cleared = [];
        $skipped = [];
        $failed = [];

        foreach ($identities as $identity) {
            $pool = $this->cacheManager->pool($identity);
            $stats = $pool->getStats();

            if ($pool->isPermanent() && !$force) {
                $skipped[] = $identity;
                continue;
            }

            $namespace = 'cache:' . $identity;
            $sharedNamespacePresent = isset($namespaceMap[$namespace]);
            $ok = $pool->clear();

            if (!$ok) {
                $failed[] = $identity;
                continue;
            }

            $sharedNamespaceCleared = false;
            if ($this->isWlsMemoryAdapter($stats['adapter'] ?? '')) {
                $sharedNamespaceCleared = $sharedNamespacePresent;
            } elseif ($sharedNamespacePresent) {
                $sharedNamespaceCleared = $this->clearSharedMemoryNamespace($namespace);
            }

            $cleared[] = [
                'identity' => $identity,
                'shared_namespace_cleared' => $sharedNamespaceCleared,
            ];
        }

        $extraNamespacesCleared = [];
        if ($force) {
            foreach ($namespaceMap as $namespace => $row) {
                $identity = \substr($namespace, 6);
                if (\in_array($identity, $identities, true)) {
                    continue;
                }
                if ($this->clearSharedMemoryNamespace($namespace)) {
                    $extraNamespacesCleared[] = $namespace;
                }
            }
        }

        return [
            'cleared' => $cleared,
            'cleared_count' => \count($cleared),
            'skipped' => $skipped,
            'skipped_count' => \count($skipped),
            'failed' => $failed,
            'failed_count' => \count($failed),
            'extra_shared_namespaces_cleared' => $extraNamespacesCleared,
            'extra_shared_namespaces_cleared_count' => \count($extraNamespacesCleared),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getPoolStats(string $identity): array
    {
        if ($identity === '') {
            throw new \InvalidArgumentException((string)__('参数 identity 不能为空'));
        }

        if (!$this->cacheManager->hasPool($identity)) {
            throw new \RuntimeException((string)__('缓存池 %{1} 不存在', $identity));
        }

        $runtime = $this->buildRuntimeContext();
        $record = $this->loadRecordMap()[$identity] ?? [];
        return $this->buildPoolItem($identity, $record, $runtime['cache_namespaces'] ?? []);
    }

    /**
     * 轻量缓存池选项列表（供顶部「清理缓存」弹窗等 UI 使用，不做逐池统计）。
     *
     * @return array<int, array{identity: string, name: string, module: string, permanent: bool}>
     */
    public function listPoolOptions(): array
    {
        $records = $this->loadRecordMap();
        $options = [];

        foreach ($this->cacheManager->getPoolIdentities() as $identity) {
            $identity = (string)$identity;
            if ($identity === '') {
                continue;
            }
            $record = $records[$identity] ?? [];
            $permanent = false;
            try {
                $permanent = $this->cacheManager->pool($identity)->isPermanent();
            } catch (\Throwable) {
            }

            $name = \trim((string)($record[CacheRecord::schema_fields_NAME] ?? ''));
            $options[] = [
                'identity' => $identity,
                'name' => $name !== '' ? $name : $identity,
                'module' => (string)($record[CacheRecord::schema_fields_Module] ?? ''),
                'permanent' => $permanent,
            ];
        }

        \usort($options, static fn(array $left, array $right): int => \strcmp($left['identity'], $right['identity']));

        return $options;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadRecordMap(): array
    {
        $map = [];

        try {
            /** @var CacheRecord $cacheModel */
            $cacheModel = ObjectManager::make(CacheRecord::class);
            $items = $cacheModel->select()->fetch()->getItems();

            foreach ($items as $item) {
                if (!$item instanceof CacheRecord) {
                    continue;
                }

                $identity = (string)$item->getData(CacheRecord::schema_fields_IDENTITY);
                if ($identity === '') {
                    continue;
                }

                $map[$identity] = [
                    CacheRecord::schema_fields_NAME => (string)$item->getData(CacheRecord::schema_fields_NAME),
                    CacheRecord::schema_fields_Status => (int)$item->getData(CacheRecord::schema_fields_Status),
                    CacheRecord::schema_fields_Permanently => (int)$item->getData(CacheRecord::schema_fields_Permanently),
                    CacheRecord::schema_fields_Module => (string)$item->getData(CacheRecord::schema_fields_Module),
                    CacheRecord::schema_fields_IDENTITY => $identity,
                    CacheRecord::schema_fields_TYPE => (int)$item->getData(CacheRecord::schema_fields_TYPE),
                    CacheRecord::schema_fields_FILE => (string)$item->getData(CacheRecord::schema_fields_FILE),
                    CacheRecord::schema_fields_DESCRIPTION => (string)$item->getData(CacheRecord::schema_fields_DESCRIPTION),
                ];
            }
        } catch (\Throwable) {
            return [];
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, array<string, mixed>> $namespaces
     * @return array<string, mixed>
     */
    private function buildPoolItem(string $identity, array $record, array $namespaces): array
    {
        $pool = $this->cacheManager->pool($identity);
        $stats = $pool->getStats();
        $adapterClass = (string)($stats['adapter'] ?? '');
        $namespace = $namespaces['cache:' . $identity] ?? null;
        $type = isset($record[CacheRecord::schema_fields_TYPE]) ? (int)$record[CacheRecord::schema_fields_TYPE] : 0;
        $module = (string)($record[CacheRecord::schema_fields_Module] ?? 'Weline_Framework');
        $description = \trim((string)($record[CacheRecord::schema_fields_DESCRIPTION] ?? ($stats['tip'] ?? '')));
        $enabled = (bool)($stats['enabled'] ?? true);
        $permanent = (bool)($stats['permanent'] ?? false);

        if ($permanent) {
            $enabled = true;
        }

        return [
            'identity' => $identity,
            'name' => (string)($record[CacheRecord::schema_fields_NAME] ?? $this->adapterLabel($adapterClass)),
            'module' => $module !== '' ? $module : 'Weline_Framework',
            'description' => $description !== '' ? $description : $identity,
            'type' => $type,
            'type_label' => $type === 1 ? __('应用') : __('系统'),
            'enabled' => $enabled,
            'permanent' => $permanent,
            'tip' => (string)($stats['tip'] ?? ''),
            'default_ttl' => (int)($stats['default_ttl'] ?? 0),
            'hits' => (int)($stats['hits'] ?? 0),
            'misses' => (int)($stats['misses'] ?? 0),
            'hit_ratio' => (float)($stats['hit_ratio'] ?? 0),
            'adapter' => $adapterClass,
            'adapter_label' => $this->adapterLabel($adapterClass),
            'storage_label' => $this->storageLabel($adapterClass),
            'taggable' => (bool)($stats['taggable'] ?? false),
            'wls_backed' => $this->isWlsMemoryAdapter($adapterClass),
            'shared_namespace' => $namespace['namespace'] ?? '',
            'shared_keys' => (int)($namespace['keys'] ?? 0),
            'sample_keys' => \is_array($namespace['sample_keys'] ?? null) ? $namespace['sample_keys'] : [],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function sortItems(array &$items): void
    {
        \usort($items, static function (array $left, array $right): int {
            if (($left['permanent'] ?? false) !== ($right['permanent'] ?? false)) {
                return ($left['permanent'] ?? false) ? -1 : 1;
            }
            if (($left['enabled'] ?? false) !== ($right['enabled'] ?? false)) {
                return ($left['enabled'] ?? false) ? -1 : 1;
            }
            return \strcmp((string)($left['identity'] ?? ''), (string)($right['identity'] ?? ''));
        });
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function applyFilters(array $items, array $filters): array
    {
        $search = \trim((string)($filters['search'] ?? ''));
        $type = (string)($filters['type'] ?? '');
        $status = (string)($filters['status'] ?? '');
        $permanent = (string)($filters['permanently'] ?? '');

        return \array_values(\array_filter($items, function (array $item) use ($search, $type, $status, $permanent): bool {
            if ($search !== '') {
                $haystack = \strtolower(\implode(' ', [
                    (string)($item['identity'] ?? ''),
                    (string)($item['description'] ?? ''),
                    (string)($item['module'] ?? ''),
                    (string)($item['adapter_label'] ?? ''),
                    (string)($item['storage_label'] ?? ''),
                ]));
                if (!\str_contains($haystack, \strtolower($search))) {
                    return false;
                }
            }

            if ($type !== '' && (string)($item['type'] ?? '') !== $type) {
                return false;
            }

            if ($status !== '' && (string)((int)(($item['enabled'] ?? false) ? 1 : 0)) !== $status) {
                return false;
            }

            if ($permanent !== '' && (string)((int)(($item['permanent'] ?? false) ? 1 : 0)) !== $permanent) {
                return false;
            }

            return true;
        }));
    }

    /**
     * @param array<int, array<string, mixed>> $allItems
     * @param array<int, array<string, mixed>> $filteredItems
     * @param array<string, mixed> $runtime
     * @return array<string, mixed>
     */
    private function buildSummary(array $allItems, array $filteredItems, array $runtime): array
    {
        $enabled = 0;
        $disabled = 0;
        $permanent = 0;
        $wlsBacked = 0;

        foreach ($allItems as $item) {
            if ($item['permanent'] ?? false) {
                $permanent++;
            }
            if ($item['enabled'] ?? false) {
                $enabled++;
            } else {
                $disabled++;
            }
            if ($item['wls_backed'] ?? false) {
                $wlsBacked++;
            }
        }

        return [
            'total' => \count($allItems),
            'filtered_total' => \count($filteredItems),
            'enabled' => $enabled,
            'disabled' => $disabled,
            'permanent' => $permanent,
            'wls_backed' => $wlsBacked,
            'shared_namespace_count' => (int)($runtime['shared_namespace_count'] ?? 0),
            'shared_key_total' => (int)($runtime['shared_key_total'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRuntimeContext(): array
    {
        $cacheNamespaces = $this->getCacheNamespaces();
        $connected = $cacheNamespaces !== [];
        $memoryOverview = $this->getMemoryOverview();
        $connected = $connected || (bool)($memoryOverview['connected'] ?? false);

        return [
            'mode' => Runtime::getMode(),
            'label' => Runtime::isWls()
                ? __('WLS 常驻内存')
                : (Runtime::isFpm() ? __('FPM 请求隔离') : __('CLI')),
            'description' => Runtime::isWls()
                ? __('缓存清理会同时处理缓存池、WLS 共享内存命名空间和 Worker 运行时同步。')
                : __('缓存清理会直接作用于当前缓存后端，下一次请求将重新装载缓存内容。'),
            'persistent' => Runtime::isPersistent(),
            'file_hijacked' => $this->shouldHijackFileDriver(),
            'memory_connected' => $connected,
            'memory_overview' => $memoryOverview,
            'cache_namespaces' => $cacheNamespaces,
            'shared_namespace_count' => \count($cacheNamespaces),
            'shared_key_total' => \array_sum(\array_map(
                static fn(array $row): int => (int)($row['keys'] ?? 0),
                $cacheNamespaces
            )),
        ];
    }

    private function shouldHijackFileDriver(): bool
    {
        if (!Runtime::isPersistent()) {
            return false;
        }

        try {
            $resolver = $this->runtimeProviderResolver
                ?? ObjectManager::getInstance(RuntimeProviderResolver::class);
            $policy = $resolver->resolve(RuntimeRoutingPolicyInterface::class);
        } catch (\Throwable) {
            return true;
        }

        return $policy instanceof RuntimeRoutingPolicyInterface
            ? $policy->shouldHijackCacheFile()
            : true;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getCacheNamespaces(): array
    {
        $service = $this->getSharedStateAdminService();
        if ($service === null) {
            return [];
        }

        try {
            $rows = $service->listMemoryNamespaces(300);
        } catch (\Throwable) {
            return [];
        }

        $namespaces = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $namespace = (string)($row['namespace'] ?? '');
            if ($namespace === '' || !\str_starts_with($namespace, 'cache:')) {
                continue;
            }

            $namespaces[$namespace] = [
                'namespace' => $namespace,
                'keys' => (int)($row['keys'] ?? 0),
                'sample_keys' => \is_array($row['sample_keys'] ?? null) ? $row['sample_keys'] : [],
            ];
        }

        \ksort($namespaces);
        return $namespaces;
    }

    /**
     * @return array<string, mixed>
     */
    private function getMemoryOverview(): array
    {
        $service = $this->getSharedStateAdminService();
        if ($service === null) {
            return ['connected' => false];
        }

        try {
            return $service->getMemoryOverview();
        } catch (\Throwable) {
            return ['connected' => false];
        }
    }

    private function hasCacheNamespace(string $namespace): bool
    {
        return isset($this->getCacheNamespaces()[$namespace]);
    }

    private function clearSharedMemoryNamespace(string $namespace): bool
    {
        $service = $this->getSharedStateAdminService();
        if ($service === null) {
            return false;
        }

        try {
            return $service->clearMemoryNamespace($namespace);
        } catch (\Throwable) {
            return false;
        }
    }

    private function getSharedStateAdminService(): ?SharedStateAdminProviderInterface
    {
        if ($this->sharedStateResolved) {
            return $this->sharedStateAdminService;
        }

        $this->sharedStateResolved = true;

        try {
            $resolver = $this->runtimeProviderResolver
                ?? ObjectManager::getInstance(RuntimeProviderResolver::class);
            $provider = $resolver->resolve(SharedStateAdminProviderInterface::class);
            $this->sharedStateAdminService = $provider instanceof SharedStateAdminProviderInterface
                ? $provider
                : null;
        } catch (\Throwable) {
            $this->sharedStateAdminService = null;
        }

        return $this->sharedStateAdminService;
    }

    /**
     * @return list<CronTaskRecord>
     */
    private function loadCronTasks(): array
    {
        try {
            $resolver = $this->runtimeProviderResolver
                ?? ObjectManager::getInstance(RuntimeProviderResolver::class);
            $catalog = $resolver->resolve(CronTaskCatalogInterface::class);
            return $catalog instanceof CronTaskCatalogInterface
                ? $catalog->listByNameContains('cache')
                : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $updates
     */
    private function upsertCacheRecord(string $identity, array $updates): void
    {
        /** @var CacheRecord $cacheModel */
        $cacheModel = ObjectManager::make(CacheRecord::class);
        $existing = $cacheModel->where(CacheRecord::schema_fields_IDENTITY, $identity)->find()->fetch();

        $pool = $this->cacheManager->pool($identity);
        $stats = $pool->getStats();
        $baseData = [
            CacheRecord::schema_fields_NAME => $this->adapterLabel((string)($stats['adapter'] ?? '')),
            CacheRecord::schema_fields_IDENTITY => $identity,
            CacheRecord::schema_fields_Module => 'Weline_Framework',
            CacheRecord::schema_fields_FILE => 'CacheManager Pool',
            CacheRecord::schema_fields_TYPE => 0,
            CacheRecord::schema_fields_Status => (int)(($stats['enabled'] ?? true) ? 1 : 0),
            CacheRecord::schema_fields_Permanently => (int)(($stats['permanent'] ?? false) ? 1 : 0),
            CacheRecord::schema_fields_DESCRIPTION => (string)($stats['tip'] ?? ''),
        ];
        $data = \array_merge($baseData, $updates);

        if ($existing && $existing->getId()) {
            $existing->setData($data)->save();
            return;
        }

        /** @var CacheRecord $newRecord */
        $newRecord = ObjectManager::make(CacheRecord::class);
        $newRecord->setData($data)->save();
    }

    private function dispatchRuntimeCacheSync(string $identity, string $operation, string $tip): void
    {
        try {
            ObjectManager::getInstance(EventsManager::class)->dispatch(
                'Weline_Framework_Cache::integration::cache_flushed',
                [
                    'identity' => $identity,
                    'operation' => $operation,
                    'tip' => $tip,
                ]
            );
        } catch (\Throwable) {
            // The cache status update should still succeed even if runtime hooks are unavailable.
        }
    }

    private function isWlsMemoryAdapter(string $adapterClass): bool
    {
        return $adapterClass !== '' && \str_ends_with($adapterClass, 'WlsMemoryAdapter');
    }

    private function adapterLabel(string $adapterClass): string
    {
        if ($adapterClass === '') {
            return (string)__('未知适配器');
        }

        $parts = \explode('\\', $adapterClass);
        return (string)\end($parts);
    }

    private function storageLabel(string $adapterClass): string
    {
        return match (true) {
            $this->isWlsMemoryAdapter($adapterClass) => (string)__('WLS 共享内存'),
            \str_ends_with($adapterClass, 'FileAdapter') => (string)__('文件缓存'),
            \str_ends_with($adapterClass, 'RedisAdapter') => (string)__('Redis'),
            \str_ends_with($adapterClass, 'MemcachedAdapter') => (string)__('Memcached'),
            \str_ends_with($adapterClass, 'ApcuAdapter') => (string)__('APCu'),
            default => $this->adapterLabel($adapterClass),
        };
    }
}
