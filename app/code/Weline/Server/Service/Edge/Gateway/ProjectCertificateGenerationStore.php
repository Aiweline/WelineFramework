<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Project-owned immutable TLS material with per-domain monotonic activation.
 *
 * Source certificates remain the project fact source. Both gateway
 * registration and the same-Master WLS fallback consume only a validated
 * content-addressed snapshot, so a renewal can never replace a live TLS
 * generation halfway through a read.
 */
final class ProjectCertificateGenerationStore
{
    public const SCHEMA_VERSION = 1;
    private const MAX_MATERIAL_BYTES = 1_048_576;
    private const MAX_STORED_SNAPSHOTS = 1024;
    private const MAX_STORED_SNAPSHOT_BYTES = 1_073_741_824;
    private const SNAPSHOT_RETENTION_SECONDS = 604_800;
    private const MAX_SNAPSHOT_ROOT_ENTRIES = 2048;
    private const MAX_ACTIVE_MANIFESTS = 1024;

    private readonly string $projectRoot;
    private readonly string $storeRoot;
    private readonly int $projectOwner;
    private readonly int $projectGroup;

    /** @var array<string,int> */
    private static array $heldLifecycleLocks = [];

    public function __construct(?string $projectRoot = null)
    {
        $requestedRoot = $projectRoot ?? (string)BP;
        if ($requestedRoot === ''
            || \str_contains($requestedRoot, "\0")
            || \is_link($requestedRoot)
        ) {
            throw new \RuntimeException('Unable to resolve a safe WLS project root.');
        }
        $root = \realpath($requestedRoot);
        $rootStatus = \is_string($root) ? @\lstat($root) : false;
        if (!\is_string($root)
            || $root === ''
            || !\is_array($rootStatus)
            || \is_link($root)
            || ((((int)($rootStatus['mode'] ?? 0)) & 0170000) !== 0040000)
            || $this->isFilesystemRoot($root)
        ) {
            throw new \RuntimeException('Unable to resolve a safe WLS project root.');
        }
        $this->projectRoot = \rtrim($root, '/\\');
        $this->storeRoot = $this->projectRoot . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'ssl'
            . DIRECTORY_SEPARATOR . '.wls-generations';
        $owner = @\lstat($this->projectRoot);
        $this->projectOwner = \is_array($owner) && \is_int($owner['uid'] ?? null)
            ? (int)$owner['uid']
            : -1;
        $this->projectGroup = \is_array($owner) && \is_int($owner['gid'] ?? null)
            ? (int)$owner['gid']
            : -1;
    }

    /**
     * Validate and atomically activate one domain's certificate material.
     *
     * If a newly supplied source is invalid but the currently active snapshot
     * is still valid, the current generation is returned with
     * retained_previous=true and remains active.
     *
     * @return array{
     *   domain:string,
     *   generation:int,
     *   source_digest:string,
     *   cert_path:string,
     *   key_path:string,
     *   chain_path:string,
     *   retained_previous:bool,
     *   activation_error:string
     * }
     */
    public function activate(
        string $domain,
        string $certificate,
        string $privateKey,
        string $chain = '',
        array $sourceRoots = [],
    ): array {
        $this->ensureStoreDirectories();
        return $this->withCertificateLifecycleLock(
            fn (): array => $this->activateWithinLifecycleLock(
                $domain,
                $certificate,
                $privateKey,
                $chain,
                $sourceRoots,
            ),
        );
    }

    /**
     * Share the lifecycle lock with explicit certificate transitions. Nested
     * activation in the same PHP process reuses the already-held authority
     * instead of deadlocking on a second file descriptor.
     */
    public function withCertificateLifecycleLock(callable $callback): mixed
    {
        $this->ensureStoreDirectories();
        $path = $this->certificateLifecycleLockPath();
        if ((self::$heldLifecycleLocks[$path] ?? 0) > 0) {
            self::$heldLifecycleLocks[$path]++;
            try {
                return $callback();
            } finally {
                self::$heldLifecycleLocks[$path]--;
            }
        }
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $path,
            function () use ($path, $callback): mixed {
                self::$heldLifecycleLocks[$path] = 1;
                try {
                    return $callback();
                } finally {
                    unset(self::$heldLifecycleLocks[$path]);
                }
            },
            fn ($handle, string $lockPath): mixed => $this->preserveProjectArtifactOwnership(
                $lockPath,
                $handle,
            ),
            waitTimeoutSeconds: 10.0,
        );
    }

    /**
     * Sign the only durable authority that may cross the current disabled
     * tombstone. The explicit HTTPS lifecycle owns this API; PEM files,
     * database rows and ordinary startup/import paths are not re-enable
     * authority. The intent is exact to both the tombstone and target material.
     *
     * The caller must already hold certificateLifecycleLockPath().
     *
     * @return array{required:bool,domain:string,source_digest:string,intent_id:string}
     */
    public function issueExplicitReenableIntent(
        string $domain,
        string $certificate,
        string $privateKey,
        string $chain = '',
        array $sourceRoots = [],
    ): array {
        $domain = $this->normalizeDomain($domain);
        $this->ensureStoreDirectories();
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $this->storeRoot . DIRECTORY_SEPARATOR . 'activation.lock',
            function () use (
                $domain,
                $certificate,
                $privateKey,
                $chain,
                $sourceRoots,
            ): array {
                $this->assertSourcesInsideRoots(
                    [$certificate, $privateKey, $chain],
                    $sourceRoots,
                );
                $material = $this->validateSourceMaterial(
                    $domain,
                    $certificate,
                    $privateKey,
                    $chain,
                );
                $snapshot = $this->publishSnapshot($material);
                $active = $this->readActiveUnlocked($domain, false);
                $disabled = $this->readDisabledUnlocked($domain);
                $sourceDigest = (string)$snapshot['source_digest'];
                if ($disabled === null
                    || ($active !== null
                        && (int)$active['generation'] > (int)$disabled['generation'])
                ) {
                    $this->removeReenableIntentUnlocked($domain);
                    return [
                        'required' => false,
                        'domain' => $domain,
                        'source_digest' => $sourceDigest,
                        'intent_id' => '',
                    ];
                }
                $intentId = $this->reenableIntentId(
                    $domain,
                    (int)$disabled['generation'],
                    (string)$disabled['source_digest'],
                    $sourceDigest,
                );
                $intent = [
                    'schema' => 'wls-project-certificate-reenable/1',
                    'state' => 'authorized',
                    'domain' => $domain,
                    'disabled_generation' => (int)$disabled['generation'],
                    'disabled_source_digest' => (string)$disabled['source_digest'],
                    'target_source_digest' => $sourceDigest,
                    'intent_id' => $intentId,
                    'issued_at' => \gmdate(DATE_ATOM),
                ];
                $this->publishManifest($this->reenableIntentFile($domain), $intent);
                $verified = $this->readReenableIntentUnlocked($domain);
                if ($verified === null
                    || !\hash_equals($intentId, (string)$verified['intent_id'])
                ) {
                    throw new \RuntimeException(
                        'Explicit certificate re-enable intent was not durably published.',
                    );
                }
                return [
                    'required' => true,
                    'domain' => $domain,
                    'source_digest' => $sourceDigest,
                    'intent_id' => $intentId,
                ];
            },
            fn ($handle, string $path): mixed => $this->preserveProjectArtifactOwnership(
                $path,
                $handle,
            ),
        );
    }

    /** @return array<string,mixed> */
    private function activateWithinLifecycleLock(
        string $domain,
        string $certificate,
        string $privateKey,
        string $chain = '',
        array $sourceRoots = [],
    ): array {
        $domain = $this->normalizeDomain($domain);
        $this->ensureStoreDirectories();
        $lockPath = $this->storeRoot . DIRECTORY_SEPARATOR . 'activation.lock';
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $lockPath,
            function () use (
                $domain,
                $certificate,
                $privateKey,
                $chain,
                $sourceRoots,
            ): array {
            // An expired generation is still the historical generation floor,
            // but it must not prevent a valid replacement from being activated.
            $active = $this->readActiveUnlocked($domain, false);
            $disabled = $this->readDisabledUnlocked($domain);
            try {
                $this->assertSourcesInsideRoots(
                    [$certificate, $privateKey, $chain],
                    $sourceRoots,
                );
                $material = $this->validateSourceMaterial(
                    $domain,
                    $certificate,
                    $privateKey,
                    $chain,
                );
                $snapshot = $this->publishSnapshot($material);
                $reenableIntent = null;
                if ($disabled !== null
                    && ($active === null
                        || (int)$active['generation'] <= (int)$disabled['generation'])
                ) {
                    $reenableIntent = $this->readReenableIntentUnlocked($domain);
                    if ($reenableIntent === null
                        || (int)$reenableIntent['disabled_generation']
                            !== (int)$disabled['generation']
                        || !\hash_equals(
                            (string)$disabled['source_digest'],
                            (string)$reenableIntent['disabled_source_digest'],
                        )
                        || !\hash_equals(
                            (string)$snapshot['source_digest'],
                            (string)$reenableIntent['target_source_digest'],
                        )
                    ) {
                        throw new \RuntimeException(
                            'Certificate is disabled; only an exact explicit re-enable intent may cross its tombstone.',
                        );
                    }
                }
                if ($active !== null
                    && ($disabled === null
                        || (int)$active['generation'] > (int)$disabled['generation'])
                    && \hash_equals(
                        (string)$active['source_digest'],
                        (string)$snapshot['source_digest'],
                    )
                ) {
                    return $active + [
                        'retained_previous' => false,
                        'activation_error' => '',
                    ];
                }
                // Generation is allocated from a project-wide durable floor.
                // Deactivation removes the mutable per-domain selector, so the
                // selector alone cannot prevent generation reuse when the same
                // domain is later imported or enabled again.
                $generation = $this->allocateCertificateGeneration(\max(
                    0,
                    (int)($active['generation'] ?? 0),
                    (int)($disabled['generation'] ?? 0),
                ));
                $next = [
                    'schema_version' => self::SCHEMA_VERSION,
                    'domain' => $domain,
                    'generation' => $generation,
                    'source_digest' => (string)$snapshot['source_digest'],
                    'cert_path' => (string)$snapshot['cert_path'],
                    'key_path' => (string)$snapshot['key_path'],
                    'chain_path' => (string)$snapshot['chain_path'],
                    'cert_sha256' => (string)$snapshot['cert_sha256'],
                    'key_sha256' => (string)$snapshot['key_sha256'],
                    'chain_sha256' => (string)$snapshot['chain_sha256'],
                    'activated_at' => \gmdate(DATE_ATOM),
                    'previous' => $active === null ? null : [
                        'generation' => (int)$active['generation'],
                        'source_digest' => (string)$active['source_digest'],
                        'cert_path' => (string)$active['cert_path'],
                        'key_path' => (string)$active['key_path'],
                        'chain_path' => (string)$active['chain_path'],
                    ],
                ];
                $this->publishManifest($this->activeManifestFile($domain), $next);
                if ($reenableIntent !== null) {
                    // The new active generation is already durably above the
                    // exact tombstone, so a cleanup failure cannot authorize a
                    // second crossing. A later deactivate allocates a newer
                    // tombstone before it removes the selector.
                    try {
                        $this->removeReenableIntentUnlocked($domain);
                    } catch (\Throwable) {
                        // Keep activation recoverable after a post-commit disk
                        // failure; the consumed tombstone generation is the
                        // authoritative one-time fence.
                    }
                }
                return $next + [
                    'retained_previous' => false,
                    'activation_error' => '',
                ];
            } catch (\Throwable $throwable) {
                $retained = null;
                if ($active !== null) {
                    try {
                        $candidate = $this->readActiveUnlocked($domain, true);
                        if ($candidate !== null
                            && ($disabled === null
                                || (int)$candidate['generation']
                                    > (int)$disabled['generation'])
                            && \hash_equals(
                                (string)$active['source_digest'],
                                (string)$candidate['source_digest'],
                            )
                        ) {
                            $retained = $candidate;
                        }
                    } catch (\Throwable) {
                        // An expired or concurrently replaced generation is not
                        // a safe serving fallback. Surface the activation error.
                    }
                }
                if ($retained !== null) {
                    return \array_replace($retained, [
                        'retained_previous' => true,
                        'activation_error' => $throwable->getMessage(),
                    ]);
                }
                throw new \RuntimeException(
                    'No valid active certificate generation is available for ' . $domain
                    . ': ' . $throwable->getMessage(),
                    0,
                    $throwable,
                );
            }
            },
            fn ($handle, string $path): mixed => $this->preserveProjectArtifactOwnership(
                $path,
                $handle,
            ),
        );
    }

    /**
     * @param list<string> $sources
     * @param array<int|string,string> $sourceRoots
     */
    private function assertSourcesInsideRoots(array $sources, array $sourceRoots): void
    {
        if ($sourceRoots === []) {
            $sourceRoots = [
                $this->projectRoot . DIRECTORY_SEPARATOR . 'app'
                    . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'ssl',
            ];
        }
        $roots = [];
        foreach ($sourceRoots as $root) {
            $candidate = (string)$root;
            if ($candidate !== '' && !$this->isAbsolutePath($candidate)) {
                $candidate = $this->projectRoot . DIRECTORY_SEPARATOR . $candidate;
            }
            $canonical = \realpath($candidate);
            $status = @\lstat($candidate);
            if (!\is_string($canonical)
                || $canonical === ''
                || !\is_array($status)
                || \is_link($candidate)
                || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
                || !$this->samePath($candidate, $canonical)
                || $this->isFilesystemRoot($canonical)
            ) {
                throw new \RuntimeException(
                    'Enrolled certificate source root must be a canonical directory.'
                );
            }
            $rootOwner = \is_int($status['uid'] ?? null)
                ? (int)$status['uid']
                : -1;
            $this->assertEnrolledDirectoryComponents(
                $canonical,
                $canonical,
                $rootOwner,
            );
            $roots[$this->pathKey($canonical)] = [
                'path' => $canonical,
                'owner' => $rootOwner,
            ];
        }
        if ($roots === []) {
            throw new \RuntimeException('No enrolled certificate source root is available.');
        }
        foreach ($sources as $source) {
            if ($source === '') {
                continue;
            }
            $real = \realpath($source);
            if (!\is_string($real)
                || !\is_file($real)
                || \is_link($source)
                || !$this->samePath($source, $real)
            ) {
                throw new \RuntimeException('Certificate material file is unavailable.');
            }
            foreach ($roots as $enrollment) {
                $root = (string)$enrollment['path'];
                if ($this->pathInside($real, $root)) {
                    $this->assertEnrolledDirectoryComponents(
                        $root,
                        \dirname($real),
                        (int)$enrollment['owner'],
                    );
                    if (\PHP_OS_FAMILY !== 'Windows'
                        && (int)$enrollment['owner'] >= 0
                    ) {
                        $sourceStatus = @\lstat($real);
                        if (!\is_array($sourceStatus)
                            || (int)($sourceStatus['uid'] ?? -1)
                                !== (int)$enrollment['owner']
                        ) {
                            throw new \RuntimeException(
                                'Certificate source owner differs from its enrolled root owner.'
                            );
                        }
                    }
                    continue 2;
                }
            }
            throw new \RuntimeException(
                'Certificate source is outside every enrolled certificate root: ' . $source
            );
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public function active(string $domain): ?array
    {
        $domain = $this->normalizeDomain($domain);
        $this->ensureStoreDirectories();
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $this->storeRoot . DIRECTORY_SEPARATOR . 'activation.lock',
            function () use ($domain): ?array {
                $active = $this->readActiveUnlocked($domain);
                if ($active === null) {
                    return null;
                }
                $disabled = $this->readDisabledUnlocked($domain);
                if ($disabled !== null
                    && (int)$active['generation'] <= (int)$disabled['generation']
                ) {
                    // deactivate() durably publishes the tombstone before it
                    // removes the mutable selector. Holding the same lock and
                    // comparing both generations makes that crash window an
                    // effective revocation instead of reviving the old PEM.
                    return null;
                }
                return $active;
            },
            fn ($handle, string $path): mixed => $this->preserveProjectArtifactOwnership(
                $path,
                $handle,
            ),
        );
    }

    /**
     * Read the durable, monotonic disabled-certificate tombstone for a domain.
     *
     * @return array{state:string,domain:string,generation:int,source_digest:string,disabled_at:string}|null
     */
    public function disabled(string $domain): ?array
    {
        $domain = $this->normalizeDomain($domain);
        return $this->readDisabledUnlocked($domain);
    }

    /**
     * Enumerate the complete durable disabled-certificate authority.
     *
     * The certificate table may legitimately omit a revoked/deleted row. The
     * tombstone store is therefore the only project-owned fact capable of
     * proving that an absent final certificate is an intentional transition,
     * rather than a transient empty database result.
     *
     * @return array<string,array{state:string,domain:string,generation:int,source_digest:string,disabled_at:string}>
     */
    public function disabledCertificates(): array
    {
        $this->ensureStoreDirectories();
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $this->storeRoot . DIRECTORY_SEPARATOR . 'activation.lock',
            function (): array {
                $root = $this->storeRoot . DIRECTORY_SEPARATOR . 'disabled';
                $handle = @\opendir($root);
                if (!\is_resource($handle)) {
                    throw new \RuntimeException(
                        'Unable to enumerate disabled certificate tombstones.',
                    );
                }
                $facts = [];
                $count = 0;
                try {
                    while (($leaf = @\readdir($handle)) !== false) {
                        if ($leaf === '.' || $leaf === '..') {
                            continue;
                        }
                        if (++$count > self::MAX_ACTIVE_MANIFESTS
                            || \preg_match('/\A[a-f0-9]{32}\.json\z/D', $leaf) !== 1
                        ) {
                            throw new \RuntimeException(
                                'Disabled certificate tombstone set is malformed or outside bounds.',
                            );
                        }
                        $path = $root . DIRECTORY_SEPARATOR . $leaf;
                        $manifest = $this->readManifest($path);
                        $domain = $this->normalizeDomain((string)(
                            $manifest['domain'] ?? ''
                        ));
                        if (!\hash_equals(
                                \substr(\hash('sha256', $domain), 0, 32) . '.json',
                                $leaf,
                            )
                            || isset($facts[$domain])
                        ) {
                            throw new \RuntimeException(
                                'Disabled certificate tombstone identity is inconsistent.',
                            );
                        }
                        $fact = $this->readDisabledUnlocked($domain);
                        if ($fact === null) {
                            throw new \RuntimeException(
                                'Disabled certificate tombstone disappeared during enumeration.',
                            );
                        }
                        $facts[$domain] = $fact;
                    }
                } finally {
                    @\closedir($handle);
                }
                \ksort($facts, SORT_STRING);
                return $facts;
            },
            fn ($handle, string $path): mixed => $this->preserveProjectArtifactOwnership(
                $path,
                $handle,
            ),
        );
    }

    /**
     * Remove only the mutable per-domain selector after the project fact source
     * deletes a certificate. Immutable snapshots remain available to current,
     * authority and recent-LKG serving manifests until ordinary seven-day GC.
     */
    public function deactivate(string $domain): void
    {
        $this->withCertificateLifecycleLock(
            fn (): mixed => $this->deactivateWithinLifecycleLock($domain),
        );
    }

    private function deactivateWithinLifecycleLock(string $domain): void
    {
        $domain = $this->normalizeDomain($domain);
        $this->ensureStoreDirectories();
        GatewayProjectStateFilesystem::withExclusiveLock(
            $this->storeRoot . DIRECTORY_SEPARATOR . 'activation.lock',
            function () use ($domain): void {
                $active = $this->readActiveUnlocked($domain, false);
                $disabled = $this->readDisabledUnlocked($domain);
                if ($active === null && $disabled !== null) {
                    $this->removeReenableIntentUnlocked($domain);
                    return;
                }
                if ($active !== null
                    && $disabled !== null
                    && (int)$disabled['generation'] > (int)$active['generation']
                ) {
                    $this->removeReenableIntentUnlocked($domain);
                    $this->removeActiveSelectorUnlocked($domain);
                    return;
                }

                // Allocate and persist the revocation fact before removing the
                // active selector. A crash can temporarily retain the old
                // selector, but can never leave an unversioned or reusable
                // certificate retirement behind.
                $generation = $this->allocateCertificateGeneration(\max(
                    (int)($active['generation'] ?? 0),
                    (int)($disabled['generation'] ?? 0),
                ));
                $next = [
                    'schema' => 'wls-project-certificate-disabled/1',
                    'state' => 'disabled',
                    'domain' => $domain,
                    'generation' => $generation,
                    'source_digest' => $this->disabledSourceDigest($domain, $generation),
                    'disabled_at' => \gmdate(DATE_ATOM),
                ];
                if ($disabled === null) {
                    $this->assertDisabledManifestCapacity();
                }
                $this->publishManifest($this->disabledManifestFile($domain), $next);
                $verified = $this->readDisabledUnlocked($domain);
                if ($verified === null
                    || (int)$verified['generation'] !== $generation
                    || !\hash_equals(
                        (string)$next['source_digest'],
                        (string)$verified['source_digest'],
                    )
                ) {
                    throw new \RuntimeException(
                        'Disabled certificate tombstone publication was not durable.',
                    );
                }
                // Any prior re-enable authority is exact to an older tombstone
                // and must not remain as misleading recoverable state.
                $this->removeReenableIntentUnlocked($domain);
                $this->removeActiveSelectorUnlocked($domain);
            },
            fn ($handle, string $path): mixed => $this->preserveProjectArtifactOwnership(
                $path,
                $handle,
            ),
        );
    }

    private function removeActiveSelectorUnlocked(string $domain): void
    {
        $path = $this->activeManifestFile($domain);
        if (@\lstat($path) === false
            && !\file_exists($path)
            && !\is_link($path)
        ) {
            return;
        }
        $active = $this->readActiveUnlocked($domain, false);
        if ($active === null) {
            return;
        }
        $this->preserveCertificateGenerationFloor((int)$active['generation']);
        GatewayProjectStateFilesystem::removeRegular(
            $path,
            'deactivated project certificate generation',
        );
        GatewayProjectStateFilesystem::syncDirectory(\dirname($path));
    }

    /**
     * @return array{
     *   source_digest:string,
     *   cert_pem:string,
     *   key_pem:string,
     *   chain_pem:string,
     *   leaf_fingerprint_sha256:string,
     *   cert_sha256:string,
     *   key_sha256:string,
     *   chain_sha256:string
     * }
     */
    private function validateSourceMaterial(
        string $domain,
        string $certificate,
        string $privateKey,
        string $chain,
    ): array {
        $certificatePem = $this->readStableFile($certificate, false);
        $keyPem = $this->readStableFile($privateKey, true);
        $chainPem = $chain === '' ? '' : $this->readStableFile($chain, false);
        return $this->validateMaterial($domain, $certificatePem, $keyPem, $chainPem);
    }

    /**
     * @return array{
     *   source_digest:string,
     *   cert_pem:string,
     *   key_pem:string,
     *   chain_pem:string,
     *   leaf_fingerprint_sha256:string,
     *   cert_sha256:string,
     *   key_sha256:string,
     *   chain_sha256:string
     * }
     */
    private function validateMaterial(
        string $domain,
        string $certificatePem,
        string $keyPem,
        string $chainPem,
        bool $requireCurrentValidity = true,
    ): array {
        if (!\preg_match(
            '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',
            $certificatePem,
            $leafMatch,
        )) {
            throw new \RuntimeException('Certificate source contains no PEM certificate.');
        }
        $leaf = @\openssl_x509_read((string)$leafMatch[0]);
        $private = @\openssl_pkey_get_private($keyPem);
        $public = $leaf !== false ? @\openssl_pkey_get_public($leaf) : false;
        $parsed = $leaf !== false ? @\openssl_x509_parse($leaf, false) : false;
        if ($leaf === false
            || $private === false
            || $public === false
            || !\is_array($parsed)
        ) {
            throw new \RuntimeException('Certificate or private key PEM is invalid.');
        }
        $privateDetails = @\openssl_pkey_get_details($private);
        $publicDetails = @\openssl_pkey_get_details($public);
        if (!\is_array($privateDetails)
            || !\is_array($publicDetails)
            || !\hash_equals(
                (string)($privateDetails['key'] ?? ''),
                (string)($publicDetails['key'] ?? ''),
            )
            || !@\openssl_x509_check_private_key($leaf, $private)
        ) {
            throw new \RuntimeException('Certificate and private key do not match.');
        }
        $keyType = (int)($privateDetails['type'] ?? -1);
        $keyBits = (int)($privateDetails['bits'] ?? 0);
        if (($keyType === OPENSSL_KEYTYPE_RSA && $keyBits < 2048)
            || ($keyType === OPENSSL_KEYTYPE_EC && $keyBits < 256)
            || !\in_array(
                $keyType,
                [OPENSSL_KEYTYPE_RSA, OPENSSL_KEYTYPE_EC],
                true,
            )
        ) {
            throw new \RuntimeException(
                'Certificate key algorithm or strength is not accepted.'
            );
        }
        $leafFingerprint = \strtolower(\str_replace(
            ':',
            '',
            (string)@\openssl_x509_fingerprint($leaf, 'sha256'),
        ));
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $leafFingerprint) !== 1) {
            throw new \RuntimeException('Unable to derive the certificate leaf fingerprint.');
        }
        $now = \time();
        if ($requireCurrentValidity
            && ((int)($parsed['validFrom_time_t'] ?? PHP_INT_MAX) > $now
                || (int)($parsed['validTo_time_t'] ?? 0) <= $now)
        ) {
            throw new \RuntimeException('Certificate is not currently valid.');
        }
        if (!$this->certificateCoversDomain($parsed, $domain)) {
            throw new \RuntimeException('Certificate SAN does not cover ' . $domain . '.');
        }
        $canonicalChain = $this->canonicalCertificateChain(
            $certificatePem,
            $chainPem,
            $now,
        );
        $fullchain = (string)$canonicalChain['fullchain_pem'];
        $normalizedChainPem = (string)$canonicalChain['chain_pem'];
        $certHash = \hash('sha256', $fullchain);
        $keyHash = \hash('sha256', $keyPem);
        // The protocol publishes fullchain.pem as the certificate source and
        // therefore sends no separate chain reference. Keep this digest
        // byte-for-byte compatible with the Controller's source fence.
        $sourceDigest = \hash('sha256', $certHash . ':' . $keyHash . ':');
        return [
            'source_digest' => $sourceDigest,
            'cert_pem' => $fullchain,
            'key_pem' => $keyPem,
            'chain_pem' => $normalizedChainPem,
            'leaf_fingerprint_sha256' => $leafFingerprint,
            'cert_sha256' => $certHash,
            'key_sha256' => $keyHash,
            'chain_sha256' => $normalizedChainPem === '' ? '' : \hash(
                'sha256',
                $normalizedChainPem,
            ),
        ];
    }

    private function validateCertificateBundle(string $pem): void
    {
        if (!\preg_match_all(
            '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',
            $pem,
            $matches,
        ) || $matches[0] === []) {
            throw new \RuntimeException('Certificate bundle contains no PEM certificate.');
        }
        foreach ($matches[0] as $certificate) {
            if (@\openssl_x509_read((string)$certificate) === false) {
                throw new \RuntimeException('Certificate bundle contains an invalid certificate.');
            }
        }
    }

    /**
     * Normalize a leaf-first bundle by DER fingerprint. Repeated intermediates
     * from `fullchain.pem` + `chain.pem` are coalesced, while a leaf repeated in
     * the chain is rejected. Every retained issuer must be a currently valid CA
     * authorized for certificate signing and must verify the preceding child.
     *
     * @return array{fullchain_pem:string,chain_pem:string}
     */
    private function canonicalCertificateChain(
        string $certificatePem,
        string $chainPem,
        int $now,
    ): array {
        $bundles = [$certificatePem];
        if ($chainPem !== '') {
            $bundles[] = $chainPem;
        }
        $pemBlocks = [];
        foreach ($bundles as $bundle) {
            if (!\preg_match_all(
                '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',
                $bundle,
                $matches,
            ) || $matches[0] === []) {
                throw new \RuntimeException('Certificate bundle contains no PEM certificate.');
            }
            foreach ($matches[0] as $block) {
                $pemBlocks[] = (string)$block;
            }
        }
        if ($pemBlocks === [] || \count($pemBlocks) > 16) {
            throw new \RuntimeException('Certificate chain exceeds the bounded certificate count.');
        }

        $certificates = [];
        $seen = [];
        $leafFingerprint = '';
        foreach ($pemBlocks as $index => $block) {
            $certificate = @\openssl_x509_read($block);
            $parsed = $certificate !== false ? @\openssl_x509_parse($certificate, false) : false;
            $fingerprint = $certificate !== false
                ? \strtolower(\str_replace(':', '', (string)@\openssl_x509_fingerprint(
                    $certificate,
                    'sha256',
                )))
                : '';
            $canonicalPem = '';
            if ($certificate === false
                || !\is_array($parsed)
                || \preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1
                || !@\openssl_x509_export($certificate, $canonicalPem, false)
                || $canonicalPem === ''
            ) {
                throw new \RuntimeException('Certificate bundle contains an invalid certificate.');
            }
            $canonicalPem = \rtrim($canonicalPem) . "\n";
            if ($index === 0) {
                $leafFingerprint = $fingerprint;
            } elseif (\hash_equals($leafFingerprint, $fingerprint)) {
                throw new \RuntimeException(
                    'Certificate chain must not contain the leaf certificate again.'
                );
            }
            if (isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;
            if ($index > 0) {
                $validFrom = (int)($parsed['validFrom_time_t'] ?? PHP_INT_MAX);
                $validTo = (int)($parsed['validTo_time_t'] ?? 0);
                $basicConstraints = (string)(
                    $parsed['extensions']['basicConstraints'] ?? ''
                );
                $keyUsage = $parsed['extensions']['keyUsage'] ?? null;
                if ($validFrom > $now
                    || $validTo <= $now
                    || \preg_match('/(?:^|[,\s])CA\s*:\s*TRUE(?:$|[,\s])/i', $basicConstraints) !== 1
                    || ($keyUsage !== null
                        && \preg_match(
                            '/Certificate Sign|keyCertSign/i',
                            (string)$keyUsage,
                        ) !== 1)
                ) {
                    throw new \RuntimeException(
                        'Certificate chain contains an expired or unauthorized CA certificate.'
                    );
                }
            }
            $certificates[] = [
                'certificate' => $certificate,
                'parsed' => $parsed,
                'pem' => $canonicalPem,
            ];
        }

        for ($index = 0, $last = \count($certificates) - 1; $index < $last; $index++) {
            $child = $certificates[$index];
            $issuer = $certificates[$index + 1];
            $issuerPublicKey = @\openssl_pkey_get_public($issuer['certificate']);
            if ($issuerPublicKey === false
                || !\hash_equals(
                    GatewayClient::canonicalJson((array)($child['parsed']['issuer'] ?? [])),
                    GatewayClient::canonicalJson((array)($issuer['parsed']['subject'] ?? [])),
                )
                || @\openssl_x509_verify($child['certificate'], $issuerPublicKey) !== 1
            ) {
                throw new \RuntimeException(
                    'Certificate chain order or issuer signature is invalid.'
                );
            }
        }
        $leafPem = (string)$certificates[0]['pem'];
        $chain = '';
        foreach (\array_slice($certificates, 1) as $certificate) {
            $chain .= (string)$certificate['pem'];
        }
        return [
            'fullchain_pem' => $leafPem . $chain,
            'chain_pem' => $chain,
        ];
    }

    /**
     * @param array<string,mixed> $parsed
     */
    private function certificateCoversDomain(array $parsed, string $domain): bool
    {
        $san = \trim((string)($parsed['extensions']['subjectAltName'] ?? ''));
        if ($san === '') {
            return false;
        }
        foreach (\explode(',', $san) as $entry) {
            $entry = \trim($entry);
            if (\filter_var($domain, FILTER_VALIDATE_IP) !== false
                && \str_starts_with(\strtoupper($entry), 'IP ADDRESS:')
            ) {
                $candidate = \trim(\substr($entry, \strlen('IP Address:')));
                if (\filter_var($candidate, FILTER_VALIDATE_IP) !== false
                    && \hash_equals(
                        (string)@\inet_pton($domain),
                        (string)@\inet_pton($candidate),
                    )
                ) {
                    return true;
                }
                continue;
            }
            if (!\str_starts_with(\strtoupper($entry), 'DNS:')) {
                continue;
            }
            try {
                $pattern = $this->normalizeDomain(\substr($entry, 4));
            } catch (\Throwable) {
                continue;
            }
            if ($this->domainPatternMatches($pattern, $domain)) {
                return true;
            }
        }
        return false;
    }

    private function domainPatternMatches(string $pattern, string $domain): bool
    {
        if (!\str_starts_with($pattern, '*.')) {
            return \hash_equals($pattern, $domain);
        }
        if (\str_starts_with($domain, '*.')) {
            return \hash_equals($pattern, $domain);
        }
        if (\substr_count($pattern, '.') !== \substr_count($domain, '.')) {
            return false;
        }
        return \str_ends_with($domain, \substr($pattern, 1));
    }

    /**
     * @param array<string,string> $material
     * @return array<string,string>
     */
    private function publishSnapshot(array $material): array
    {
        $digest = (string)$material['source_digest'];
        $snapshots = $this->storeRoot . DIRECTORY_SEPARATOR . 'snapshots';
        $target = $snapshots . DIRECTORY_SEPARATOR . $digest;
        $targetStatus = @\lstat($target);
        if (\is_array($targetStatus)) {
            if (\is_link($target)
                || ((((int)($targetStatus['mode'] ?? 0)) & 0170000) !== 0040000)
            ) {
                throw new \RuntimeException(
                    'Existing certificate snapshot path is linked or special.',
                );
            }
            $this->inspectSnapshotDirectory($target, $digest);
            return $this->verifySnapshot($target, $material);
        }
        if (\file_exists($target) || \is_link($target)) {
            throw new \RuntimeException('Existing certificate snapshot path is unsafe.');
        }
        $this->assertSnapshotStoreCapacity(
            \strlen((string)$material['cert_pem'])
                + \strlen((string)$material['key_pem'])
                + \strlen((string)$material['chain_pem'])
                + 16_384,
        );
        $temporary = $snapshots . DIRECTORY_SEPARATOR . '.tmp-'
            . \bin2hex(\random_bytes(12));
        if (!@\mkdir($temporary, 0700) || \is_link($temporary)) {
            throw new \RuntimeException('Unable to create certificate snapshot staging directory.');
        }
        // When an administrator performs enrollment, keep the staging
        // directory root-owned and mode 0700 until its complete immutable tree
        // is renamed. Chowning this path early would let the project owner race
        // privileged cleanup with path replacement.
        try {
            $this->atomicWrite(
                $temporary . DIRECTORY_SEPARATOR . 'fullchain.pem',
                (string)$material['cert_pem'],
                0600,
            );
            $this->atomicWrite(
                $temporary . DIRECTORY_SEPARATOR . 'privkey.pem',
                (string)$material['key_pem'],
                0600,
            );
            if ((string)$material['chain_pem'] !== '') {
                $this->atomicWrite(
                    $temporary . DIRECTORY_SEPARATOR . 'chain.pem',
                    (string)$material['chain_pem'],
                    0600,
                );
            }
            $this->publishManifest(
                $temporary . DIRECTORY_SEPARATOR . 'snapshot.json',
                [
                    'schema_version' => self::SCHEMA_VERSION,
                    'source_digest' => $digest,
                    'leaf_fingerprint_sha256' => (string)$material['leaf_fingerprint_sha256'],
                    'cert_sha256' => (string)$material['cert_sha256'],
                    'key_sha256' => (string)$material['key_sha256'],
                    'chain_sha256' => (string)$material['chain_sha256'],
                    'created_at' => \gmdate(DATE_ATOM),
                ],
            );
            if (!@\rename($temporary, $target)) {
                if (!\is_dir($target) || \is_link($target)) {
                    throw new \RuntimeException('Unable to publish immutable certificate snapshot.');
                }
                $this->removeDirectory($temporary);
            } else {
                GatewayProjectStateFilesystem::syncDirectory($snapshots);
            }
        } catch (\Throwable $throwable) {
            $this->removeDirectory($temporary);
            throw $throwable;
        }
        return $this->verifySnapshot($target, $material);
    }

    private function assertSnapshotStoreCapacity(int $prospectiveBytes): void
    {
        if ($prospectiveBytes < 1
            || $prospectiveBytes > (self::MAX_MATERIAL_BYTES * 3) + 16_384
        ) {
            throw new \RuntimeException('Certificate snapshot size is outside its quota.');
        }
        $inventory = $this->storedSnapshotInventory();
        $bytes = \array_sum(\array_column($inventory, 'bytes'));
        $activeReferences = $this->activeSnapshotReferences($inventory);
        $servingStore = new ProjectServingManifestStore($this->projectRoot);
        $servingStore->withCertificateSnapshotReferences(
            function (array $servingReferences) use (
                $inventory,
                $activeReferences,
                $bytes,
                $prospectiveBytes,
            ): void {
                $references = $activeReferences + $servingReferences;
                foreach ($references as $digest => $_) {
                    if (!isset($inventory[$digest])) {
                        throw new \RuntimeException(
                            'Certificate snapshot reference targets a missing immutable generation.',
                        );
                    }
                }
                $remainingCount = \count($inventory);
                $remainingBytes = $bytes;
                $cutoff = \time() - self::SNAPSHOT_RETENTION_SECONDS;
                $collectable = \array_values(\array_filter(
                    $inventory,
                    static fn (array $entry): bool => (int)$entry['mtime'] > 0
                        && (int)$entry['mtime'] <= $cutoff
                        && !isset($references[(string)$entry['digest']]),
                ));
                \usort($collectable, static function (array $left, array $right): int {
                    $order = (int)$left['mtime'] <=> (int)$right['mtime'];
                    return $order !== 0
                        ? $order
                        : (string)$left['digest'] <=> (string)$right['digest'];
                });
                foreach ($collectable as $entry) {
                    $this->removeSnapshotDirectory(
                        (string)$entry['path'],
                        (string)$entry['digest'],
                    );
                    $remainingCount--;
                    $remainingBytes -= (int)$entry['bytes'];
                }
                if ($remainingCount >= self::MAX_STORED_SNAPSHOTS
                    || $remainingBytes + $prospectiveBytes
                        > self::MAX_STORED_SNAPSHOT_BYTES
                ) {
                    throw new \RuntimeException(
                        'Certificate snapshot store has no capacity for another generation.',
                    );
                }
            },
        );
    }

    /**
     * @return array<string,array{digest:string,path:string,bytes:int,mtime:int,cert_sha256:string,key_sha256:string,chain_sha256:string}>
     */
    private function storedSnapshotInventory(): array
    {
        $root = $this->storeRoot . DIRECTORY_SEPARATOR . 'snapshots';
        $rootStatus = @\lstat($root);
        $canonical = \realpath($root);
        if (!\is_array($rootStatus)
            || \is_link($root)
            || ((((int)($rootStatus['mode'] ?? 0)) & 0170000) !== 0040000)
            || !\is_string($canonical)
            || !$this->samePath($root, $canonical)
            || !$this->pathInside($canonical, $this->storeRoot)
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((((int)$rootStatus['mode']) & 0777) !== 0700
                    || ($this->projectOwner >= 0
                        && (int)($rootStatus['uid'] ?? -1) !== $this->projectOwner)))
        ) {
            throw new \RuntimeException('Certificate snapshot root is unsafe.');
        }
        $handle = @\opendir($root);
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to enumerate certificate snapshots.');
        }
        $inventory = [];
        $rawEntries = 0;
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                if (++$rawEntries > self::MAX_SNAPSHOT_ROOT_ENTRIES) {
                    throw new \RuntimeException(
                        'Certificate snapshot root exceeds its bounded entry count.',
                    );
                }
                $path = $root . DIRECTORY_SEPARATOR . $leaf;
                if (\preg_match('/\A\.tmp-[a-f0-9]{24}\z/D', $leaf) === 1) {
                    // activation.lock excludes a live publisher here. Any
                    // exact staging tree therefore belongs to an interrupted
                    // publication and is removed with the bounded no-follow
                    // cleanup path before quota accounting.
                    $this->removeDirectory($path);
                    continue;
                }
                if (\preg_match('/\A[a-f0-9]{64}\z/D', $leaf) !== 1
                    || isset($inventory[$leaf])
                ) {
                    throw new \RuntimeException(
                        'Certificate snapshot root contains an invalid entry.',
                    );
                }
                $inventory[$leaf] = $this->inspectSnapshotDirectory($path, $leaf);
            }
        } finally {
            @\closedir($handle);
        }
        return $inventory;
    }

    /**
     * @return array{digest:string,path:string,bytes:int,mtime:int,cert_sha256:string,key_sha256:string,chain_sha256:string}
     */
    private function inspectSnapshotDirectory(string $directory, string $digest): array
    {
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
            || !\hash_equals($digest, \basename($directory))
            || !$this->pathInside(
                $directory,
                $this->storeRoot . DIRECTORY_SEPARATOR . 'snapshots',
            )
        ) {
            throw new \RuntimeException('Certificate snapshot identity is invalid.');
        }
        $records = GatewayBoundedTreeWalker::collect($directory, true, false);
        if (\count($records) < 4 || \count($records) > 5) {
            throw new \RuntimeException('Certificate snapshot file set is invalid.');
        }
        $allowed = [
            'fullchain.pem' => true,
            'privkey.pem' => true,
            'chain.pem' => true,
            'snapshot.json' => true,
        ];
        $seen = [];
        $bytes = 0;
        $mtime = 0;
        foreach ($records as $record) {
            $status = GatewayBoundedTreeWalker::revalidate($record);
            if ((int)$record['depth'] === 0) {
                if (!$record['directory']
                    || !$this->samePath((string)$record['path'], $directory)
                    || (\PHP_OS_FAMILY !== 'Windows'
                        && ((((int)$status['mode']) & 0777) !== 0700
                            || ($this->projectOwner >= 0
                                && (int)($status['uid'] ?? -1) !== $this->projectOwner)))
                ) {
                    throw new \RuntimeException(
                        'Certificate snapshot directory owner or mode is unsafe.',
                    );
                }
                $mtime = (int)($status['mtime'] ?? 0);
                continue;
            }
            $leaf = \basename((string)$record['path']);
            if ((int)$record['depth'] !== 1
                || $record['directory']
                || !isset($allowed[$leaf])
                || isset($seen[$leaf])
                || (int)($status['size'] ?? -1) < 1
                || (int)$status['size'] > self::MAX_MATERIAL_BYTES
                || (\PHP_OS_FAMILY !== 'Windows'
                    && ((((int)$status['mode']) & 0777) !== 0600
                        || ($this->projectOwner >= 0
                            && (int)($status['uid'] ?? -1) !== $this->projectOwner)))
            ) {
                throw new \RuntimeException(
                    'Certificate snapshot contains an unsafe or unexpected file.',
                );
            }
            $seen[$leaf] = true;
            $bytes += (int)$status['size'];
        }
        foreach (['fullchain.pem', 'privkey.pem', 'snapshot.json'] as $required) {
            if (!isset($seen[$required])) {
                throw new \RuntimeException('Certificate snapshot is incomplete.');
            }
        }
        $manifest = $this->readManifest(
            $directory . DIRECTORY_SEPARATOR . 'snapshot.json',
        );
        $cert = $this->readStableFile(
            $directory . DIRECTORY_SEPARATOR . 'fullchain.pem',
            false,
        );
        $key = $this->readStableFile(
            $directory . DIRECTORY_SEPARATOR . 'privkey.pem',
            true,
        );
        $chain = isset($seen['chain.pem'])
            ? $this->readStableFile($directory . DIRECTORY_SEPARATOR . 'chain.pem', false)
            : '';
        $certHash = \hash('sha256', $cert);
        $keyHash = \hash('sha256', $key);
        $chainHash = $chain === '' ? '' : \hash('sha256', $chain);
        if ((int)($manifest['schema_version'] ?? 0) !== self::SCHEMA_VERSION
            || !\hash_equals($digest, (string)($manifest['source_digest'] ?? ''))
            || !\hash_equals($certHash, (string)($manifest['cert_sha256'] ?? ''))
            || !\hash_equals($keyHash, (string)($manifest['key_sha256'] ?? ''))
            || !\hash_equals($chainHash, (string)($manifest['chain_sha256'] ?? ''))
            || !\hash_equals($digest, \hash('sha256', $certHash . ':' . $keyHash . ':'))
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                $manifest['leaf_fingerprint_sha256'] ?? ''
            )) !== 1
        ) {
            throw new \RuntimeException(
                'Certificate snapshot manifest integrity validation failed.',
            );
        }
        return [
            'digest' => $digest,
            'path' => $directory,
            'bytes' => $bytes,
            'mtime' => $mtime,
            'cert_sha256' => $certHash,
            'key_sha256' => $keyHash,
            'chain_sha256' => $chainHash,
        ];
    }

    /**
     * @param array<string,array{digest:string,path:string,bytes:int,mtime:int,cert_sha256:string,key_sha256:string,chain_sha256:string}> $inventory
     * @return array<string,true>
     */
    private function activeSnapshotReferences(array $inventory): array
    {
        $activeRoot = $this->storeRoot . DIRECTORY_SEPARATOR . 'active';
        $rootStatus = @\lstat($activeRoot);
        $canonical = \realpath($activeRoot);
        if (!\is_array($rootStatus)
            || \is_link($activeRoot)
            || ((((int)($rootStatus['mode'] ?? 0)) & 0170000) !== 0040000)
            || !\is_string($canonical)
            || !$this->samePath($activeRoot, $canonical)
            || !$this->pathInside($canonical, $this->storeRoot)
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((((int)$rootStatus['mode']) & 0777) !== 0700
                    || ($this->projectOwner >= 0
                        && (int)($rootStatus['uid'] ?? -1) !== $this->projectOwner)))
        ) {
            throw new \RuntimeException('Active certificate generation root is unsafe.');
        }
        $handle = @\opendir($activeRoot);
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to enumerate active certificate generations.');
        }
        $references = [];
        $count = 0;
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                if (++$count > self::MAX_ACTIVE_MANIFESTS
                    || \preg_match('/\A[a-f0-9]{32}\.json\z/D', $leaf) !== 1
                ) {
                    throw new \RuntimeException(
                        'Active certificate generation manifest set is invalid.',
                    );
                }
                $path = $activeRoot . DIRECTORY_SEPARATOR . $leaf;
                $status = @\lstat($path);
                if (!\is_array($status)
                    || \is_link($path)
                    || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0100000)
                    || (int)($status['nlink'] ?? 0) !== 1
                    || (\PHP_OS_FAMILY !== 'Windows'
                        && ((((int)$status['mode']) & 0777) !== 0600
                            || ($this->projectOwner >= 0
                                && (int)($status['uid'] ?? -1) !== $this->projectOwner)))
                ) {
                    throw new \RuntimeException(
                        'Active certificate generation manifest is unsafe.',
                    );
                }
                $manifest = $this->readManifest($path);
                $domain = $this->normalizeDomain((string)($manifest['domain'] ?? ''));
                $current = \strtolower(\trim((string)(
                    $manifest['source_digest'] ?? ''
                )));
                if (!\hash_equals(
                    \substr(\hash('sha256', $domain), 0, 32) . '.json',
                    $leaf,
                )
                    || (int)($manifest['generation'] ?? 0) < 1
                    || \preg_match('/\A[a-f0-9]{64}\z/D', $current) !== 1
                ) {
                    throw new \RuntimeException(
                        'Active certificate generation reference is corrupt.',
                    );
                }
                $snapshot = $inventory[$current] ?? null;
                if (!\is_array($snapshot)
                    || !\hash_equals(
                        (string)$snapshot['cert_sha256'],
                        (string)($manifest['cert_sha256'] ?? ''),
                    )
                    || !\hash_equals(
                        (string)$snapshot['key_sha256'],
                        (string)($manifest['key_sha256'] ?? ''),
                    )
                    || !\hash_equals(
                        (string)$snapshot['chain_sha256'],
                        (string)($manifest['chain_sha256'] ?? ''),
                    )
                ) {
                    throw new \RuntimeException(
                        'Active certificate generation does not match its snapshot.',
                    );
                }
                $references[$current] = true;
                $previous = $manifest['previous'] ?? null;
                if ($previous !== null) {
                    $previousDigest = \strtolower(\trim((string)(
                        \is_array($previous) ? ($previous['source_digest'] ?? '') : ''
                    )));
                    if (!\is_array($previous)
                        || (int)($previous['generation'] ?? 0) < 1
                        || (int)$previous['generation'] >= (int)$manifest['generation']
                        || \preg_match('/\A[a-f0-9]{64}\z/D', $previousDigest) !== 1
                        || \hash_equals($current, $previousDigest)
                    ) {
                        throw new \RuntimeException(
                            'Previous certificate generation reference is corrupt.',
                        );
                    }
                    $references[$previousDigest] = true;
                }
            }
        } finally {
            @\closedir($handle);
        }
        foreach ($references as $digest => $_) {
            if (!isset($inventory[$digest])) {
                throw new \RuntimeException(
                    'Active certificate generation references a missing snapshot.',
                );
            }
        }
        return $references;
    }

    private function removeSnapshotDirectory(string $directory, string $digest): void
    {
        $this->inspectSnapshotDirectory($directory, $digest);
        $records = GatewayBoundedTreeWalker::collect($directory, true, true);
        foreach ($records as $record) {
            GatewayBoundedTreeWalker::revalidate($record);
        }
        foreach ($records as $record) {
            GatewayBoundedTreeWalker::revalidate($record);
            $removed = $record['directory']
                ? @\rmdir((string)$record['path'])
                : @\unlink((string)$record['path']);
            if (!$removed) {
                throw new \RuntimeException(
                    'Unable to remove an expired unreferenced certificate snapshot.',
                );
            }
        }
        GatewayProjectStateFilesystem::syncDirectory(
            $this->storeRoot . DIRECTORY_SEPARATOR . 'snapshots',
        );
    }

    /**
     * @param array<string,string> $material
     * @return array<string,string>
     */
    private function verifySnapshot(string $directory, array $material): array
    {
        $cert = $directory . DIRECTORY_SEPARATOR . 'fullchain.pem';
        $key = $directory . DIRECTORY_SEPARATOR . 'privkey.pem';
        $chain = $directory . DIRECTORY_SEPARATOR . 'chain.pem';
        $this->preserveProjectArtifactOwnership($directory);
        $this->preserveProjectArtifactOwnership($cert);
        $this->preserveProjectArtifactOwnership($key);
        if (\is_file($chain)) {
            $this->preserveProjectArtifactOwnership($chain);
        }
        $certHash = $this->safeHashFile($cert);
        $keyHash = $this->safeHashFile($key);
        $chainHash = \is_file($chain) && !\is_link($chain) ? $this->safeHashFile($chain) : '';
        if (!\hash_equals((string)$material['cert_sha256'], $certHash)
            || !\hash_equals((string)$material['key_sha256'], $keyHash)
            || !\hash_equals((string)$material['chain_sha256'], $chainHash)
        ) {
            throw new \RuntimeException('Existing certificate snapshot failed content verification.');
        }
        if (\PHP_OS_FAMILY !== 'Windows') {
            $mode = @\fileperms($key);
            if (!\is_int($mode) || ($mode & 0077) !== 0) {
                throw new \RuntimeException('Certificate snapshot private key permissions are unsafe.');
            }
        }
        return [
            'source_digest' => (string)$material['source_digest'],
            'leaf_fingerprint_sha256' => (string)$material['leaf_fingerprint_sha256'],
            'cert_path' => $cert,
            'key_path' => $key,
            'chain_path' => $chainHash === '' ? '' : $chain,
            'cert_sha256' => $certHash,
            'key_sha256' => $keyHash,
            'chain_sha256' => $chainHash,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readActiveUnlocked(
        string $domain,
        bool $requireCurrentValidity = true,
    ): ?array
    {
        $file = $this->activeManifestFile($domain);
        if (!\file_exists($file) && !\is_link($file)) {
            return null;
        }
        $this->preserveProjectArtifactOwnership($file);
        $manifest = $this->readManifest($file);
        if (!\hash_equals($domain, (string)($manifest['domain'] ?? ''))
            || (int)($manifest['generation'] ?? 0) < 1
            || \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($manifest['source_digest'] ?? ''),
            ) !== 1
        ) {
            throw new \RuntimeException('Active certificate generation manifest is invalid.');
        }
        $directory = $this->storeRoot . DIRECTORY_SEPARATOR . 'snapshots'
            . DIRECTORY_SEPARATOR . (string)$manifest['source_digest'];
        // Snapshot locations are derived from the content digest under the
        // current project root. Persisted absolute paths describe the host
        // that activated the generation and must not make a copied project
        // unusable or authorize reads outside the migrated project.
        $certPath = $directory . DIRECTORY_SEPARATOR . 'fullchain.pem';
        $keyPath = $directory . DIRECTORY_SEPARATOR . 'privkey.pem';
        $chainPath = (string)($manifest['chain_sha256'] ?? '') === ''
            ? ''
            : $directory . DIRECTORY_SEPARATOR . 'chain.pem';
        $cert = $this->readStableFile($certPath, false);
        $key = $this->readStableFile($keyPath, true);
        $chain = $chainPath === '' ? '' : $this->readStableFile($chainPath, false);
        if (!$this->pathInside($certPath, $directory)
            || !$this->pathInside($keyPath, $directory)
            || ($chainPath !== '' && !$this->pathInside($chainPath, $directory))
            || !\hash_equals((string)$manifest['cert_sha256'], \hash('sha256', $cert))
            || !\hash_equals((string)$manifest['key_sha256'], \hash('sha256', $key))
            || !\hash_equals(
                (string)($manifest['chain_sha256'] ?? ''),
                $chain === '' ? '' : \hash('sha256', $chain),
            )
        ) {
            throw new \RuntimeException('Active certificate snapshot integrity check failed.');
        }
        $validated = $this->validateMaterial(
            $domain,
            $cert,
            $key,
            '',
            $requireCurrentValidity,
        );
        if (!\hash_equals(
            (string)$manifest['source_digest'],
            (string)$validated['source_digest'],
        )) {
            throw new \RuntimeException('Active certificate source digest is invalid.');
        }
        $manifestFingerprint = \strtolower(\trim(
            (string)($manifest['leaf_fingerprint_sha256'] ?? ''),
        ));
        if ($manifestFingerprint !== ''
            && (\preg_match('/\A[a-f0-9]{64}\z/D', $manifestFingerprint) !== 1
                || !\hash_equals(
                    (string)$validated['leaf_fingerprint_sha256'],
                    $manifestFingerprint,
                ))
        ) {
            throw new \RuntimeException('Active certificate leaf fingerprint is invalid.');
        }
        return \array_replace($manifest, [
            'leaf_fingerprint_sha256' => (string)$validated['leaf_fingerprint_sha256'],
            'cert_path' => $certPath,
            'key_path' => $keyPath,
            'chain_path' => $chainPath,
            'retained_previous' => false,
            'activation_error' => '',
        ]);
    }

    /**
     * @return array{state:string,domain:string,generation:int,source_digest:string,disabled_at:string}|null
     */
    private function readDisabledUnlocked(string $domain): ?array
    {
        $file = $this->disabledManifestFile($domain);
        $status = @\lstat($file);
        if (!\is_array($status)) {
            if (\file_exists($file) || \is_link($file)) {
                throw new \RuntimeException(
                    'Disabled certificate tombstone is indeterminate.',
                );
            }
            return null;
        }
        if (\is_link($file)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($status['nlink'] ?? 0) !== 1
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((((int)$status['mode']) & 0777) !== 0600
                    || ($this->projectOwner >= 0
                        && (int)($status['uid'] ?? -1) !== $this->projectOwner)))
        ) {
            throw new \RuntimeException(
                'Disabled certificate tombstone is unsafe.',
            );
        }
        $this->preserveProjectArtifactOwnership($file);
        $manifest = $this->readManifest($file);
        $generation = (int)($manifest['generation'] ?? 0);
        $sourceDigest = \strtolower(\trim((string)(
            $manifest['source_digest'] ?? ''
        )));
        if (!\hash_equals(
                'wls-project-certificate-disabled/1',
                (string)($manifest['schema'] ?? ''),
            )
            || !\hash_equals('disabled', (string)($manifest['state'] ?? ''))
            || !\hash_equals($domain, (string)($manifest['domain'] ?? ''))
            || $generation < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $sourceDigest) !== 1
            || !\hash_equals(
                $this->disabledSourceDigest($domain, $generation),
                $sourceDigest,
            )
            || \trim((string)($manifest['disabled_at'] ?? '')) === ''
        ) {
            throw new \RuntimeException(
                'Disabled certificate tombstone integrity validation failed.',
            );
        }
        return [
            'state' => 'disabled',
            'domain' => $domain,
            'generation' => $generation,
            'source_digest' => $sourceDigest,
            'disabled_at' => (string)$manifest['disabled_at'],
        ];
    }

    private function disabledSourceDigest(string $domain, int $generation): string
    {
        if ($generation < 1) {
            throw new \RuntimeException(
                'Disabled certificate generation is invalid.',
            );
        }
        return \hash(
            'sha256',
            "wls-disabled-certificate\0" . $domain . "\0" . $generation,
        );
    }

    /**
     * @return array{
     *   domain:string,
     *   disabled_generation:int,
     *   disabled_source_digest:string,
     *   target_source_digest:string,
     *   intent_id:string,
     *   issued_at:string
     * }|null
     */
    private function readReenableIntentUnlocked(string $domain): ?array
    {
        $file = $this->reenableIntentFile($domain);
        $status = @\lstat($file);
        if (!\is_array($status)) {
            if (\file_exists($file) || \is_link($file)) {
                throw new \RuntimeException(
                    'Certificate re-enable intent is indeterminate.',
                );
            }
            return null;
        }
        if (\is_link($file)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($status['nlink'] ?? 0) !== 1
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((((int)$status['mode']) & 0777) !== 0600
                    || ($this->projectOwner >= 0
                        && (int)($status['uid'] ?? -1) !== $this->projectOwner)))
        ) {
            throw new \RuntimeException('Certificate re-enable intent is unsafe.');
        }
        $this->preserveProjectArtifactOwnership($file);
        $intent = $this->readManifest($file);
        $generation = (int)($intent['disabled_generation'] ?? 0);
        $disabledDigest = \strtolower(\trim((string)(
            $intent['disabled_source_digest'] ?? ''
        )));
        $targetDigest = \strtolower(\trim((string)(
            $intent['target_source_digest'] ?? ''
        )));
        $intentId = \strtolower(\trim((string)($intent['intent_id'] ?? '')));
        if (!\hash_equals(
                'wls-project-certificate-reenable/1',
                (string)($intent['schema'] ?? ''),
            )
            || !\hash_equals('authorized', (string)($intent['state'] ?? ''))
            || !\hash_equals($domain, (string)($intent['domain'] ?? ''))
            || $generation < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $disabledDigest) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $targetDigest) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $intentId) !== 1
            || !\hash_equals(
                $this->disabledSourceDigest($domain, $generation),
                $disabledDigest,
            )
            || !\hash_equals(
                $this->reenableIntentId(
                    $domain,
                    $generation,
                    $disabledDigest,
                    $targetDigest,
                ),
                $intentId,
            )
            || \trim((string)($intent['issued_at'] ?? '')) === ''
        ) {
            throw new \RuntimeException(
                'Certificate re-enable intent integrity validation failed.',
            );
        }
        return [
            'domain' => $domain,
            'disabled_generation' => $generation,
            'disabled_source_digest' => $disabledDigest,
            'target_source_digest' => $targetDigest,
            'intent_id' => $intentId,
            'issued_at' => (string)$intent['issued_at'],
        ];
    }

    private function reenableIntentId(
        string $domain,
        int $disabledGeneration,
        string $disabledSourceDigest,
        string $targetSourceDigest,
    ): string {
        if ($disabledGeneration < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $disabledSourceDigest) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $targetSourceDigest) !== 1
        ) {
            throw new \RuntimeException('Certificate re-enable authority is invalid.');
        }
        return \hash(
            'sha256',
            "wls-certificate-reenable\0" . $domain . "\0"
                . $disabledGeneration . "\0" . $disabledSourceDigest . "\0"
                . $targetSourceDigest,
        );
    }

    private function removeReenableIntentUnlocked(string $domain): void
    {
        $path = $this->reenableIntentFile($domain);
        if (@\lstat($path) === false
            && !\file_exists($path)
            && !\is_link($path)
        ) {
            return;
        }
        GatewayProjectStateFilesystem::removeRegular(
            $path,
            'certificate re-enable intent',
        );
        GatewayProjectStateFilesystem::syncDirectory(\dirname($path));
    }

    private function readStableFile(string $path, bool $privateKey): string
    {
        if ($path === '' || \str_contains($path, "\0") || \is_link($path)) {
            throw new \RuntimeException('Certificate material path is unsafe.');
        }
        $real = \realpath($path);
        if (!\is_string($real) || !\is_file($real) || \is_link($real)) {
            throw new \RuntimeException('Certificate material file is unavailable.');
        }
        if (!$this->samePath($path, $real)) {
            throw new \RuntimeException('Symbolic-link or non-canonical certificate paths are forbidden.');
        }
        $before = @\lstat($real);
        $stream = @\fopen($real, 'rb');
        if (!\is_array($before) || !\is_resource($stream)) {
            throw new \RuntimeException('Unable to open certificate material safely.');
        }
        try {
            $opened = @\fstat($stream);
            if (!\is_array($opened)
                || ((int)($opened['mode'] ?? 0) & 0170000) !== 0100000
                || (int)($opened['nlink'] ?? 0) !== 1
                || (int)($opened['size'] ?? -1) < 1
                || (int)$opened['size'] > self::MAX_MATERIAL_BYTES
            ) {
                throw new \RuntimeException('Certificate material size or file type is invalid.');
            }
            if (\PHP_OS_FAMILY !== 'Windows') {
                if ($privateKey && ((int)$opened['mode'] & 0077) !== 0) {
                    throw new \RuntimeException(
                        'Private key source must not grant group or other permissions.'
                    );
                }
            }
            $contents = \stream_get_contents($stream, self::MAX_MATERIAL_BYTES + 1);
            $after = @\fstat($stream);
        } finally {
            @\fclose($stream);
        }
        $latest = @\lstat($real);
        if (!\is_string($contents)
            || \strlen($contents) < 1
            || \strlen($contents) > self::MAX_MATERIAL_BYTES
            || !\is_array($after)
            || !\is_array($latest)
        ) {
            throw new \RuntimeException('Certificate material read was incomplete.');
        }
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $field) {
            if ((int)($before[$field] ?? -1) !== (int)($opened[$field] ?? -2)
                || (int)($opened[$field] ?? -1) !== (int)($after[$field] ?? -2)
                || (int)($after[$field] ?? -1) !== (int)($latest[$field] ?? -2)
            ) {
                throw new \RuntimeException('Certificate material changed while being read.');
            }
        }
        return $contents;
    }

    private function safeHashFile(string $path): string
    {
        return \hash('sha256', $this->readStableFile($path, \str_ends_with($path, 'privkey.pem')));
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = \strtolower(\rtrim(\trim($domain), '.'));
        $wildcard = \str_starts_with($domain, '*.');
        $base = $wildcard ? \substr($domain, 2) : $domain;
        if (!$wildcard && \filter_var($base, FILTER_VALIDATE_IP) !== false) {
            $packed = @\inet_pton($base);
            if (!\is_string($packed)) {
                throw new \InvalidArgumentException('Invalid TLS IP address: ' . $domain);
            }
            return (string)@\inet_ntop($packed);
        }
        if (\function_exists('idn_to_ascii')) {
            $variant = \defined('INTL_IDNA_VARIANT_UTS46')
                ? \constant('INTL_IDNA_VARIANT_UTS46')
                : 0;
            $ascii = @\idn_to_ascii($base, IDNA_DEFAULT, $variant);
            if (\is_string($ascii) && $ascii !== '') {
                $base = \strtolower($ascii);
            }
        }
        if (\strlen($base) > 253
            || \preg_match(
                '/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*'
                    . '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D',
                $base,
            ) !== 1
        ) {
            throw new \InvalidArgumentException('Invalid TLS domain name: ' . $domain);
        }
        return $wildcard ? '*.' . $base : $base;
    }

    private function ensureStoreDirectories(): void
    {
        $current = $this->projectRoot;
        foreach (['app', 'etc', 'ssl', '.wls-generations', 'snapshots'] as $index => $leaf) {
            $directory = $current . DIRECTORY_SEPARATOR . $leaf;
            $mode = $index < 2 ? 0755 : 0700;
            $status = @\lstat($directory);
            $created = false;
            if (!\is_array($status)) {
                if (\file_exists($directory)
                    || \is_link($directory)
                    || !@\mkdir($directory, $mode)
                ) {
                    throw new \RuntimeException(
                        'Project certificate generation directory is unavailable: ' . $directory
                    );
                }
                $created = true;
            }
            if ($created && $index < 3) {
                $this->preserveCreatedProjectDirectory($directory);
            }
            if (!\is_dir($directory)) {
                throw new \RuntimeException(
                    'Project certificate generation directory is unavailable: ' . $directory
                );
            }
            $status = @\lstat($directory);
            $real = \realpath($directory);
            if (!\is_array($status)
                || \is_link($directory)
                || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
                || !\is_string($real)
                || !$this->pathInside($real, $this->projectRoot)
                || (\PHP_OS_FAMILY !== 'Windows'
                    && $index < 3
                    && ((((int)($status['mode'] ?? 0)) & 0022) !== 0))
                || (\PHP_OS_FAMILY !== 'Windows'
                    && $index >= 3
                    && (!@\chmod($directory, 0700)
                        || (((int)(@\fileperms($directory) ?: 0)) & 0777) !== 0700))
            ) {
                throw new \RuntimeException(
                    'Project certificate generation directory is unsafe: ' . $directory
                );
            }
            $current = \rtrim($real, '/\\');
            if ($index >= 3) {
                $this->preserveProjectArtifactOwnership($current);
            }
        }
        foreach (['active', 'disabled', 'reenable-intents'] as $selectorDirectory) {
            $selectorRoot = $this->storeRoot . DIRECTORY_SEPARATOR . $selectorDirectory;
            if (!\is_dir($selectorRoot)
                && !@\mkdir($selectorRoot, 0700)
                && !\is_dir($selectorRoot)
            ) {
                throw new \RuntimeException(
                    'Project certificate generation directory is unavailable: '
                        . $selectorRoot,
                );
            }
            $selectorStatus = @\lstat($selectorRoot);
            if (!\is_array($selectorStatus)
                || \is_link($selectorRoot)
                || ((((int)($selectorStatus['mode'] ?? 0)) & 0170000) !== 0040000)
                || (\PHP_OS_FAMILY !== 'Windows' && !@\chmod($selectorRoot, 0700))
            ) {
                throw new \RuntimeException(
                    'Project certificate generation selector directory is unsafe.',
                );
            }
            $this->preserveProjectArtifactOwnership($selectorRoot);
        }
    }

    private function activeManifestFile(string $domain): string
    {
        return $this->storeRoot . DIRECTORY_SEPARATOR . 'active'
            . DIRECTORY_SEPARATOR . \substr(\hash('sha256', $domain), 0, 32) . '.json';
    }

    private function disabledManifestFile(string $domain): string
    {
        return $this->storeRoot . DIRECTORY_SEPARATOR . 'disabled'
            . DIRECTORY_SEPARATOR . \substr(\hash('sha256', $domain), 0, 32) . '.json';
    }

    private function reenableIntentFile(string $domain): string
    {
        return $this->storeRoot . DIRECTORY_SEPARATOR . 'reenable-intents'
            . DIRECTORY_SEPARATOR . \substr(\hash('sha256', $domain), 0, 32) . '.json';
    }

    private function certificateLifecycleLockPath(): string
    {
        return \dirname($this->storeRoot) . DIRECTORY_SEPARATOR
            . '.wls-certificate-lifecycle.lock';
    }

    private function assertDisabledManifestCapacity(): void
    {
        $root = $this->storeRoot . DIRECTORY_SEPARATOR . 'disabled';
        $handle = @\opendir($root);
        if (!\is_resource($handle)) {
            throw new \RuntimeException(
                'Unable to enumerate disabled certificate tombstones.',
            );
        }
        $count = 0;
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                $path = $root . DIRECTORY_SEPARATOR . $leaf;
                $status = @\lstat($path);
                if (++$count > self::MAX_ACTIVE_MANIFESTS
                    || \preg_match('/\A[a-f0-9]{32}\.json\z/D', $leaf) !== 1
                    || !\is_array($status)
                    || \is_link($path)
                    || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0100000)
                    || (int)($status['nlink'] ?? 0) !== 1
                ) {
                    throw new \RuntimeException(
                        'Disabled certificate tombstone store is malformed or full.',
                    );
                }
            }
        } finally {
            @\closedir($handle);
        }
        if ($count >= self::MAX_ACTIVE_MANIFESTS) {
            throw new \RuntimeException(
                'Disabled certificate tombstone store has reached its quota.',
            );
        }
    }

    private function certificateGenerationFloorFile(): string
    {
        return $this->storeRoot . DIRECTORY_SEPARATOR . 'generation-floor.txt';
    }

    private function allocateCertificateGeneration(int $activeGeneration): int
    {
        $floor = \max($activeGeneration, $this->readCertificateGenerationFloor());
        if ($floor >= PHP_INT_MAX) {
            throw new \RuntimeException('Certificate generation authority is exhausted.');
        }
        $next = $floor + 1;
        $this->atomicWrite(
            $this->certificateGenerationFloorFile(),
            (string)$next . "\n",
            0600,
        );
        return $next;
    }

    private function preserveCertificateGenerationFloor(int $generation): void
    {
        if ($generation < 1) {
            throw new \RuntimeException('Certificate generation floor is invalid.');
        }
        if ($generation <= $this->readCertificateGenerationFloor()) {
            return;
        }
        $this->atomicWrite(
            $this->certificateGenerationFloorFile(),
            (string)$generation . "\n",
            0600,
        );
    }

    private function readCertificateGenerationFloor(): int
    {
        $path = $this->certificateGenerationFloorFile();
        $status = @\lstat($path);
        if (!\is_array($status)) {
            if (\file_exists($path) || \is_link($path)) {
                throw new \RuntimeException(
                    'Certificate generation floor is indeterminate or unsafe.',
                );
            }
            return 0;
        }
        if (\is_link($path)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($status['nlink'] ?? 0) !== 1
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((((int)$status['mode']) & 0777) !== 0600
                    || ($this->projectOwner >= 0
                        && (int)($status['uid'] ?? -1) !== $this->projectOwner)))
        ) {
            throw new \RuntimeException('Certificate generation floor is unsafe.');
        }
        $encoded = GatewayProjectStateFilesystem::read(
            $path,
            64,
            'certificate generation floor',
        );
        $value = \trim($encoded);
        $maximum = (string)PHP_INT_MAX;
        if (\preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D', $value) !== 1
            || \strlen($value) > \strlen($maximum)
            || (\strlen($value) === \strlen($maximum)
                && \strcmp($value, $maximum) > 0)
        ) {
            throw new \RuntimeException('Certificate generation floor is corrupt.');
        }
        return (int)$value;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function publishManifest(string $path, array $payload): void
    {
        $envelope = [
            'payload' => $payload,
            'sha256' => \hash('sha256', GatewayClient::canonicalJson($payload)),
        ];
        $encoded = \json_encode(
            $envelope,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if (!\is_string($encoded)) {
            throw new \RuntimeException('Unable to encode certificate generation manifest.');
        }
        $this->atomicWrite($path, $encoded, 0600);
    }

    /**
     * @return array<string,mixed>
     */
    private function readManifest(string $path): array
    {
        $encoded = $this->readStableFile($path, false);
        $envelope = \json_decode($encoded, true);
        $payload = \is_array($envelope) && \is_array($envelope['payload'] ?? null)
            ? $envelope['payload']
            : null;
        if (!\is_array($payload)
            || !\hash_equals(
                (string)($envelope['sha256'] ?? ''),
                \hash('sha256', GatewayClient::canonicalJson($payload)),
            )
        ) {
            throw new \RuntimeException('Certificate generation manifest failed integrity validation.');
        }
        return $payload;
    }

    private function atomicWrite(string $path, string $contents, int $mode): void
    {
        $this->assertSafeTarget($path);
        GatewayProjectStateFilesystem::atomicWrite(
            $path,
            $contents,
            $mode,
            fn ($handle, string $candidate): mixed => $this->preserveProjectArtifactOwnership(
                $candidate,
                $handle,
            ),
        );
    }

    /**
     * Root may coordinate enrollment/promotion, but every derived certificate
     * generation remains a project-owned fact. Never apply this repair to the
     * original certificate sources or to paths outside the private store.
     *
     * @param resource|null $handle
     */
    private function preserveProjectArtifactOwnership(string $path, mixed $handle = null): void
    {
        if (\PHP_OS_FAMILY === 'Windows'
            || $this->projectOwner < 0
            || $this->projectGroup < 0
            || !\function_exists('posix_geteuid')
            || \posix_geteuid() !== 0
        ) {
            return;
        }
        $store = \realpath($this->storeRoot);
        $real = \realpath($path);
        $status = @\lstat($path);
        if (!\is_string($store)
            || !\is_string($real)
            || !$this->pathInside($real, $store)
            || \is_link($path)
            || !\is_array($status)
            || (!\is_file($path) && !\is_dir($path))
        ) {
            throw new \RuntimeException(
                'Certificate generation ownership target is unsafe.'
            );
        }
        $ownerApplied = \is_resource($handle)
            && \function_exists('fchown')
            && @\fchown($handle, $this->projectOwner);
        if (!$ownerApplied) {
            $ownerApplied = \function_exists('lchown')
                ? @\lchown($path, $this->projectOwner)
                : @\chown($path, $this->projectOwner);
        }
        $groupApplied = \is_resource($handle)
            && \function_exists('fchgrp')
            && @\fchgrp($handle, $this->projectGroup);
        if (!$groupApplied) {
            $groupApplied = \function_exists('lchgrp')
                ? @\lchgrp($path, $this->projectGroup)
                : @\chgrp($path, $this->projectGroup);
        }
        $actual = @\lstat($path);
        if (!$ownerApplied
            || !$groupApplied
            || !\is_array($actual)
            || (int)($actual['uid'] ?? -1) !== $this->projectOwner
            || (int)($actual['gid'] ?? -1) !== $this->projectGroup
        ) {
            throw new \RuntimeException(
                'Unable to preserve the project owner on certificate generations.'
            );
        }
    }

    private function assertSafeTarget(string $path): void
    {
        if (\is_link($path)) {
            throw new \RuntimeException('Symbolic-link certificate generation targets are forbidden.');
        }
        $parent = \realpath(\dirname($path));
        if (!\is_string($parent) || !$this->pathInside($parent, $this->storeRoot)) {
            throw new \RuntimeException('Certificate generation target escapes the project store.');
        }
    }

    private function pathInside(string $path, string $root): bool
    {
        $path = \str_replace('\\', '/', \rtrim($path, '/\\'));
        $root = \str_replace('\\', '/', \rtrim($root, '/\\'));
        if ($root === '' || $this->isFilesystemRoot($root)) {
            return false;
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            $path = \strtolower($path);
            $root = \strtolower($root);
        }
        return $path === $root || \str_starts_with($path, $root . '/');
    }

    private function isFilesystemRoot(string $path): bool
    {
        $path = \str_replace('\\', '/', \trim($path));
        if (\preg_match('#\A/+\z#D', $path) === 1) {
            return true;
        }
        $path = \rtrim($path, '/');
        return \preg_match('/\A[A-Za-z]:\z/D', $path) === 1
            || \preg_match('#\A//(?![?.](?:/|\z))[^/]+(?:/[^/]+)?\z#D', $path) === 1
            || \preg_match('#\A//[?.]/[A-Za-z]:\z#Di', $path) === 1
            || \preg_match('#\A//[?.]/UNC(?:/[^/]+(?:/[^/]+)?)?\z#Di', $path) === 1
            || \preg_match('#\A//[?.]/Volume\{[0-9A-Fa-f-]+\}\z#Di', $path) === 1;
    }

    private function samePath(string $path, string $real): bool
    {
        $absolute = \str_starts_with($path, '/')
            || \preg_match('/\A[A-Za-z]:[\\\\\\/]/D', $path) === 1
            ? $path
            : $this->projectRoot . DIRECTORY_SEPARATOR . $path;
        $absolute = \str_replace('\\', '/', \rtrim($absolute, '/\\'));
        $real = \str_replace('\\', '/', \rtrim($real, '/\\'));
        if (\PHP_OS_FAMILY === 'Windows') {
            $absolute = \strtolower($absolute);
            $real = \strtolower($real);
        }
        return $absolute === $real;
    }

    private function isAbsolutePath(string $path): bool
    {
        return \str_starts_with($path, '/')
            || \preg_match('/\A[A-Za-z]:[\\\\\/]/D', $path) === 1
            || \str_starts_with($path, '\\\\');
    }

    private function pathKey(string $path): string
    {
        $path = \str_replace('\\', '/', \rtrim($path, '/\\'));
        return \PHP_OS_FAMILY === 'Windows' ? \strtolower($path) : $path;
    }

    private function assertProjectPathComponents(string $path): void
    {
        if (!$this->pathInside($path, $this->projectRoot)) {
            throw new \RuntimeException('Certificate path escapes the project root.');
        }
        $relative = \ltrim(\substr(
            $this->pathKey($path),
            \strlen($this->pathKey($this->projectRoot)),
        ), '/');
        $current = $this->projectRoot;
        foreach ($relative === '' ? [] : \explode('/', $relative) as $segment) {
            $current .= DIRECTORY_SEPARATOR . $segment;
            $status = @\lstat($current);
            if (!\is_array($status)
                || \is_link($current)
                || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
                || (\PHP_OS_FAMILY !== 'Windows'
                    && (((int)($status['mode'] ?? 0)) & 0022) !== 0)
            ) {
                throw new \RuntimeException(
                    'Certificate source path contains a linked, special or group/world-writable directory.'
                );
            }
        }
    }

    /**
     * Validate only the explicit enrollment boundary and its descendants. Host
     * ancestors such as /tmp are not implicitly trusted or rejected; enrolling
     * /tmp itself still fails because the enrolled root is writable. Every
     * component below a secure /tmp/project-certificates enrollment remains
     * subject to the same owner and permission policy.
     */
    private function assertEnrolledDirectoryComponents(
        string $root,
        string $directory,
        int $expectedOwner,
    ): void
    {
        $root = \rtrim($root, '/\\');
        $directory = \rtrim($directory, '/\\');
        if (!$this->pathInside($directory, $root)) {
            throw new \RuntimeException(
                'Certificate source directory escapes its enrolled root.'
            );
        }
        $relative = \ltrim(\substr(
            $this->pathKey($directory),
            \strlen($this->pathKey($root)),
        ), '/');
        $segments = $relative === '' ? [] : \explode('/', $relative);
        if (\count($segments) > 256) {
            throw new \RuntimeException(
                'Certificate source path exceeds the 256-segment limit.'
            );
        }
        $current = $root;
        foreach ([null, ...$segments] as $segment) {
            if (\is_string($segment)) {
                $current .= DIRECTORY_SEPARATOR . $segment;
            }
            $status = @\lstat($current);
            if (!\is_array($status)
                || \is_link($current)
                || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
                || (\PHP_OS_FAMILY !== 'Windows'
                    && ((((int)($status['mode'] ?? 0)) & 0022) !== 0
                        || ($expectedOwner >= 0
                            && (int)($status['uid'] ?? -1) !== $expectedOwner)))
            ) {
                throw new \RuntimeException(
                    'Certificate source enrollment contains a linked, special, '
                    . 'foreign-owned or group/world-writable directory.'
                );
            }
        }
    }

    private function preserveCreatedProjectDirectory(string $directory): void
    {
        if (\PHP_OS_FAMILY === 'Windows'
            || $this->projectOwner < 0
            || $this->projectGroup < 0
            || !\function_exists('posix_geteuid')
            || \posix_geteuid() !== 0
        ) {
            return;
        }
        if (!@\chown($directory, $this->projectOwner)
            || !@\chgrp($directory, $this->projectGroup)
        ) {
            throw new \RuntimeException(
                'Unable to preserve project ownership on certificate directories.'
            );
        }
    }

    private function removeDirectory(string $directory): void
    {
        $snapshotRoot = $this->storeRoot . DIRECTORY_SEPARATOR . 'snapshots';
        $basename = \basename($directory);
        if (!\is_dir($directory)
            || \is_link($directory)
            || !$this->pathInside($directory, $snapshotRoot)
            || \preg_match('/\A\.tmp-[a-f0-9]{24}\z/D', $basename) !== 1
        ) {
            return;
        }
        $entries = GatewayBoundedTreeWalker::collect($directory, true, true);
        foreach ($entries as $entry) {
            GatewayBoundedTreeWalker::revalidate($entry);
        }
        foreach ($entries as $entry) {
            GatewayBoundedTreeWalker::revalidate($entry);
            $removed = $entry['directory']
                ? @\rmdir((string)$entry['path'])
                : @\unlink((string)$entry['path']);
            if (!$removed) {
                throw new \RuntimeException(
                    'Unable to remove a verified certificate snapshot staging entry.'
                );
            }
        }
    }

}
