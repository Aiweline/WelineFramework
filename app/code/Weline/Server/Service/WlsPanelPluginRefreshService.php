<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Handle;
use Weline\Framework\Registry\Service\RegistryUpdateService;
use Weline\Framework\Router\Service\RouteUpdateService;

class WlsPanelPluginRefreshService
{
    /**
     * @return array{
     *     success: bool,
     *     error: string,
     *     registry_refreshed: bool,
     *     registry_mode: string,
     *     registry_modules: array<int, string>,
     *     routes_refreshed: bool,
     *     route_modules: array<int, string>,
     *     plugin_count: int,
     *     contribution_count: int,
     *     capabilities: array<string, mixed>
     * }
     */
    public function refreshPanelCapabilities(?string $locale = null): array
    {
        $errors = [];
        /** @var WlsPanelPluginDiscoveryService $pluginDiscovery */
        $pluginDiscovery = ObjectManager::getInstance(WlsPanelPluginDiscoveryService::class);

        $this->refreshModuleList($errors);
        $pluginState = $pluginDiscovery->getInstalledPlugins($locale);
        $routeModules = $this->extractRouteModules((array)($pluginState['items'] ?? []));
        $registryResult = $this->refreshRegistries($routeModules, $errors);
        $registryRefreshed = (bool)($registryResult['success'] ?? false);

        if ($registryRefreshed) {
            $pluginState = $pluginDiscovery->getInstalledPlugins($locale);
            $routeModules = $this->extractRouteModules((array)($pluginState['items'] ?? []));
        }

        $routesRefreshed = $this->refreshRoutes($routeModules, $errors);

        $capabilities = $pluginDiscovery->refreshCapabilities($locale);
        $capabilityError = trim((string)($capabilities['error'] ?? ''));
        if ($capabilityError !== '') {
            $errors[] = $capabilityError;
        }

        $error = trim(implode(' ', array_values(array_unique(array_filter($errors)))));

        return [
            'success' => $error === '' && $registryRefreshed && $routesRefreshed,
            'error' => $error,
            'registry_refreshed' => $registryRefreshed,
            'registry_mode' => (string)($registryResult['mode'] ?? 'unknown'),
            'registry_modules' => (array)($registryResult['modules'] ?? []),
            'routes_refreshed' => $routesRefreshed,
            'route_modules' => $routeModules,
            'plugin_count' => (int)($capabilities['plugin_count'] ?? 0),
            'contribution_count' => (int)($capabilities['contribution_count'] ?? 0),
            'capabilities' => $capabilities,
        ];
    }

    /**
     * @param array<int, string> $errors
     */
    private function refreshModuleList(array &$errors): void
    {
        try {
            Env::getInstance()->getModuleList(true);
        } catch (\Throwable $e) {
            $errors[] = (string)__('Panel plugin module list refresh failed: %{1}', [$e->getMessage()]);
        }
    }

    /**
     * @param array<int, string> $moduleNames
     * @param array<int, string> $errors
     * @return array{success: bool, mode: string, modules: array<int, string>}
     */
    private function refreshRegistries(array $moduleNames, array &$errors): array
    {
        $moduleNames = $this->normalizeModuleNames($moduleNames);
        if ($moduleNames === []) {
            return [
                'success' => true,
                'mode' => 'noop',
                'modules' => [],
            ];
        }

        $incrementalError = '';
        try {
            /** @var RegistryUpdateService $registryService */
            $registryService = ObjectManager::getInstance(RegistryUpdateService::class);
            $ok = $registryService->updateModuleRegistriesIncremental($moduleNames, true);
            if (!$ok) {
                $incrementalError = (string)__('Panel plugin registry refresh completed with warnings.');
            } else {
                return [
                    'success' => true,
                    'mode' => 'incremental',
                    'modules' => $moduleNames,
                ];
            }
        } catch (\Throwable $e) {
            $incrementalError = (string)__('Panel plugin registry refresh failed: %{1}', [$e->getMessage()]);
        }

        $fallback = $this->refreshAllRegistries($errors);
        if (!$fallback['success'] && $incrementalError !== '') {
            $errors[] = $incrementalError;
        }

        return $fallback['success']
            ? [
                'success' => true,
                'mode' => 'global_fallback',
                'modules' => $moduleNames,
            ]
            : $fallback;
    }

    /**
     * @param array<int, string> $errors
     * @return array{success: bool, mode: string, modules: array<int, string>}
     */
    private function refreshAllRegistries(array &$errors): array
    {
        try {
            /** @var RegistryUpdateService $registryService */
            $registryService = ObjectManager::getInstance(RegistryUpdateService::class);
            $ok = $registryService->updateAllRegistries(true, false, true);
            if (!$ok) {
                $errors[] = (string)__('Panel plugin registry refresh completed with warnings.');
            }

            return [
                'success' => $ok,
                'mode' => 'global',
                'modules' => [],
            ];
        } catch (\Throwable $e) {
            $errors[] = (string)__('Panel plugin registry refresh failed: %{1}', [$e->getMessage()]);

            return [
                'success' => false,
                'mode' => 'failed',
                'modules' => [],
            ];
        }
    }

    /**
     * @param array<int, string> $routeModules
     * @param array<int, string> $errors
     */
    private function refreshRoutes(array $routeModules, array &$errors): bool
    {
        if ($routeModules === []) {
            return true;
        }

        try {
            /** @var RouteUpdateService $routeUpdateService */
            $routeUpdateService = ObjectManager::make(RouteUpdateService::class, [
                'moduleHandle' => ObjectManager::make(Handle::class),
            ]);
            $routeUpdateService->updateRoutes($routeModules);

            return true;
        } catch (\Throwable $e) {
            $errors[] = (string)__('Panel plugin route refresh failed: %{1}', [$e->getMessage()]);
            return false;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $plugins
     * @return array<int, string>
     */
    private function extractRouteModules(array $plugins): array
    {
        $modules = [];
        foreach ($plugins as $plugin) {
            $moduleName = trim((string)($plugin['module_name'] ?? ''));
            if ($moduleName === '') {
                continue;
            }
            $modules[$moduleName] = $moduleName;
        }

        return $this->normalizeModuleNames($modules);
    }

    /**
     * @param array<int|string, mixed> $moduleNames
     * @return array<int, string>
     */
    private function normalizeModuleNames(array $moduleNames): array
    {
        $modules = [];
        foreach ($moduleNames as $moduleName) {
            $moduleName = trim((string)$moduleName);
            if ($moduleName === '') {
                continue;
            }
            $modules[$moduleName] = $moduleName;
        }

        ksort($modules, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values($modules);
    }
}
