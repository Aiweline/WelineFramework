<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Service;

use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Model\SystemConfig;

/**
 * TASK-P1C-003 / TEST-P1C-06：继承影响图精确缓存失效。
 *
 * - 写父层时：失效真实继承后代；有显式覆盖（且未 suppressed）的后代 key 可跳过
 * - scope generation 版本向量写入 cache key，未知继承者不会脏读
 * - 宁可临时扩大失效并重建，不可保留脏读
 */
final class ScopeConfigCacheInvalidator
{
    private const GEN_PREFIX = 'system_config_scope_gen:';

    /**
     * @param list<string> $keys
     * @return array{
     *   written_scope:string,
     *   invalidate_scopes:list<string>,
     *   skipped_override_scopes:list<string>,
     *   generation:int,
     *   version_vector:string,
     *   metrics:array<string,int>
     * }
     */
    public function planImpact(
        string $module,
        string $area,
        string $scope,
        string $locale,
        array $keys,
        ?SystemConfig $config = null,
    ): array {
        $scope = $config?->normalizeScope($scope) ?? $this->normalizeScopeFallback($scope);
        $keys = $this->stringList($keys);
        $generation = $this->readGeneration($scope);
        $vector = $this->versionVectorFor($scope);

        $candidates = $this->discoverDescendantScopes($module, $area, $scope, $keys, $config);
        $invalidate = [];
        $skipped = [];

        foreach ($candidates as $descendant) {
            $hasOverride = false;
            foreach ($keys as $key) {
                if ($this->hasActiveOverride($config, $key, $module, $area, $descendant, $locale)) {
                    $hasOverride = true;
                    break;
                }
            }
            if ($hasOverride && \count($keys) === 1) {
                $skipped[] = $descendant;
                continue;
            }
            $invalidate[] = $descendant;
        }

        $invalidate = $this->stringList($invalidate);
        $skipped = $this->stringList($skipped);

        return [
            'written_scope' => $scope,
            'invalidate_scopes' => $invalidate,
            'skipped_override_scopes' => $skipped,
            'generation' => $generation,
            'version_vector' => $vector,
            'metrics' => [
                'candidate_descendants' => \count($candidates),
                'invalidate_descendants' => \count($invalidate),
                'skipped_overrides' => \count($skipped),
                'keys' => \count($keys),
                'generation' => $generation,
            ],
        ];
    }

    /**
     * @param list<string> $keys
     * @return array{
     *   written_scope:string,
     *   invalidate_scopes:list<string>,
     *   skipped_override_scopes:list<string>,
     *   generation:int,
     *   version_vector:string,
     *   metrics:array<string,int>
     * }
     */
    public function planAndBump(
        string $module,
        string $area,
        string $scope,
        string $locale,
        array $keys,
        ?SystemConfig $config = null,
    ): array {
        $plan = $this->planImpact($module, $area, $scope, $locale, $keys, $config);
        $generation = $this->bumpGeneration($plan['written_scope']);
        $plan['generation'] = $generation;
        $plan['version_vector'] = $this->versionVectorFor($plan['written_scope']);
        $plan['metrics']['generation'] = $generation;

        return $plan;
    }

    /**
     * 单 key 粒度：后代是否应失效该 key 的 resolved cache。
     */
    public function shouldInvalidateKeyAtScope(
        ?SystemConfig $config,
        string $key,
        string $module,
        string $area,
        string $descendantScope,
        string $locale,
    ): bool {
        return !$this->hasActiveOverride($config, $key, $module, $area, $descendantScope, $locale);
    }

    public function bumpGeneration(string $scope): int
    {
        $scope = $this->normalizeScopeFallback($scope);
        $cache = \w_cache('system_config');
        $cacheKey = self::GEN_PREFIX . $scope;
        $current = (int)($cache->getCustom($cacheKey) ?: 0);
        $next = $current + 1;
        $cache->setCustom($cacheKey, $next, 86400 * 30);
        return $next;
    }

    public function readGeneration(string $scope): int
    {
        $scope = $this->normalizeScopeFallback($scope);
        $cache = \w_cache('system_config');
        return (int)($cache->getCustom(self::GEN_PREFIX . $scope) ?: 0);
    }

    /**
     * 读侧版本向量：自身 + 祖先 generation（近→远）。
     */
    public function versionVectorFor(string $readerScope): string
    {
        $readerScope = $this->normalizeScopeFallback($readerScope);
        $parts = [];
        foreach ($this->ancestorScopesInclusive($readerScope) as $ancestor) {
            $parts[] = $ancestor . '=' . $this->readGeneration($ancestor);
        }

        return KeyBuilder::systemConfigVersionVectorToken($parts);
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    public function discoverDescendantScopes(
        string $module,
        string $area,
        string $parentScope,
        array $keys = [],
        ?SystemConfig $config = null,
    ): array {
        $parentScope = $this->normalizeScopeFallback($parentScope);
        $found = [];

        if ($config !== null) {
            foreach ($keys as $key) {
                foreach ($config->listRowsForKey($module, $area, (string)$key) as $row) {
                    $rowScope = (string)($row[SystemConfig::schema_fields_SCOPE] ?? '');
                    if (SystemConfigLockService::isDescendantScope($parentScope, $rowScope)) {
                        $found[$rowScope] = $rowScope;
                    }
                }
            }
        }

        foreach ($this->catalogDescendantScopes($parentScope) as $scope) {
            $found[$scope] = $scope;
        }

        $result = \array_values($found);
        \sort($result, \SORT_STRING);

        return $result;
    }

    /**
     * @return list<string>
     */
    public function ancestorScopesInclusive(string $scope): array
    {
        $scope = $this->normalizeScopeFallback($scope);
        [$w, $s, $c] = \explode('.', $scope) + ['default', 'default', 'default'];
        $chain = [];
        if ($scope !== SystemConfig::SCOPE_GLOBAL) {
            $chain[] = $scope;
            if (!($s === 'default' && $c === 'default')
                && !($s === SystemConfigScopeResolver::WEBSITE_DEFAULT_SENTINEL && $c === 'default')) {
                if ($c !== 'default') {
                    $chain[] = $w . '.' . $s . '.default';
                }
                if ($w === 'default') {
                    $chain[] = 'default.' . SystemConfigScopeResolver::WEBSITE_DEFAULT_SENTINEL . '.default';
                } else {
                    $chain[] = $w . '.default.default';
                }
            }
        }
        $chain[] = SystemConfig::SCOPE_GLOBAL;

        return \array_values(\array_unique($chain));
    }

    private function hasActiveOverride(
        ?SystemConfig $config,
        string $key,
        string $module,
        string $area,
        string $scope,
        string $locale,
    ): bool {
        if ($config === null || $key === '') {
            return false;
        }
        $row = $config->getScopedConfigRow($key, $module, $area, $scope, $locale);
        if ($row === null) {
            // 尝试 default locale
            if ($locale !== SystemConfig::LOCALE_DEFAULT) {
                $row = $config->getScopedConfigRow($key, $module, $area, $scope, SystemConfig::LOCALE_DEFAULT);
            }
        }
        if ($row === null) {
            return false;
        }
        if (\array_key_exists(SystemConfig::schema_fields_IS_ACTIVE, $row)
            && (int)$row[SystemConfig::schema_fields_IS_ACTIVE] === 0) {
            return false;
        }
        if (SystemConfigLockService::isRowSuppressed($row)) {
            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function catalogDescendantScopes(string $parentScope): array
    {
        [$w, $s, $c] = \explode('.', $parentScope) + ['default', 'default', 'default'];
        $parentIsWebsite = ($parentScope === SystemConfig::SCOPE_GLOBAL)
            || ($s === 'default' && $c === 'default')
            || ($s === SystemConfigScopeResolver::WEBSITE_DEFAULT_SENTINEL && $c === 'default');
        $parentIsStore = !$parentIsWebsite && $c === 'default';

        if (!$parentIsWebsite && !$parentIsStore) {
            return [];
        }

        try {
            if (!\class_exists(\Weline\Websites\Model\Website::class)) {
                return [];
            }
            /** @var \Weline\Websites\Model\Website $websiteModel */
            $websiteModel = ObjectManager::getInstance(\Weline\Websites\Model\Website::class);
            $websiteCode = $parentScope === SystemConfig::SCOPE_GLOBAL ? null : $w;
            $websites = $websiteModel->clear()->reset()->select()->fetchArray();
            if (!\is_array($websites)) {
                return [];
            }

            $out = [];
            foreach ($websites as $website) {
                if (!\is_array($website)) {
                    continue;
                }
                $websiteId = (int)($website[\Weline\Websites\Model\Website::schema_fields_ID] ?? 0);
                $code = \strtolower((string)($website[\Weline\Websites\Model\Website::schema_fields_CODE] ?? ''));
                if ($code === '' || $websiteId < 0) {
                    continue;
                }
                if ($websiteCode !== null && $code !== $websiteCode) {
                    continue;
                }
                if (!\class_exists(\Weline\Websites\Service\WebsiteStoreChannelDirectory::class)) {
                    continue;
                }
                /** @var \Weline\Websites\Service\WebsiteStoreChannelDirectory $dir */
                $dir = ObjectManager::getInstance(\Weline\Websites\Service\WebsiteStoreChannelDirectory::class);
                foreach ($dir->forWebsite($websiteId) as $store) {
                    $storeCode = \strtolower((string)($store['code'] ?? ''));
                    if ($storeCode === '') {
                        continue;
                    }
                    $storeScope = $code . '.' . $storeCode . '.default';
                    if ($parentIsWebsite && SystemConfigLockService::isDescendantScope($parentScope, $storeScope)) {
                        $out[$storeScope] = $storeScope;
                    }
                    if ($parentIsStore && $storeScope !== $parentScope) {
                        continue;
                    }
                    foreach (($store['channels'] ?? []) as $channel) {
                        if (!\is_array($channel)) {
                            continue;
                        }
                        $channelCode = \strtolower((string)($channel['code'] ?? ''));
                        if ($channelCode === '' || $channelCode === 'default') {
                            continue;
                        }
                        $channelScope = $code . '.' . $storeCode . '.' . $channelCode;
                        if (SystemConfigLockService::isDescendantScope($parentScope, $channelScope)) {
                            $out[$channelScope] = $channelScope;
                        }
                    }
                }
            }

            return \array_values($out);
        } catch (\Throwable) {
            return [];
        }
    }

    private function normalizeScopeFallback(string $scope): string
    {
        $scope = \strtolower(\trim($scope));
        if ($scope === '' || $scope === 'default') {
            return SystemConfig::SCOPE_GLOBAL;
        }
        $parts = \array_values(\array_filter(\explode('.', $scope), static fn(string $p): bool => $p !== ''));
        while (\count($parts) < 3) {
            $parts[] = 'default';
        }

        return \implode('.', \array_slice($parts, 0, 3));
    }

    /** @return list<string> */
    private function stringList(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $value = \trim((string)$value);
            if ($value !== '') {
                $result[$value] = $value;
            }
        }
        $result = \array_values($result);
        \sort($result, \SORT_STRING);

        return $result;
    }
}
