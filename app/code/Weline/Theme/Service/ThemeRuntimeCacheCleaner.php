<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\SharedCacheStateInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\FullPageCacheCoordinator;
use Weline\Framework\Runtime\RuntimeControlBroadcasterInterface;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Theme\Block\Partials;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Observer\ControllerFetchFileBefore;

final class ThemeRuntimeCacheCleaner
{
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

    private function runtimeProvider(string $contract): ?object
    {
        try {
            return ObjectManager::getInstance(RuntimeProviderResolver::class)->resolve($contract);
        } catch (\Throwable) {
            return null;
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
}
