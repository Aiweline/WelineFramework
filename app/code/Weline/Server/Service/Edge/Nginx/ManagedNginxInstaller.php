<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

use Weline\Server\Service\Edge\Gateway\GatewayBoundedCommandRunner;
use Weline\Server\Service\Edge\Gateway\GatewayBoundedTreeWalker;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;

/**
 * Downloads and installs pinned Nginx into the project's isolated local install root.
 *
 * Platform matrix:
 * - Darwin/Linux: build from official source tarball into project prefix
 * - Windows: extract official nginx.zip (nginx.exe at install root)
 *
 * Installation is explicit. Ordinary server:start remains pure PHP and never
 * downloads or compiles Nginx. server:nginx:install performs the opt-in install
 * or force reinstall.
 */
final class ManagedNginxInstaller
{
    private const MAX_DOWNLOAD_BYTES = 512 * 1024 * 1024;
    private const MAX_TREE_BYTES = 512 * 1024 * 1024;
    private const MAX_TREE_ENTRIES = 8192;
    private const MAX_TREE_DEPTH = 64;
    private const MAX_CPUINFO_BYTES = 8 * 1024 * 1024;
    private const MAX_PARALLEL_JOBS = 256;
    private const MAX_DOWNLOAD_REDIRECTS = 5;
    private const DOWNLOAD_DEADLINE_SECONDS = 300.0;
    private const DOWNLOAD_READ_TIMEOUT_SECONDS = 30.0;
    private const DOWNLOAD_HOST = 'nginx.org';
    private const TOOL_PROBE_TIMEOUT_SECONDS = 30.0;
    private const EXTRACT_TIMEOUT_SECONDS = 300.0;
    private const CONFIGURE_TIMEOUT_SECONDS = 600.0;
    private const BUILD_TIMEOUT_SECONDS = 1800.0;
    private const INSTALL_TIMEOUT_SECONDS = 600.0;
    private const INSTALL_LOCK_TIMEOUT_SECONDS = 30.0;
    private const MAX_INSTALL_CANDIDATE_RECOVERY_ENTRIES = 256;

    public const VERSION = '1.30.4';

    public const SOURCE_URL = 'https://nginx.org/download/nginx-1.30.4.tar.gz';

    public const SOURCE_SHA256 = '4261dc90e9e47c1c4041276e9aaa3d48ebe2e664f728e14fa95ae6c67d57a08b';

    public const WINDOWS_ZIP_URL = 'https://nginx.org/download/nginx-1.30.4.zip';

    /** Official Windows zip SHA-256 (nginx.org release package). */
    public const WINDOWS_ZIP_SHA256 = '159294214d403f34f0bb4ae598801ab1f6a0d8c8da707f8f08748e294a222a01';

    public function __construct(private readonly ManagedNginxPaths $paths = new ManagedNginxPaths())
    {
    }

    /**
     * @return array{ok:bool,message:string,manifest?:array<string,mixed>,platform?:string}
     */
    public function ensureInstalled(bool $force = false): array
    {
        try {
            return $this->withInstallationLock(
                fn(): array => $this->ensureInstalledLocked($force),
            );
        } catch (\Throwable $throwable) {
            return [
                'ok' => false,
                'message' => $throwable->getMessage(),
                'platform' => \PHP_OS_FAMILY,
            ];
        }
    }

    /**
     * @return array{ok:bool,message:string,manifest?:array<string,mixed>,platform?:string}
     */
    private function ensureInstalledLocked(bool $force): array
    {
        try {
            $this->cleanupManifestAtomicWriteRecoveryBackups();
            $this->reconcileInterruptedInstallPublication();
            $this->reconcileInterruptedInstallCandidates();
        } catch (\Throwable $throwable) {
            return [
                'ok' => false,
                'message' => 'Managed nginx interrupted-publication recovery failed closed: '
                    . $throwable->getMessage(),
                'platform' => \PHP_OS_FAMILY,
            ];
        }
        if (!$force && $this->paths->isInstalled()) {
            $manifest = $this->readManifest();
            if ($this->manifestMatches($manifest)) {
                return [
                    'ok' => true,
                    'message' => 'managed nginx already installed',
                    'manifest' => $manifest ?? [],
                    'platform' => \PHP_OS_FAMILY,
                ];
            }
            if ($this->legacyManifestMatches($manifest)) {
                $this->writeManifest($this->migratedLegacyManifest($manifest ?? []));
                $migrated = $this->readManifest();
                if (!$this->manifestMatches($migrated)) {
                    throw new \RuntimeException(
                        'Managed nginx legacy manifest migration did not produce a valid pinned manifest.',
                    );
                }
                $this->reconcileInterruptedInstallCandidates();

                return [
                    'ok' => true,
                    'message' => 'managed nginx already installed; legacy manifest upgraded in place',
                    'manifest' => $migrated ?? [],
                    'platform' => \PHP_OS_FAMILY,
                ];
            }
            return [
                'ok' => false,
                'message' => 'managed nginx binary exists but its pinned manifest does not match '
                    . self::VERSION
                    . '; stop the managed edge, then run php bin/w server:nginx:install --force',
                'platform' => \PHP_OS_FAMILY,
            ];
        }

        return match (\PHP_OS_FAMILY) {
            'Windows' => $this->installWindows($force),
            'Darwin', 'Linux' => $this->installUnixFromSource($force),
            default => [
                'ok' => false,
                'message' => 'managed nginx install unsupported on OS family ' . \PHP_OS_FAMILY
                    . ' (supported: Darwin, Linux, Windows)',
                'platform' => \PHP_OS_FAMILY,
            ],
        };
    }

    /**
     * @param callable():array{ok:bool,message:string,manifest?:array<string,mixed>,platform?:string} $operation
     * @return array{ok:bool,message:string,manifest?:array<string,mixed>,platform?:string}
     */
    private function withInstallationLock(callable $operation): array
    {
        $lockFile = \PHP_OS_FAMILY === 'Windows'
            ? \dirname($this->paths->installRoot()) . DIRECTORY_SEPARATOR . 'managed-nginx.install.lock'
            : $this->paths->projectRoot() . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR
                . 'server' . DIRECTORY_SEPARATOR . 'managed-nginx.install.lock';
        $directory = \dirname($lockFile);
        if (!\is_dir($directory)
            && !@\mkdir($directory, 0700, true)
            && !\is_dir($directory)
        ) {
            throw new \RuntimeException('Unable to create managed nginx installer lock directory.');
        }
        $directoryStatus = @\lstat($directory);
        if (!\is_array($directoryStatus)
            || \is_link($directory)
            || ((((int)($directoryStatus['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException('Managed nginx installer lock directory is unsafe.');
        }
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $lockFile,
            static fn(): array => $operation(),
            null,
            self::INSTALL_LOCK_TIMEOUT_SECONDS,
        );
    }

    /**
     * @return array{installed:bool,manifest_matches:bool,expected_version:string,manifest:array<string,mixed>|null}
     */
    public function installationStatus(): array
    {
        return [
            'installed' => $this->paths->isInstalled(),
            'manifest_matches' => $this->paths->isInstalled() && $this->manifestMatches(),
            'expected_version' => self::VERSION,
            'manifest' => $this->readManifest(),
        ];
    }

    /** @param array<string,mixed>|null $manifest */
    private function manifestMatches(?array $manifest = null): bool
    {
        $manifest ??= $this->readManifest();
        if ($manifest === null) {
            return false;
        }
        if (!\hash_equals(self::VERSION, (string)($manifest['version'] ?? ''))) {
            return false;
        }
        if (!\hash_equals(\PHP_OS_FAMILY, (string)($manifest['platform'] ?? ''))) {
            return false;
        }
        if (($manifest['schema_version'] ?? null) !== 2
            || !\is_string($manifest['role'] ?? null)
            || !\hash_equals('legacy-project-nginx', (string)$manifest['role'])
            || !\is_string($manifest['implementation_level'] ?? null)
            || !\hash_equals('nginx-runtime-v2', (string)$manifest['implementation_level'])
            || !\is_string($manifest['binary'] ?? null)
            || !$this->sameManifestPath((string)$manifest['binary'], $this->paths->binary())
            || !\is_string($manifest['prefix'] ?? null)
            || !$this->sameManifestPath((string)$manifest['prefix'], $this->paths->installRoot())
        ) {
            return false;
        }
        $runtimeGeneration = $manifest['runtime_generation'] ?? null;
        if (!\is_string($runtimeGeneration)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $runtimeGeneration) !== 1
        ) {
            return false;
        }
        $generationSource = $manifest;
        unset($generationSource['runtime_generation']);
        try {
            $calculatedGeneration = \hash(
                'sha256',
                \json_encode(
                    $this->canonicalManifest($generationSource),
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ),
            );
        } catch (\Throwable) {
            return false;
        }
        if (!\hash_equals($runtimeGeneration, $calculatedGeneration)) {
            return false;
        }
        if (\PHP_OS_FAMILY !== 'Windows') {
            $buildFlags = \is_array($manifest['build_flags'] ?? null)
                ? $manifest['build_flags']
                : [];
            if (($buildFlags['has_pcre'] ?? false) !== true
                || ($buildFlags['without_rewrite'] ?? true) !== false
            ) {
                return false;
            }
        }
        $binaryArchitecture = $this->binaryArchitecture($this->paths->binary());
        if ($binaryArchitecture === ''
            || !\hash_equals($binaryArchitecture, (string)($manifest['arch'] ?? ''))
            || (\PHP_OS_FAMILY !== 'Windows'
                && !\hash_equals($binaryArchitecture, $this->normalizeArchitecture((string)\php_uname('m'))))
        ) {
            return false;
        }
        $expected = \PHP_OS_FAMILY === 'Windows' ? self::WINDOWS_ZIP_SHA256 : self::SOURCE_SHA256;
        $actual = (string)($manifest['artifact_sha256'] ?? $manifest['source_sha256'] ?? '');
        $expectedBinarySha256 = \strtolower((string)($manifest['binary_sha256'] ?? ''));
        $actualBinarySha256 = $this->sha256RegularFile(
            $this->paths->binary(),
            self::MAX_TREE_BYTES,
        );

        return $actual !== ''
            && \hash_equals(\strtolower($expected), \strtolower($actual))
            && \preg_match('/\A[a-f0-9]{64}\z/D', $expectedBinarySha256) === 1
            && \is_string($actualBinarySha256)
            && \hash_equals($expectedBinarySha256, \strtolower($actualBinarySha256));
    }

    /** @param array<string,mixed>|null $manifest */
    private function legacyManifestMatches(?array $manifest): bool
    {
        if ($manifest === null
            || \array_key_exists('schema_version', $manifest)
            || \array_key_exists('runtime_generation', $manifest)
            || \array_key_exists('role', $manifest)
            || \array_key_exists('implementation_level', $manifest)
            || !\hash_equals(self::VERSION, (string)($manifest['version'] ?? ''))
            || !\hash_equals(\PHP_OS_FAMILY, (string)($manifest['platform'] ?? ''))
        ) {
            return false;
        }
        $expectedArtifact = \PHP_OS_FAMILY === 'Windows'
            ? self::WINDOWS_ZIP_SHA256
            : self::SOURCE_SHA256;
        $artifact = \strtolower((string)(
            $manifest['artifact_sha256'] ?? $manifest['source_sha256'] ?? ''
        ));
        $expectedBinary = \strtolower((string)($manifest['binary_sha256'] ?? ''));
        $actualBinary = $this->sha256RegularFile(
            $this->paths->binary(),
            self::MAX_TREE_BYTES,
        );
        $architecture = $this->binaryArchitecture($this->paths->binary());
        if (!\hash_equals(\strtolower($expectedArtifact), $artifact)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $expectedBinary) !== 1
            || !\is_string($actualBinary)
            || !\hash_equals($expectedBinary, \strtolower($actualBinary))
            || $architecture === ''
            || !\hash_equals($architecture, (string)($manifest['arch'] ?? ''))
        ) {
            return false;
        }
        foreach (['binary' => $this->paths->binary(), 'prefix' => $this->paths->installRoot()] as $key => $path) {
            if (isset($manifest[$key])
                && (!\is_string($manifest[$key])
                    || !$this->sameManifestPath((string)$manifest[$key], $path))
            ) {
                return false;
            }
        }
        if (\PHP_OS_FAMILY !== 'Windows') {
            $buildFlags = \is_array($manifest['build_flags'] ?? null)
                ? $manifest['build_flags']
                : [];
            if (($buildFlags['has_pcre'] ?? false) !== true
                || ($buildFlags['without_rewrite'] ?? true) !== false
                || !\hash_equals(
                    $architecture,
                    $this->normalizeArchitecture((string)\php_uname('m')),
                )
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $manifest @return array<string,mixed> */
    private function migratedLegacyManifest(array $manifest): array
    {
        $windows = \PHP_OS_FAMILY === 'Windows';
        $artifact = $windows ? self::WINDOWS_ZIP_SHA256 : self::SOURCE_SHA256;
        $legacyFlags = \is_array($manifest['build_flags'] ?? null)
            ? $manifest['build_flags']
            : [];
        $buildFlags = $windows
            ? [
                'http_v2_module' => (bool)($legacyFlags['http_v2_module'] ?? true),
                'http_v3_module' => false,
                'http_v3_reason' => 'ngx_http_v3_module is not supported on Win32',
            ]
            : [
                'http_v2_module' => (bool)($legacyFlags['http_v2_module'] ?? true),
                'http_v3_module' => (bool)($legacyFlags['http_v3_module'] ?? true),
                'has_pcre' => true,
                'has_openssl' => (bool)($legacyFlags['has_openssl'] ?? true),
                'has_zlib' => (bool)($legacyFlags['has_zlib'] ?? true),
                'without_rewrite' => false,
                'without_gzip' => (bool)($legacyFlags['without_gzip'] ?? false),
            ];
        $installedAt = \trim((string)($manifest['installed_at'] ?? ''));
        if ($installedAt === '' || \strlen($installedAt) > 128 || \strtotime($installedAt) === false) {
            $installedAt = \date('c');
        }

        return [
            'version' => self::VERSION,
            'source_url' => $windows ? self::WINDOWS_ZIP_URL : self::SOURCE_URL,
            'artifact_sha256' => $artifact,
            'source_sha256' => $artifact,
            'platform' => \PHP_OS_FAMILY,
            'arch' => $this->binaryArchitecture($this->paths->binary()),
            'php_process_arch' => $this->normalizeArchitecture((string)\php_uname('m')),
            'prefix' => $this->paths->installRoot(),
            'binary' => $this->paths->binary(),
            'binary_sha256' => \strtolower((string)$manifest['binary_sha256']),
            'build_flags' => $buildFlags,
            'installed_at' => $installedAt,
            'migrated_from_schema' => 1,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readManifest(): ?array
    {
        $file = $this->paths->manifestFile();
        if (!\file_exists($file) && !\is_link($file)) {
            return null;
        }
        $decoded = \json_decode(GatewayProjectStateFilesystem::read(
            $file,
            16 * 1024 * 1024,
            'Managed Nginx install manifest',
        ), true);
        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private function writeManifest(array $manifest): void
    {
        $root = $this->paths->installRoot();
        $status = @\lstat($root);
        if (!\is_array($status)
            || \is_link($root)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException('Nginx install root is missing or unsafe: ' . $root);
        }
        $this->writeManifestFile($this->paths->manifestFile(), $manifest);
        $this->cleanupManifestAtomicWriteRecoveryBackups();
    }

    /**
     * The active install manifest has one writer namespace: ensureInstalled()
     * while holding managed-nginx.install.lock. Candidate-slot manifests use
     * unique paths and are deliberately outside this recovery collector.
     */
    private function cleanupManifestAtomicWriteRecoveryBackups(): void
    {
        $root = $this->paths->installRoot();
        if (!\is_dir($root) || \is_link($root)) {
            return;
        }
        GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
            $this->paths->manifestFile(),
            16 * 1024 * 1024,
            'Managed Nginx install manifest',
            function (string $contents): void {
                $manifest = \json_decode(
                    $contents,
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );
                if (!\is_array($manifest)
                    || (!$this->manifestMatches($manifest)
                        && !$this->legacyManifestMatches($manifest))
                ) {
                    throw new \RuntimeException(
                        'Managed Nginx install manifest recovery target is invalid.',
                    );
                }
            },
        );
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private function writeManifestFile(string $file, array $manifest): void
    {
        $manifest['schema_version'] = 2;
        $manifest['role'] = 'legacy-project-nginx';
        $manifest['implementation_level'] = 'nginx-runtime-v2';
        unset($manifest['runtime_generation']);
        $canonical = $this->canonicalManifest($manifest);
        $manifest['runtime_generation'] = \hash(
            'sha256',
            \json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        $json = \json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        GatewayProjectStateFilesystem::atomicWrite($file, $json, 0644);
    }

    /** @param array<string,mixed> $manifest @return array<string,mixed> */
    private function canonicalManifest(array $manifest): array
    {
        foreach ($manifest as $key => $value) {
            if (\is_array($value)) {
                $manifest[$key] = $this->canonicalManifest($value);
            }
        }
        if (!\array_is_list($manifest)) {
            \ksort($manifest, SORT_STRING);
        }
        return $manifest;
    }

    private function sameManifestPath(string $left, string $right): bool
    {
        $left = \rtrim(\str_replace('\\', '/', \trim($left)), '/');
        $right = \rtrim(\str_replace('\\', '/', \trim($right)), '/');
        if (\PHP_OS_FAMILY === 'Windows') {
            $left = \strtolower($left);
            $right = \strtolower($right);
        }
        return $left !== '' && \hash_equals($left, $right);
    }

    private function assertUnixInstallRootPolicy(
        string $prefix,
        bool $requireExistingOwnership,
    ): void {
        $project = \rtrim($this->paths->projectRoot(), '/\\');
        $canonicalProject = @\realpath($project);
        $parent = \dirname($prefix);
        $canonicalParent = @\realpath($parent);
        $normalizedProject = \rtrim(\str_replace('\\', '/', $project), '/');
        $normalizedPrefix = \rtrim(\str_replace('\\', '/', $prefix), '/');
        if (!\is_string($canonicalProject)
            || !\is_string($canonicalParent)
            || !$this->sameManifestPath($project, $canonicalProject)
            || !$this->sameManifestPath($parent, $canonicalParent)
            || !\str_starts_with($normalizedPrefix . '/', $normalizedProject . '/')
        ) {
            throw new \RuntimeException(
                'Managed nginx install root must remain in the canonical project tree.'
            );
        }
        $relative = \substr($normalizedPrefix, \strlen($normalizedProject) + 1);
        $segments = \explode('/', $relative);
        $first = \strtolower((string)($segments[0] ?? ''));
        $leaf = \strtolower((string)\end($segments));
        if ($relative === ''
            || \in_array($first, [
                '.codex',
                '.git',
                'app',
                'dev',
                'node_modules',
                'pub',
                'test',
                'tests',
                'vendor',
            ], true)
            || \preg_match('/\Anginx(?:[-._][a-z0-9][a-z0-9._-]*)?\z/D', $leaf) !== 1
        ) {
            throw new \RuntimeException(
                'Managed nginx install_root must be a dedicated nginx-named directory; '
                    . 'reserved project trees cannot be replaced.'
            );
        }
        if ($requireExistingOwnership) {
            $this->assertExistingInstallOwnership();
        }
    }

    private function assertExistingInstallOwnership(): void
    {
        $manifest = $this->readManifest();
        if (!$this->managedInstallOwnershipMatches($manifest)
            && !$this->legacyManifestMatches($manifest)
        ) {
            throw new \RuntimeException(
                'Existing managed nginx install_root has no valid WLS ownership manifest; '
                    . 'automatic replacement is refused.'
            );
        }
    }

    /** @param array<string,mixed>|null $manifest */
    private function managedInstallOwnershipMatches(?array $manifest): bool
    {
        if (!\is_array($manifest)
            || ($manifest['schema_version'] ?? null) !== 2
            || !\hash_equals('legacy-project-nginx', (string)($manifest['role'] ?? ''))
            || !\hash_equals('nginx-runtime-v2', (string)($manifest['implementation_level'] ?? ''))
            || !$this->sameManifestPath(
                (string)($manifest['prefix'] ?? ''),
                $this->paths->installRoot(),
            )
            || !\is_string($manifest['binary'] ?? null)
        ) {
            return false;
        }
        $prefix = \rtrim(\str_replace('\\', '/', $this->paths->installRoot()), '/');
        $binary = \str_replace('\\', '/', (string)$manifest['binary']);
        if (!\str_starts_with($binary, $prefix . '/')) {
            return false;
        }
        $generation = $manifest['runtime_generation'] ?? null;
        if (!\is_string($generation)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $generation) !== 1
        ) {
            return false;
        }
        $source = $manifest;
        unset($source['runtime_generation']);
        try {
            $calculated = \hash('sha256', \json_encode(
                $this->canonicalManifest($source),
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));
        } catch (\Throwable) {
            return false;
        }
        return \hash_equals($generation, $calculated);
    }

    private function reconcileInterruptedInstallPublication(): void
    {
        $prefix = $this->paths->installRoot();
        $rollback = $prefix . '.wls-rollback';
        if (!\file_exists($rollback) && !\is_link($rollback)) {
            return;
        }
        if (\PHP_OS_FAMILY !== 'Windows') {
            $this->assertUnixInstallRootPolicy($prefix, false);
        }
        if (!\is_dir($rollback) || \is_link($rollback)) {
            throw new \RuntimeException('The managed nginx rollback slot is linked or special.');
        }
        if (!$this->managedRollbackMatches($rollback)) {
            throw new \RuntimeException(
                'The managed nginx rollback slot has no complete WLS ownership proof; manual recovery is required.'
            );
        }

        if (!\file_exists($prefix) && !\is_link($prefix)) {
            if (!@\rename($rollback, $prefix)) {
                throw new \RuntimeException('Unable to restore the verified managed nginx rollback slot.');
            }
            if (!$this->paths->isInstalled() || !$this->manifestMatches()) {
                if (!@\rename($prefix, $rollback)) {
                    throw new \RuntimeException(
                        'Restored managed nginx rollback failed verification and could not be returned to its slot.'
                    );
                }
                throw new \RuntimeException('Restored managed nginx rollback failed verification.');
            }
            return;
        }

        if (!\is_dir($prefix) || \is_link($prefix)) {
            throw new \RuntimeException(
                'The managed nginx target collided with a linked or special path while a rollback slot exists.'
            );
        }
        if (!$this->paths->isInstalled() || !$this->manifestMatches()) {
            throw new \RuntimeException(
                'Both managed nginx target and rollback slots exist, but the target is invalid; manual recovery is required.'
            );
        }
        $this->removeTree($rollback);
    }

    /**
     * Collect crash residues only while ensureInstalled() owns the installer
     * lock. Enumeration and validation finish before the first deletion, and
     * an exact committed prefix is the sole authority for discarding them.
     */
    private function reconcileInterruptedInstallCandidates(): void
    {
        $prefix = $this->paths->installRoot();
        $parent = \dirname($prefix);
        if (!\file_exists($parent) && !\is_link($parent)) {
            return;
        }
        $parentStatus = @\lstat($parent);
        if (!\is_array($parentStatus)
            || \is_link($parent)
            || ((((int)($parentStatus['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException(
                'Managed nginx install candidate parent is unsafe.',
            );
        }
        $candidates = [];
        $entries = 0;
        $namespacePrefix = 'install-candidate-';
        foreach (new \FilesystemIterator($parent, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if (++$entries > self::MAX_INSTALL_CANDIDATE_RECOVERY_ENTRIES) {
                throw new \RuntimeException(
                    'Managed nginx install candidate recovery enumeration exceeds its fixed limit.',
                );
            }
            $name = $entry->getFilename();
            $reserved = \str_starts_with($name, $namespacePrefix)
                || (\PHP_OS_FAMILY === 'Windows'
                    && \strncasecmp(
                        $name,
                        $namespacePrefix,
                        \strlen($namespacePrefix),
                    ) === 0);
            if (!$reserved) {
                continue;
            }
            if (\preg_match('/\Ainstall-candidate-[a-f0-9]{16}\z/D', $name) !== 1) {
                throw new \RuntimeException(
                    'Managed nginx install candidate namespace contains a malformed retained entry.',
                );
            }
            $path = $entry->getPathname();
            if ($entry->isLink() || \is_link($path) || !$entry->isDir()) {
                throw new \RuntimeException(
                    'Managed nginx install candidate recovery found a linked or special candidate.',
                );
            }
            $candidates[] = $path;
        }
        $parentAfterDiscovery = @\lstat($parent);
        if (!\is_array($parentAfterDiscovery)
            || \is_link($parent)
            || ((((int)($parentAfterDiscovery['mode'] ?? 0)) & 0170000) !== 0040000)
            || (int)($parentAfterDiscovery['dev'] ?? -1) !== (int)($parentStatus['dev'] ?? -2)
            || (int)($parentAfterDiscovery['ino'] ?? -1) !== (int)($parentStatus['ino'] ?? -2)
        ) {
            throw new \RuntimeException(
                'Managed nginx install candidate parent changed during discovery.',
            );
        }
        if ($candidates === [] || !$this->committedInstallPrefixFullyValid()) {
            return;
        }
        foreach ($candidates as $candidate) {
            $this->assertSafeTree($candidate, 'managed nginx interrupted install candidate');
        }
        if (!$this->committedInstallPrefixFullyValid()) {
            throw new \RuntimeException(
                'Committed managed nginx install changed before candidate cleanup.',
            );
        }
        foreach ($candidates as $candidate) {
            $this->removeTree($candidate);
        }
    }

    private function committedInstallPrefixFullyValid(): bool
    {
        $prefix = $this->paths->installRoot();
        if (!\is_dir($prefix) || \is_link($prefix)) {
            return false;
        }
        try {
            if (\PHP_OS_FAMILY === 'Windows') {
                $this->assertExistingInstallOwnership();
            } else {
                $this->assertUnixInstallRootPolicy($prefix, true);
            }
            $this->assertSafeTree($prefix, 'committed managed nginx install');
            return $this->paths->isInstalled() && $this->manifestMatches();
        } catch (\Throwable) {
            return false;
        }
    }

    private function managedRollbackMatches(string $rollback): bool
    {
        try {
            $this->assertSafeTree($rollback, 'managed nginx rollback slot');
            $manifestFile = $rollback . DIRECTORY_SEPARATOR
                . \basename($this->paths->manifestFile());
            $manifest = \json_decode(GatewayProjectStateFilesystem::read(
                $manifestFile,
                16 * 1024 * 1024,
                'Managed nginx rollback manifest',
            ), true, 512, JSON_THROW_ON_ERROR);
            if (!\is_array($manifest)
                || !$this->managedInstallOwnershipMatches($manifest)
                || !\hash_equals(self::VERSION, (string)($manifest['version'] ?? ''))
                || !\hash_equals(\PHP_OS_FAMILY, (string)($manifest['platform'] ?? ''))
            ) {
                return false;
            }
            $expectedArtifact = \PHP_OS_FAMILY === 'Windows'
                ? self::WINDOWS_ZIP_SHA256
                : self::SOURCE_SHA256;
            $artifact = \strtolower((string)(
                $manifest['artifact_sha256'] ?? $manifest['source_sha256'] ?? ''
            ));
            if (!\hash_equals(\strtolower($expectedArtifact), $artifact)) {
                return false;
            }
            $prefix = \rtrim(\str_replace('\\', '/', $this->paths->installRoot()), '/');
            $declaredBinary = \str_replace('\\', '/', (string)($manifest['binary'] ?? ''));
            if (!\str_starts_with($declaredBinary, $prefix . '/')) {
                return false;
            }
            $relative = \substr($declaredBinary, \strlen($prefix) + 1);
            $segments = \explode('/', $relative);
            if ($relative === ''
                || \in_array('', $segments, true)
                || \in_array('.', $segments, true)
                || \in_array('..', $segments, true)
            ) {
                return false;
            }
            $binary = $rollback . DIRECTORY_SEPARATOR
                . \implode(DIRECTORY_SEPARATOR, $segments);
            $actualDigest = $this->sha256RegularFile($binary, self::MAX_TREE_BYTES);
            $expectedDigest = \strtolower((string)($manifest['binary_sha256'] ?? ''));
            $architecture = $this->binaryArchitecture($binary);
            return \is_string($actualDigest)
                && \preg_match('/\A[a-f0-9]{64}\z/D', $expectedDigest) === 1
                && \hash_equals($expectedDigest, \strtolower($actualDigest))
                && $architecture !== ''
                && \hash_equals($architecture, (string)($manifest['arch'] ?? ''))
                && (\PHP_OS_FAMILY === 'Windows' || \is_executable($binary));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{ok:bool,message:string,manifest?:array<string,mixed>,platform?:string}
     */
    private function installUnixFromSource(bool $force): array
    {
        $preflight = $this->unixPreflight();
        if (!($preflight['ok'] ?? false)) {
            return [
                'ok' => false,
                'message' => (string)$preflight['message'],
                'platform' => \PHP_OS_FAMILY,
            ];
        }

        $cacheDir = $this->paths->projectRoot() . DIRECTORY_SEPARATOR . 'var'
            . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'nginx-build';
        if (\is_link($cacheDir)) {
            return ['ok' => false, 'message' => 'build cache must not be a link: ' . $cacheDir, 'platform' => \PHP_OS_FAMILY];
        }
        if (!\is_dir($cacheDir) && !@\mkdir($cacheDir, 0700, true) && !\is_dir($cacheDir)) {
            return ['ok' => false, 'message' => 'unable to create build cache: ' . $cacheDir, 'platform' => \PHP_OS_FAMILY];
        }
        if (\PHP_OS_FAMILY !== 'Windows' && !@\chmod($cacheDir, 0700)) {
            return ['ok' => false, 'message' => 'unable to seal build cache permissions: ' . $cacheDir, 'platform' => \PHP_OS_FAMILY];
        }
        $tarball = $cacheDir . DIRECTORY_SEPARATOR . 'nginx-' . self::VERSION . '.tar.gz';
        try {
            $this->downloadFile(self::SOURCE_URL, $tarball);
            $this->assertSha256($tarball, self::SOURCE_SHA256);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'platform' => \PHP_OS_FAMILY];
        }

        $srcDir = $cacheDir . DIRECTORY_SEPARATOR . 'nginx-' . self::VERSION;
        if (\is_dir($srcDir)) {
            $this->removeTree($srcDir);
        }
        if (\file_exists($srcDir) || \is_link($srcDir)) {
            return [
                'ok' => false,
                'message' => 'nginx source cache path is not an empty safe directory target: ' . $srcDir,
                'platform' => \PHP_OS_FAMILY,
            ];
        }
        $tar = $this->executablePath('tar');
        if ($tar === null) {
            return ['ok' => false, 'message' => 'tar executable disappeared after preflight', 'platform' => \PHP_OS_FAMILY];
        }
        $extract = $this->runTool(
            [$tar, '-xzf', $tarball, '-C', $cacheDir],
            self::EXTRACT_TIMEOUT_SECONDS,
            null,
            false,
        );
        try {
            $this->assertSha256($tarball, self::SOURCE_SHA256);
        } catch (\Throwable $throwable) {
            if (\is_dir($srcDir) || \is_link($srcDir)) {
                $this->removeTree($srcDir);
            }
            return [
                'ok' => false,
                'message' => 'nginx source artifact changed while being extracted: ' . $throwable->getMessage(),
                'platform' => \PHP_OS_FAMILY,
            ];
        }
        if ((int)$extract['code'] !== 0 || !\is_dir($srcDir) || \is_link($srcDir)) {
            return [
                'ok' => false,
                'message' => 'tar extract failed: ' . $this->tailText((string)$extract['output'], 4000),
                'platform' => \PHP_OS_FAMILY,
            ];
        }
        try {
            $this->assertSafeTree($srcDir, 'nginx source archive');
        } catch (\Throwable $throwable) {
            $this->removeTree($srcDir);
            return [
                'ok' => false,
                'message' => $throwable->getMessage(),
                'platform' => \PHP_OS_FAMILY,
            ];
        }

        $prefix = $this->paths->installRoot();
        $prefixParent = \dirname($prefix);
        if (!\is_dir($prefixParent)
            && !@\mkdir($prefixParent, 0755, true)
            && !\is_dir($prefixParent)
        ) {
            return [
                'ok' => false,
                'message' => 'unable to create managed nginx install parent: ' . $prefixParent,
                'platform' => \PHP_OS_FAMILY,
            ];
        }
        $parentStatus = @\lstat($prefixParent);
        if (!\is_array($parentStatus)
            || \is_link($prefixParent)
            || ((((int)($parentStatus['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            return [
                'ok' => false,
                'message' => 'managed nginx install parent is linked or unsafe: ' . $prefixParent,
                'platform' => \PHP_OS_FAMILY,
            ];
        }
        $prefixPresent = \file_exists($prefix) || \is_link($prefix);
        try {
            $this->assertUnixInstallRootPolicy($prefix, false);
        } catch (\Throwable $throwable) {
            return [
                'ok' => false,
                'message' => $throwable->getMessage(),
                'platform' => \PHP_OS_FAMILY,
            ];
        }
        if ($prefixPresent && (!$force || !\is_dir($prefix) || \is_link($prefix))) {
            return [
                'ok' => false,
                'message' => \is_link($prefix) || !\is_dir($prefix)
                    ? 'managed nginx install root is linked or special and will not be replaced'
                    : 'incomplete nginx install root exists; rerun with --force after confirming it is not running',
                'platform' => \PHP_OS_FAMILY,
            ];
        }
        if ($prefixPresent) {
            try {
                $this->assertUnixInstallRootPolicy($prefix, true);
                $this->assertSafeTree($prefix, 'existing managed nginx install');
            } catch (\Throwable $throwable) {
                return [
                    'ok' => false,
                    'message' => $throwable->getMessage(),
                    'platform' => \PHP_OS_FAMILY,
                ];
            }
        }

        $deps = $this->resolveUnixBuildFlags();
        if (!$deps['has_pcre']) {
            return [
                'ok' => false,
                'message' => 'managed nginx build refused: PCRE and the HTTP rewrite module are required.',
                'platform' => \PHP_OS_FAMILY,
            ];
        }
        $compiler = $this->detectCc();
        $make = $this->executablePath('make');
        $configureScript = $srcDir . DIRECTORY_SEPARATOR . 'configure';
        if ($compiler === null
            || $make === null
            || !\is_file($configureScript)
            || !\is_executable($configureScript)
            || \is_link($configureScript)
        ) {
            return [
                'ok' => false,
                'message' => 'nginx build tools or the verified configure script disappeared after preflight',
                'platform' => \PHP_OS_FAMILY,
            ];
        }
        $configureDigest = $this->sha256RegularFile($configureScript, 16 * 1024 * 1024);
        if ($configureDigest === null) {
            return [
                'ok' => false,
                'message' => 'verified nginx configure script is not a bounded regular file',
                'platform' => \PHP_OS_FAMILY,
            ];
        }
        $candidate = $prefixParent . DIRECTORY_SEPARATOR
            . 'install-candidate-' . \bin2hex(\random_bytes(8));
        $this->resetDirectory($candidate);
        try {
            $configure = [
                $configureScript,
                '--prefix=' . $candidate,
                '--with-http_ssl_module',
                '--with-http_v2_module',
                '--with-http_v3_module',
                '--with-cc=' . $compiler,
            ];
            foreach (\preg_split('/\s+/', \trim((string)$deps['configure_extra'])) ?: [] as $option) {
                if ($option !== '') {
                    $configure[] = $option;
                }
            }
            if ($deps['cc_opts'] !== []) {
                $configure[] = '--with-cc-opt=' . \implode(' ', \array_values(\array_unique($deps['cc_opts'])));
            }
            if ($deps['ld_opts'] !== []) {
                $configure[] = '--with-ld-opt=' . \implode(' ', \array_values(\array_unique($deps['ld_opts'])));
            }

            $jobs = $this->detectParallelJobs();
            $configureResult = $this->runTool(
                $configure,
                self::CONFIGURE_TIMEOUT_SECONDS,
                $srcDir,
                false,
            );
            $configureAfter = $this->sha256RegularFile($configureScript, 16 * 1024 * 1024);
            if (!\is_string($configureAfter) || !\hash_equals($configureDigest, $configureAfter)) {
                $configureResult = [
                    'code' => 125,
                    'output' => 'verified nginx configure script changed while it was being consumed',
                ];
            }
            $buildResult = (int)$configureResult['code'] === 0
                ? $this->runTool([$make, '-s', '-j' . $jobs], self::BUILD_TIMEOUT_SECONDS, $srcDir, false)
                : ['code' => 125, 'output' => 'build skipped because configure failed'];
            $installResult = (int)$buildResult['code'] === 0
                ? $this->runTool([$make, '-s', 'install'], self::INSTALL_TIMEOUT_SECONDS, $srcDir, false)
                : ['code' => 125, 'output' => 'install skipped because build failed'];
            if ((int)$configureResult['code'] !== 0
                || (int)$buildResult['code'] !== 0
                || (int)$installResult['code'] !== 0
            ) {
                $hint = $this->unixFailureHint();
                $diagnostic = 'configure: ' . (string)$configureResult['output']
                    . "\nbuild: " . (string)$buildResult['output']
                    . "\ninstall: " . (string)$installResult['output'];
                return [
                    'ok' => false,
                    'message' => 'nginx build/install failed on ' . \PHP_OS_FAMILY . '/' . \php_uname('m')
                        . '. ' . $hint . ' Output: '
                        . $this->tailText(\trim($diagnostic), 4000),
                    'platform' => \PHP_OS_FAMILY,
                ];
            }

            $candidateBinary = $candidate . DIRECTORY_SEPARATOR . 'sbin'
                . DIRECTORY_SEPARATOR . 'nginx';
            if (!\is_file($candidateBinary)
                || \is_link($candidateBinary)
                || !@\chmod($candidateBinary, 0755)
                || !\is_executable($candidateBinary)
            ) {
                return [
                    'ok' => false,
                    'message' => 'managed nginx candidate binary is missing or unsafe',
                    'platform' => \PHP_OS_FAMILY,
                ];
            }
            $binarySha256 = $this->sha256RegularFile(
                $candidateBinary,
                self::MAX_TREE_BYTES,
            );
            if (!\is_string($binarySha256)) {
                return ['ok' => false, 'message' => 'unable to hash installed nginx binary', 'platform' => \PHP_OS_FAMILY];
            }
            $binaryArchitecture = $this->binaryArchitecture($candidateBinary);
            if ($binaryArchitecture === '') {
                return ['ok' => false, 'message' => 'unable to identify installed nginx binary architecture', 'platform' => \PHP_OS_FAMILY];
            }
            $manifest = [
                'version' => self::VERSION,
                'source_url' => self::SOURCE_URL,
                'artifact_sha256' => self::SOURCE_SHA256,
                'source_sha256' => self::SOURCE_SHA256,
                'platform' => \PHP_OS_FAMILY,
                'arch' => $binaryArchitecture,
                'php_process_arch' => $this->normalizeArchitecture((string)\php_uname('m')),
                'prefix' => $prefix,
                'binary' => $prefix . DIRECTORY_SEPARATOR . 'sbin'
                    . DIRECTORY_SEPARATOR . 'nginx',
                'binary_sha256' => $binarySha256,
                'build_flags' => [
                    'http_v2_module' => true,
                    'http_v3_module' => true,
                    'has_pcre' => $deps['has_pcre'],
                    'has_openssl' => $deps['has_openssl'],
                    'has_zlib' => $deps['has_zlib'],
                    'without_rewrite' => false,
                    'without_gzip' => !$deps['has_zlib'],
                ],
                'installed_at' => \date('c'),
            ];
            $this->writeManifestFile(
                $candidate . DIRECTORY_SEPARATOR . \basename($this->paths->manifestFile()),
                $manifest,
            );
            $this->assertSafeTree($candidate, 'managed nginx install candidate');
            $this->publishInstallCandidate($candidate, $prefix, true);
            return [
                'ok' => true,
                'message' => 'managed nginx installed from source (' . \PHP_OS_FAMILY . '/' . \php_uname('m') . ')',
                'manifest' => $manifest,
                'platform' => \PHP_OS_FAMILY,
            ];
        } finally {
            if (\file_exists($candidate) || \is_link($candidate)) {
                $this->removeTree($candidate);
            }
        }
    }

    /**
     * @return array{ok:bool,message:string}
     */
    private function unixPreflight(): array
    {
        $missing = [];
        foreach (['tar', 'make'] as $bin) {
            if (!$this->commandExists($bin)) {
                $missing[] = $bin;
            }
        }
        $cc = $this->detectCc();
        if ($cc === null) {
            $missing[] = 'cc/clang/gcc';
        }
        if ($missing !== []) {
            return [
                'ok' => false,
                'message' => 'missing build tools: ' . \implode(', ', $missing) . '. ' . $this->unixFailureHint(),
            ];
        }
        if (!$this->hasPcreHeaders([])) {
            return [
                'ok' => false,
                'message' => 'managed nginx requires PCRE development headers because the isolated config uses '
                    . 'the HTTP rewrite module. ' . $this->unixFailureHint(),
            ];
        }

        return ['ok' => true, 'message' => 'ok'];
    }

    /**
     * @return array{
     *   cc_opts:list<string>,
     *   ld_opts:list<string>,
     *   configure_extra:string,
     *   has_pcre:bool,
     *   has_openssl:bool,
     *   has_zlib:bool
     * }
     */
    private function resolveUnixBuildFlags(): array
    {
        $ccOpts = ['-Wno-error'];
        // Apple Clang exposes this warning switch; GCC rejects the switch itself.
        if (\PHP_OS_FAMILY === 'Darwin') {
            $ccOpts[] = '-Wno-error=unterminated-string-initialization';
        }
        $ldOpts = [];
        $includeDirs = [];
        $libDirs = [];

        if (\PHP_OS_FAMILY === 'Darwin') {
            foreach (['openssl@3', 'openssl', 'pcre2', 'pcre', 'zlib'] as $brewPkg) {
                $brewPrefix = $this->brewPrefix($brewPkg);
                if ($brewPrefix === null) {
                    continue;
                }
                if (\is_dir($brewPrefix . '/include')) {
                    $includeDirs[] = $brewPrefix . '/include';
                }
                if (\is_dir($brewPrefix . '/lib')) {
                    $libDirs[] = $brewPrefix . '/lib';
                }
            }
        }

        if (\PHP_OS_FAMILY === 'Linux') {
            foreach ([
                '/usr',
                '/usr/local',
                '/usr/local/opt/openssl',
                '/usr/local/opt/openssl@3',
                '/opt/homebrew/opt/openssl@3',
            ] as $root) {
                if (\is_dir($root . '/include')) {
                    $includeDirs[] = $root . '/include';
                }
                if (\is_dir($root . '/lib') || \is_dir($root . '/lib64')) {
                    if (\is_dir($root . '/lib')) {
                        $libDirs[] = $root . '/lib';
                    }
                    if (\is_dir($root . '/lib64')) {
                        $libDirs[] = $root . '/lib64';
                    }
                }
            }
            $pkgConfig = $this->executablePath('pkg-config');
            foreach (['openssl', 'libssl', 'libpcre', 'libpcre2-8', 'zlib'] as $pkg) {
                $cflagsResult = $pkgConfig !== null
                    ? $this->runTool([$pkgConfig, '--cflags-only-I', $pkg], self::TOOL_PROBE_TIMEOUT_SECONDS)
                    : ['code' => 127, 'output' => ''];
                $libsResult = $pkgConfig !== null
                    ? $this->runTool([$pkgConfig, '--libs-only-L', $pkg], self::TOOL_PROBE_TIMEOUT_SECONDS)
                    : ['code' => 127, 'output' => ''];
                $cflags = (int)$cflagsResult['code'] === 0 ? \trim((string)$cflagsResult['output']) : '';
                $libs = (int)$libsResult['code'] === 0 ? \trim((string)$libsResult['output']) : '';
                if ($cflags !== '') {
                    foreach (\preg_split('/\s+/', $cflags) ?: [] as $flag) {
                        if (\str_starts_with($flag, '-I') && \strlen($flag) > 2) {
                            $includeDirs[] = \substr($flag, 2);
                        }
                    }
                }
                if ($libs !== '') {
                    foreach (\preg_split('/\s+/', $libs) ?: [] as $flag) {
                        if (\str_starts_with($flag, '-L') && \strlen($flag) > 2) {
                            $libDirs[] = \substr($flag, 2);
                        }
                    }
                }
            }
        }

        foreach (\array_unique($includeDirs) as $dir) {
            $ccOpts[] = '-I' . $dir;
        }
        foreach (\array_unique($libDirs) as $dir) {
            $ldOpts[] = '-L' . $dir;
        }

        $hasOpenssl = $this->hasOpensslHeaders($includeDirs);
        $hasPcre = $this->hasPcreHeaders($includeDirs);
        $hasZlib = $this->hasZlibHeaders($includeDirs);

        $configureOptions = [];
        if (!$hasZlib) {
            $configureOptions[] = '--without-http_gzip_module';
        }

        return [
            'cc_opts' => $ccOpts,
            'ld_opts' => $ldOpts,
            'configure_extra' => \implode(' ', $configureOptions),
            'has_pcre' => $hasPcre,
            'has_openssl' => $hasOpenssl,
            'has_zlib' => $hasZlib,
        ];
    }

    /**
     * @param list<string> $includeDirs
     */
    private function hasOpensslHeaders(array $includeDirs): bool
    {
        foreach ($includeDirs as $dir) {
            if (\is_file($dir . '/openssl/ssl.h')) {
                return true;
            }
        }
        return \is_file('/usr/include/openssl/ssl.h')
            || \is_file('/usr/local/include/openssl/ssl.h')
            || $this->pkgExists('openssl')
            || $this->pkgExists('libssl');
    }

    /**
     * @param list<string> $includeDirs
     */
    private function hasPcreHeaders(array $includeDirs): bool
    {
        foreach ($includeDirs as $dir) {
            if (\is_file($dir . '/pcre.h') || \is_file($dir . '/pcre2.h')) {
                return true;
            }
        }
        return \is_file('/usr/include/pcre.h')
            || \is_file('/usr/include/pcre2.h')
            || \is_file('/usr/local/include/pcre.h')
            || $this->pkgExists('libpcre')
            || $this->pkgExists('libpcre2-8')
            || $this->brewPrefix('pcre') !== null
            || $this->brewPrefix('pcre2') !== null;
    }

    /**
     * @param list<string> $includeDirs
     */
    private function hasZlibHeaders(array $includeDirs): bool
    {
        foreach ($includeDirs as $dir) {
            if (\is_file($dir . '/zlib.h')) {
                return true;
            }
        }
        return \is_file('/usr/include/zlib.h')
            || \is_file('/usr/local/include/zlib.h')
            || $this->macOsSdkHeaderExists('zlib.h')
            || $this->pkgExists('zlib')
            || $this->brewPrefix('zlib') !== null;
    }

    private function macOsSdkHeaderExists(string $header): bool
    {
        $xcrun = $this->executablePath('xcrun');
        if (\PHP_OS_FAMILY !== 'Darwin' || $xcrun === null) {
            return false;
        }
        $result = $this->runTool([$xcrun, '--show-sdk-path'], self::TOOL_PROBE_TIMEOUT_SECONDS);
        $sdk = (int)$result['code'] === 0 ? \trim((string)$result['output']) : '';

        return $sdk !== '' && \is_file($sdk . '/usr/include/' . \ltrim($header, '/'));
    }

    private function unixFailureHint(): string
    {
        return match (\PHP_OS_FAMILY) {
            'Darwin' => 'Install Xcode CLT and Homebrew deps: brew install openssl@3 pcre2',
            'Linux' => 'Install build tools and headers, e.g. apt: build-essential libssl-dev libpcre3-dev zlib1g-dev'
                . ' | dnf/yum: gcc make openssl-devel pcre-devel zlib-devel'
                . ' | apk: build-base openssl-dev pcre-dev zlib-dev',
            default => 'Install a C toolchain plus OpenSSL and PCRE development headers.',
        };
    }

    /**
     * @return array{ok:bool,message:string,manifest?:array<string,mixed>,platform?:string}
     */
    private function installWindows(bool $force): array
    {
        if (!\class_exists(\ZipArchive::class)
            && $this->executablePath('tar') === null
            && ($this->executablePath('powershell') === null
                || !\function_exists('iconv'))
        ) {
            return [
                'ok' => false,
                'message' => 'Windows managed nginx install requires PHP ZipArchive, tar.exe, or PowerShell with PHP iconv',
                'platform' => 'Windows',
            ];
        }

        $prefix = $this->paths->installRoot();
        $localRoot = \dirname($prefix);
        $cacheDir = $localRoot . DIRECTORY_SEPARATOR . 'installer-cache';
        if (\is_link($cacheDir)) {
            return ['ok' => false, 'message' => 'local installer cache must not be a link: ' . $cacheDir, 'platform' => 'Windows'];
        }
        if (!\is_dir($cacheDir) && !@\mkdir($cacheDir, 0700, true) && !\is_dir($cacheDir)) {
            return ['ok' => false, 'message' => 'unable to create local installer cache: ' . $cacheDir, 'platform' => 'Windows'];
        }
        if (\is_dir($prefix) && !$force) {
            return [
                'ok' => false,
                'message' => 'incomplete Windows nginx install root exists; rerun with --force after confirming it is not running',
                'platform' => 'Windows',
            ];
        }
        if (\file_exists($prefix) || \is_link($prefix)) {
            if (!\is_dir($prefix) || \is_link($prefix)) {
                return [
                    'ok' => false,
                    'message' => 'Windows managed nginx install root is linked or special and will not be replaced',
                    'platform' => 'Windows',
                ];
            }
            try {
                $this->assertSafeTree($prefix, 'existing Windows managed nginx install');
                $this->assertExistingInstallOwnership();
            } catch (\Throwable $throwable) {
                return [
                    'ok' => false,
                    'message' => $throwable->getMessage(),
                    'platform' => 'Windows',
                ];
            }
        }

        $zip = $cacheDir . DIRECTORY_SEPARATOR . 'nginx-' . self::VERSION . '.zip';
        $extractDir = '';
        $candidate = '';
        try {
            $this->downloadFile(self::WINDOWS_ZIP_URL, $zip);
            $this->assertSha256($zip, self::WINDOWS_ZIP_SHA256);

            $token = \bin2hex(\random_bytes(8));
            $extractDir = $cacheDir . DIRECTORY_SEPARATOR . 'win-extract-' . $token;
            $candidate = $localRoot . DIRECTORY_SEPARATOR . 'install-candidate-' . $token;
            $this->resetDirectory($extractDir);
            $this->extractZip($zip, $extractDir);
            $this->assertSha256($zip, self::WINDOWS_ZIP_SHA256);

            $nested = $extractDir . DIRECTORY_SEPARATOR . 'nginx-' . self::VERSION;
            if (!\is_dir($nested)) {
                // Some extractions nest differently; pick first directory containing nginx.exe.
                $nested = $this->findWindowsNginxRoot($extractDir) ?? $extractDir;
            }

            $this->resetDirectory($candidate);
            $this->copyTree($nested, $candidate);
            $candidateBinary = $candidate . DIRECTORY_SEPARATOR . 'nginx.exe';
            if (!\is_file($candidateBinary)) {
                throw new \RuntimeException('windows nginx.exe missing from validated install candidate');
            }
            $binarySha256 = $this->sha256RegularFile($candidateBinary, self::MAX_TREE_BYTES);
            if (!\is_string($binarySha256)) {
                throw new \RuntimeException('unable to hash Windows nginx install candidate');
            }
            $binaryArchitecture = $this->binaryArchitecture($candidateBinary);
            if ($binaryArchitecture === '') {
                throw new \RuntimeException('unable to identify Windows nginx install candidate architecture');
            }
            $manifest = [
                'version' => self::VERSION,
                'source_url' => self::WINDOWS_ZIP_URL,
                'artifact_sha256' => self::WINDOWS_ZIP_SHA256,
                'source_sha256' => self::WINDOWS_ZIP_SHA256,
                'platform' => 'Windows',
                'arch' => $binaryArchitecture,
                'php_process_arch' => $this->normalizeArchitecture((string)\php_uname('m')),
                'prefix' => $prefix,
                'binary' => $prefix . DIRECTORY_SEPARATOR . 'nginx.exe',
                'binary_sha256' => $binarySha256,
                'build_flags' => [
                    'http_v2_module' => true,
                    'http_v3_module' => false,
                    'http_v3_reason' => 'ngx_http_v3_module is not supported on Win32',
                ],
                'installed_at' => \date('c'),
                'note' => 'Official nginx.org Windows zip is typically x86/x64; on ARM Windows use x64 PHP emulation if needed.',
            ];
            $this->writeManifestFile(
                $candidate . DIRECTORY_SEPARATOR . \basename($this->paths->manifestFile()),
                $manifest,
            );
            $this->publishInstallCandidate($candidate, $prefix, true);

            return [
                'ok' => true,
                'message' => 'managed nginx installed from windows zip',
                'manifest' => $manifest,
                'platform' => 'Windows',
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'platform' => 'Windows'];
        } finally {
            if ($extractDir !== '') {
                $this->removeTree($extractDir);
            }
            if ($candidate !== '') {
                $this->removeTree($candidate);
            }
        }
    }

    private function extractZip(string $zip, string $destination): void
    {
        $failures = [];
        if (\class_exists(\ZipArchive::class)) {
            $zipArchive = new \ZipArchive();
            $opened = $zipArchive->open($zip);
            if ($opened === true
                && $this->validateZipTopology($zipArchive)
                && $zipArchive->extractTo($destination)
            ) {
                $zipArchive->close();
                return;
            }
            if ($opened === true) {
                $status = \method_exists($zipArchive, 'getStatusString')
                    ? $zipArchive->getStatusString()
                    : 'status=' . (string)$zipArchive->status . ', statusSys=' . (string)$zipArchive->statusSys;
                $zipArchive->close();
                $failures[] = 'ZipArchive extract failed (' . $status . ')';
            } else {
                $failures[] = 'ZipArchive open failed (code=' . (string)$opened . ')';
            }
            $this->resetDirectory($destination);
        }
        $tar = $this->executablePath('tar');
        if ($tar !== null) {
            $result = $this->runTool(
                [$tar, '-xf', $zip, '-C', $destination],
                self::EXTRACT_TIMEOUT_SECONDS,
                null,
                false,
            );
            if ((int)$result['code'] === 0) {
                return;
            }
            $failures[] = 'tar failed: ' . $this->tailText((string)$result['output'], 4000);
            $this->resetDirectory($destination);
        }
        $powershell = $this->executablePath('powershell');
        if ($powershell !== null && \function_exists('iconv')) {
            $script = 'Expand-Archive -LiteralPath '
                . $this->powerShellLiteral($zip)
                . ' -DestinationPath '
                . $this->powerShellLiteral($destination)
                . ' -Force';
            $encoded = \iconv('UTF-8', 'UTF-16LE', $script);
            if (!\is_string($encoded)) {
                throw new \RuntimeException('unable to encode PowerShell Expand-Archive command');
            }
            $result = $this->runTool([
                $powershell,
                '-NoProfile',
                '-NonInteractive',
                '-EncodedCommand',
                \base64_encode($encoded),
            ], self::EXTRACT_TIMEOUT_SECONDS, null, false);
            if ((int)$result['code'] === 0) {
                return;
            }
            $failures[] = 'Expand-Archive failed: ' . $this->tailText((string)$result['output'], 4000);
        } elseif ($powershell !== null) {
            $failures[] = 'PowerShell extraction requires the PHP iconv extension';
        }
        throw new \RuntimeException(
            $failures === []
                ? 'no zip extractor available'
                : 'unable to extract windows nginx zip: ' . \implode(' | ', $failures),
        );
    }

    private function validateZipTopology(\ZipArchive $archive): bool
    {
        $entries = $archive->numFiles;
        if ($entries < 1 || $entries > self::MAX_TREE_ENTRIES) {
            return false;
        }
        $totalBytes = 0;
        for ($index = 0; $index < $entries; $index++) {
            $stat = $archive->statIndex($index, \ZipArchive::FL_UNCHANGED);
            if (!\is_array($stat)) {
                return false;
            }
            $name = \str_replace('\\', '/', (string)($stat['name'] ?? ''));
            $trimmed = \rtrim($name, '/');
            if ($trimmed === ''
                || \strlen($name) > 4096
                || \str_contains($name, "\0")
                || \str_starts_with($name, '/')
                || \preg_match('/\A[A-Za-z]:\//D', $name) === 1
            ) {
                return false;
            }
            $components = \explode('/', $trimmed);
            if (\count($components) > self::MAX_TREE_DEPTH + 1) {
                return false;
            }
            foreach ($components as $component) {
                if ($component === '' || $component === '.' || $component === '..') {
                    return false;
                }
            }
            $size = $stat['size'] ?? null;
            if (!\is_int($size) || $size < 0 || $size > self::MAX_TREE_BYTES - $totalBytes) {
                return false;
            }
            $totalBytes += $size;
            $attributes = 0;
            $opsys = 0;
            if ($archive->getExternalAttributesIndex($index, $opsys, $attributes)) {
                $type = ($attributes >> 16) & 0170000;
                if ($type !== 0 && $type !== 0040000 && $type !== 0100000) {
                    return false;
                }
            }
        }

        return true;
    }

    private function powerShellLiteral(string $value): string
    {
        return "'" . \str_replace("'", "''", $value) . "'";
    }

    private function resetDirectory(string $dir): void
    {
        if (\is_dir($dir)) {
            $this->removeTree($dir);
        }
        if (\is_dir($dir) || (!@\mkdir($dir, 0755, true) && !\is_dir($dir))) {
            throw new \RuntimeException('unable to prepare local nginx installer directory: ' . $dir);
        }
    }

    private function publishInstallCandidate(
        string $candidate,
        string $prefix,
        bool $requireOwnedPrevious = false,
    ): void
    {
        $rollback = $prefix . '.wls-rollback';
        $hadPrevious = \is_dir($prefix);
        if (!\is_dir($candidate) || \is_link($candidate)) {
            throw new \RuntimeException('Managed nginx install candidate is linked, missing, or special.');
        }
        $this->assertSafeTree($candidate, 'managed nginx install candidate');
        if (\file_exists($prefix) || \is_link($prefix)) {
            if (!$hadPrevious || \is_link($prefix)) {
                throw new \RuntimeException('Managed nginx install target is linked or special.');
            }
            if ($requireOwnedPrevious) {
                if (\PHP_OS_FAMILY === 'Windows') {
                    $this->assertExistingInstallOwnership();
                } else {
                    $this->assertUnixInstallRootPolicy($prefix, true);
                }
            }
            $this->assertSafeTree($prefix, 'existing managed nginx install');
        }
        if (\file_exists($rollback) || \is_link($rollback)) {
            throw new \RuntimeException(
                'Managed nginx rollback slot is occupied; interrupted-publication recovery must complete first.'
            );
        }
        if ($hadPrevious && !@\rename($prefix, $rollback)) {
            throw new \RuntimeException('Unable to stage the existing managed nginx install for rollback.');
        }

        try {
            if (!@\rename($candidate, $prefix)) {
                throw new \RuntimeException('Unable to atomically publish the managed nginx install candidate.');
            }
            if (!$this->paths->isInstalled() || !$this->manifestMatches()) {
                throw new \RuntimeException('Published managed nginx install failed binary/manifest validation.');
            }
        } catch (\Throwable $e) {
            $candidateCleanupFailure = null;
            try {
                if (\is_dir($prefix)) {
                    $this->removeTree($prefix);
                }
            } catch (\Throwable $cleanupFailure) {
                $candidateCleanupFailure = $cleanupFailure;
            }
            if (\file_exists($prefix) || \is_link($prefix) || $candidateCleanupFailure !== null) {
                throw new \RuntimeException(
                    'Managed nginx install failed and the invalid candidate could not be removed'
                    . ($hadPrevious ? '; rollback remains at ' . $rollback : '')
                    . ': '
                    . ($candidateCleanupFailure?->getMessage() ?? $e->getMessage()),
                    0,
                    $e,
                );
            }
            if ($hadPrevious && !@\rename($rollback, $prefix)) {
                throw new \RuntimeException(
                    'Managed nginx install failed and rollback restoration failed; previous install remains at '
                    . $rollback
                    . ': '
                    . $e->getMessage(),
                    0,
                    $e,
                );
            }
            throw $e;
        }

        if ($hadPrevious) {
            $this->removeTree($rollback);
            if (\file_exists($rollback) || \is_link($rollback)) {
                throw new \RuntimeException('Managed nginx install succeeded but rollback cleanup failed: ' . $rollback);
            }
        }
        $this->reconcileInterruptedInstallCandidates();
    }

    private function findWindowsNginxRoot(string $root): ?string
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $entries = 0;
        $matches = [];
        foreach ($iterator as $item) {
            if (++$entries > self::MAX_TREE_ENTRIES || $iterator->getDepth() > self::MAX_TREE_DEPTH) {
                throw new \RuntimeException('Windows nginx archive tree exceeds the fixed traversal limit.');
            }
            if ($item->isLink() || \is_link($item->getPathname())) {
                throw new \RuntimeException('Windows nginx archive contains a linked entry.');
            }
            if ($item->isFile() && \strtolower($item->getFilename()) === 'nginx.exe') {
                $path = $item->getPathname();
                $status = @\lstat($path);
                if (!\is_array($status)
                    || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0100000)
                    || (int)($status['nlink'] ?? 0) !== 1
                ) {
                    throw new \RuntimeException(
                        'Windows nginx archive executable identity is unsafe.'
                    );
                }
                $matches[] = $item->getPath();
                if (\count($matches) > 1) {
                    throw new \RuntimeException(
                        'Windows nginx archive contains multiple nginx.exe roots.'
                    );
                }
            }
        }
        return $matches[0] ?? null;
    }

    private function downloadFile(string $url, string $destination): void
    {
        if (\file_exists($destination) || \is_link($destination)) {
            if ($this->validDownloadedFile($destination)) {
                return;
            }
            if (\is_dir($destination) && !\is_link($destination)) {
                throw new \RuntimeException('download cache target is an unexpected directory: ' . $destination);
            }
            if (!@\unlink($destination)
                && (\file_exists($destination) || \is_link($destination))
            ) {
                throw new \RuntimeException('unable to remove invalid cached download: ' . $destination);
            }
        }
        $tmp = $destination . '.part';
        if (\file_exists($tmp) || \is_link($tmp)) {
            if (\is_dir($tmp) && !\is_link($tmp)) {
                throw new \RuntimeException('partial download target is an unexpected directory: ' . $tmp);
            }
            if (!@\unlink($tmp) && (\file_exists($tmp) || \is_link($tmp))) {
                throw new \RuntimeException('unable to remove stale partial download: ' . $tmp);
            }
        }

        $deadline = $this->monotonicSeconds() + self::DOWNLOAD_DEADLINE_SECONDS;
        $source = $this->openBoundedNginxDownload($url, $deadline);
        $target = @\fopen($tmp, 'xb');
        if (!\is_resource($target)) {
            @\fclose($source);
            throw new \RuntimeException('unable to create partial download: ' . $tmp);
        }
        $total = 0;
        try {
            while (!@\feof($source)) {
                if ($this->monotonicSeconds() >= $deadline) {
                    throw new \RuntimeException('download exceeded the fixed wall-clock deadline: ' . $url);
                }
                $chunk = @\fread($source, 1024 * 1024);
                if (!\is_string($chunk)) {
                    throw new \RuntimeException('download read failed: ' . $url);
                }
                if ($chunk === '') {
                    $metadata = @\stream_get_meta_data($source);
                    if ((bool)($metadata['timed_out'] ?? false)) {
                        throw new \RuntimeException('download read timed out: ' . $url);
                    }
                    if (!@\feof($source)) {
                        throw new \RuntimeException('download made no progress: ' . $url);
                    }
                    break;
                }
                $total += \strlen($chunk);
                if ($total > self::MAX_DOWNLOAD_BYTES) {
                    throw new \RuntimeException('download exceeds the fixed 512 MiB limit: ' . $url);
                }
                $offset = 0;
                while ($offset < \strlen($chunk)) {
                    $written = @\fwrite($target, \substr($chunk, $offset));
                    if (!\is_int($written) || $written < 1) {
                        throw new \RuntimeException('unable to write partial download: ' . $tmp);
                    }
                    $offset += $written;
                }
            }
            if (!@\fflush($target)
                || (\function_exists('fsync') && !@\fsync($target))
            ) {
                throw new \RuntimeException('unable to flush partial download: ' . $tmp);
            }
        } catch (\Throwable $throwable) {
            @\fclose($source);
            @\fclose($target);
            if (\file_exists($tmp) && !@\unlink($tmp)) {
                throw new \RuntimeException(
                    'download failed and its partial file could not be removed: ' . $tmp,
                    0,
                    $throwable,
                );
            }
            throw $throwable;
        }
        @\fclose($source);
        @\fclose($target);
        if ($total <= 1000 || !$this->validDownloadedFile($tmp)) {
            if (\file_exists($tmp) && !@\unlink($tmp)) {
                throw new \RuntimeException('invalid partial download could not be removed: ' . $tmp);
            }
            throw new \RuntimeException('download is empty, truncated, or oversized: ' . $url);
        }
        $this->publishDownloadedFile($tmp, $destination);
    }

    /** @return resource */
    private function openBoundedNginxDownload(string $url, float $deadline)
    {
        $current = $url;
        for ($redirect = 0; $redirect <= self::MAX_DOWNLOAD_REDIRECTS; ++$redirect) {
            $parts = \parse_url($current);
            if (!\is_array($parts)
                || !\hash_equals('https', \strtolower((string)($parts['scheme'] ?? '')))
                || !\hash_equals(self::DOWNLOAD_HOST, \strtolower((string)($parts['host'] ?? '')))
                || isset($parts['user'])
                || isset($parts['pass'])
                || isset($parts['fragment'])
                || (isset($parts['port']) && (int)$parts['port'] !== 443)
            ) {
                throw new \RuntimeException(
                    'Managed nginx downloads and redirects must remain on canonical nginx.org HTTPS URLs.'
                );
            }
            $remaining = $deadline - $this->monotonicSeconds();
            if ($remaining <= 0.0) {
                throw new \RuntimeException('Managed nginx download exceeded its total deadline.');
            }
            $context = \stream_context_create([
                'http' => [
                    'follow_location' => 0,
                    'ignore_errors' => true,
                    'timeout' => (int)\max(1, \min(
                        self::DOWNLOAD_READ_TIMEOUT_SECONDS,
                        \ceil($remaining),
                    )),
                    'user_agent' => 'WelineFramework-ManagedNginx/' . self::VERSION,
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'SNI_enabled' => true,
                ],
            ]);
            $http_response_header = [];
            $stream = @\fopen($current, 'rb', false, $context);
            $headers = \is_array($http_response_header) ? $http_response_header : [];
            $status = 0;
            $location = '';
            $contentLength = null;
            foreach ($headers as $header) {
                $header = (string)$header;
                if (\preg_match('/\AHTTP\/\S+\s+([0-9]{3})\b/i', $header, $match) === 1) {
                    $status = (int)$match[1];
                    $location = '';
                    $contentLength = null;
                } elseif (\stripos($header, 'Location:') === 0) {
                    $location = \trim(\substr($header, 9));
                } elseif (\stripos($header, 'Content-Length:') === 0) {
                    $length = \trim(\substr($header, 15));
                    if (\preg_match('/\A[0-9]+\z/D', $length) !== 1) {
                        if (\is_resource($stream)) {
                            @\fclose($stream);
                        }
                        throw new \RuntimeException('Managed nginx download returned an invalid content length.');
                    }
                    $contentLength = (int)$length;
                }
            }
            if ($status >= 200 && $status < 300 && \is_resource($stream)) {
                if ($contentLength !== null && $contentLength > self::MAX_DOWNLOAD_BYTES) {
                    @\fclose($stream);
                    throw new \RuntimeException('Managed nginx download exceeds the fixed 512 MiB limit.');
                }
                return $stream;
            }
            if (\is_resource($stream)) {
                @\fclose($stream);
            }
            if ($status < 300 || $status > 399 || $location === '') {
                throw new \RuntimeException('Managed nginx HTTPS endpoint returned an invalid response.');
            }
            if ($redirect >= self::MAX_DOWNLOAD_REDIRECTS) {
                throw new \RuntimeException('Managed nginx download exceeded its redirect limit.');
            }
            $current = $this->resolveNginxHttpsRedirect($current, $location);
        }

        throw new \RuntimeException('Managed nginx redirect resolution failed.');
    }

    private function resolveNginxHttpsRedirect(string $base, string $location): string
    {
        if ($location === '' || \preg_match('/[\x00-\x20\x7f]/', $location) === 1) {
            throw new \RuntimeException('Managed nginx redirect location is malformed.');
        }
        if (\preg_match('/\Ahttps:\/\//i', $location) === 1) {
            return $location;
        }
        if (\str_starts_with($location, '//')
            || \preg_match('/\A[A-Za-z][A-Za-z0-9+.-]*:/D', $location) === 1
        ) {
            throw new \RuntimeException('Managed nginx redirect attempted a protocol change.');
        }
        $parts = \parse_url($base);
        if (!\is_array($parts) || !isset($parts['host'])) {
            throw new \RuntimeException('Managed nginx redirect base is invalid.');
        }
        $authority = 'https://' . $parts['host'];
        if (isset($parts['port'])) {
            $authority .= ':' . (int)$parts['port'];
        }
        if (\str_starts_with($location, '/')) {
            return $authority . $location;
        }
        $path = (string)($parts['path'] ?? '/');
        return $authority . \substr($path, 0, (int)\strrpos($path, '/') + 1) . $location;
    }

    private function monotonicSeconds(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }

    private function validDownloadedFile(string $path): bool
    {
        $status = @\lstat($path);
        $size = \is_array($status) ? (int)($status['size'] ?? -1) : -1;

        return \is_array($status)
            && !\is_link($path)
            && ((((int)($status['mode'] ?? 0)) & 0170000) === 0100000)
            && (int)($status['nlink'] ?? 0) === 1
            && $size > 1000
            && $size <= self::MAX_DOWNLOAD_BYTES;
    }

    private function publishDownloadedFile(string $temporary, string $destination): void
    {
        if (\file_exists($destination) || \is_link($destination)) {
            if ((\file_exists($temporary) || \is_link($temporary)) && !@\unlink($temporary)) {
                throw new \RuntimeException('download destination collision and partial cleanup failed: ' . $temporary);
            }
            throw new \RuntimeException('download destination appeared before publication: ' . $destination);
        }
        if (!$this->validDownloadedFile($temporary) || !@\rename($temporary, $destination)) {
            if ((\file_exists($temporary) || \is_link($temporary)) && !@\unlink($temporary)) {
                throw new \RuntimeException('download publication and cleanup both failed: ' . $temporary);
            }
            throw new \RuntimeException('unable to publish validated download: ' . $destination);
        }
        if (\PHP_OS_FAMILY !== 'Windows' && !@\chmod($destination, 0600)) {
            if (!@\unlink($destination) && (\file_exists($destination) || \is_link($destination))) {
                throw new \RuntimeException('download permission sealing and cleanup both failed: ' . $destination);
            }
            throw new \RuntimeException('unable to seal downloaded artifact permissions: ' . $destination);
        }
    }

    private function assertSha256(string $file, string $expected): void
    {
        if (!$this->validDownloadedFile($file)) {
            throw new \RuntimeException('downloaded artifact is not a bounded private regular file: ' . $file);
        }
        $actual = $this->sha256RegularFile($file, self::MAX_DOWNLOAD_BYTES);
        if (!\is_string($actual) || !\hash_equals(\strtolower($expected), \strtolower($actual))) {
            if (!@\unlink($file) && (\file_exists($file) || \is_link($file))) {
                throw new \RuntimeException('SHA-256 mismatch and artifact cleanup failed for ' . $file);
            }
            throw new \RuntimeException('SHA-256 mismatch for ' . $file);
        }
    }

    private function sha256RegularFile(string $path, int $maximumBytes): ?string
    {
        $named = @\lstat($path);
        $expectedBytes = \is_array($named) ? (int)($named['size'] ?? -1) : -1;
        if (!\is_array($named)
            || \is_link($path)
            || ((((int)($named['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($named['nlink'] ?? 0) !== 1
            || $expectedBytes < 1
            || $expectedBytes > $maximumBytes
        ) {
            return null;
        }
        $handle = @\fopen($path, 'rb');
        if (!\is_resource($handle)) {
            return null;
        }
        $digest = \hash_init('sha256');
        $readBytes = 0;
        try {
            $opened = @\fstat($handle);
            if (!\is_array($opened)
                || ((((int)($opened['mode'] ?? 0)) & 0170000) !== 0100000)
                || (int)($opened['nlink'] ?? 0) !== 1
                || (int)($opened['dev'] ?? -1) !== (int)($named['dev'] ?? -2)
                || (int)($opened['ino'] ?? -1) !== (int)($named['ino'] ?? -2)
                || (int)($opened['size'] ?? -1) !== $expectedBytes
            ) {
                return null;
            }
            while ($readBytes < $expectedBytes) {
                $chunk = @\fread($handle, \min(1024 * 1024, $expectedBytes - $readBytes));
                if (!\is_string($chunk) || $chunk === '') {
                    return null;
                }
                \hash_update($digest, $chunk);
                $readBytes += \strlen($chunk);
            }
            $extra = @\fread($handle, 1);
            $openedAfter = @\fstat($handle);
            if (!\is_string($extra)
                || $extra !== ''
                || !\is_array($openedAfter)
                || (int)($openedAfter['size'] ?? -1) !== $expectedBytes
                || (int)($openedAfter['mtime'] ?? -1) !== (int)($opened['mtime'] ?? -2)
                || (int)($openedAfter['ctime'] ?? -1) !== (int)($opened['ctime'] ?? -2)
            ) {
                return null;
            }
        } finally {
            @\fclose($handle);
        }
        $after = @\lstat($path);
        if (!\is_array($after)
            || \is_link($path)
            || (int)($after['dev'] ?? -1) !== (int)($named['dev'] ?? -2)
            || (int)($after['ino'] ?? -1) !== (int)($named['ino'] ?? -2)
            || (int)($after['size'] ?? -1) !== $expectedBytes
            || (int)($after['mtime'] ?? -1) !== (int)($named['mtime'] ?? -2)
            || (int)($after['ctime'] ?? -1) !== (int)($named['ctime'] ?? -2)
        ) {
            return null;
        }

        return \hash_final($digest);
    }

    private function detectCc(): ?string
    {
        foreach (['clang', 'gcc', 'cc'] as $bin) {
            $executable = $this->executablePath($bin);
            if ($executable !== null) {
                return $executable;
            }
        }
        return null;
    }

    private function detectParallelJobs(): int
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $raw = \trim((string)\getenv('NUMBER_OF_PROCESSORS'));
            $n = \preg_match('/\A[1-9][0-9]*\z/D', $raw) === 1 ? (int)$raw : 2;
            return \max(1, \min(self::MAX_PARALLEL_JOBS, $n));
        }
        if (\is_readable('/proc/cpuinfo')) {
            $cpuInfo = $this->readBoundedLocalFile('/proc/cpuinfo', self::MAX_CPUINFO_BYTES);
            if ($cpuInfo !== null) {
                $count = \preg_match_all('/^processor\s*:/m', $cpuInfo);
                if (\is_int($count) && $count > 0) {
                    return \min(self::MAX_PARALLEL_JOBS, $count);
                }
            }
        }
        if (\PHP_OS_FAMILY === 'Darwin') {
            return $this->boundedProcessorCount(['/usr/sbin/sysctl', '-n', 'hw.ncpu']);
        }
        foreach (['/usr/bin/nproc', '/bin/nproc'] as $nproc) {
            if (\is_file($nproc) && \is_executable($nproc)) {
                return $this->boundedProcessorCount([$nproc]);
            }
        }

        return 2;
    }

    /** @param list<string> $command */
    private function boundedProcessorCount(array $command): int
    {
        if (!\is_file($command[0]) || !\is_executable($command[0])) {
            return 2;
        }
        $result = GatewayBoundedCommandRunner::run($command, 5.0);
        $raw = \trim((string)($result['output'] ?? ''));
        $count = (int)($result['code'] ?? 1) === 0
            && \preg_match('/\A[1-9][0-9]*\z/D', $raw) === 1
            ? (int)$raw
            : 2;

        return \max(1, \min(self::MAX_PARALLEL_JOBS, $count));
    }

    private function readBoundedLocalFile(string $path, int $maximumBytes): ?string
    {
        $handle = @\fopen($path, 'rb');
        if (!\is_resource($handle)) {
            return null;
        }
        try {
            $contents = @\stream_get_contents($handle, $maximumBytes + 1);
        } finally {
            @\fclose($handle);
        }

        return \is_string($contents) && \strlen($contents) <= $maximumBytes
            ? $contents
            : null;
    }

    private function binaryArchitecture(string $binary): string
    {
        if (!\is_file($binary)) {
            return '';
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            $handle = @\fopen($binary, 'rb');
            if (!\is_resource($handle)) {
                return '';
            }
            $header = @\fread($handle, 64);
            if (!\is_string($header) || \strlen($header) < 64 || \substr($header, 0, 2) !== 'MZ') {
                @\fclose($handle);
                return '';
            }
            $offset = \unpack('Voffset', \substr($header, 0x3c, 4));
            $peOffset = (int)($offset['offset'] ?? 0);
            if ($peOffset <= 0 || @\fseek($handle, $peOffset) !== 0) {
                @\fclose($handle);
                return '';
            }
            $pe = @\fread($handle, 6);
            @\fclose($handle);
            if (!\is_string($pe) || \strlen($pe) < 6 || \substr($pe, 0, 4) !== "PE\0\0") {
                return '';
            }
            $machine = \unpack('vmachine', \substr($pe, 4, 2));
            return match ((int)($machine['machine'] ?? 0)) {
                0x8664 => 'x86_64',
                0xaa64 => 'arm64',
                0x014c => 'x86',
                default => '',
            };
        }
        $header = @\file_get_contents($binary, false, null, 0, 32);
        if (\is_string($header) && \strlen($header) >= 20) {
            if (\substr($header, 0, 4) === "\x7fELF") {
                $format = \ord($header[5]) === 2 ? 'n' : 'v';
                $machine = \unpack($format . 'machine', \substr($header, 18, 2));
                $detected = match ((int)($machine['machine'] ?? 0)) {
                    62 => 'x86_64',
                    183 => 'arm64',
                    3 => 'x86',
                    default => '',
                };
                if ($detected !== '') {
                    return $detected;
                }
            }
            $magic = \bin2hex(\substr($header, 0, 4));
            if ($magic === 'cffaedfe' || $magic === 'cefaedfe') {
                $cpu = \unpack('Vcpu', \substr($header, 4, 4));
                $detected = match ((int)($cpu['cpu'] ?? 0)) {
                    0x0100000c => 'arm64',
                    0x01000007 => 'x86_64',
                    7 => 'x86',
                    default => '',
                };
                if ($detected !== '') {
                    return $detected;
                }
            }
        }
        $file = $this->executablePath('file');
        if ($file === null) {
            return '';
        }
        $result = $this->runTool([$file, '-b', $binary], self::TOOL_PROBE_TIMEOUT_SECONDS);
        if ((int)$result['code'] !== 0) {
            return '';
        }
        $description = \strtolower((string)$result['output']);
        return match (true) {
            \str_contains($description, 'arm64'), \str_contains($description, 'aarch64') => 'arm64',
            \str_contains($description, 'x86-64'), \str_contains($description, 'x86_64') => 'x86_64',
            \str_contains($description, '80386'), \str_contains($description, 'i386') => 'x86',
            default => '',
        };
    }

    private function normalizeArchitecture(string $architecture): string
    {
        return match (\strtolower(\trim($architecture))) {
            'amd64', 'x86_64', 'x64' => 'x86_64',
            'arm64', 'aarch64' => 'arm64',
            'x86', 'i386', 'i686' => 'x86',
            default => \strtolower(\trim($architecture)),
        };
    }

    private function commandExists(string $name): bool
    {
        return $this->executablePath($name) !== null;
    }

    private function executablePath(string $name): ?string
    {
        $name = \strtolower(\trim($name));
        if (\preg_match('/\A[a-z0-9_.+-]+\z/D', $name) !== 1) {
            return null;
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            $systemRoot = \rtrim(\trim((string)\getenv('SystemRoot')), '/\\');
            if ($systemRoot === ''
                || \str_contains($systemRoot, "\0")
                || \preg_match('/\A[A-Za-z]:[\\\\\/]/D', $systemRoot) !== 1
            ) {
                return null;
            }
            $candidates = match ($name) {
                'powershell', 'powershell.exe' => [
                    $systemRoot . DIRECTORY_SEPARATOR . 'System32' . DIRECTORY_SEPARATOR
                        . 'WindowsPowerShell' . DIRECTORY_SEPARATOR . 'v1.0' . DIRECTORY_SEPARATOR . 'powershell.exe',
                ],
                'tar', 'tar.exe' => [
                    $systemRoot . DIRECTORY_SEPARATOR . 'System32' . DIRECTORY_SEPARATOR . 'tar.exe',
                ],
                default => [],
            };
        } else {
            $candidates = match ($name) {
                'tar' => ['/usr/bin/tar', '/bin/tar', '/opt/homebrew/bin/tar', '/usr/local/bin/tar'],
                'make' => ['/usr/bin/make', '/bin/make', '/opt/homebrew/bin/make', '/usr/local/bin/make'],
                'clang' => [
                    '/usr/bin/clang',
                    '/Library/Developer/CommandLineTools/usr/bin/clang',
                    '/Applications/Xcode.app/Contents/Developer/Toolchains/XcodeDefault.xctoolchain/usr/bin/clang',
                ],
                'gcc' => ['/usr/bin/gcc', '/bin/gcc', '/opt/homebrew/bin/gcc', '/usr/local/bin/gcc'],
                'cc' => ['/usr/bin/cc', '/bin/cc', '/opt/homebrew/bin/cc', '/usr/local/bin/cc'],
                'pkg-config' => [
                    '/usr/bin/pkg-config',
                    '/bin/pkg-config',
                    '/opt/homebrew/bin/pkg-config',
                    '/usr/local/bin/pkg-config',
                ],
                'xcrun' => ['/usr/bin/xcrun'],
                'brew' => ['/opt/homebrew/bin/brew', '/usr/local/bin/brew'],
                'file' => ['/usr/bin/file', '/bin/file'],
                default => [],
            };
        }
        foreach ($candidates as $candidate) {
            $real = @\realpath($candidate);
            if (\is_string($real)
                && $real !== ''
                && !\str_contains($real, "\0")
                && \is_file($real)
                && \is_executable($real)
            ) {
                return $real;
            }
        }

        return null;
    }

    private function brewPrefix(string $name): ?string
    {
        $brew = $this->executablePath('brew');
        if (\PHP_OS_FAMILY !== 'Darwin' || $brew === null) {
            return null;
        }
        $result = $this->runTool([$brew, '--prefix', $name], self::TOOL_PROBE_TIMEOUT_SECONDS);
        $prefix = (int)$result['code'] === 0 ? \trim((string)$result['output']) : '';
        $real = $prefix !== '' && !\str_contains($prefix, "\0") ? @\realpath($prefix) : false;

        return \is_string($real) && $real !== '' && \is_dir($real) ? $real : null;
    }

    private function pkgExists(string $name): bool
    {
        $pkgConfig = $this->executablePath('pkg-config');
        if ($pkgConfig === null || \preg_match('/\A[a-zA-Z0-9_.+\-]+\z/D', $name) !== 1) {
            return false;
        }
        $result = $this->runTool([$pkgConfig, '--exists', $name], self::TOOL_PROBE_TIMEOUT_SECONDS);

        return (int)$result['code'] === 0;
    }

    /** @param list<string> $command @return array{code:int,output:string} */
    private function runTool(
        array $command,
        float $timeoutSeconds,
        ?string $workingDirectory = null,
        bool $failOnTruncatedOutput = true,
    ): array
    {
        try {
            return GatewayBoundedCommandRunner::run(
                $command,
                $timeoutSeconds,
                $workingDirectory,
                $failOnTruncatedOutput,
            );
        } catch (\InvalidArgumentException $exception) {
            return ['code' => 126, 'output' => $exception->getMessage()];
        }
    }

    private function tailText(string $text, int $max): string
    {
        if (\function_exists('mb_substr')) {
            return \mb_substr($text, -$max);
        }
        return \substr($text, -$max);
    }

    private function removeTree(string $dir): void
    {
        if (!\file_exists($dir) && !\is_link($dir)) {
            return;
        }
        if (!\is_dir($dir) || \is_link($dir)) {
            throw new \RuntimeException(
                'nginx installer cleanup root is linked or special: ' . $dir
            );
        }
        $records = GatewayBoundedTreeWalker::collect(
            $dir,
            true,
            true,
            self::MAX_TREE_ENTRIES,
            self::MAX_TREE_DEPTH,
        );
        foreach ($records as $record) {
            GatewayBoundedTreeWalker::revalidate($record);
            $removed = $record['directory']
                ? @\rmdir($record['path'])
                : @\unlink($record['path']);
            if (!$removed) {
                throw new \RuntimeException('unable to remove nginx installer entry: ' . $record['path']);
            }
        }
    }

    private function copyTree(string $src, string $dst): void
    {
        $src = \rtrim($src, '/\\');
        $dst = \rtrim($dst, '/\\');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $records = [];
        $totalBytes = 0;
        foreach ($iterator as $item) {
            if (\count($records) >= self::MAX_TREE_ENTRIES || $iterator->getDepth() > self::MAX_TREE_DEPTH) {
                throw new \RuntimeException('nginx copy tree exceeds the fixed traversal limit.');
            }
            $source = $item->getPathname();
            if ($item->isLink() || \is_link($source) || (!$item->isDir() && !$item->isFile())) {
                throw new \RuntimeException('nginx copy tree contains a linked or special entry: ' . $source);
            }
            $size = $item->isFile() ? $item->getSize() : 0;
            $totalBytes += \max(0, $size);
            if ($totalBytes > self::MAX_TREE_BYTES) {
                throw new \RuntimeException('nginx copy tree exceeds the fixed 512 MiB limit.');
            }
            $records[] = [
                'source' => $source,
                'relative' => $iterator->getSubPathName(),
                'directory' => $item->isDir(),
                'size' => $size,
            ];
        }
        foreach ($records as $record) {
            $target = $dst . DIRECTORY_SEPARATOR . $record['relative'];
            if ($record['directory']) {
                if (!\is_dir($target)) {
                    if (!@\mkdir($target, 0755, true) && !\is_dir($target)) {
                        throw new \RuntimeException('unable to create nginx install directory: ' . $target);
                    }
                }
            } else {
                $parent = \dirname($target);
                if (!\is_dir($parent)) {
                    if (!@\mkdir($parent, 0755, true) && !\is_dir($parent)) {
                        throw new \RuntimeException('unable to create nginx install directory: ' . $parent);
                    }
                }
                $this->copyRegularFileSafely(
                    (string)$record['source'],
                    $target,
                    (int)$record['size'],
                );
            }
        }
    }

    private function assertSafeTree(string $root, string $label): void
    {
        $rootStatus = @\lstat($root);
        if (!\is_array($rootStatus)
            || \is_link($root)
            || ((((int)($rootStatus['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException($label . ' root is unsafe.');
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        $entries = 0;
        $totalBytes = 0;
        foreach ($iterator as $item) {
            if (++$entries > self::MAX_TREE_ENTRIES
                || $iterator->getDepth() > self::MAX_TREE_DEPTH
            ) {
                throw new \RuntimeException($label . ' exceeds its fixed traversal limit.');
            }
            $path = $item->getPathname();
            $status = @\lstat($path);
            if (!\is_array($status)
                || $item->isLink()
                || \is_link($path)
                || (!$item->isDir() && !$item->isFile())
                || ($item->isFile()
                    && ((((int)($status['mode'] ?? 0)) & 0170000) !== 0100000
                        || (int)($status['nlink'] ?? 0) !== 1))
            ) {
                throw new \RuntimeException($label . ' contains a linked or special entry.');
            }
            if ($item->isFile()) {
                $size = (int)($status['size'] ?? -1);
                if ($size < 0 || $size > self::MAX_TREE_BYTES - $totalBytes) {
                    throw new \RuntimeException($label . ' exceeds its fixed byte limit.');
                }
                $totalBytes += $size;
            }
        }
    }

    private function copyRegularFileSafely(string $source, string $target, int $expectedBytes): void
    {
        if ($expectedBytes < 0
            || $expectedBytes > self::MAX_TREE_BYTES
            || \file_exists($target)
            || \is_link($target)
        ) {
            throw new \RuntimeException('unsafe managed nginx copy target or size: ' . $target);
        }
        $named = @\lstat($source);
        if (!\is_array($named)
            || \is_link($source)
            || ((((int)($named['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($named['nlink'] ?? 0) !== 1
            || (int)($named['size'] ?? -1) !== $expectedBytes
        ) {
            throw new \RuntimeException('managed nginx source changed before copy: ' . $source);
        }
        $input = @\fopen($source, 'rb');
        $output = @\fopen($target, 'xb');
        if (!\is_resource($input) || !\is_resource($output)) {
            if (\is_resource($input)) {
                @\fclose($input);
            }
            if (\is_resource($output)) {
                @\fclose($output);
            }
            if ((\file_exists($target) || \is_link($target)) && !@\unlink($target)) {
                throw new \RuntimeException('managed nginx copy setup and cleanup failed: ' . $target);
            }
            throw new \RuntimeException('unable to open managed nginx copy handles: ' . $target);
        }

        $copyFailure = null;
        try {
            $opened = @\fstat($input);
            if (!\is_array($opened)
                || (int)($opened['dev'] ?? -1) !== (int)($named['dev'] ?? -2)
                || (int)($opened['ino'] ?? -1) !== (int)($named['ino'] ?? -2)
                || ((((int)($opened['mode'] ?? 0)) & 0170000) !== 0100000)
                || (int)($opened['nlink'] ?? 0) !== 1
                || (int)($opened['size'] ?? -1) !== $expectedBytes
            ) {
                throw new \RuntimeException('managed nginx source identity changed while opening: ' . $source);
            }
            $copied = 0;
            while ($copied < $expectedBytes) {
                $chunk = @\fread($input, \min(1024 * 1024, $expectedBytes - $copied));
                if (!\is_string($chunk) || $chunk === '') {
                    throw new \RuntimeException('managed nginx source was truncated during copy: ' . $source);
                }
                $offset = 0;
                while ($offset < \strlen($chunk)) {
                    $written = @\fwrite($output, \substr($chunk, $offset));
                    if (!\is_int($written) || $written < 1) {
                        throw new \RuntimeException('managed nginx target write failed: ' . $target);
                    }
                    $offset += $written;
                }
                $copied += \strlen($chunk);
            }
            $extra = @\fread($input, 1);
            if (!\is_string($extra) || $extra !== '') {
                throw new \RuntimeException('managed nginx source grew during copy: ' . $source);
            }
            if (!@\fflush($output)) {
                throw new \RuntimeException('managed nginx target flush failed: ' . $target);
            }
            if (\function_exists('fsync') && !@\fsync($output)) {
                throw new \RuntimeException('managed nginx target fsync failed: ' . $target);
            }
        } catch (\Throwable $throwable) {
            $copyFailure = $throwable;
        } finally {
            @\fclose($input);
            @\fclose($output);
        }
        if ($copyFailure !== null) {
            if ((\file_exists($target) || \is_link($target)) && !@\unlink($target)) {
                throw new \RuntimeException(
                    'managed nginx copy failed and partial cleanup failed: ' . $target,
                    0,
                    $copyFailure,
                );
            }
            throw $copyFailure;
        }
        $mode = (((int)($named['mode'] ?? 0)) & 0111) !== 0 ? 0755 : 0644;
        if (!@\chmod($target, $mode)) {
            throw new \RuntimeException('unable to set managed nginx copied file permissions: ' . $target);
        }
    }
}
