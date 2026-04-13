<?php

declare(strict_types=1);

namespace Weline\Hook\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Hook\HookRegistry;

final class HookListingService
{
    private const REGISTRY_FILE = BP . 'generated' . DIRECTORY_SEPARATOR . 'hooks.php';

    private ?HookRegistry $hookRegistry = null;
    private ?array $cachedHooks = null;
    private int $cachedRegistryMtime = -1;

    private function getHookRegistry(): HookRegistry
    {
        if ($this->hookRegistry === null) {
            $this->hookRegistry = ObjectManager::getInstance(HookRegistry::class);
        }

        return $this->hookRegistry;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getAllHooks(): array
    {
        if (!$this->hasRegistryFile()) {
            return [];
        }

        $registryMtime = $this->getRegistryFileMtime();
        if ($this->cachedHooks !== null && $this->cachedRegistryMtime === $registryMtime) {
            return $this->cachedHooks;
        }

        $registry = $this->getHookRegistry();
        $registry->initialize();
        $registryHooks = $registry->getHooks();
        $registeredHooks = $registry->getAllRegisteredHooks();
        $registeredHookLookup = array_fill_keys($registeredHooks, true);

        $result = [];
        foreach ($registryHooks as $hookName => $hookInfo) {
            $parts = explode('::', $hookName);
            $moduleName = $parts[0] ?? '';
            $area = $parts[1] ?? '';
            $type = $parts[2] ?? '';
            $component = $parts[3] ?? '';
            $position = $parts[4] ?? '';
            $implementations = is_array($hookInfo['implementations'] ?? null) ? $hookInfo['implementations'] : [];
            $files = $this->buildFilesFromImplementations($implementations);
            $usingModules = array_keys($implementations);
            sort($usingModules);

            $isRegistered = isset($registeredHookLookup[$hookName]);
            $hookInfoFromInterface = $isRegistered ? $registry->getHookInfoFromInterface($hookName) : null;

            $result[$hookName] = [
                'name' => $hookName,
                'display_name' => $hookInfo['name'] ?? $hookName,
                'description' => $hookInfo['description'] ?? '',
                'constant' => $hookInfoFromInterface['constant'] ?? '',
                'module' => $hookInfo['module'] ?? $moduleName,
                'area' => $area,
                'type' => $type,
                'component' => $component,
                'position' => $position,
                'files' => $files,
                'file_count' => count($files),
                'using_modules' => $usingModules,
                'using_module_count' => count($usingModules),
                'is_registered' => $isRegistered,
                'has_files' => $files !== [],
                'has_spec' => $hookInfo['has_spec'] ?? false,
                'has_doc' => $hookInfo['has_doc'] ?? false,
                'doc' => $hookInfo['doc'] ?? '',
                'doc_path' => $hookInfo['doc_path'] ?? '',
            ];
        }

        foreach ($registeredHooks as $hookName) {
            if (isset($result[$hookName])) {
                continue;
            }

            $hookInfoFromInterface = $registry->getHookInfoFromInterface($hookName);
            $parts = explode('::', $hookName);
            $result[$hookName] = [
                'name' => $hookName,
                'display_name' => $hookName,
                'description' => '',
                'constant' => $hookInfoFromInterface['constant'] ?? '',
                'module' => $parts[0] ?? '',
                'area' => $parts[1] ?? '',
                'type' => $parts[2] ?? '',
                'component' => $parts[3] ?? '',
                'position' => $parts[4] ?? '',
                'files' => [],
                'file_count' => 0,
                'using_modules' => [],
                'using_module_count' => 0,
                'is_registered' => true,
                'has_files' => false,
                'has_spec' => false,
                'has_doc' => false,
                'doc' => '',
                'doc_path' => '',
            ];
        }

        $this->cachedHooks = $result;
        $this->cachedRegistryMtime = $registryMtime;

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getHookDetail(string $hookName): ?array
    {
        $allHooks = $this->getAllHooks();

        return $allHooks[$hookName] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getHookStats(): array
    {
        $hooks = $this->getAllHooks();

        $stats = [
            'total_hooks' => count($hooks),
            'registered_hooks' => 0,
            'unregistered_hooks' => 0,
            'hooks_with_files' => 0,
            'hooks_without_files' => 0,
            'total_files' => 0,
            'modules_with_hooks' => [],
            'modules_using_hooks' => [],
            'areas' => ['frontend' => 0, 'backend' => 0],
            'types' => ['partials' => 0, 'layouts' => 0],
        ];

        foreach ($hooks as $hookInfo) {
            if ($hookInfo['is_registered']) {
                $stats['registered_hooks']++;
            } else {
                $stats['unregistered_hooks']++;
            }

            $fileCount = (int)($hookInfo['file_count'] ?? 0);
            $stats['total_files'] += $fileCount;
            if ($fileCount > 0) {
                $stats['hooks_with_files']++;
            } else {
                $stats['hooks_without_files']++;
            }

            $module = (string)($hookInfo['module'] ?? '');
            if ($module !== '') {
                $stats['modules_with_hooks'][$module] = (int)($stats['modules_with_hooks'][$module] ?? 0) + 1;
            }

            foreach (($hookInfo['using_modules'] ?? []) as $usingModule) {
                $usingModule = (string)$usingModule;
                if ($usingModule === '') {
                    continue;
                }

                $stats['modules_using_hooks'][$usingModule] = (int)($stats['modules_using_hooks'][$usingModule] ?? 0) + 1;
            }

            $area = (string)($hookInfo['area'] ?? '');
            if (isset($stats['areas'][$area])) {
                $stats['areas'][$area]++;
            }

            $type = (string)($hookInfo['type'] ?? '');
            if (isset($stats['types'][$type])) {
                $stats['types'][$type]++;
            }
        }

        return $stats;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function searchHooks(string $searchTerm, string $searchType = 'all'): array
    {
        $hooks = $this->getAllHooks();
        $results = [];

        foreach ($hooks as $hookName => $hookInfo) {
            $matched = false;
            $matchReasons = [];

            if ($searchTerm === '') {
                $results[$hookName] = $hookInfo;
                continue;
            }

            if ($searchType === 'all' || $searchType === 'name') {
                if (stripos($hookName, $searchTerm) !== false) {
                    $matched = true;
                    $matchReasons[] = 'Hook 名';
                }
            }

            if ($searchType === 'all' || $searchType === 'module') {
                if (stripos((string)($hookInfo['module'] ?? ''), $searchTerm) !== false) {
                    $matched = true;
                    $matchReasons[] = '定义模块';
                }
                foreach (($hookInfo['using_modules'] ?? []) as $usingModule) {
                    if (stripos((string)$usingModule, $searchTerm) !== false) {
                        $matched = true;
                        $matchReasons[] = '使用模块';
                    }
                }
            }

            if ($searchType === 'all' || $searchType === 'component') {
                if (stripos((string)($hookInfo['component'] ?? ''), $searchTerm) !== false) {
                    $matched = true;
                    $matchReasons[] = '组件';
                }
            }

            if ($searchType === 'all' || $searchType === 'position') {
                if (stripos((string)($hookInfo['position'] ?? ''), $searchTerm) !== false) {
                    $matched = true;
                    $matchReasons[] = '位置';
                }
            }

            if ($matched) {
                $hookInfo['match_reasons'] = array_values(array_unique($matchReasons));
                $results[$hookName] = $hookInfo;
            }
        }

        return $results;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getHooksByModule(string $moduleName): array
    {
        $hooks = $this->getAllHooks();
        $results = [];

        foreach ($hooks as $hookName => $hookInfo) {
            if ((string)($hookInfo['module'] ?? '') === $moduleName) {
                $results[$hookName] = $hookInfo;
                continue;
            }

            if (in_array($moduleName, $hookInfo['using_modules'] ?? [], true)) {
                $results[$hookName] = $hookInfo;
            }
        }

        return $results;
    }

    /**
     * @param array<string, array<string, mixed>> $implementations
     * @return array<int, string>
     */
    private function buildFilesFromImplementations(array $implementations): array
    {
        $files = [];

        foreach ($implementations as $moduleName => $implementation) {
            if (!is_array($implementation)) {
                continue;
            }

            $file = trim((string)($implementation['file'] ?? ''));
            if ($file === '') {
                continue;
            }

            $file = str_replace(['\\', '/'], '/', $file);
            if (!str_starts_with($file, 'view/hooks/')) {
                $file = 'view/hooks/' . ltrim($file, '/');
            }

            $files[] = $moduleName . '::' . $file;
        }

        sort($files);

        return $files;
    }

    protected function hasRegistryFile(): bool
    {
        return file_exists(self::REGISTRY_FILE);
    }

    protected function getRegistryFileMtime(): int
    {
        if (!$this->hasRegistryFile()) {
            return 0;
        }

        return (int)(filemtime(self::REGISTRY_FILE) ?: 0);
    }
}
