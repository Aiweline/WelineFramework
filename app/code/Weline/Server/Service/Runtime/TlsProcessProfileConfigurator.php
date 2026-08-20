<?php
declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;

/**
 * Configures OpenSSL before WLS child PHP processes are spawned.
 *
 * PHP streams expose protocol/cipher controls but not the TLS 1.3 supported
 * groups list. The performance profile therefore uses OpenSSL's process-level
 * SSL_CONF hook. It remains opt-out and never overwrites an operator supplied
 * OPENSSL_CONF.
 */
final class TlsProcessProfileConfigurator
{
    public const PROFILE_PERFORMANCE = 'performance';
    public const PROFILE_SYSTEM = 'system';

    private const MAX_CONFIG_BYTES = 4096;
    private const MAX_RECOVERY_ARTIFACTS = 128;
    private const MAX_RUNTIME_DIRECTORY_ENTRIES = 4096;
    private const PUBLICATION_LOCK_LEAF = '.openssl-performance.lock';
    private const PUBLICATION_LOCK_WAIT_SECONDS = 30.0;

    /**
     * @param array<string, mixed> $config
     * @return array{requested:string,effective:string,openssl_conf:?string,reason:string}
     */
    public function activate(
        array $config,
        bool $sslEnabled,
        ?float $deadlineMonotonic = null,
    ): array
    {
        $selection = $this->resolveConfiguration($config);
        $requested = $selection['requested'];
        $protocols = $selection['protocols'];

        if (!$sslEnabled) {
            return [
                'requested' => $requested,
                'effective' => 'disabled',
                'openssl_conf' => null,
                'reason' => 'HTTPS is disabled',
            ];
        }

        if (\in_array('tls1.2', $protocols, true)
            && !\defined('STREAM_CRYPTO_METHOD_TLSv1_2_SERVER')
        ) {
            throw new \RuntimeException(
                'WLS TLS 1.2 requires a PHP/OpenSSL build exposing STREAM_CRYPTO_METHOD_TLSv1_2_SERVER.'
            );
        }
        if ($this->tls13Requested($protocols) && !\defined('STREAM_CRYPTO_METHOD_TLSv1_3_SERVER')) {
            throw new \RuntimeException(
                'WLS TLS 1.3 requires a PHP/OpenSSL build exposing STREAM_CRYPTO_METHOD_TLSv1_3_SERVER.'
            );
        }

        if ($requested === self::PROFILE_SYSTEM) {
            return [
                'requested' => $requested,
                'effective' => self::PROFILE_SYSTEM,
                'openssl_conf' => $this->currentOpenSslConfig(),
                'reason' => 'using the operator/system OpenSSL group policy',
            ];
        }

        $existing = $this->currentOpenSslConfig();
        if ($existing !== null) {
            return [
                'requested' => $requested,
                'effective' => 'external',
                'openssl_conf' => $existing,
                'reason' => 'preserving the operator supplied OPENSSL_CONF',
            ];
        }

        $path = $this->writePerformanceConfig($deadlineMonotonic);
        \putenv('OPENSSL_CONF=' . $path);
        $_ENV['OPENSSL_CONF'] = $path;
        $_SERVER['OPENSSL_CONF'] = $path;

        return [
            'requested' => $requested,
            'effective' => self::PROFILE_PERFORMANCE,
            'openssl_conf' => $path,
            'reason' => 'TLS 1.3 groups pinned to X25519:P-256 for lower handshake CPU and wire size',
        ];
    }

    /**
     * Resolve the transport-neutral TLS contract once so native PHP TLS and
     * the public WLS gateway cannot silently apply different versions or
     * key-exchange profiles.
     *
     * @param array<string, mixed> $config
     * @return array{requested:string,protocols:non-empty-list<'tls1.2'|'tls1.3'>}
     */
    public function resolveConfiguration(array $config): array
    {
        $ssl = \is_array($config['ssl'] ?? null) ? $config['ssl'] : [];

        return [
            'requested' => $this->normalizeProfile(
                $ssl['key_exchange_profile'] ?? self::PROFILE_PERFORMANCE
            ),
            'protocols' => $this->normalizeProtocols($ssl),
        ];
    }

    private function normalizeProfile(mixed $profile): string
    {
        $profile = \strtolower(\trim((string)$profile));
        if ($profile === '' || $profile === 'auto' || $profile === 'optimized') {
            return self::PROFILE_PERFORMANCE;
        }
        if (\in_array($profile, ['system', 'default', 'post_quantum', 'post-quantum'], true)) {
            return self::PROFILE_SYSTEM;
        }
        if ($profile !== self::PROFILE_PERFORMANCE) {
            throw new \RuntimeException(
                'wls.ssl.key_exchange_profile must be performance or system.'
            );
        }

        return $profile;
    }

    /**
     * @param array<string, mixed> $ssl
     * @return non-empty-list<'tls1.2'|'tls1.3'>
     */
    private function normalizeProtocols(array $ssl): array
    {
        $configured = \array_key_exists('protocols', $ssl)
            ? $ssl['protocols']
            : (\array_key_exists('server_protocols', $ssl)
                ? $ssl['server_protocols']
                : ['tls1.2', 'tls1.3']);
        if (\is_string($configured)) {
            $configured = \preg_split('/[\s,|]+/', $configured, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (!\is_array($configured) || $configured === []) {
            throw new \RuntimeException(
                'wls.ssl.protocols must be a non-empty list containing only tls1.2 and/or tls1.3.'
            );
        }

        $protocols = [];
        foreach ($configured as $protocol) {
            if (!\is_string($protocol)) {
                throw new \RuntimeException(
                    'wls.ssl.protocols must contain only string values tls1.2 and/or tls1.3.'
                );
            }
            $protocol = \strtolower(\str_replace(['_', '-', ' '], ['.', '.', ''], \trim((string)$protocol)));
            $protocol = \str_replace('tlsv', 'tls', $protocol);
            if (\in_array($protocol, ['1.2', 'tls1.2', 'tls12'], true)) {
                $protocols[] = 'tls1.2';
                continue;
            }
            if (\in_array($protocol, ['1.3', 'tls1.3', 'tls13'], true)) {
                $protocols[] = 'tls1.3';
                continue;
            }

            throw new \RuntimeException(
                'wls.ssl.protocols contains unsupported value "' . $protocol
                . '"; only tls1.2 and tls1.3 are allowed.'
            );
        }

        $protocols = \array_values(\array_unique($protocols));
        if ($protocols === []) {
            throw new \RuntimeException(
                'wls.ssl.protocols must enable at least one of tls1.2 or tls1.3.'
            );
        }

        return $protocols;
    }

    /** @param list<string> $protocols */
    private function tls13Requested(array $protocols): bool
    {
        return \in_array('tls1.3', $protocols, true);
    }

    private function currentOpenSslConfig(): ?string
    {
        $path = \trim((string)(\getenv('OPENSSL_CONF') ?: ''));
        return $path !== '' ? $path : null;
    }

    private function writePerformanceConfig(?float $deadlineMonotonic = null): string
    {
        $content = <<<'CONF'
openssl_conf = wls_init

[wls_init]
ssl_conf = wls_ssl

[wls_ssl]
system_default = wls_system_default

[wls_system_default]
Groups = X25519:P-256
CONF;
        $content .= "\n";
        $runtime = $this->ensureRuntimeDirectory();
        $directory = $runtime['directory'];
        $path = $directory . DIRECTORY_SEPARATOR
            . 'openssl-performance-' . \substr(\hash('sha256', $content), 0, 16) . '.cnf';
        $this->assertExistingPublicationLock($directory, $runtime['uid']);

        return GatewayProjectStateFilesystem::withExclusiveLock(
            $directory . DIRECTORY_SEPARATOR . self::PUBLICATION_LOCK_LEAF,
            function () use (
                $directory,
                $path,
                $content,
                $runtime,
                $deadlineMonotonic,
            ): string {
                self::deadlineRemaining($deadlineMonotonic);
                $this->recoverPerformanceConfigArtifactsLocked(
                    $directory,
                    $path,
                    $content,
                    $runtime['uid'],
                );
                $snapshot = $this->performanceConfigArtifactSnapshot(
                    $directory,
                    $path,
                    $content,
                    $runtime['uid'],
                );
                if ($snapshot['artifacts'] !== []) {
                    throw new \RuntimeException(
                        'WLS OpenSSL performance config recovery did not converge.'
                    );
                }
                if ($snapshot['target_status'] === null) {
                    GatewayProjectStateFilesystem::atomicWrite(
                        $path,
                        $content,
                        0644,
                        $this->ownedFileSeal($runtime['uid']),
                    );
                    // ReplaceFileW can report a committed generation while a
                    // verified previous-generation backup remains retained.
                    // Reconcile that exact evidence before exposing the path.
                    $this->recoverPerformanceConfigArtifactsLocked(
                        $directory,
                        $path,
                        $content,
                        $runtime['uid'],
                    );
                }

                $published = $this->performanceConfigArtifactSnapshot(
                    $directory,
                    $path,
                    $content,
                    $runtime['uid'],
                );
                if ($published['target_status'] === null || $published['artifacts'] !== []) {
                    throw new \RuntimeException(
                        'WLS OpenSSL performance config publication is incomplete.'
                    );
                }
                return $path;
            },
            $this->ownedFileSeal($runtime['uid']),
            self::lockWaitTimeout($deadlineMonotonic),
        );
    }

    private static function lockWaitTimeout(?float $deadlineMonotonic): float
    {
        if ($deadlineMonotonic === null) {
            return self::PUBLICATION_LOCK_WAIT_SECONDS;
        }
        return \min(
            self::PUBLICATION_LOCK_WAIT_SECONDS,
            self::deadlineRemaining($deadlineMonotonic),
        );
    }

    private static function deadlineRemaining(?float $deadlineMonotonic): float
    {
        if ($deadlineMonotonic === null) {
            return self::PUBLICATION_LOCK_WAIT_SECONDS;
        }
        if (!\is_finite($deadlineMonotonic)) {
            throw new \RuntimeException('WLS OpenSSL profile deadline is invalid.');
        }
        $remaining = $deadlineMonotonic - (\hrtime(true) / 1_000_000_000);
        if ($remaining <= 0.0) {
            throw new \RuntimeException('WLS OpenSSL profile deadline was exhausted.');
        }
        return $remaining;
    }

    /** @return array{directory:string,uid:int} */
    private function ensureRuntimeDirectory(): array
    {
        if (!\defined('BP')) {
            throw new \RuntimeException('WLS project root is not initialized.');
        }
        $root = \rtrim((string)\constant('BP'), '/\\');
        $rootStatus = @\lstat($root);
        if (!\is_array($rootStatus)
            || \is_link($root)
            || ((((int)$rootStatus['mode']) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException('WLS project root is missing or unsafe.');
        }
        $ownerUid = (int)$rootStatus['uid'];
        if (\PHP_OS_FAMILY !== 'Windows' && \function_exists('posix_geteuid')) {
            $effectiveUid = (int)\posix_geteuid();
            if ($effectiveUid !== 0 && $effectiveUid !== $ownerUid) {
                throw new \RuntimeException(
                    'WLS OpenSSL runtime must be prepared by the project owner or root.'
                );
            }
        }

        $directory = $root;
        foreach (['var', 'server', 'tls'] as $leaf) {
            $parent = $directory;
            $directory .= DIRECTORY_SEPARATOR . $leaf;
            $status = @\lstat($directory);
            $created = false;
            if (!\is_array($status)) {
                if (\file_exists($directory) || \is_link($directory)) {
                    throw new \RuntimeException(
                        'WLS OpenSSL runtime directory path is indeterminate or unsafe.'
                    );
                }
                if (@\mkdir($directory, 0755)) {
                    $created = true;
                    if (\PHP_OS_FAMILY !== 'Windows') {
                        if (!@\chmod($directory, 0755)) {
                            throw new \RuntimeException(
                                'Unable to protect WLS TLS runtime directory: ' . $directory
                            );
                        }
                        $this->adoptCreatedPathOwner($directory, $ownerUid);
                    }
                } else {
                    // A concurrent first-start may have created this exact
                    // component after our missing snapshot. Accept only the
                    // fully validated directory it published.
                    $status = @\lstat($directory);
                    if (!\is_array($status)) {
                        throw new \RuntimeException(
                            'Unable to create WLS TLS runtime directory: ' . $directory
                        );
                    }
                }
                $status = @\lstat($directory);
            }
            if (!\is_array($status)) {
                throw new \RuntimeException('WLS OpenSSL runtime directory is missing.');
            }
            $hardened = $this->hardenOwnedDirectory($directory, $status, $ownerUid);
            if (((int)$hardened['mode'] & 0777) !== ((int)$status['mode'] & 0777)) {
                GatewayProjectStateFilesystem::syncDirectory($parent);
                $status = $hardened;
            }
            $this->assertOwnedDirectoryStatus($directory, $status, $ownerUid);
            if ($created) {
                GatewayProjectStateFilesystem::syncDirectory($parent);
            }
        }

        return ['directory' => $directory, 'uid' => $ownerUid];
    }

    /**
     * Existing project trees often ship a group-writable var/ directory.
     * Harden only owner-matched directories before the strict TLS runtime
     * proof runs, mirroring the 0755 mode used on first creation.
     *
     * @param array<string|int,mixed> $status
     * @return array<string|int,mixed>
     */
    private function hardenOwnedDirectory(string $path, array $status, int $ownerUid): array
    {
        if (\PHP_OS_FAMILY === 'Windows'
            || \is_link($path)
            || ((((int)$status['mode']) & 0170000) !== 0040000)
            || (int)$status['uid'] !== $ownerUid
            || ((((int)$status['mode']) & 0022) === 0)
        ) {
            return $status;
        }
        if (!@\chmod($path, 0755)) {
            return $status;
        }
        $after = @\lstat($path);
        return \is_array($after) ? $after : $status;
    }

    private function adoptCreatedPathOwner(string $path, int $ownerUid): void
    {
        $status = @\lstat($path);
        if (!\is_array($status) || (int)$status['uid'] === $ownerUid) {
            return;
        }
        if (!\function_exists('posix_geteuid')
            || (int)\posix_geteuid() !== 0
            || !@\chown($path, $ownerUid)
        ) {
            throw new \RuntimeException('Unable to assign the WLS TLS runtime directory owner.');
        }
    }

    private function assertExistingPublicationLock(string $directory, int $ownerUid): void
    {
        $path = $directory . DIRECTORY_SEPARATOR . self::PUBLICATION_LOCK_LEAF;
        $status = @\lstat($path);
        if (!\is_array($status)) {
            if (\file_exists($path) || \is_link($path)) {
                throw new \RuntimeException(
                    'WLS OpenSSL publication lock path is indeterminate or unsafe.'
                );
            }
            return;
        }
        $this->validatedManagedFile(
            $path,
            $ownerUid,
            0,
            16384,
            'WLS OpenSSL publication lock',
            // A concurrent first creator can be observed after fopen(x+b)
            // and before the common lock primitive seals the inode to 0600.
            // Accept only a non-writable safe mode here; the locked snapshot
            // below still requires the final canonical 0600 mode.
            null,
        );
    }

    /** @return \Closure(resource,string):void */
    private function ownedFileSeal(int $ownerUid): \Closure
    {
        return static function ($handle, string $path) use ($ownerUid): void {
            if (\PHP_OS_FAMILY === 'Windows') {
                return;
            }
            $status = @\fstat($handle);
            if (!\is_array($status)) {
                throw new \RuntimeException('Unable to inspect the WLS OpenSSL publication owner.');
            }
            if ((int)$status['uid'] === $ownerUid) {
                return;
            }
            if (!\function_exists('posix_geteuid')
                || (int)\posix_geteuid() !== 0
                || !\function_exists('fchown')
                || !@\fchown($handle, $ownerUid)
            ) {
                throw new \RuntimeException('Unable to assign the WLS OpenSSL publication owner.');
            }
            $owned = @\fstat($handle);
            if (!\is_array($owned) || (int)$owned['uid'] !== $ownerUid) {
                throw new \RuntimeException('WLS OpenSSL publication owner verification failed.');
            }
        };
    }

    private function recoverPerformanceConfigArtifactsLocked(
        string $directory,
        string $target,
        string $content,
        int $ownerUid,
    ): void {
        $selected = $this->performanceConfigArtifactSnapshot(
            $directory,
            $target,
            $content,
            $ownerUid,
        );
        if ($selected['artifacts'] === []) {
            return;
        }
        $rechecked = $this->performanceConfigArtifactSnapshot(
            $directory,
            $target,
            $content,
            $ownerUid,
        );
        $this->assertEquivalentRecoverySnapshots($selected, $rechecked);
        $this->assertRecoverySnapshotStillCurrent($rechecked, $directory, $target);

        $targetIdentity = $rechecked['target_status'];
        foreach ($rechecked['artifacts'] as $artifact) {
            $currentTarget = @\lstat($target);
            if (!self::sameOptionalFileState($targetIdentity, $currentTarget)) {
                throw new \RuntimeException(
                    'WLS OpenSSL recovery target changed during artifact cleanup.'
                );
            }
            if (!GatewayProjectStateFilesystem::removeRegular(
                $artifact['path'],
                'WLS OpenSSL interrupted publication artifact',
                $artifact['identity'],
            )) {
                throw new \RuntimeException(
                    'Unable to collect a WLS OpenSSL interrupted publication artifact.'
                );
            }
        }

        $after = $this->performanceConfigArtifactSnapshot(
            $directory,
            $target,
            $content,
            $ownerUid,
        );
        if ($after['artifacts'] !== []) {
            throw new \RuntimeException('WLS OpenSSL recovery artifacts remain after cleanup.');
        }
    }

    /**
     * @return array{
     *   directory:array<string|int,mixed>,
     *   target_status:array<string|int,mixed>|null,
     *   artifacts:array<string,array{
     *     path:string,
     *     kind:string,
     *     identity:array<string|int,mixed>
     *   }>
     * }
     */
    private function performanceConfigArtifactSnapshot(
        string $directory,
        string $target,
        string $content,
        int $ownerUid,
    ): array {
        $directoryBefore = @\lstat($directory);
        if (!\is_array($directoryBefore)) {
            throw new \RuntimeException('WLS OpenSSL runtime directory is missing.');
        }
        $this->assertOwnedDirectoryStatus($directory, $directoryBefore, $ownerUid);
        $targetLeaf = \basename(\str_replace('\\', '/', $target));
        $targetFolded = \strtolower($targetLeaf);
        $lockFolded = \strtolower(self::PUBLICATION_LOCK_LEAF);
        $legacyPattern = '/\A' . \preg_quote($targetLeaf, '/')
            . '\.[1-9][0-9]{0,18}\.[a-f0-9]{8}\.tmp\z/D';
        $atomicPattern = '/\A' . \preg_quote($targetLeaf, '/')
            . '\.(tmp-[a-f0-9]{24}|wls-backup-[a-f0-9]{16})\z/D';
        $handle = @\opendir($directory);
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to enumerate the WLS OpenSSL runtime directory.');
        }
        $targetStatus = null;
        $artifacts = [];
        $visited = 0;
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                if (++$visited > self::MAX_RUNTIME_DIRECTORY_ENTRIES) {
                    throw new \RuntimeException(
                        'WLS OpenSSL runtime directory exceeds its raw entry quota.'
                    );
                }
                $folded = \strtolower($leaf);
                $path = $directory . DIRECTORY_SEPARATOR . $leaf;
                if (\hash_equals($lockFolded, $folded)) {
                    if (!\hash_equals(self::PUBLICATION_LOCK_LEAF, $leaf)) {
                        throw new \RuntimeException(
                            'WLS OpenSSL publication lock has a non-canonical case alias.'
                        );
                    }
                    $this->validatedManagedFile(
                        $path,
                        $ownerUid,
                        0,
                        16384,
                        'WLS OpenSSL publication lock',
                        0600,
                    );
                    continue;
                }
                if (\str_starts_with($folded, $lockFolded)) {
                    throw new \RuntimeException(
                        'WLS OpenSSL recovery contains a malformed publication lock leaf.'
                    );
                }
                if (\hash_equals($targetFolded, $folded)) {
                    if (!\hash_equals($targetLeaf, $leaf)) {
                        throw new \RuntimeException(
                            'WLS OpenSSL target has a non-canonical case alias.'
                        );
                    }
                    $targetStatus = $this->validatedPerformanceTarget(
                        $path,
                        $content,
                        $ownerUid,
                    );
                    continue;
                }
                if (!\str_starts_with($folded, $targetFolded . '.')) {
                    continue;
                }
                if (!\hash_equals($leaf, $folded)) {
                    throw new \RuntimeException(
                        'WLS OpenSSL recovery contains a non-canonical case alias.'
                    );
                }
                $kind = '';
                if (\preg_match($legacyPattern, $leaf) === 1) {
                    $kind = 'legacy staging';
                } elseif (\preg_match($atomicPattern, $leaf, $matches) === 1) {
                    $kind = \str_starts_with((string)$matches[1], 'tmp-')
                        ? 'atomic staging'
                        : 'atomic backup';
                } else {
                    throw new \RuntimeException(
                        'WLS OpenSSL recovery contains a malformed reserved leaf.'
                    );
                }
                if (\count($artifacts) >= self::MAX_RECOVERY_ARTIFACTS) {
                    throw new \RuntimeException(
                        'WLS OpenSSL recovery artifact quota is exhausted.'
                    );
                }
                $artifacts[$path] = [
                    'path' => $path,
                    'kind' => $kind,
                    'identity' => $this->validatedManagedFile(
                        $path,
                        $ownerUid,
                        0,
                        self::MAX_CONFIG_BYTES,
                        'WLS OpenSSL recovery artifact',
                        $kind === 'atomic backup' ? 0644 : null,
                    ),
                ];
            }
        } finally {
            @\closedir($handle);
        }

        if ($targetStatus === null) {
            if (\is_array(@\lstat($target)) || \file_exists($target) || \is_link($target)) {
                throw new \RuntimeException('WLS OpenSSL target is indeterminate or unsafe.');
            }
        }
        // A backup is a previous committed generation, never an
        // uncommitted partial. Because the target name is derived from this
        // fixed policy, every legitimate retained backup has exact bytes even
        // when the current target is already valid. Preserve the complete
        // namespace when that semantic proof fails.
        foreach ($artifacts as $path => $artifact) {
            if ($artifact['kind'] !== 'atomic backup') {
                continue;
            }
            $backup = GatewayProjectStateFilesystem::read(
                $path,
                self::MAX_CONFIG_BYTES,
                'WLS OpenSSL recovery backup',
            );
            if (!\hash_equals($content, $backup)) {
                throw new \RuntimeException(
                    'WLS OpenSSL recovery backup does not contain the expected performance policy.'
                );
            }
            $after = @\lstat($path);
            if (!\is_array($after)
                || !self::sameFileState($artifact['identity'], $after)
            ) {
                throw new \RuntimeException(
                    'WLS OpenSSL recovery backup changed during validation.'
                );
            }
            $artifacts[$path]['identity'] = $after;
        }
        $directoryAfter = @\lstat($directory);
        if (!\is_array($directoryAfter)
            || !self::sameFileState($directoryBefore, $directoryAfter)
        ) {
            throw new \RuntimeException(
                'WLS OpenSSL runtime directory changed during recovery inspection.'
            );
        }
        \ksort($artifacts, SORT_STRING);
        return [
            'directory' => $directoryAfter,
            'target_status' => $targetStatus,
            'artifacts' => $artifacts,
        ];
    }

    /** @return array<string|int,mixed> */
    private function validatedPerformanceTarget(
        string $path,
        string $content,
        int $ownerUid,
    ): array {
        $status = $this->validatedManagedFile(
            $path,
            $ownerUid,
            \strlen($content),
            \strlen($content),
            'WLS OpenSSL performance config',
            0644,
        );
        $published = GatewayProjectStateFilesystem::read(
            $path,
            self::MAX_CONFIG_BYTES,
            'WLS OpenSSL performance config',
        );
        if (!\hash_equals($content, $published)) {
            throw new \RuntimeException(
                'WLS OpenSSL performance config does not match the expected performance policy.'
            );
        }
        $after = @\lstat($path);
        if (!\is_array($after) || !self::sameFileState($status, $after)) {
            throw new \RuntimeException(
                'WLS OpenSSL performance config changed during semantic validation.'
            );
        }
        return $after;
    }

    /**
     * @return array<string|int,mixed>
     */
    private function validatedManagedFile(
        string $path,
        int $ownerUid,
        int $minimumBytes,
        int $maximumBytes,
        string $label,
        ?int $requiredMode = null,
    ): array {
        $before = @\lstat($path);
        if (!\is_array($before)
            || \is_link($path)
            || ((((int)$before['mode']) & 0170000) !== 0100000)
            || (int)$before['nlink'] !== 1
        ) {
            throw new \RuntimeException($label . ' must be one regular non-linked file.');
        }
        if ((int)$before['uid'] !== $ownerUid) {
            throw new \RuntimeException($label . ' owner is unsafe.');
        }
        $mode = ((int)$before['mode']) & 0777;
        if (\PHP_OS_FAMILY !== 'Windows'
            && (($requiredMode !== null && $mode !== $requiredMode)
                || ($requiredMode === null && ($mode & 0022) !== 0))
        ) {
            throw new \RuntimeException($label . ' mode is unsafe.');
        }
        $size = (int)$before['size'];
        if ($minimumBytes < 0
            || $maximumBytes < $minimumBytes
            || $size < $minimumBytes
            || $size > $maximumBytes
        ) {
            throw new \RuntimeException($label . ' size is unsafe.');
        }
        $observedSize = GatewayProjectStateFilesystem::size($path, $maximumBytes, $label);
        $after = @\lstat($path);
        if ($observedSize !== $size
            || !\is_array($after)
            || !self::sameFileState($before, $after)
        ) {
            throw new \RuntimeException($label . ' changed during inspection.');
        }
        return $after;
    }

    /** @param array<string|int,mixed> $status */
    private function assertOwnedDirectoryStatus(string $path, array $status, int $ownerUid): void
    {
        if (\is_link($path)
            || ((((int)$status['mode']) & 0170000) !== 0040000)
            || (int)$status['uid'] !== $ownerUid
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((((int)$status['mode']) & 0022) !== 0))
        ) {
            throw new \RuntimeException('WLS OpenSSL runtime directory owner or mode is unsafe.');
        }
    }

    /**
     * @param array{
     *   directory:array<string|int,mixed>,
     *   target_status:array<string|int,mixed>|null,
     *   artifacts:array<string,array{path:string,kind:string,identity:array<string|int,mixed>}>
     * } $selected
     * @param array{
     *   directory:array<string|int,mixed>,
     *   target_status:array<string|int,mixed>|null,
     *   artifacts:array<string,array{path:string,kind:string,identity:array<string|int,mixed>}>
     * } $rechecked
     */
    private function assertEquivalentRecoverySnapshots(array $selected, array $rechecked): void
    {
        if (!self::sameFileState($selected['directory'], $rechecked['directory'])
            || !self::sameOptionalFileState(
                $selected['target_status'],
                $rechecked['target_status'],
            )
            || \array_keys($selected['artifacts']) !== \array_keys($rechecked['artifacts'])
        ) {
            throw new \RuntimeException(
                'WLS OpenSSL recovery namespace changed before cleanup.'
            );
        }
        foreach ($selected['artifacts'] as $path => $artifact) {
            $current = $rechecked['artifacts'][$path] ?? null;
            if (!\is_array($current)
                || !\hash_equals($artifact['kind'], $current['kind'])
                || !self::sameFileState($artifact['identity'], $current['identity'])
            ) {
                throw new \RuntimeException(
                    'WLS OpenSSL recovery artifact changed before cleanup.'
                );
            }
        }
    }

    /**
     * @param array{
     *   directory:array<string|int,mixed>,
     *   target_status:array<string|int,mixed>|null,
     *   artifacts:array<string,array{path:string,kind:string,identity:array<string|int,mixed>}>
     * } $snapshot
     */
    private function assertRecoverySnapshotStillCurrent(
        array $snapshot,
        string $directory,
        string $target,
    ): void {
        $currentDirectory = @\lstat($directory);
        $currentTarget = @\lstat($target);
        if (!\is_array($currentDirectory)
            || !self::sameFileState($snapshot['directory'], $currentDirectory)
            || !self::sameOptionalFileState($snapshot['target_status'], $currentTarget)
        ) {
            throw new \RuntimeException(
                'WLS OpenSSL recovery namespace changed before its first mutation.'
            );
        }
        foreach ($snapshot['artifacts'] as $artifact) {
            $current = @\lstat($artifact['path']);
            if (!\is_array($current)
                || !self::sameFileState($artifact['identity'], $current)
            ) {
                throw new \RuntimeException(
                    'WLS OpenSSL recovery artifact changed before its first mutation.'
                );
            }
        }
    }

    /**
     * @param array<string|int,mixed> $before
     * @param array<string|int,mixed> $after
     */
    private static function sameFileState(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $field) {
            if (!\array_key_exists($field, $before)
                || !\array_key_exists($field, $after)
                || (int)$before[$field] !== (int)$after[$field]
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string|int,mixed>|null $before
     * @param array<string|int,mixed>|false|null $after
     */
    private static function sameOptionalFileState(?array $before, array|false|null $after): bool
    {
        if ($before === null || !\is_array($after)) {
            return $before === null && !\is_array($after);
        }
        return self::sameFileState($before, $after);
    }
}
