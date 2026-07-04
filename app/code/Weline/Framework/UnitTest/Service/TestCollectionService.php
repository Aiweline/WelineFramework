<?php

declare(strict_types=1);

namespace Weline\Framework\UnitTest\Service;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Weline\Framework\App\Env;

class TestCollectionService
{
    private const PHP_TEST_SUFFIX = 'Test.php';
    private const E2E_TEST_SUFFIX = '.spec.js';

    public function collect(?string $type = null, ?string $moduleFilter = null): array
    {
        $type = $this->normalizeType($type);
        $modules = $this->resolveModules($moduleFilter);
        $result = [
            'generated_at' => date('c'),
            'type' => $type ?? 'all',
            'total_modules' => 0,
            'total_tests' => 0,
            'modules' => [],
            'all' => [
                'unit' => [],
                'integration' => [],
                'php_e2e' => [],
                'phpunit' => [],
                'e2e' => [],
            ],
        ];

        foreach ($modules as $moduleName => $module) {
            $basePath = $this->normalizeDirectory((string)($module['base_path'] ?? ''));
            if ($basePath === null) {
                continue;
            }

            $moduleTests = $this->collectModuleTests($moduleName, $basePath);
            if ($type !== null) {
                $moduleTests = $this->filterModuleTests($moduleTests, $type);
            }

            $count = $this->countModuleTests($moduleTests);
            if ($count === 0) {
                continue;
            }

            $node = [
                'module' => $moduleName,
                'base_path' => $this->toProjectRelativePath($basePath),
                'test_roots' => array_map([$this, 'toProjectRelativePath'], $this->resolveTestRoots($moduleName, $basePath)),
                'tests' => $moduleTests,
                'counts' => array_map('count', $moduleTests),
                'count' => $count,
            ];

            $result['modules'][$moduleName] = $node;
            foreach ($moduleTests as $testType => $files) {
                if (!isset($result['all'][$testType])) {
                    $result['all'][$testType] = [];
                }
                $result['all'][$testType] = array_merge($result['all'][$testType], $files);
            }
        }

        foreach ($result['all'] as $testType => $files) {
            $result['all'][$testType] = $this->uniqueSorted($files);
        }

        $result['total_modules'] = count($result['modules']);
        $result['total_tests'] = $type === null
            ? count($this->uniqueSorted(array_merge(...array_values($result['all']))))
            : count($result['all'][$type] ?? []);

        return $result;
    }

    public function collectE2eManifest(?string $moduleFilter = null): array
    {
        $collection = $this->collect('e2e', $moduleFilter);
        $modules = [];
        $allFiles = [];

        foreach ($collection['modules'] as $moduleName => $module) {
            $files = $module['tests']['e2e'] ?? [];
            if (!is_array($files) || $files === []) {
                continue;
            }

            $modules[$moduleName] = [
                'module' => $moduleName,
                'base_path' => $module['base_path'] ?? '',
                'test_path' => $this->commonTestPath($files),
                'test_files' => array_values($files),
                'count' => count($files),
                'autodiscovered' => true,
            ];
            $allFiles = array_merge($allFiles, $files);
        }

        $allFiles = $this->uniqueSorted($allFiles);

        return [
            'generated_at' => date('c'),
            'source' => 'Weline_Framework_Test',
            'total_modules' => count($modules),
            'total_tests' => count($allFiles),
            'modules' => $modules,
            'all_test_files' => $allFiles,
        ];
    }

    public function writeJson(array $manifest, string $file): bool
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return false;
        }

        return @file_put_contents($file, $json . PHP_EOL) !== false;
    }

    /**
     * @return string[]
     */
    public function getPhpUnitFiles(?string $moduleFilter = null): array
    {
        $collection = $this->collect(null, $moduleFilter);
        $files = [];
        foreach ($collection['modules'] as $module) {
            $phpunitFiles = $module['tests']['phpunit'] ?? [];
            if (is_array($phpunitFiles)) {
                $files = array_merge($files, $phpunitFiles);
            }
        }

        return $this->uniqueSorted($files);
    }

    public function resolveModuleName(string $moduleFilter): ?string
    {
        $moduleFilter = trim($moduleFilter);
        if ($moduleFilter === '') {
            return null;
        }

        $modules = Env::getInstance()->getActiveModules();
        if (isset($modules[$moduleFilter])) {
            return $moduleFilter;
        }

        foreach ($modules as $moduleName => $module) {
            if ($moduleName === $moduleFilter) {
                return $moduleName;
            }
        }

        foreach ($modules as $moduleName => $module) {
            if (str_contains($moduleName, $moduleFilter) || str_contains($moduleFilter, $moduleName)) {
                return $moduleName;
            }
        }

        return null;
    }

    public function getModuleBasePath(string $moduleFilter): ?string
    {
        $moduleName = $this->resolveModuleName($moduleFilter);
        if ($moduleName === null) {
            return null;
        }

        $modules = Env::getInstance()->getActiveModules();
        return $this->normalizeDirectory((string)($modules[$moduleName]['base_path'] ?? ''));
    }

    public function findPhpTestFile(string $fileName, ?string $moduleFilter = null): ?string
    {
        $fileName = trim($fileName);
        if ($fileName === '') {
            return null;
        }

        $actualFileName = str_contains($fileName, '::') ? explode('::', $fileName, 2)[0] : $fileName;
        $actualFileName = trim($actualFileName, " \t\n\r\0\x0B\"'");
        if ($actualFileName === '') {
            return null;
        }

        $direct = $this->resolveDirectFile($actualFileName);
        if ($direct !== null) {
            return $direct;
        }

        $normalizedInput = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $actualFileName);
        $inputBase = basename($normalizedInput, '.php');
        $files = $this->getPhpUnitFiles($moduleFilter);

        foreach ($files as $file) {
            $absolute = $this->toAbsolutePath($file);
            $relative = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $file);
            $basename = basename($file, '.php');

            if ($absolute !== null && $this->isSamePath($absolute, $actualFileName)) {
                return $absolute;
            }
            if ($relative === $normalizedInput || str_ends_with($relative, DIRECTORY_SEPARATOR . $normalizedInput)) {
                return $absolute ?? $file;
            }
            if ($this->isTestFileNameMatch($basename, $inputBase)) {
                return $absolute ?? $file;
            }
        }

        return null;
    }

    private function collectModuleTests(string $moduleName, string $basePath): array
    {
        $tests = [
            'unit' => [],
            'integration' => [],
            'php_e2e' => [],
            'phpunit' => [],
            'e2e' => [],
        ];

        foreach ($this->resolveTestRoots($moduleName, $basePath) as $testRoot) {
            foreach ($this->collectFiles($testRoot, self::PHP_TEST_SUFFIX) as $file) {
                $relativeFile = $this->toProjectRelativePath($file);
                $tests['phpunit'][] = $relativeFile;
                if ($this->pathHasSegment($file, ['Integration', 'integration'])) {
                    $tests['integration'][] = $relativeFile;
                } elseif ($this->pathHasSegment($file, ['E2E', 'e2e'])) {
                    $tests['php_e2e'][] = $relativeFile;
                } else {
                    $tests['unit'][] = $relativeFile;
                }
            }

            foreach ($this->resolveE2eRoots($testRoot) as $e2eRoot) {
                foreach ($this->collectFiles($e2eRoot, self::E2E_TEST_SUFFIX) as $file) {
                    $tests['e2e'][] = $this->toProjectRelativePath($file);
                }
            }
        }

        foreach ($tests as $testType => $files) {
            $tests[$testType] = $this->uniqueSorted($files);
        }

        return $tests;
    }

    /**
     * @return string[]
     */
    private function resolveTestRoots(string $moduleName, string $basePath): array
    {
        $roots = [];
        foreach (['test', 'Test', 'tests', 'Tests', 'UnitTest'] as $dir) {
            $roots[] = $basePath . $dir;
        }

        $existing = [];
        foreach ($roots as $root) {
            $normalized = $this->normalizeDirectory($root);
            if ($normalized !== null) {
                $existing[] = rtrim($normalized, "\\/");
            }
        }

        return $this->uniquePathsSorted($existing);
    }

    /**
     * @return string[]
     */
    private function resolveE2eRoots(string $testRoot): array
    {
        $roots = [];
        foreach (['e2e', 'E2E'] as $dir) {
            $candidate = rtrim($testRoot, "\\/") . DIRECTORY_SEPARATOR . $dir;
            if (is_dir($candidate)) {
                $roots[] = $candidate;
            }
        }

        return $this->uniquePathsSorted($roots);
    }

    /**
     * @return string[]
     */
    private function collectFiles(string $dir, string $suffix): array
    {
        $dir = rtrim($dir, "\\/");
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            if (str_ends_with($file->getFilename(), $suffix)) {
                $files[] = $path;
            }
        }

        return $this->uniquePathsSorted($files);
    }

    private function filterModuleTests(array $moduleTests, string $type): array
    {
        return [
            $type => $moduleTests[$type] ?? [],
        ];
    }

    private function countModuleTests(array $moduleTests): int
    {
        $files = [];
        foreach ($moduleTests as $items) {
            if (is_array($items)) {
                $files = array_merge($files, $items);
            }
        }

        return count($this->uniqueSorted($files));
    }

    private function normalizeType(?string $type): ?string
    {
        $type = strtolower(trim((string)$type));
        return match ($type) {
            '', 'all' => null,
            'php' => 'phpunit',
            'phpunit', 'unit', 'integration', 'php_e2e', 'e2e' => $type,
            default => $type,
        };
    }

    private function resolveModules(?string $moduleFilter): array
    {
        $modules = Env::getInstance()->getActiveModules();
        $moduleFilter = trim((string)$moduleFilter);
        if ($moduleFilter === '') {
            return $modules;
        }

        $moduleName = $this->resolveModuleName($moduleFilter);
        if ($moduleName === null || !isset($modules[$moduleName])) {
            return [];
        }

        return [$moduleName => $modules[$moduleName]];
    }

    private function commonTestPath(array $files): string
    {
        if ($files === []) {
            return '';
        }

        $first = dirname((string)$files[0]);
        foreach ($files as $file) {
            $dir = dirname((string)$file);
            while ($first !== '.' && $first !== '' && !str_starts_with($dir . '/', $first . '/')) {
                $first = dirname($first);
            }
        }

        return $first === '.' ? dirname((string)$files[0]) : $first;
    }

    private function normalizeDirectory(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        if (!str_starts_with($path, DIRECTORY_SEPARATOR) && !preg_match('/^[A-Za-z]:\\\\/', $path)) {
            $path = BP . ltrim($path, DIRECTORY_SEPARATOR);
        }

        $path = rtrim($path, "\\/") . DIRECTORY_SEPARATOR;
        return is_dir($path) ? $path : null;
    }

    private function toProjectRelativePath(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $base = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, BP), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', rtrim($path, "\\/"));
    }

    private function toAbsolutePath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if (is_file($path)) {
            return $path;
        }

        $absolute = BP . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
        return is_file($absolute) ? $absolute : null;
    }

    private function resolveDirectFile(string $file): ?string
    {
        if (is_file($file)) {
            return $file;
        }

        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file);
        $absolute = BP . ltrim($normalized, DIRECTORY_SEPARATOR);
        return is_file($absolute) ? $absolute : null;
    }

    private function isSamePath(string $absolutePath, string $input): bool
    {
        $normalizedInput = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $input);
        if (is_file($normalizedInput)) {
            return realpath($absolutePath) === realpath($normalizedInput);
        }

        $absoluteInput = BP . ltrim($normalizedInput, DIRECTORY_SEPARATOR);
        return is_file($absoluteInput) && realpath($absolutePath) === realpath($absoluteInput);
    }

    private function isTestFileNameMatch(string $basename, string $actualFileName): bool
    {
        $actualFileName = preg_replace('/\.php$/i', '', $actualFileName) ?? $actualFileName;
        if ($basename === $actualFileName) {
            return true;
        }
        if ($basename === $actualFileName . 'Test') {
            return true;
        }
        if (strlen($actualFileName) >= 3 && str_starts_with($basename, $actualFileName) && str_ends_with($basename, 'Test')) {
            return true;
        }

        return false;
    }

    private function pathHasSegment(string $path, array $segments): bool
    {
        $parts = preg_split('#[\\\\/]#', $path) ?: [];
        foreach ($parts as $part) {
            if (in_array($part, $segments, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $items
     * @return string[]
     */
    private function uniqueSorted(array $items): array
    {
        $items = array_values(array_unique(array_filter($items, static fn($item): bool => is_string($item) && $item !== '')));
        sort($items, SORT_STRING);
        return $items;
    }

    /**
     * Deduplicate path aliases such as test/Test on case-insensitive filesystems.
     *
     * @param string[] $items
     * @return string[]
     */
    private function uniquePathsSorted(array $items): array
    {
        $unique = [];
        foreach ($items as $item) {
            if (!is_string($item) || $item === '') {
                continue;
            }

            $real = realpath($item);
            $key = strtolower($real !== false ? $real : str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $item));
            if (!isset($unique[$key])) {
                $unique[$key] = $item;
            }
        }

        $items = array_values($unique);
        sort($items, SORT_STRING);
        return $items;
    }
}
