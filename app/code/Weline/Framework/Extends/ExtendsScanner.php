<?php

declare(strict_types=1);

namespace Weline\Framework\Extends;

use Weline\Framework\App\Env;
use Weline\Framework\Module\Service\ModuleScanService;
use Weline\Framework\Registry\Service\RegistryProgress;
use Weline\Framework\System\File\Scan;

/**
 * Scan module extends definitions and implementations.
 */
class ExtendsScanner
{
    private ModuleScanService $moduleScanService;

    public function __construct($moduleScanService = null)
    {
        $this->moduleScanService = $moduleScanService instanceof ModuleScanService
            ? $moduleScanService
            : new ModuleScanService(new Scan());
    }

    public function scanAllExtends(): array
    {
        return $this->scanRegisteredModules(Env::getInstance()->getModuleList());
    }

    public function scanModules(array $moduleNames): array
    {
        $modules = Env::getInstance()->getModuleList();
        $selected = [];

        foreach ($moduleNames as $moduleName) {
            if (isset($modules[$moduleName])) {
                $selected[$moduleName] = $modules[$moduleName];
            }
        }

        return $this->scanRegisteredModules($selected);
    }

    private function scanRegisteredModules(array $modules): array
    {
        $result = [];
        $totalModules = 0;
        foreach ($modules as $module) {
            $basePath = $module['base_path'] ?? '';
            if ($basePath !== '' && ($module['status'] ?? false)) {
                $totalModules++;
            }
        }
        $moduleIndex = 0;

        foreach ($modules as $moduleName => $module) {
            $basePath = $module['base_path'] ?? '';
            if ($basePath === '' || !($module['status'] ?? false)) {
                continue;
            }
            $moduleIndex++;
            RegistryProgress::module('Extends scan module', $moduleIndex, $totalModules, (string)$moduleName, 'start');

            $extendsConfig = $this->scanModuleExtendsConfig($moduleName, $basePath);
            if ($extendsConfig) {
                $result[$moduleName] = [
                    'extends' => $extendsConfig,
                    'extended_by' => $result[$moduleName]['extended_by'] ?? [],
                ];
            }

            $scannerConfig = \is_array($extendsConfig['scanner'] ?? null) ? $extendsConfig['scanner'] : [];
            $extendedBy = $this->scanModuleExtends($moduleName, $basePath, $scannerConfig);
            $extendedFileCount = array_sum(array_map('count', $extendedBy));
            RegistryProgress::module(
                'Extends scan module',
                $moduleIndex,
                $totalModules,
                (string)$moduleName,
                sprintf('done config=%s extension_files=%d', $extendsConfig ? 'yes' : 'no', $extendedFileCount)
            );
            foreach ($extendedBy as $targetModule => $extendInfo) {
                if (!isset($result[$targetModule])) {
                    $result[$targetModule] = [
                        'extends' => [],
                        'extended_by' => [],
                    ];
                }
                if (!isset($result[$targetModule]['extended_by'][$moduleName])) {
                    $result[$targetModule]['extended_by'][$moduleName] = [];
                }

                $result[$targetModule]['extended_by'][$moduleName] = array_merge(
                    $result[$targetModule]['extended_by'][$moduleName],
                    $extendInfo
                );
            }
            unset($extendsConfig, $extendedBy);
        }

        return $result;
    }

    private function scanModuleExtendsConfig(string $moduleName, string $basePath): ?array
    {
        $extendsFile = $this->moduleScanService->resolveFile($basePath, 'extends.php');
        if ($extendsFile === null) {
            return null;
        }

        $config = include $extendsFile;
        if (!is_array($config)) {
            return null;
        }

        if ($this->moduleScanService->resolveFile($basePath, 'extends.md') === null) {
            w_log_warning("Warning: module {$moduleName} defines extends.php but misses extends.md");
        }

        return $config;
    }

    private function scanModuleExtends(string $sourceModule, string $basePath, array $scannerConfig = []): array
    {
        $result = [];

        $extendsModuleDir = $this->moduleScanService->resolveDirectory($basePath, 'extends/module')
            ?? $this->moduleScanService->resolveDirectory($basePath, 'Extends/module');
        if ($extendsModuleDir !== null) {
            $this->scanExtendsDirectory($extendsModuleDir, $sourceModule, $basePath, 'module', $result, $scannerConfig);
        }

        $extendsThemeDir = $this->moduleScanService->resolveDirectory($basePath, 'extends/theme')
            ?? $this->moduleScanService->resolveDirectory($basePath, 'Extends/theme');
        if ($extendsThemeDir !== null) {
            $this->scanExtendsDirectory($extendsThemeDir, $sourceModule, $basePath, 'theme', $result, $scannerConfig);
        }

        return $result;
    }

    private function scanExtendsDirectory(
        string $dir,
        string $sourceModule,
        string $basePath,
        string $type,
        array &$result,
        array $scannerConfig = [],
    ): void {
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $filePath = $file->getPathname();
                $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $filePath);
                $relativePath = str_replace('\\', '/', $relativePath);

                if (!str_starts_with(strtolower($relativePath), 'extends/')) {
                    $basePathTrimmed = rtrim($basePath, '/\\');
                    $relativePath = str_replace($basePathTrimmed . DIRECTORY_SEPARATOR, '', $filePath);
                    $relativePath = str_replace('\\', '/', $relativePath);
                    if (!str_starts_with(strtolower($relativePath), 'extends/')) {
                        continue;
                    }
                }

                $pathParts = explode('/', $relativePath);
                $parsedTarget = $this->parseExtensionTarget($pathParts, $type, $scannerConfig);
                if ($parsedTarget === null) {
                    continue;
                }

                $targetModule = $parsedTarget['target_module'];
                if (!isset($result[$targetModule])) {
                    $result[$targetModule] = [];
                }

                $className = null;
                if (str_ends_with($filePath, '.php')) {
                    $className = $this->extractClassName($filePath);
                }

                $extendInfo = [
                    'type' => $type,
                    'source_module' => $sourceModule,
                    'source_file' => $filePath,
                    'target_module' => $targetModule,
                    'file_path' => $parsedTarget['file_path'],
                    'relative_path' => $relativePath,
                    'class_name' => $className,
                ];

                foreach (($parsedTarget['metadata'] ?? []) as $metadataKey => $metadataValue) {
                    if (
                        \is_string($metadataKey)
                        && \preg_match('/^[a-z][a-z0-9_]*$/', $metadataKey) === 1
                        && !\array_key_exists($metadataKey, $extendInfo)
                    ) {
                        $extendInfo[$metadataKey] = $metadataValue;
                    }
                }

                if (isset($parsedTarget['theme_name'])) {
                    $extendInfo['theme_name'] = $parsedTarget['theme_name'];
                }

                $result[$targetModule][] = $extendInfo;
            }
        } catch (\Exception $e) {
            w_log_error("Failed to scan module extends: {$sourceModule}, error: " . $e->getMessage());
        }
    }

    private function parseExtensionTarget(array $pathParts, string $type, array $scannerConfig = []): ?array
    {
        if (strcasecmp($pathParts[0] ?? '', 'extends') !== 0 || strcasecmp($pathParts[1] ?? '', $type) !== 0) {
            return null;
        }

        if ($type === 'theme') {
            $themeName = $pathParts[2] ?? '';
            $targetIndex = 3;
            if ($themeName === '') {
                return null;
            }
        } else {
            $themeName = null;
            $targetIndex = 2;
        }

        $targetModulePath = $pathParts[$targetIndex] ?? '';
        if ($targetModulePath === '') {
            return null;
        }

        $nextIndex = $targetIndex + 1;

        if (($scannerConfig['target_shape'] ?? '') === 'nested_vendor_module') {
            $vendor = $pathParts[$nextIndex] ?? '';
            $module = $pathParts[$nextIndex + 1] ?? '';
            $filePath = implode('/', array_slice($pathParts, $nextIndex + 2));
            if (!$this->isModuleSegment($vendor) || !$this->isModuleSegment($module) || $filePath === '') {
                return null;
            }

            $result = [
                'target_module' => $vendor . '_' . $module,
                'file_path' => $filePath,
                'metadata' => \is_array($scannerConfig['metadata'] ?? null)
                    ? $scannerConfig['metadata']
                    : [],
            ];
            $typeField = $scannerConfig['type_field'] ?? null;
            if (\is_string($typeField) && \preg_match('/^[a-z][a-z0-9_]*$/', $typeField) === 1) {
                $result['metadata'][$typeField] = $type;
            }

            if ($themeName !== null) {
                $result['theme_name'] = $themeName;
            }

            return $result;
        }

        if ($this->isValidModuleName($targetModulePath)) {
            $filePath = implode('/', array_slice($pathParts, $nextIndex));
            if ($filePath === '') {
                return null;
            }

            $result = [
                'target_module' => $targetModulePath,
                'file_path' => $filePath,
                'is_sticker_extension' => false,
            ];

            if ($themeName !== null) {
                $result['theme_name'] = $themeName;
            }

            return $result;
        }

        $vendor = $targetModulePath;
        $module = $pathParts[$nextIndex] ?? '';
        $filePath = implode('/', array_slice($pathParts, $nextIndex + 1));
        if (!$this->isModuleSegment($vendor) || !$this->isModuleSegment($module) || $filePath === '') {
            return null;
        }

        $result = [
            'target_module' => $vendor . '_' . $module,
            'file_path' => $filePath,
            'is_sticker_extension' => false,
        ];

        if ($themeName !== null) {
            $result['theme_name'] = $themeName;
        }

        return $result;
    }

    private function isValidModuleName(string $moduleName): bool
    {
        return (bool)preg_match('/^[A-Za-z][A-Za-z0-9_]*_[A-Za-z][A-Za-z0-9_]*$/', $moduleName);
    }

    private function isModuleSegment(string $segment): bool
    {
        return (bool)preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $segment);
    }

    private function extractClassName(string $filePath): ?string
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath, false, null, 0, 4096);
        if ($content === false) {
            return null;
        }

        $namespace = null;
        $className = null;

        if (preg_match('/^\s*namespace\s+([^;]+)\s*;/m', $content, $matches)) {
            $namespace = trim($matches[1]);
        }

        if (preg_match('/^\s*(?:abstract\s+)?(?:final\s+)?(?:class|interface|trait)\s+(\w+)/m', $content, $matches)) {
            $className = trim($matches[1]);
        }

        if ($namespace && $className) {
            return $namespace . '\\' . $className;
        }

        return null;
    }
}
