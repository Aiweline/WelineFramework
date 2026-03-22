<?php

declare(strict_types=1);

namespace Weline\Websites\Service\AiWorkbench;

use Weline\Framework\Extends\ExtendsData;

class ExtensionPointReader
{
    public function hasExtensionPoint(string $moduleName, string $extensionPointName, bool $forceReload = false): bool
    {
        $moduleExtends = ExtendsData::getModuleExtends($moduleName, $forceReload);
        return isset($moduleExtends['extends'][$extensionPointName]);
    }

    public function getExtensionEntries(string $moduleName, string $extensionPointName, bool $forceReload = false): array
    {
        if (!$this->hasExtensionPoint($moduleName, $extensionPointName, $forceReload)) {
            return [];
        }

        $entries = [];
        $prefix = strtolower($extensionPointName) . '/';
        $extendedBy = ExtendsData::getExtendedBy($moduleName, $forceReload);

        foreach ($extendedBy as $extensions) {
            foreach ($extensions as $extension) {
                $filePath = str_replace('\\', '/', (string)($extension['file_path'] ?? ''));
                if ($filePath === '') {
                    continue;
                }

                if (str_starts_with(strtolower($filePath), $prefix)) {
                    $entries[] = $extension;
                }
            }
        }

        return $entries;
    }

    public function getRegistryFileMtime(): int
    {
        return ExtendsData::getRegistryFileMtime();
    }

    public function resolveClassName(array $extension): ?string
    {
        $className = trim((string)($extension['class_name'] ?? ''));
        if ($className !== '') {
            return $className;
        }

        $sourceFile = (string)($extension['source_file'] ?? '');
        if ($sourceFile === '' || !is_file($sourceFile) || !is_readable($sourceFile)) {
            return null;
        }

        $content = file_get_contents($sourceFile, false, null, 0, 4096);
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

        if ($namespace !== null && $className !== null) {
            return $namespace . '\\' . $className;
        }

        return null;
    }
}
