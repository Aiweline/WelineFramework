<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Cache\Contract\SharedCacheStateInterface;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Model\Cache\NamespaceVersion;
use Weline\Framework\Router\FullPageCacheCoordinator;
use Weline\Framework\Runtime\RuntimeControlBroadcasterInterface;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Runtime\WlsRuntimeAdapterInterface;
use Weline\SystemConfig\Api\Scope\ScopeContext;
use Weline\Theme\Block\Partials;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Observer\ControllerFetchFileBefore;

final class ThemeRuntimeCacheCleaner
{
    /**
     * Full theme-related invalidation for publish / resource_changed.
     * Clears theme namespace generations (all recorded theme scopes), WLS FPC,
     * optional CDN full-page purge, plus the shared non-global theme runtime pools.
     *
     * @return array{reason:string,theme_id:int|null,steps:array<string,bool>,failures:array<string,string>}
     */
    public function clearAllThemeRelatedCaches(?int $themeId = null, string $reason = 'theme_changed'): array
    {
        $result = $this->clearNonGlobalCaches($themeId, $reason);
        $result['reason'] = $reason;

        $this->runStep($result, 'theme_namespace_generations_all', function (): void {
            $this->bumpAllThemeNamespaces();
        });
        $this->runStep($result, 'fpc_cache_pools', function (): void {
            $cacheManager = ObjectManager::getInstance(CacheManager::class);
            foreach (['fpc', 'router'] as $pool) {
                if (\method_exists($cacheManager, 'hasPool') && !$cacheManager->hasPool($pool)) {
                    continue;
                }
                $cacheManager->pool($pool)->clear();
            }
        });
        $this->runStep($result, 'wls_shared_fpc_full', function () use ($reason): void {
            $this->clearWlsSharedFpcAndRouter($reason);
        });
        $this->runStep($result, 'wls_worker_broadcast_all', function (): void {
            $broadcaster = $this->runtimeProvider(RuntimeControlBroadcasterInterface::class);
            if ($broadcaster instanceof RuntimeControlBroadcasterInterface) {
                // null = all WLS instances, not only the current worker.
                $broadcaster->cacheClear(null);
            }
        });
        $this->runStep($result, 'cdn_full_page_purge', function (): void {
            $this->purgeCdnFullPageCaches();
        });

        return $result;
    }

    /**
     * Invalidate only the published Theme namespace for this Scope. Because
     * storefront vectors include their parent paths, descendants are invalidated
     * naturally while siblings retain their FPC generation.
     *
     * @return array{reason:string,theme_id:int|null,scope:string,steps:array<string,bool>,failures:array<string,string>}
     */
    public function clearScopedCaches(
        ScopeContext $scope,
        ?int $themeId = null,
        string $reason = 'theme_scoped_publish',
    ): array {
        $result = [
            'reason' => $reason,
            'theme_id' => $themeId,
            'scope' => $scope->storageScope,
            'steps' => [],
            'failures' => [],
        ];

        $this->runStep($result, 'storefront_scoped_theme_generation', function () use ($scope): void {
            $namespacePath = ObjectManager::getInstance(NamespacePath::class);
            ObjectManager::getInstance(NamespaceGenerationInterface::class)->bump(
                $this->scopeThemeNamespace($namespacePath, $scope->identity),
            );
        });

        $this->runStep($result, 'theme_model_active_keys', function () use ($themeId): void {
            $theme = ObjectManager::getInstance(WelineTheme::class);
            foreach (['theme', 'theme_frontend', 'theme_backend'] as $cacheKey) {
                $theme->_cache->delete($cacheKey);
            }
            if ($themeId !== null && $themeId > 0) {
                $theme->_cache->delete('theme_parent_' . $themeId);
            }
        });

        if ($themeId !== null && $themeId > 0) {
            $this->runStep($result, 'generated_theme_cache', function () use ($themeId): void {
                ObjectManager::getInstance(ThemeCacheGenerator::class)->clearCache($themeId);
            });
        }

        $this->runStep($result, 'theme_data_runtime', static function (): void {
            ThemeData::clearCache();
        });
        $this->runStep($result, 'controller_fetch_file_runtime', static function (): void {
            ControllerFetchFileBefore::clearRuntimeCache();
        });
        $this->runStep($result, 'partials_runtime', static function (): void {
            Partials::clearAllCaches();
        });
        $this->runStep($result, 'storefront_chrome_hot_cache', static function (): void {
            self::purgeStorefrontChromeHotCachePool();
        });
        foreach ($this->themeCacheServices() as $step => $serviceClass) {
            $this->runStep($result, $step, static function () use ($serviceClass): void {
                $service = ObjectManager::getInstance($serviceClass);
                if (\method_exists($service, 'clearCache')) {
                    $service->clearCache();
                }
            });
        }
        $this->runStep($result, 'compiled_template_cache', static function (): void {
            if (\class_exists(\Weline\Framework\View\TemplateCacheManager::class)) {
                \Weline\Framework\View\TemplateCacheManager::getInstance()->clearAll();
            }
        });
        $this->runStep($result, 'module_view_tpl_compiled', function (): void {
            $this->purgeModuleCompiledViewTpl();
        });
        $this->runStep($result, 'taglib_cache_pool', static function (): void {
            ObjectManager::getInstance(CacheManager::class)->pool('taglib')->clear();
        });
        $this->runStep($result, 'view_cache_pool', static function (): void {
            ObjectManager::getInstance(CacheManager::class)->pool('view')->clear();
        });
        $this->runStep($result, 'fpc_process_cache', static function (): void {
            if (\class_exists(FullPageCacheCoordinator::class)) {
                FullPageCacheCoordinator::clearProcessCache();
            }
        });
        $this->runStep($result, 'shared_theme_runtime_memory', function (): void {
            if ($this->currentRuntimeInstanceName() === null) {
                return;
            }
            $state = $this->runtimeProvider(SharedCacheStateInterface::class);
            if ($state instanceof SharedCacheStateInterface) {
                $state->clearNamespace('theme_runtime');
            }
        });
        $this->runStep($result, 'runtime_cache_broadcast', function (): void {
            $instanceName = $this->currentRuntimeInstanceName();
            $broadcaster = $this->runtimeProvider(RuntimeControlBroadcasterInterface::class);
            if ($broadcaster instanceof RuntimeControlBroadcasterInterface) {
                $broadcaster->cacheClear($instanceName);
            }
        });
        $this->runStep($result, 'router_fpc_payload_files', function (): void {
            $this->purgeRouterFpcPayloadFiles();
        });

        return $result;
    }

    /**
     * @return array{reason:string,theme_id:int|null,steps:array<string,bool>,failures:array<string,string>}
     */
    public function clearNonGlobalCaches(?int $themeId = null, string $reason = 'theme_activation'): array
    {
        $result = [
            'reason' => $reason,
            'theme_id' => $themeId,
            'steps' => [],
            'failures' => [],
        ];

        $this->runStep($result, 'storefront_theme_generation', static function (): void {
            $namespacePath = ObjectManager::getInstance(NamespacePath::class);
            ObjectManager::getInstance(NamespaceGenerationInterface::class)->bump(
                $namespacePath->global('storefront', ['theme']),
            );
        });

        $this->runStep($result, 'framework_non_global_pools', function (): void {
            ObjectManager::getInstance(CacheManager::class)->clearAll();
        });

        $this->runStep($result, 'router_runtime_cache', static function (): void {
            ObjectManager::getInstance(CacheManager::class)->pool('router')->clear();
        });

        $this->runStep($result, 'theme_model_active_keys', function () use ($themeId): void {
            $theme = ObjectManager::getInstance(WelineTheme::class);
            foreach (['theme', 'theme_frontend', 'theme_backend'] as $cacheKey) {
                $theme->_cache->delete($cacheKey);
            }
            if ($themeId !== null && $themeId > 0) {
                $theme->_cache->delete('theme_parent_' . $themeId);
            }
        });

        if ($themeId !== null && $themeId > 0) {
            $this->runStep($result, 'generated_theme_cache', function () use ($themeId): void {
                ObjectManager::getInstance(ThemeCacheGenerator::class)->clearCache($themeId);
            });
        }

        $this->runStep($result, 'theme_data_runtime', static function (): void {
            ThemeData::clearCache();
        });

        $this->runStep($result, 'controller_fetch_file_runtime', static function (): void {
            ControllerFetchFileBefore::clearRuntimeCache();
        });

        $this->runStep($result, 'partials_runtime', static function (): void {
            Partials::clearAllCaches();
        });

        foreach ($this->themeCacheServices() as $step => $serviceClass) {
            $this->runStep($result, $step, static function () use ($serviceClass): void {
                $service = ObjectManager::getInstance($serviceClass);
                if (\method_exists($service, 'clearCache')) {
                    $service->clearCache();
                }
            });
        }

        $this->runStep($result, 'compiled_template_cache', static function (): void {
            if (\class_exists(\Weline\Framework\View\TemplateCacheManager::class)) {
                \Weline\Framework\View\TemplateCacheManager::getInstance()->clearAll();
            }
        });
        $this->runStep($result, 'module_view_tpl_compiled', function (): void {
            $this->purgeModuleCompiledViewTpl();
        });
        $this->runStep($result, 'taglib_cache_pool', static function (): void {
            ObjectManager::getInstance(CacheManager::class)->pool('taglib')->clear();
        });
        $this->runStep($result, 'view_cache_pool', static function (): void {
            ObjectManager::getInstance(CacheManager::class)->pool('view')->clear();
        });

        $this->runStep($result, 'fpc_process_cache', static function (): void {
            if (\class_exists(FullPageCacheCoordinator::class)) {
                FullPageCacheCoordinator::clearProcessCache();
            }
        });

        $this->runStep($result, 'shared_theme_runtime_memory', function (): void {
            if ($this->currentRuntimeInstanceName() === null) {
                return;
            }
            $state = $this->runtimeProvider(SharedCacheStateInterface::class);
            if (!$state instanceof SharedCacheStateInterface) {
                return;
            }
            $state->clearCache('router');
            $state->clearCache('fpc');
            $state->clearNamespace('theme_runtime');
        });

        $this->runStep($result, 'storefront_chrome_hot_cache', static function (): void {
            self::purgeStorefrontChromeHotCachePool();
        });

        $this->runStep($result, 'runtime_cache_broadcast', function (): void {
            $instanceName = $this->currentRuntimeInstanceName();
            $broadcaster = $this->runtimeProvider(RuntimeControlBroadcasterInterface::class);
            if ($broadcaster instanceof RuntimeControlBroadcasterInterface) {
                $broadcaster->cacheClear($instanceName);
            }
        });

        $this->runStep($result, 'router_fpc_payload_files', function (): void {
            $this->purgeRouterFpcPayloadFiles();
        });

        return $result;
    }

    /**
     * Drop every storefront chrome envelope. Keys are theme.chrome.{type}.{sha1}, so
     * forget(theme.chrome.header) alone leaves nested widget HTML (e.g. account avatar) stale.
     */
    private static function purgeStorefrontChromeHotCachePool(): void
    {
        if (!\class_exists(\Weline\Framework\Cache\Service\StorefrontScopeHotCache::class)) {
            return;
        }
        $hotCache = ObjectManager::getInstance(\Weline\Framework\Cache\Service\StorefrontScopeHotCache::class);
        $hotCache->purgeProcessCacheForLogicalKey('theme.chrome.');
        \Weline\Framework\Cache\Service\StorefrontScopeHotCache::resetProcessCache();
        try {
            ObjectManager::getInstance(CacheManager::class)
                ->pool('weline_theme_storefront_chrome')
                ->clear();
        } catch (\Throwable) {
        }
    }

    private function runtimeProvider(string $contract): ?object
    {
        try {
            return ObjectManager::getInstance(RuntimeProviderResolver::class)->resolve($contract);
        } catch (\Throwable) {
            return null;
        }
    }

    private function scopeThemeNamespace(NamespacePath $paths, ScopeIdentity $identity): string
    {
        if ($identity->isGlobal()) {
            return $paths->global('storefront', ['theme']);
        }
        $websiteCode = (string)$identity->websiteCode;
        if ($websiteCode === '') {
            throw new \InvalidArgumentException('theme_cache_scope_website_required');
        }
        if ($identity->scopeKind === ScopeIdentity::KIND_WEBSITE) {
            return $paths->website($websiteCode, ['theme']);
        }
        $storeCode = (string)$identity->storeCode;
        $storeMode = (string)$identity->storeMode;
        if ($storeCode === '' || $storeMode === '') {
            throw new \InvalidArgumentException('theme_cache_scope_store_required');
        }
        $segments = ['theme', 'store', $storeCode, $storeMode];
        if ($identity->scopeKind === ScopeIdentity::KIND_CHANNEL) {
            $channelCode = (string)$identity->channelCode;
            if ($channelCode === '') {
                throw new \InvalidArgumentException('theme_cache_scope_channel_required');
            }
            $segments[] = 'channel';
            $segments[] = $channelCode;
        }

        return $paths->website($websiteCode, $segments);
    }

    /** Bump every recorded theme cache namespace plus the global storefront theme authority. */
    private function bumpAllThemeNamespaces(): void
    {
        $namespacePath = ObjectManager::getInstance(NamespacePath::class);
        $namespaces = [
            $namespacePath->global('storefront', ['theme']),
        ];
        try {
            $rows = ObjectManager::getInstance(NamespaceVersion::class)
                ->clear()
                ->clearQuery()
                ->fields(NamespaceVersion::schema_fields_NAMESPACE)
                ->select()
                ->fetchArray();
            foreach (\is_array($rows) ? $rows : [] as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $ns = \trim((string)($row[NamespaceVersion::schema_fields_NAMESPACE] ?? ''));
                if ($ns === '' || !\preg_match('#(^|/)theme(/|$)#', $ns)) {
                    continue;
                }
                $namespaces[] = $namespacePath->canonicalize($ns);
            }
        } catch (\Throwable) {
            // Table may be empty/unavailable during early bootstrap; global bump still runs.
        }
        $namespaces = \array_values(\array_unique($namespaces));
        \sort($namespaces, \SORT_STRING);
        ObjectManager::getInstance(NamespaceGenerationInterface::class)->bumpMany($namespaces);
    }

    private function clearWlsSharedFpcAndRouter(string $reason): void
    {
        $adapter = $this->runtimeProvider(WlsRuntimeAdapterInterface::class);
        if ($adapter instanceof WlsRuntimeAdapterInterface) {
            $facade = $adapter->createSharedState([
                'consumer_code' => $reason,
                'prefer_direct_connect' => true,
                'pool_size' => 1,
                'auto_start' => false,
            ]);
            $facade->clearCache('router');
            $facade->clearCache('fpc');
            $facade->disconnect();
            return;
        }

        // Fallback when not under WLS adapter: clear SharedCacheState if present.
        if ($this->currentRuntimeInstanceName() === null) {
            return;
        }
        $state = $this->runtimeProvider(SharedCacheStateInterface::class);
        if ($state instanceof SharedCacheStateInterface) {
            $state->clearCache('router');
            $state->clearCache('fpc');
            $state->clearNamespace('theme_runtime');
        }
    }

    /**
     * Best-effort CDN full-page purge for every enabled domain.
     * Soft-fails into the step result when Cdn is absent or a domain purge fails.
     */
    private function purgeCdnFullPageCaches(): void
    {
        if (!\class_exists(\Weline\Cdn\Model\Domain::class)
            || !\class_exists(\Weline\Cdn\Service\CachePurger::class)
        ) {
            return;
        }
        /** @var \Weline\Cdn\Model\Domain $domainModel */
        $domainModel = ObjectManager::getInstance(\Weline\Cdn\Model\Domain::class);
        $domains = (clone $domainModel)->reset()
            ->where(\Weline\Cdn\Model\Domain::schema_fields_ENABLED, 1)
            ->select()
            ->fetch()
            ->getItems();
        if ($domains === []) {
            return;
        }
        /** @var \Weline\Cdn\Service\CachePurger $purger */
        $purger = ObjectManager::getInstance(\Weline\Cdn\Service\CachePurger::class);
        $errors = [];
        foreach ($domains as $domain) {
            if (!$domain instanceof \Weline\Cdn\Model\Domain) {
                continue;
            }
            $domainId = (int)$domain->getData(\Weline\Cdn\Model\Domain::schema_fields_DOMAIN_ID);
            $host = (string)$domain->getData(\Weline\Cdn\Model\Domain::schema_fields_DOMAIN_NAME);
            if ($domainId < 1) {
                continue;
            }
            try {
                $result = $purger->purge($domainId, 'everything', []);
                if (($result['success'] ?? false) !== true) {
                    // Adapters that reject everything fall back to host purge.
                    $result = $purger->purge($domainId, 'hosts', ['hosts' => [$host]]);
                }
                if (($result['success'] ?? false) !== true) {
                    $errors[] = $host !== '' ? $host : ('domain:' . $domainId);
                }
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ($errors !== []) {
            throw new \RuntimeException('cdn_purge_partial_failure:' . \implode(';', $errors));
        }
    }

    private function currentRuntimeInstanceName(): ?string
    {
        foreach ([
            $_SERVER['WLS_INSTANCE_NAME'] ?? null,
            $_SERVER['WLS_INSTANCE'] ?? null,
            $_ENV['WLS_INSTANCE_NAME'] ?? null,
            $_ENV['WLS_INSTANCE'] ?? null,
            \getenv('WLS_INSTANCE_NAME') ?: null,
            \getenv('WLS_INSTANCE') ?: null,
        ] as $candidate) {
            if (!\is_string($candidate)) {
                continue;
            }
            $candidate = \trim($candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<string, class-string>
     */
    private function themeCacheServices(): array
    {
        return [
            'slot_renderer_runtime' => SlotRendererService::class,
            'layout_data_runtime' => LayoutDataService::class,
            'theme_directory_runtime' => ThemeDirectoryResolver::class,
            'theme_resource_catalog_runtime' => ThemeResourceCatalog::class,
            'theme_builder_schema_runtime' => ThemeBuilderSchemaService::class,
            'theme_component_catalog_runtime' => ThemeComponentCatalog::class,
        ];
    }

    /**
     * @param array{steps:array<string,bool>,failures:array<string,string>} $result
     */
    private function runStep(array &$result, string $step, callable $callback): void
    {
        try {
            $callback();
            $result['steps'][$step] = true;
        } catch (\Throwable $e) {
            $result['steps'][$step] = false;
            $result['failures'][$step] = $e->getMessage();
            Env::log_error('theme_cache_clear', 'Theme runtime cache clear step failed: ' . $step . ' - ' . $e->getMessage());
        }
    }

    private function purgeRouterFpcPayloadFiles(): void
    {
        $dir = BP . 'var' . \DIRECTORY_SEPARATOR . 'cache' . \DIRECTORY_SEPARATOR . 'router-fpc-payloads';
        $base = \realpath(BP);
        $resolved = \realpath($dir);
        if ($base === false || $resolved === false || !\is_dir($resolved)) {
            return;
        }

        $baseNormalized = \strtolower(\rtrim(\str_replace('\\', '/', $base), '/') . '/');
        $dirNormalized = \strtolower(\rtrim(\str_replace('\\', '/', $resolved), '/') . '/');
        $expectedPrefix = $baseNormalized . 'var/cache/router-fpc-payloads/';
        if ($dirNormalized !== $expectedPrefix) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @\rmdir($item->getPathname());
            } else {
                @\unlink($item->getPathname());
            }
        }
    }

    /**
     * Delete module-local view/tpl compile products. @static/?v= is baked there at
     * compile time; TemplateCacheManager only covers var/cache/template.
     */
    private function purgeModuleCompiledViewTpl(): void
    {
        $codeRoot = BP . 'app' . \DIRECTORY_SEPARATOR . 'code';
        $codeReal = \realpath($codeRoot);
        if ($codeReal === false || !\is_dir($codeReal)) {
            return;
        }

        $prefix = \strtolower(\rtrim(\str_replace('\\', '/', $codeReal), '/') . '/');
        $pattern = $codeRoot
            . \DIRECTORY_SEPARATOR . '*'
            . \DIRECTORY_SEPARATOR . '*'
            . \DIRECTORY_SEPARATOR . 'view'
            . \DIRECTORY_SEPARATOR . 'tpl';
        foreach (\glob($pattern, \GLOB_ONLYDIR) ?: [] as $tplDir) {
            $resolved = \realpath($tplDir);
            if ($resolved === false || !\is_dir($resolved)) {
                continue;
            }
            $dirNormalized = \strtolower(\rtrim(\str_replace('\\', '/', $resolved), '/'));
            if (!\str_starts_with($dirNormalized . '/', $prefix)) {
                continue;
            }
            if (!\str_ends_with($dirNormalized, '/view/tpl')) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                $path = $item->getPathname();
                $real = \realpath($path) ?: $path;
                $realNorm = \strtolower(\str_replace('\\', '/', $real));
                if (!\str_starts_with($realNorm, \rtrim($prefix, '/'))) {
                    continue;
                }
                if ($item->isDir()) {
                    @\rmdir($path);
                } else {
                    @\unlink($path);
                }
            }
        }
    }
}
