<?php

declare(strict_types=1);

namespace Weline\Framework\Compilation;

/**
 * Exact source/artifact fence for reusing one compiled framework generation.
 *
 * The manifest records content hashes, not mtimes. A same-size edit made in
 * the same filesystem timestamp tick therefore still invalidates the cached
 * generation. New/removed module directories are fenced separately because a
 * file list from the previous generation cannot describe a newly added module.
 * Validation runs only in the startup control plane, never in a request path.
 */
final class FrameworkCompileManifest
{
    public const FORMAT_VERSION = 1;

    public const FILE_NAME = 'compile_manifest.php';

    /**
     * Container remains the last promoted runtime artifact.
     */
    public const ARTIFACT_FILES = [
        'modules.php',
        'query_providers.php',
        'runtime_policy_providers.php',
        'template_cache_policies.php',
        'container.php',
    ];

    public const GENERATION_FILES = [
        'modules.php',
        'query_providers.php',
        'runtime_policy_providers.php',
        'template_cache_policies.php',
        self::FILE_NAME,
        'container.php',
    ];

    public function __construct(
        private readonly CompiledPhpArrayWriter $writer = new CompiledPhpArrayWriter(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function write(string $modulesRoot, string $outputDirectory): array
    {
        $modulesRoot = $this->canonicalDirectory($modulesRoot);
        $projectRoot = $this->projectRoot($modulesRoot);
        $outputDirectory = $this->canonicalDirectory($outputDirectory);
        $moduleCandidates = $this->moduleCandidates($modulesRoot);

        $inputs = [];
        foreach (\get_included_files() as $includedFile) {
            $canonical = $this->canonicalFile($includedFile);
            if ($canonical === null || !$this->isTrackedIncludedFile($canonical, $projectRoot, $modulesRoot)) {
                continue;
            }
            $inputs[$this->relativePath($canonical, $projectRoot)] = $this->hashFile($canonical);
        }

        foreach ($moduleCandidates as $moduleName) {
            $manifest = $modulesRoot . DIRECTORY_SEPARATOR . $moduleName
                . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'module.php';
            $canonical = $this->canonicalFile($manifest);
            if ($canonical === null) {
                throw new \RuntimeException("Compiled module manifest disappeared: {$manifest}.");
            }
            $inputs[$this->relativePath($canonical, $projectRoot)] = $this->hashFile($canonical);
        }

        foreach ($this->optionalControlInputs($projectRoot) as $relativePath => $path) {
            $canonical = $this->canonicalFile($path);
            $inputs[$relativePath] = $canonical === null ? null : $this->hashFile($canonical);
        }
        \ksort($inputs, SORT_STRING);

        $artifacts = [];
        foreach (self::ARTIFACT_FILES as $fileName) {
            $path = $outputDirectory . DIRECTORY_SEPARATOR . $fileName;
            $canonical = $this->canonicalFile($path);
            if ($canonical === null) {
                throw new \RuntimeException("Compiled framework artifact is missing: {$fileName}.");
            }
            $artifacts[$fileName] = $this->hashFile($canonical);
        }

        $manifest = [
            'format' => self::FORMAT_VERSION,
            'algorithm' => 'sha256',
            'project_root_digest' => \hash('sha256', $this->comparablePath($projectRoot)),
            'runtime' => [
                'php_version_id' => PHP_VERSION_ID,
                'php_int_size' => PHP_INT_SIZE,
                'os_family' => PHP_OS_FAMILY,
            ],
            'module_candidates' => $moduleCandidates,
            'inputs' => $inputs,
            'artifacts' => $artifacts,
        ];
        $manifest['digest'] = $this->payloadDigest($manifest);
        $this->writer->write($outputDirectory . DIRECTORY_SEPARATOR . self::FILE_NAME, $manifest);

        return $manifest;
    }

    /**
     * @return array{fresh:bool,reason:string,manifest:array<string,mixed>}
     */
    public function validate(string $modulesRoot, string $outputDirectory): array
    {
        try {
            $modulesRoot = $this->canonicalDirectory($modulesRoot);
            $projectRoot = $this->projectRoot($modulesRoot);
            $outputDirectory = $this->canonicalDirectory($outputDirectory);
            $manifestPath = $outputDirectory . DIRECTORY_SEPARATOR . self::FILE_NAME;
            $manifest = $this->readStableManifest($manifestPath);

            if ((int)($manifest['format'] ?? 0) !== self::FORMAT_VERSION
                || ($manifest['algorithm'] ?? null) !== 'sha256'
            ) {
                return $this->stale('manifest_format');
            }
            $expectedDigest = \strtolower(\trim((string)($manifest['digest'] ?? '')));
            $payload = $manifest;
            unset($payload['digest']);
            if (!$this->validDigest($expectedDigest)
                || !\hash_equals($expectedDigest, $this->payloadDigest($payload))
            ) {
                return $this->stale('manifest_digest');
            }
            if (!\hash_equals(
                (string)($manifest['project_root_digest'] ?? ''),
                \hash('sha256', $this->comparablePath($projectRoot)),
            )) {
                return $this->stale('project_root');
            }

            $runtime = $manifest['runtime'] ?? null;
            if (!\is_array($runtime)
                || (int)($runtime['php_version_id'] ?? 0) !== PHP_VERSION_ID
                || (int)($runtime['php_int_size'] ?? 0) !== PHP_INT_SIZE
                || (string)($runtime['os_family'] ?? '') !== PHP_OS_FAMILY
            ) {
                return $this->stale('runtime');
            }

            $expectedCandidates = $manifest['module_candidates'] ?? null;
            if (!\is_array($expectedCandidates)
                || !\array_is_list($expectedCandidates)
                || $expectedCandidates !== $this->moduleCandidates($modulesRoot)
            ) {
                return $this->stale('module_candidates');
            }

            $inputs = $manifest['inputs'] ?? null;
            if (!\is_array($inputs) || $inputs === []) {
                return $this->stale('inputs');
            }
            foreach ($inputs as $relativePath => $expectedHash) {
                if (!\is_string($relativePath) || !$this->validRelativePath($relativePath)) {
                    return $this->stale('input_path');
                }
                $path = $projectRoot . DIRECTORY_SEPARATOR
                    . \str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
                $canonical = $this->canonicalFile($path);
                if ($expectedHash === null) {
                    if ($canonical !== null) {
                        return $this->stale('optional_input_created');
                    }
                    continue;
                }
                if (!$this->validDigest($expectedHash)
                    || $canonical === null
                    || !$this->isWithin($canonical, $projectRoot)
                    || !\hash_equals($expectedHash, $this->hashFile($canonical))
                ) {
                    return $this->stale('input_changed');
                }
            }

            $artifacts = $manifest['artifacts'] ?? null;
            if (!\is_array($artifacts) || \array_keys($artifacts) !== self::ARTIFACT_FILES) {
                return $this->stale('artifacts');
            }
            foreach ($artifacts as $fileName => $expectedHash) {
                $path = $outputDirectory . DIRECTORY_SEPARATOR . $fileName;
                if (!$this->validDigest($expectedHash)
                    || !\is_file($path)
                    || !\hash_equals($expectedHash, $this->hashFile($path))
                ) {
                    return $this->stale('artifact_changed');
                }
            }

            return ['fresh' => true, 'reason' => 'content_match', 'manifest' => $manifest];
        } catch (\Throwable $throwable) {
            return $this->stale('validation_error:' . $throwable->getMessage());
        }
    }

    /**
     * @return array{fresh:false,reason:string,manifest:array<string,mixed>}
     */
    private function stale(string $reason): array
    {
        return ['fresh' => false, 'reason' => $reason, 'manifest' => []];
    }

    /**
     * @return array<string, mixed>
     */
    private function readStableManifest(string $path): array
    {
        if (!\is_file($path)) {
            throw new \RuntimeException('compile manifest missing');
        }
        $before = $this->hashFile($path);
        $manifest = require $path;
        $after = $this->hashFile($path);
        if (!\hash_equals($before, $after) || !\is_array($manifest)) {
            throw new \RuntimeException('compile manifest changed while loading');
        }
        return $manifest;
    }

    /**
     * Match ModuleManifestReader::readAll() discovery semantics.
     *
     * @return list<string>
     */
    private function moduleCandidates(string $modulesRoot): array
    {
        $modules = [];
        foreach (new \DirectoryIterator($modulesRoot) as $entry) {
            if (!$entry->isDir() || $entry->isDot()) {
                continue;
            }
            $path = $entry->getPathname();
            if (!\is_file($path . DIRECTORY_SEPARATOR . 'register.php')
                && !\is_file($path . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'module.php')
            ) {
                continue;
            }
            $modules[] = $entry->getFilename();
        }
        \sort($modules, SORT_STRING);
        return $modules;
    }

    /**
     * @return array<string, string>
     */
    private function optionalControlInputs(string $projectRoot): array
    {
        return [
            'generated/hooks.php' => $projectRoot . DIRECTORY_SEPARATOR . 'generated'
                . DIRECTORY_SEPARATOR . 'hooks.php',
            'generated/extends.php' => $projectRoot . DIRECTORY_SEPARATOR . 'generated'
                . DIRECTORY_SEPARATOR . 'extends.php',
            'composer.json' => $projectRoot . DIRECTORY_SEPARATOR . 'composer.json',
            'composer.lock' => $projectRoot . DIRECTORY_SEPARATOR . 'composer.lock',
            'app/bootstrap.php' => $projectRoot . DIRECTORY_SEPARATOR . 'app'
                . DIRECTORY_SEPARATOR . 'bootstrap.php',
        ];
    }

    private function isTrackedIncludedFile(string $file, string $projectRoot, string $modulesRoot): bool
    {
        if ($this->isWithin($file, $modulesRoot)) {
            return true;
        }
        return $this->isWithin(
            $file,
            $projectRoot . DIRECTORY_SEPARATOR . 'vendor',
        );
    }

    private function projectRoot(string $modulesRoot): string
    {
        $projectRoot = \dirname($modulesRoot, 3);
        return $this->canonicalDirectory($projectRoot);
    }

    private function canonicalDirectory(string $path): string
    {
        $canonical = \realpath($path);
        if (!\is_string($canonical) || !\is_dir($canonical)) {
            throw new \RuntimeException("Framework compile directory does not exist: {$path}.");
        }
        return \rtrim($canonical, '/\\');
    }

    private function canonicalFile(string $path): ?string
    {
        $canonical = \realpath($path);
        return \is_string($canonical) && \is_file($canonical) ? $canonical : null;
    }

    private function relativePath(string $path, string $root): string
    {
        if (!$this->isWithin($path, $root)) {
            throw new \RuntimeException('Framework compile input escaped the project root.');
        }
        return \str_replace('\\', '/', \substr($path, \strlen($root) + 1));
    }

    private function isWithin(string $path, string $root): bool
    {
        $path = $this->comparablePath(\rtrim($path, '/\\'));
        $root = $this->comparablePath(\rtrim($root, '/\\'));
        return $path === $root || \str_starts_with($path, $root . '/');
    }

    private function comparablePath(string $path): string
    {
        $path = \str_replace('\\', '/', $path);
        return DIRECTORY_SEPARATOR === '\\' ? \strtolower($path) : $path;
    }

    private function validRelativePath(string $path): bool
    {
        if ($path === '' || \str_starts_with($path, '/') || \str_contains($path, '\\')) {
            return false;
        }
        foreach (\explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    private function hashFile(string $path): string
    {
        $hash = @\hash_file('sha256', $path);
        if (!\is_string($hash) || !$this->validDigest($hash)) {
            throw new \RuntimeException("Unable to hash framework compile input: {$path}.");
        }
        return $hash;
    }

    private function validDigest(mixed $digest): bool
    {
        return \is_string($digest) && \preg_match('/^[a-f0-9]{64}$/D', $digest) === 1;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function payloadDigest(array $payload): string
    {
        return \hash('sha256', \serialize($payload));
    }
}
