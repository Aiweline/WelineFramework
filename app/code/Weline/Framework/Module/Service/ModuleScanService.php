<?php

declare(strict_types=1);

namespace Weline\Framework\Module\Service;

use Weline\Framework\System\File\Scan;

class ModuleScanService
{
    public function __construct(
        private readonly Scan $scan
    ) {
    }

    public function resolveDirectory(string $basePath, string $relativePath): ?string
    {
        $path = $this->buildPath($basePath, $relativePath);
        return is_dir($path) ? $path : null;
    }

    public function resolveFile(string $basePath, string $relativePath): ?string
    {
        $path = $this->buildPath($basePath, $relativePath);
        return is_file($path) ? $path : null;
    }

    public function scanDirTreeIfExists(string $basePath, string $relativePath, int $level = 0): array
    {
        $path = $this->resolveDirectory($basePath, $relativePath);
        if ($path === null) {
            return [];
        }

        return $this->scan->scanDirTree($path, $level);
    }

    public function globPhpClassesInDirectory(
        string $basePath,
        string $relativePath,
        string $namespacePath,
        bool $removeExt = true,
        bool $classPath = true,
        ?string $composerDir = null
    ): array {
        $path = $this->resolveDirectory($basePath, $relativePath);
        if ($path === null) {
            return [];
        }

        $files = [];
        $composerRoot = $composerDir ?? $basePath;
        $this->scan->globFile(
            $path . DIRECTORY_SEPARATOR . '*',
            $files,
            '.php',
            rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR,
            $namespacePath . '\\',
            $removeExt,
            $classPath,
            $composerRoot
        );

        return $files;
    }

    public function buildPath(string $basePath, string $relativePath): string
    {
        $basePath = rtrim($basePath, '/\\');
        $relativePath = ltrim($relativePath, '/\\');
        return $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
    }
}
