<?php

declare(strict_types=1);

namespace Weline\Framework\Compilation;

final class FrameworkCompileManifest
{
    public const FILE_NAME = 'compile_manifest.php';

    private const FORMAT = 'weline-framework-compile-manifest/v2';

    /** @var list<string> */
    private const ARTIFACT_FILES = [
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

    /**
     * @param array<string, array{size:int,mtime:int,ctime:int,inode:int,sha256:string}> $previousSources
     * @return array{
     *     format:string,
     *     php_version_id:int,
     *     modules_root:string,
     *     hooks_sha256:string,
     *     capture_started_at:int,
     *     capture_finished_at:int,
     *     source_count:int,
     *     directory_count:int,
     *     source_fingerprint:string,
     *     sources:array<string, array{size:int,mtime:int,ctime:int,inode:int,sha256:string}>,
     *     directories:array<string, array{mtime:int,ctime:int,inode:int}>
     * }
     */
    public function capture(
        string $modulesRoot,
        string $hooksFile,
        array $previousSources = [],
    ): array {
        \clearstatcache(true, $modulesRoot);
        if (\is_link($modulesRoot)) {
            throw new \RuntimeException('Symlinked framework compiler modules root is not supported.');
        }
        $canonicalRoot = \realpath($modulesRoot);
        if (!\is_string($canonicalRoot) || !\is_dir($canonicalRoot)) {
            throw new \RuntimeException('Framework compiler modules root is unavailable.');
        }
        $canonicalRoot = $this->normalizePath($canonicalRoot);
        $captureStartedAt = \time();
        $sources = [];
        $rootStat = @\stat($canonicalRoot);
        if (!\is_array($rootStat)) {
            throw new \RuntimeException('Unable to stat framework compiler modules root.');
        }
        $directories = [
            '' => [
                'mtime' => (int)($rootStat['mtime'] ?? -1),
                'ctime' => (int)($rootStat['ctime'] ?? -1),
                'inode' => (int)($rootStat['ino'] ?? 0),
            ],
        ];

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $canonicalRoot,
                    \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO,
                ),
                \RecursiveIteratorIterator::SELF_FIRST,
                \RecursiveIteratorIterator::CATCH_GET_CHILD,
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo) {
                    continue;
                }
                if ($file->isLink()) {
                    throw new \RuntimeException(
                        'Symlinked framework compiler input is not supported: ' . $file->getPathname()
                    );
                }
                $path = $this->normalizePath($file->getPathname());
                $relative = \ltrim(\substr($path, \strlen($canonicalRoot)), '/');
                if ($file->isDir()) {
                    if (isset($directories[$relative])) {
                        throw new \RuntimeException(
                            'Duplicate normalized framework compiler directory: ' . $relative
                        );
                    }
                    $stat = @\stat($file->getPathname());
                    if (!\is_array($stat)) {
                        throw new \RuntimeException('Unable to stat framework compiler directory: ' . $relative);
                    }
                    $directories[$relative] = [
                        'mtime' => (int)($stat['mtime'] ?? -1),
                        'ctime' => (int)($stat['ctime'] ?? -1),
                        'inode' => (int)($stat['ino'] ?? 0),
                    ];
                    continue;
                }
                if (!$file->isFile()
                    || $relative === ''
                    || \strtolower($file->getExtension()) !== 'php'
                ) {
                    continue;
                }
                if (isset($sources[$relative])) {
                    throw new \RuntimeException(
                        'Duplicate normalized framework compiler input: ' . $relative
                    );
                }
                $stat = @\stat($file->getPathname());
                if (!\is_array($stat)) {
                    throw new \RuntimeException('Unable to stat framework compiler input: ' . $relative);
                }
                $state = [
                    'size' => (int)($stat['size'] ?? -1),
                    'mtime' => (int)($stat['mtime'] ?? -1),
                    'ctime' => (int)($stat['ctime'] ?? -1),
                    'inode' => (int)($stat['ino'] ?? 0),
                ];
                $previous = $previousSources[$relative] ?? null;
                $sha256 = '';
                if (\is_array($previous)
                    && (int)($previous['size'] ?? -2) === $state['size']
                    && (int)($previous['mtime'] ?? -2) === $state['mtime']
                    && (int)($previous['ctime'] ?? -2) === $state['ctime']
                    && (int)($previous['inode'] ?? -1) === $state['inode']
                    && \preg_match('/^[a-f0-9]{64}$/D', (string)($previous['sha256'] ?? '')) === 1
                ) {
                    $sha256 = (string)$previous['sha256'];
                } else {
                    $sha256 = (string)@\hash_file('sha256', $file->getPathname());
                    if (\preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
                        throw new \RuntimeException('Unable to hash framework compiler input: ' . $relative);
                    }
                }
                $sources[$relative] = $state + ['sha256' => $sha256];
            }
        } catch (\UnexpectedValueException $exception) {
            throw new \RuntimeException('Unable to scan framework compiler inputs.', 0, $exception);
        }

        \ksort($directories, \SORT_STRING);
        \ksort($sources, \SORT_STRING);
        $fingerprintContext = \hash_init('sha256');
        \hash_update($fingerprintContext, self::FORMAT . "\0" . \PHP_VERSION_ID . "\0" . $canonicalRoot . "\0");
        foreach ($sources as $relative => $source) {
            \hash_update($fingerprintContext, $relative . "\0" . $source['sha256'] . "\0");
        }

        $hooksSha256 = '';
        \clearstatcache(true, $hooksFile);
        if (\is_link($hooksFile)) {
            throw new \RuntimeException('Symlinked compiled hook registry is not supported.');
        }
        if (\is_file($hooksFile)) {
            $hooksSha256 = (string)@\hash_file('sha256', $hooksFile);
            if (\preg_match('/^[a-f0-9]{64}$/D', $hooksSha256) !== 1) {
                throw new \RuntimeException('Unable to hash compiled hook registry.');
            }
        }
        \hash_update($fingerprintContext, 'hooks' . "\0" . $hooksSha256 . "\0");

        return [
            'format' => self::FORMAT,
            'php_version_id' => \PHP_VERSION_ID,
            'modules_root' => $canonicalRoot,
            'hooks_sha256' => $hooksSha256,
            'capture_started_at' => $captureStartedAt,
            'capture_finished_at' => \time(),
            'source_count' => \count($sources),
            'directory_count' => \count($directories),
            'source_fingerprint' => \hash_final($fingerprintContext),
            'sources' => $sources,
            'directories' => $directories,
        ];
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public function sameSourceState(array $before, array $after): bool
    {
        $beforeFingerprint = (string)($before['source_fingerprint'] ?? '');
        $afterFingerprint = (string)($after['source_fingerprint'] ?? '');

        return \preg_match('/^[a-f0-9]{64}$/D', $beforeFingerprint) === 1
            && \preg_match('/^[a-f0-9]{64}$/D', $afterFingerprint) === 1
            && \hash_equals($beforeFingerprint, $afterFingerprint);
    }

    /**
     * @param array<string, mixed> $sourceState
     * @return array<string, mixed>
     */
    public function write(array $sourceState, string $outputDirectory): array
    {
        $artifacts = $this->artifactDigests($outputDirectory);
        $manifest = $sourceState + [
            'artifacts' => $artifacts,
            'compiled_at' => \time(),
        ];
        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn "
            . \var_export($manifest, true)
            . ";\n";
        (new AtomicCompiledFilePublisher())->publish(
            rtrim($outputDirectory, '/\\') . DS . self::FILE_NAME,
            $content,
        );

        return $manifest;
    }

    /**
     * Validate one already-published generation without recursively enumerating
     * the source tree. Every previously compiled PHP file is stat-checked, files
     * whose metadata changed are content-hashed, and directory metadata detects
     * additions/removals. The current hook registry and immutable artifacts are
     * part of the same generation proof.
     */
    public function isPublishedGenerationValid(
        string $modulesRoot,
        string $outputDirectory,
        string $hooksFile,
    ): bool {
        $outputDirectory = rtrim($outputDirectory, '/\\');
        $manifestFile = $outputDirectory . DS . self::FILE_NAME;
        \clearstatcache(true, $manifestFile);
        if (\is_link($manifestFile) || !\is_file($manifestFile)) {
            return false;
        }

        try {
            $loader = static fn (string $file): mixed => require $file;
            $manifest = $loader($manifestFile);
            \clearstatcache(true, $modulesRoot);
            if (\is_link($modulesRoot)) {
                return false;
            }
            $canonicalRoot = \realpath($modulesRoot);
            if (!\is_array($manifest)
                || !\is_string($canonicalRoot)
                || !\is_dir($canonicalRoot)
                || (string)($manifest['format'] ?? '') !== self::FORMAT
                || (int)($manifest['php_version_id'] ?? 0) !== \PHP_VERSION_ID
                || \preg_match('/^[a-f0-9]{64}$/D', (string)($manifest['source_fingerprint'] ?? '')) !== 1
                || (int)($manifest['capture_started_at'] ?? 0) <= 0
                || (int)($manifest['capture_finished_at'] ?? 0)
                    < (int)($manifest['capture_started_at'] ?? 0)
                || !\is_array($manifest['sources'] ?? null)
                || !\is_array($manifest['directories'] ?? null)
                || !\is_array($manifest['artifacts'] ?? null)
            ) {
                return false;
            }
            $canonicalRoot = $this->normalizePath($canonicalRoot);
            if (!\hash_equals((string)($manifest['modules_root'] ?? ''), $canonicalRoot)
                || !$this->sourceSnapshotMatches($canonicalRoot, $manifest)
            ) {
                return false;
            }

            $expectedHooks = (string)($manifest['hooks_sha256'] ?? '');
            if ($expectedHooks !== ''
                && \preg_match('/^[a-f0-9]{64}$/D', $expectedHooks) !== 1) {
                return false;
            }
            \clearstatcache(true, $hooksFile);
            if (\is_link($hooksFile)
                || (\file_exists($hooksFile) && !\is_file($hooksFile))) {
                return false;
            }
            $actualHooks = \is_file($hooksFile)
                ? (string)@\hash_file('sha256', $hooksFile)
                : '';
            if (($actualHooks !== '' && \preg_match('/^[a-f0-9]{64}$/D', $actualHooks) !== 1)
                || !\hash_equals($expectedHooks, $actualHooks)
            ) {
                return false;
            }

            $actualArtifacts = $this->artifactDigests($outputDirectory);
            foreach (self::ARTIFACT_FILES as $fileName) {
                $expected = (string)($manifest['artifacts'][$fileName] ?? '');
                $actual = (string)($actualArtifacts[$fileName] ?? '');
                if (\preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1
                    || \preg_match('/^[a-f0-9]{64}$/D', $actual) !== 1
                    || !\hash_equals($expected, $actual)
                ) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function isFresh(string $modulesRoot, string $outputDirectory): bool
    {
        $outputDirectory = rtrim($outputDirectory, '/\\');
        $manifestFile = $outputDirectory . DS . self::FILE_NAME;
        if (!\is_file($manifestFile)) {
            return false;
        }

        try {
            $loader = static fn (string $file): mixed => require $file;
            $manifest = $loader($manifestFile);
            if (!\is_array($manifest)
                || (string)($manifest['format'] ?? '') !== self::FORMAT
                || (int)($manifest['php_version_id'] ?? 0) !== \PHP_VERSION_ID
                || \preg_match('/^[a-f0-9]{64}$/D', (string)($manifest['source_fingerprint'] ?? '')) !== 1
                || !\is_array($manifest['sources'] ?? null)
                || !\is_array($manifest['directories'] ?? null)
                || (int)($manifest['directory_count'] ?? -1)
                    !== \count($manifest['directories'] ?? [])
            ) {
                return false;
            }

            $current = $this->capture(
                $modulesRoot,
                \dirname($outputDirectory) . DS . 'hooks.php',
                $manifest['sources'],
            );
            if (!$this->sameSourceState($manifest, $current)) {
                return false;
            }

            $expectedArtifacts = $manifest['artifacts'] ?? null;
            if (!\is_array($expectedArtifacts)) {
                return false;
            }
            $actualArtifacts = $this->artifactDigests($outputDirectory);
            foreach (self::ARTIFACT_FILES as $fileName) {
                $expected = (string)($expectedArtifacts[$fileName] ?? '');
                $actual = (string)($actualArtifacts[$fileName] ?? '');
                if (\preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1
                    || \preg_match('/^[a-f0-9]{64}$/D', $actual) !== 1
                    || !\hash_equals($expected, $actual)
                ) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function sourceSnapshotMatches(string $canonicalRoot, array $manifest): bool
    {
        $sources = $manifest['sources'] ?? null;
        $directories = $manifest['directories'] ?? null;
        $captureStartedAt = (int)($manifest['capture_started_at'] ?? 0);
        $captureFinishedAt = (int)($manifest['capture_finished_at'] ?? 0);
        if (!\is_array($sources)
            || !\is_array($directories)
            || !isset($directories[''])
            || $captureStartedAt <= 0
            || $captureFinishedAt < $captureStartedAt
            || (int)($manifest['source_count'] ?? -1) !== \count($sources)
            || (int)($manifest['directory_count'] ?? -1) !== \count($directories)
        ) {
            return false;
        }

        foreach ($directories as $relative => $expected) {
            if (!\is_string($relative) || !\is_array($expected)) {
                return false;
            }
            $path = $this->resolveManifestPath($canonicalRoot, $relative, true);
            if ($path === null) {
                return false;
            }
            \clearstatcache(true, $path);
            $stat = @\stat($path);
            $mtime = (int)($stat['mtime'] ?? -1);
            if (!\is_dir($path)
                || !\is_array($stat)
                || $this->isRacyTimestamp($mtime, $captureStartedAt, $captureFinishedAt)
                || (int)($expected['mtime'] ?? -2) !== $mtime
                || (int)($expected['ctime'] ?? -2) !== (int)($stat['ctime'] ?? -1)
                || (int)($expected['inode'] ?? -1) !== (int)($stat['ino'] ?? 0)
            ) {
                return false;
            }
        }

        foreach ($sources as $relative => $expected) {
            if (!\is_string($relative) || !\is_array($expected)) {
                return false;
            }
            $path = $this->resolveManifestPath($canonicalRoot, $relative, false);
            if ($path === null) {
                return false;
            }
            \clearstatcache(true, $path);
            if (!\is_file($path)
                || \strtolower((string)\pathinfo($path, \PATHINFO_EXTENSION)) !== 'php'
            ) {
                return false;
            }
            $stat = @\stat($path);
            $expectedHash = (string)($expected['sha256'] ?? '');
            if (!\is_array($stat)
                || \preg_match('/^[a-f0-9]{64}$/D', $expectedHash) !== 1
            ) {
                return false;
            }
            $mtime = (int)($stat['mtime'] ?? -1);
            $ctime = (int)($stat['ctime'] ?? -1);
            $statMatches = (int)($expected['size'] ?? -2) === (int)($stat['size'] ?? -1)
                && (int)($expected['mtime'] ?? -2) === $mtime
                && (int)($expected['ctime'] ?? -2) === $ctime
                && (int)($expected['inode'] ?? -1) === (int)($stat['ino'] ?? 0);
            $racy = $this->isRacyTimestamp($mtime, $captureStartedAt, $captureFinishedAt)
                || $this->isRacyTimestamp($ctime, $captureStartedAt, $captureFinishedAt);
            if ($statMatches && !$racy) {
                continue;
            }
            $actualHash = (string)@\hash_file('sha256', $path);
            if (\preg_match('/^[a-f0-9]{64}$/D', $actualHash) !== 1
                || !\hash_equals($expectedHash, $actualHash)
            ) {
                return false;
            }
        }

        return true;
    }

    private function isRacyTimestamp(int $timestamp, int $captureStartedAt, int $captureFinishedAt): bool
    {
        return $timestamp >= ($captureStartedAt - 1)
            && $timestamp <= ($captureFinishedAt + 1);
    }

    private function resolveManifestPath(
        string $canonicalRoot,
        string $relative,
        bool $allowRoot,
    ): ?string {
        if ($relative === '') {
            return $allowRoot ? $canonicalRoot : null;
        }
        if (\str_starts_with($relative, '/')
            || \preg_match('/^[A-Za-z]:/', $relative) === 1
            || \str_contains($relative, "\0")
            || \str_contains($relative, '\\')
            || \preg_match('#(?:^|/)\.\.(?:/|$)#D', $relative) === 1
        ) {
            return null;
        }

        $candidate = $canonicalRoot . DS . \str_replace('/', DS, $relative);
        \clearstatcache(true, $candidate);
        if (\is_link($candidate)) {
            return null;
        }
        $resolved = \realpath($candidate);
        if (!\is_string($resolved)) {
            return null;
        }
        $resolved = $this->normalizePath($resolved);
        if (!\hash_equals($canonicalRoot, $resolved)
            && !\str_starts_with($resolved, $canonicalRoot . '/')
        ) {
            return null;
        }

        return $resolved;
    }

    /**
     * @return array<string, string>
     */
    private function artifactDigests(string $outputDirectory): array
    {
        $outputDirectory = rtrim($outputDirectory, '/\\');
        $digests = [];
        foreach (self::ARTIFACT_FILES as $fileName) {
            $path = $outputDirectory . DS . $fileName;
            \clearstatcache(true, $path);
            if (\is_link($path) || !\is_file($path)) {
                throw new \RuntimeException('Compiled framework artifact is missing: ' . $fileName);
            }
            $digest = (string)@\hash_file('sha256', $path);
            if (\preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                throw new \RuntimeException('Unable to hash compiled framework artifact: ' . $fileName);
            }
            $digests[$fileName] = $digest;
        }

        return $digests;
    }

    private function normalizePath(string $path): string
    {
        $normalized = \str_replace('\\', '/', \rtrim($path, '/\\'));

        return \PHP_OS_FAMILY === 'Windows' ? \strtolower($normalized) : $normalized;
    }
}
