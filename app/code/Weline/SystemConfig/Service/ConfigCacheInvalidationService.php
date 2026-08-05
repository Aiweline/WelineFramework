<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Service;

use Weline\Framework\Cache\Namespace\NamespaceGenerationRepository;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Database\TransactionContext;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\SystemConfig\Model\SystemConfig;

/**
 * Defers request/shared config cache mutation until the owning DB commit.
 *
 * Database namespace generations are still advanced inside the transaction;
 * only process/request/shared-cache side effects run after commit.
 */
final class ConfigCacheInvalidationService
{
    private const STATE_KEY = 'system_config.cache_invalidation_transactions';
    private const WEBSITE_START_PAGE_MODULE = 'Weline_Websites';
    private const WEBSITE_START_PAGE_KEY = 'frontend_start_page_path';
    private const BACKEND_START_PAGE_MODULE = 'Weline_Backend';
    private const BACKEND_START_PAGE_KEY = 'start_page_path';

    public function __construct(
        private readonly TransactionCoordinatorInterface $transactions,
        private readonly NamespaceGenerationRepository $namespaces,
        private readonly NamespacePath $namespacePath,
    ) {
    }

    /**
     * @param list<string> $keys
     * @param list<string> $fallbackScopes
     * @param list<string> $fallbackLocales
     */
    public function schedule(
        ConnectionFactory $connection,
        string $module,
        string $area,
        string $scope,
        string $locale,
        array $keys,
        array $fallbackScopes,
        array $fallbackLocales,
        bool $deferNamespace = false,
    ): void {
        $keys = $this->stringList($keys);
        $fallbackScopes = $this->stringList($fallbackScopes);
        $fallbackLocales = $this->stringList($fallbackLocales);

        if (!$deferNamespace && $this->isWebsiteStartPageChange($module, $area, $keys)) {
            $websiteCode = (string)(explode('.', $scope, 2)[0] ?? '');
            if ($websiteCode !== '') {
                $this->namespaces->bumpMany([
                    $this->namespacePath->website($websiteCode, ['config', 'start-page']),
                ]);
            }
        }

        if (!$this->transactions->isActive($connection)) {
            $this->invalidateNow(
                $module,
                $area,
                $scope,
                $locale,
                $keys,
                $fallbackScopes,
                $fallbackLocales,
            );
            return;
        }

        $connectionKey = TransactionContext::logicalConnectionKey($connection->getConnector());
        $state = $this->state($connectionKey);
        $entryKey = hash('sha256', implode("\0", [$module, $area, $scope, $locale]));
        $entry = $state['entries'][$entryKey] ?? [
            'module' => $module,
            'area' => $area,
            'scope' => $scope,
            'locale' => $locale,
            'keys' => [],
            'fallback_scopes' => [],
            'fallback_locales' => [],
        ];
        $entry['keys'] = $this->stringList(array_merge($entry['keys'], $keys));
        $entry['fallback_scopes'] = $this->stringList(array_merge(
            $entry['fallback_scopes'],
            $fallbackScopes,
        ));
        $entry['fallback_locales'] = $this->stringList(array_merge(
            $entry['fallback_locales'],
            $fallbackLocales,
        ));
        $state['entries'][$entryKey] = $entry;
        $this->storeState($connectionKey, $state);

        if ($state['callbacks_registered']) {
            return;
        }

        $state['callbacks_registered'] = true;
        $this->storeState($connectionKey, $state);
        $this->transactions->afterRollback(
            $connection,
            'system_config_cache_invalidation_rollback',
            fn(): bool => $this->forgetState($connectionKey),
        );
        $this->transactions->afterCommit(
            $connection,
            'system_config_cache_invalidation_commit',
            function () use ($connectionKey): void {
                $committed = $this->state($connectionKey);
                $this->forgetState($connectionKey);
                foreach ($committed['entries'] as $entry) {
                    $this->invalidateNow(
                        (string)$entry['module'],
                        (string)$entry['area'],
                        (string)$entry['scope'],
                        (string)$entry['locale'],
                        (array)$entry['keys'],
                        (array)$entry['fallback_scopes'],
                        (array)$entry['fallback_locales'],
                    );
                }
            },
        );
    }

    /**
     * @param list<string> $keys
     * @param list<string> $fallbackScopes
     * @param list<string> $fallbackLocales
     */
    private function invalidateNow(
        string $module,
        string $area,
        string $scope,
        string $locale,
        array $keys,
        array $fallbackScopes,
        array $fallbackLocales,
    ): void {
        unset(SystemConfig::$configs[$area][$module]);
        $cache = w_cache('system_config');

        /** @var ScopeConfigCacheInvalidator $impact */
        $impact = ObjectManager::getInstance(ScopeConfigCacheInvalidator::class);
        /** @var SystemConfig $configModel */
        $configModel = ObjectManager::getInstance(SystemConfig::class);
        // 先按旧 version vector 删键，再 bump（避免只删新键导致旧脏缓存残留）
        $plan = $impact->planImpact($module, $area, $scope, $locale, $keys, $configModel);

        $scopesToTouch = $this->stringList(array_merge(
            [$scope],
            $fallbackScopes,
            $plan['invalidate_scopes'],
        ));
        $localesToTouch = $this->stringList(array_merge([$locale], $fallbackLocales));

        foreach ($keys as $key) {
            foreach ($scopesToTouch as $touchScope) {
                $isWrittenOrAncestor = $touchScope === $scope || in_array($touchScope, $fallbackScopes, true);
                $isSkippedOverride = in_array($touchScope, $plan['skipped_override_scopes'], true);
                if (!$isWrittenOrAncestor && $isSkippedOverride
                    && !$impact->shouldInvalidateKeyAtScope($configModel, $key, $module, $area, $touchScope, $locale)
                ) {
                    continue;
                }
                foreach ($localesToTouch as $touchLocale) {
                    RequestContext::remove($this->requestKey('raw', $module, $area, $key, $touchScope, $touchLocale));
                    RequestContext::remove($this->requestKey('resolved', $module, $area, $key, $touchScope, $touchLocale));
                    $cache->delete($this->singleKey(
                        $key,
                        $module,
                        $area,
                        $touchScope,
                        $touchLocale,
                    ));
                }
            }
        }

        foreach ($scopesToTouch as $touchScope) {
            if (in_array($touchScope, $plan['skipped_override_scopes'], true) && $touchScope !== $scope) {
                continue;
            }
            foreach ($localesToTouch as $touchLocale) {
                RequestContext::remove($this->requestKey('module_rows', $module, $area, null, $touchScope, $touchLocale));
                RequestContext::remove($this->requestKey('module_map', $module, $area, null, $touchScope, $touchLocale));
                $cache->delete($this->moduleRowsKey($module, $area, $touchScope, $touchLocale));
                $cache->delete($this->moduleMapKey($module, $area, $touchScope, $touchLocale));
            }
        }

        $generation = $impact->bumpGeneration($scope);
        $plan['generation'] = $generation;
        $plan['version_vector'] = $impact->versionVectorFor($scope);
        $plan['metrics']['generation'] = $generation;

        // 记录最近一次影响指标（请求内可观测；TEST-P1C-06）
        RequestContext::set('system_config.cache_invalidation.last_plan', [
            'module' => $module,
            'area' => $area,
            'scope' => $scope,
            'locale' => $locale,
            'keys' => $keys,
            'plan' => $plan,
        ]);
    }

    /** @param list<string> $keys */
    private function isWebsiteStartPageChange(string $module, string $area, array $keys): bool
    {
        return ($module === self::WEBSITE_START_PAGE_MODULE
                && $area === SystemConfig::area_FRONTEND
                && in_array(self::WEBSITE_START_PAGE_KEY, $keys, true))
            || ($module === self::BACKEND_START_PAGE_MODULE
                && $area === SystemConfig::area_BACKEND
                && in_array(self::BACKEND_START_PAGE_KEY, $keys, true));
    }

    /** @return array{callbacks_registered:bool,entries:array<string,array<string,mixed>>} */
    private function state(string $connectionKey): array
    {
        $all = RequestContext::get(self::STATE_KEY, []);
        $state = is_array($all) ? ($all[$connectionKey] ?? []) : [];
        return [
            'callbacks_registered' => (bool)($state['callbacks_registered'] ?? false),
            'entries' => is_array($state['entries'] ?? null) ? $state['entries'] : [],
        ];
    }

    /** @param array{callbacks_registered:bool,entries:array<string,array<string,mixed>>} $state */
    private function storeState(string $connectionKey, array $state): void
    {
        $all = RequestContext::get(self::STATE_KEY, []);
        $all = is_array($all) ? $all : [];
        $all[$connectionKey] = $state;
        RequestContext::set(self::STATE_KEY, $all);
    }

    private function forgetState(string $connectionKey): bool
    {
        $all = RequestContext::get(self::STATE_KEY, []);
        if (!is_array($all)) {
            return false;
        }
        unset($all[$connectionKey]);
        if ($all === []) {
            RequestContext::remove(self::STATE_KEY);
        } else {
            RequestContext::set(self::STATE_KEY, $all);
        }
        return true;
    }

    /** @return list<string> */
    private function stringList(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $result[$value] = $value;
            }
        }
        $result = array_values($result);
        sort($result, SORT_STRING);
        return $result;
    }

    private function singleKey(string $key, string $module, string $area, string $scope, string $locale): string
    {
        /** @var ScopeConfigCacheInvalidator $impact */
        $impact = ObjectManager::getInstance(ScopeConfigCacheInvalidator::class);
        $vector = $impact->versionVectorFor($scope);

        return 'system_config_cache_' . sha1(implode('|', [$area, $module, $key, $scope, $locale, $vector]));
    }

    private function moduleRowsKey(string $module, string $area, string $scope, string $locale): string
    {
        /** @var ScopeConfigCacheInvalidator $impact */
        $impact = ObjectManager::getInstance(ScopeConfigCacheInvalidator::class);
        $vector = $impact->versionVectorFor($scope);

        return 'system_config_rows_' . sha1(implode('|', [$area, $module, $scope, $locale, $vector]));
    }

    private function moduleMapKey(string $module, string $area, string $scope, string $locale): string
    {
        /** @var ScopeConfigCacheInvalidator $impact */
        $impact = ObjectManager::getInstance(ScopeConfigCacheInvalidator::class);
        $vector = $impact->versionVectorFor($scope);

        return 'system_config_map_' . sha1(implode('|', [$area, $module, $scope, $locale, $vector]));
    }

    private function requestKey(
        string $type,
        string $module,
        string $area,
        ?string $key,
        string $scope,
        string $locale,
    ): string {
        /** @var ScopeConfigCacheInvalidator $impact */
        $impact = ObjectManager::getInstance(ScopeConfigCacheInvalidator::class);
        $vector = $impact->versionVectorFor($scope);

        return implode(':', array_filter([
            'system_config',
            $type,
            $area,
            $module,
            $key,
            $scope,
            $locale,
            $vector,
        ], static fn(?string $value): bool => $value !== null && $value !== ''));
    }
}
