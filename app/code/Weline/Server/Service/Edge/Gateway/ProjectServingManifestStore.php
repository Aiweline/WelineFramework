<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Project-owned, whole-project TLS serving truth.
 *
 * The mutable current pointer never contains serving facts. It selects one
 * immutable, content-addressed generation which is fully validated before a
 * Worker or a runtime projection may consume it.
 */
final class ProjectServingManifestStore
{
    public const SCHEMA = 'wls-project-serving-manifest/2';
    private const LEGACY_SCHEMA = 'wls-project-serving-manifest/1';
    public const POINTER_SCHEMA = 'wls-project-serving-manifest-pointer/1';
    public const AUTHORITY_SCHEMA = 'wls-project-serving-manifest-authority/1';
    public const LKG_SCHEMA = 'wls-project-serving-manifest-lkg/1';
    public const MAX_ROUTES = 256;
    private const MAX_MANIFEST_BYTES = 4_194_304;
    private const MAX_CERTIFICATE_BYTES = 1_048_576;
    private const MAX_STORED_MANIFESTS = 128;
    private const MAX_STORED_MANIFEST_BYTES = 268_435_456;
    private const MAX_ORPHANED_MANIFEST_CANDIDATES = 64;
    private const MAX_RAW_MANIFEST_DIRECTORY_ENTRIES = self::MAX_STORED_MANIFESTS
        + self::MAX_ORPHANED_MANIFEST_CANDIDATES;
    private const MANIFEST_RETENTION_SECONDS = 604_800;
    private const MAX_STORE_ROOT_ENTRIES = 2048;
    private const MANIFEST_RETIREMENT_SCHEMA = 'wls-serving-manifest-retirement/1';
    private const MANIFEST_RETIREMENT_MARKER_SCHEMA
        = 'wls-serving-manifest-retirement-marker/1';
    private const MAX_RETIREMENT_STATE_BYTES = 262_144;
    private const POST_COMMIT_CLEANUP_SECONDS = 1.0;

    private readonly string $projectRoot;
    private readonly string $storeRoot;
    private readonly string $manifestRoot;
    private readonly int $projectOwner;
    private readonly int $projectGroup;
    private readonly ?\Closure $publicationWallClock;
    private readonly ?\Closure $publicationMonotonicClock;
    private readonly ?\Closure $publicationBootIdentityResolver;
    private readonly ?\Closure $snapshotReferenceTransition;

    private int $publicationTransactionDepth = 0;
    private string $publicationTransactionInstance = '';

    public function __construct(
        ?string $projectRoot = null,
        ?\Closure $publicationWallClock = null,
        ?\Closure $publicationMonotonicClock = null,
        ?\Closure $publicationBootIdentityResolver = null,
        ?\Closure $snapshotReferenceTransition = null,
    ) {
        $requested = $projectRoot ?? (string)BP;
        if ($requested === '' || \str_contains($requested, "\0") || \is_link($requested)) {
            throw new \RuntimeException('WLS serving manifest project root is unsafe.');
        }
        $canonical = \realpath($requested);
        $status = \is_string($canonical) ? @\lstat($canonical) : false;
        if (!\is_string($canonical)
            || $canonical === ''
            || !\is_array($status)
            || \is_link($canonical)
            || (((int)($status['mode'] ?? 0) & 0170000) !== 0040000)
            || $this->isFilesystemRoot($canonical)
        ) {
            throw new \RuntimeException('WLS serving manifest project root is unavailable.');
        }
        $this->projectRoot = \rtrim($canonical, '/\\');
        $this->storeRoot = $this->projectRoot . DIRECTORY_SEPARATOR . 'var'
            . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'serving-manifest';
        $this->manifestRoot = $this->storeRoot . DIRECTORY_SEPARATOR . 'manifests';
        $this->projectOwner = \is_int($status['uid'] ?? null) ? (int)$status['uid'] : -1;
        $this->projectGroup = \is_int($status['gid'] ?? null) ? (int)$status['gid'] : -1;
        $this->publicationWallClock = $publicationWallClock;
        $this->publicationMonotonicClock = $publicationMonotonicClock;
        $this->publicationBootIdentityResolver = $publicationBootIdentityResolver;
        $this->snapshotReferenceTransition = $snapshotReferenceTransition;
    }

    public function currentPointerPath(string $instanceId): string
    {
        $this->assertInstanceId($instanceId);
        return $this->storeRoot . DIRECTORY_SEPARATOR . 'current-'
            . \substr(\hash('sha256', $instanceId), 0, 32) . '.json';
    }

    /**
     * Publish one all-or-nothing manifest from a complete registration.
     *
     * @param array<string,mixed> $registration
     * @param list<string>|null $servingRouteIds null means every route with a
     *        currently valid project certificate generation
     * @param array<string,int> $routeGenerations
     * @return array{path:string,generation:int,digest:string,converged:bool,route_count:int,payload:array<string,mixed>}
     */
    public function publishFromRegistration(
        array $registration,
        ?array $servingRouteIds = null,
        array $routeGenerations = [],
        ?bool $converged = null,
        ?float $deadlineMonotonic = null,
    ): array {
        $instanceId = (string)($registration['instance_id'] ?? '');
        return $this->withPublicationTransaction(
            $instanceId,
            function () use (
                $registration,
                $servingRouteIds,
                $routeGenerations,
                $converged,
                $deadlineMonotonic,
            ): array {
                $this->assertPublicationDeadline($deadlineMonotonic);
                $candidate = $this->candidateFromRegistration(
                    $registration,
                    $servingRouteIds,
                    $routeGenerations,
                    $converged,
                    $deadlineMonotonic,
                );
                return GatewayProjectStateFilesystem::withExclusiveLock(
                    $this->storeRoot . DIRECTORY_SEPARATOR . 'publish.lock',
                    function () use ($candidate, $deadlineMonotonic): array {
                        $this->assertPublicationDeadline($deadlineMonotonic);
                        return $this->publishLocked(
                            $candidate,
                            $deadlineMonotonic,
                        );
                    },
                    fn ($handle, string $path): mixed => $this->preserveOwnership(
                        $path,
                        $handle,
                    ),
                    waitTimeoutSeconds: $this->publicationLockWaitTimeout(
                        $deadlineMonotonic,
                    ),
                );
            },
            $this->publicationLockWaitTimeout($deadlineMonotonic),
        );
    }

    private function publicationLockWaitTimeout(
        ?float $deadlineMonotonic,
    ): float {
        if ($deadlineMonotonic === null) {
            return 300.0;
        }
        return \min(0.25, $this->assertPublicationDeadline($deadlineMonotonic));
    }

    private function assertPublicationDeadline(
        ?float $deadlineMonotonic,
    ): float {
        if ($deadlineMonotonic === null) {
            return 300.0;
        }
        if (!\is_finite($deadlineMonotonic)) {
            throw new \RuntimeException('Serving manifest deadline is invalid.');
        }
        $remaining = $deadlineMonotonic - $this->publicationMonotonicNow();
        if ($remaining <= 0.0) {
            throw new \RuntimeException('Serving manifest deadline was exhausted.');
        }
        return $remaining;
    }

    private function publicationMonotonicNow(): float
    {
        $monotonic = $this->publicationMonotonicClock !== null
            ? ($this->publicationMonotonicClock)()
            : (\hrtime(true) / 1_000_000_000);
        if ((!\is_int($monotonic) && !\is_float($monotonic))
            || !\is_finite((float)$monotonic)
            || (float)$monotonic < 0.0
        ) {
            throw new \RuntimeException(
                'Serving manifest monotonic clock is invalid.',
            );
        }
        return (float)$monotonic;
    }

    /**
     * Serialize manifest publication and its exact Worker acknowledgement.
     * Nested publishers in the same PHP request reuse the already-held lock;
     * every other process blocks before it can advance the current pointer.
     *
     * @template TResult
     * @param \Closure():TResult $callback
     * @return TResult
     */
    public function withPublicationTransaction(
        string $instanceId,
        \Closure $callback,
        float $waitTimeoutSeconds = 300.0,
    ): mixed {
        $this->assertInstanceId($instanceId);
        $this->ensureStoreDirectories();
        $path = $this->storeRoot . DIRECTORY_SEPARATOR . 'transaction-'
            . \substr(\hash('sha256', $instanceId), 0, 32) . '.lock';
        if ($this->publicationTransactionDepth > 0) {
            if (!\hash_equals($this->publicationTransactionInstance, $instanceId)) {
                throw new \RuntimeException(
                    'A serving manifest transaction cannot nest a different instance.',
                );
            }
            ++$this->publicationTransactionDepth;
            try {
                return $callback();
            } finally {
                --$this->publicationTransactionDepth;
            }
        }
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $path,
            function () use ($instanceId, $callback): mixed {
                $this->publicationTransactionInstance = $instanceId;
                $this->publicationTransactionDepth = 1;
                try {
                    return $callback();
                } finally {
                    $this->publicationTransactionDepth = 0;
                    $this->publicationTransactionInstance = '';
                }
            },
            fn ($handle, string $lockPath): mixed => $this->preserveOwnership(
                $lockPath,
                $handle,
            ),
            waitTimeoutSeconds: $waitTimeoutSeconds,
        );
    }

    /** @return array{path:string,generation:int,digest:string,converged:bool,route_count:int,payload:array<string,mixed>} */
    public function current(string $instanceId): array
    {
        $pointer = $this->readPointer($instanceId);
        return $this->readBound(
            (string)$pointer['path'],
            (int)$pointer['generation'],
            (string)$pointer['digest'],
        );
    }

    /**
     * @param array<string,mixed> $fence
     * @return array{path:string,generation:int,digest:string,converged:bool,route_count:int,payload:array<string,mixed>}
     */
    public function currentForFence(array $fence): array
    {
        $manifest = $this->current((string)($fence['instance_id'] ?? ''));
        $this->assertLaunchFence((array)$manifest['payload'], $fence);
        return $manifest;
    }

    /**
     * Run a snapshot-GC decision while excluding serving-manifest publication.
     *
     * Candidate construction validates certificate bytes before publish.lock is
     * acquired. Holding the same lock through reference collection and deletion
     * ensures a publisher either revalidates after GC or has made its immutable
     * manifest/current reference visible before GC can remove a snapshot.
     *
     * @template T
     * @param \Closure(array<string,true>):T $callback
     * @return T
     */
    public function withCertificateSnapshotReferences(
        \Closure $callback,
        ?float $deadlineMonotonic = null,
    ): mixed {
        $this->assertPublicationDeadline($deadlineMonotonic);
        $status = @\lstat($this->storeRoot);
        if (!\is_array($status)) {
            if (\file_exists($this->storeRoot) || \is_link($this->storeRoot)) {
                throw new \RuntimeException(
                    'WLS serving manifest reference store is unsafe.',
                );
            }
            // A publisher validates immutable certificate snapshots before it
            // takes publish.lock. Even when this is the first publication, GC
            // must create and take that same lock; returning an unlocked empty
            // set here would allow the first publisher to bind a snapshot while
            // GC concurrently removes it as unreferenced.
            $this->ensureStoreDirectories();
            $status = @\lstat($this->storeRoot);
        }
        $canonical = \realpath($this->storeRoot);
        if (!\is_array($status)
            || \is_link($this->storeRoot)
            || (((int)($status['mode'] ?? 0) & 0170000) !== 0040000)
            || !\is_string($canonical)
            || !$this->samePath($canonical, $this->storeRoot)
            || !$this->pathInside($canonical, $this->projectRoot)
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((((int)$status['mode']) & 0777) !== 0700
                    || ($this->projectOwner >= 0
                        && (int)($status['uid'] ?? -1) !== $this->projectOwner)))
        ) {
            throw new \RuntimeException(
                'WLS serving manifest reference store is unsafe.',
            );
        }
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $this->storeRoot . DIRECTORY_SEPARATOR . 'publish.lock',
            function () use ($callback, $deadlineMonotonic): mixed {
                $this->assertPublicationDeadline($deadlineMonotonic);
                return $callback($this->certificateSnapshotReferencesUnlocked());
            },
            fn ($handle, string $path): mixed => $this->preserveOwnership($path, $handle),
            waitTimeoutSeconds: $this->publicationLockWaitTimeout(
                $deadlineMonotonic,
            ),
        );
    }

    /** @return array<string,true> */
    public function referencedCertificateSnapshotDigests(): array
    {
        return $this->withCertificateSnapshotReferences(
            static fn (array $references): array => $references,
        );
    }

    /**
     * Read only the exact immutable generation supplied by the launcher.
     * No default path discovery is performed here.
     *
     * @param array<string,mixed> $fence
     * @return array{path:string,generation:int,digest:string,converged:bool,route_count:int,payload:array<string,mixed>}
     */
    public function readBound(
        string $path,
        int $generation,
        string $digest,
        array $fence = [],
    ): array {
        $digest = \strtolower(\trim($digest));
        if ($generation < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
            || !$this->canonicalManifestPathMatches($path, $generation, $digest)
        ) {
            throw new \RuntimeException('WLS serving manifest launch binding is invalid.');
        }
        $this->assertPrivateStateFile($path, 'WLS serving manifest');
        $encoded = GatewayProjectStateFilesystem::read(
            $path,
            self::MAX_MANIFEST_BYTES,
            'WLS serving manifest',
        );
        $envelope = \json_decode($encoded, true);
        $payload = \is_array($envelope) && \is_array($envelope['payload'] ?? null)
            ? $envelope['payload']
            : null;
        $unsigned = \is_array($envelope) ? $envelope : [];
        unset($unsigned['digest']);
        $schema = (string)($envelope['schema'] ?? '');
        if (!\is_array($payload)
            || (!\hash_equals(self::SCHEMA, $schema)
                && !\hash_equals(self::LEGACY_SCHEMA, $schema))
            || (int)($envelope['generation'] ?? 0) !== $generation
            || !\hash_equals($digest, (string)($envelope['digest'] ?? ''))
            || !\hash_equals(
                (string)($envelope['payload_sha256'] ?? ''),
                \hash('sha256', GatewayClient::canonicalJson($payload)),
            )
            || !\hash_equals(
                $digest,
                \hash('sha256', GatewayClient::canonicalJson($unsigned)),
            )
        ) {
            throw new \RuntimeException('WLS serving manifest envelope integrity failed.');
        }
        $this->assertPayload($payload, \hash_equals(self::SCHEMA, $schema));
        if ($fence !== []) {
            $this->assertLaunchFence($payload, $fence);
        }
        return [
            'path' => $path,
            'generation' => $generation,
            'digest' => $digest,
            'converged' => (bool)$payload['converged'],
            'route_count' => \count((array)$payload['routes']),
            'payload' => $payload,
        ];
    }

    public static function normalizeHost(string $host, bool $allowWildcard = true): string
    {
        $host = \strtolower(\rtrim(\trim($host), '.'));
        if (\str_contains($host, "\0") || $host === '') {
            throw new \InvalidArgumentException('Serving manifest host is empty or unsafe.');
        }
        $wildcard = $allowWildcard && \str_starts_with($host, '*.');
        $body = $wildcard ? \substr($host, 2) : $host;
        if (\function_exists('idn_to_ascii')) {
            $variant = \defined('INTL_IDNA_VARIANT_UTS46')
                ? \constant('INTL_IDNA_VARIANT_UTS46')
                : 0;
            $ascii = @\idn_to_ascii($body, IDNA_DEFAULT, $variant);
            if (\is_string($ascii) && $ascii !== '') {
                $body = \strtolower($ascii);
            }
        }
        // Local loopback fact keys remain valid without a public TLD.
        // WLS local start commonly uses 127.0.0.1 / ::1 as the serving host.
        if ($body === 'localhost') {
            return $wildcard ? '*.' . $body : $body;
        }
        if (\filter_var($body, FILTER_VALIDATE_IP) !== false) {
            if (!$wildcard && self::isLoopbackIpLiteral($body)) {
                return self::canonicalLoopbackIpLiteral($body);
            }
            throw new \InvalidArgumentException('Serving manifest host is invalid: ' . $host);
        }
        if (\strlen($body) > 253
            || \preg_match(
                '/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)'
                    . '(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))+\z/D',
                $body,
            ) !== 1
        ) {
            throw new \InvalidArgumentException('Serving manifest host is invalid: ' . $host);
        }
        return $wildcard ? '*.' . $body : $body;
    }

    /**
     * Resolve an exact or wildcard serving route for a Host/SNI authority.
     *
     * Local loopback literals (`localhost`, `127.0.0.1`, `::1`) share one
     * tenant: browsers use IP SNI for https://127.0.0.1 while local start may
     * only have published the localhost route.
     *
     * @param array<string,array<string,mixed>> $routesByDomain
     * @return array<string,mixed>|null
     */
    public static function routeForHost(string $host, array $routesByDomain): ?array
    {
        try {
            $host = self::normalizeHost($host, false);
        } catch (\Throwable) {
            return null;
        }
        if (\is_array($routesByDomain[$host] ?? null)) {
            return $routesByDomain[$host];
        }
        $loopbackAliases = ['localhost', '127.0.0.1', '::1'];
        if (\in_array($host, $loopbackAliases, true)) {
            foreach ($loopbackAliases as $alias) {
                if ($alias === $host) {
                    continue;
                }
                if (\is_array($routesByDomain[$alias] ?? null)) {
                    return $routesByDomain[$alias];
                }
            }
        }
        $dot = \strpos($host, '.');
        if ($dot === false || $dot < 1) {
            return null;
        }
        $wildcard = '*.' . \substr($host, $dot + 1);

        return \is_array($routesByDomain[$wildcard] ?? null) ? $routesByDomain[$wildcard] : null;
    }

    private static function isLoopbackIpLiteral(string $ip): bool
    {
        if (\filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return \str_starts_with($ip, '127.');
        }
        if (\filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = @\inet_pton($ip);
            return \is_string($packed)
                && \strlen($packed) === 16
                && $packed === "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\1";
        }

        return false;
    }

    private static function canonicalLoopbackIpLiteral(string $ip): string
    {
        $packed = @\inet_pton($ip);
        $canonical = \is_string($packed) ? @\inet_ntop($packed) : false;
        if (!\is_string($canonical) || $canonical === '') {
            throw new \InvalidArgumentException('Serving manifest host is invalid: ' . $ip);
        }

        return \strtolower($canonical);
    }

    /** @param array<string,mixed> $registration @return array<string,mixed> */
    private function candidateFromRegistration(
        array $registration,
        ?array $servingRouteIds,
        array $routeGenerations,
        ?bool $converged,
        ?float $deadlineMonotonic,
    ): array {
        $this->assertPublicationDeadline($deadlineMonotonic);
        $projectUuid = \strtolower(\trim((string)($registration['project_uuid'] ?? '')));
        $instanceId = \trim((string)($registration['instance_id'] ?? ''));
        $projectRoot = \realpath((string)($registration['project_root'] ?? ''));
        $masterPid = (int)($registration['master_pid']
            ?? $registration['routes'][0]['backend_identity']['master_pid'] ?? 0);
        $masterEpoch = (int)($registration['master_epoch'] ?? 0);
        $launchId = \strtolower(\trim((string)($registration['launch_id'] ?? '')));
        $instanceGeneration = (int)($registration['instance_generation'] ?? 0);
        $projectGeneration = (int)($registration['project_generation'] ?? 0);
        $requestDigest = \strtolower(\trim((string)($registration['request_digest'] ?? '')));
        $desiredDigest = \strtolower(\trim((string)(
            $registration['non_certificate_desired_digest'] ?? ''
        )));
        if (!\is_string($projectRoot)
            || !$this->samePath($projectRoot, $this->projectRoot)
            || \preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                $projectUuid,
            ) !== 1
            || \preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $instanceId) !== 1
            || $masterPid < 1
            || $masterEpoch < 1
            || $instanceGeneration < 1
            || $projectGeneration < 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', $launchId) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $requestDigest) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $desiredDigest) !== 1
        ) {
            throw new \RuntimeException('Serving manifest registration fence is invalid.');
        }
        $desiredRoutes = \is_array($registration['routes'] ?? null)
            && \array_is_list($registration['routes'])
                ? $registration['routes']
                : [];
        if ($desiredRoutes === [] || \count($desiredRoutes) > self::MAX_ROUTES) {
            throw new \RuntimeException('Serving manifest desired route set is outside bounds.');
        }
        $selected = null;
        if ($servingRouteIds !== null) {
            if (!\array_is_list($servingRouteIds) || \count($servingRouteIds) > self::MAX_ROUTES) {
                throw new \RuntimeException('Serving manifest selected route set is outside bounds.');
            }
            $selected = [];
            foreach ($servingRouteIds as $routeId) {
                $this->assertPublicationDeadline($deadlineMonotonic);
                $routeId = \strtolower(\trim((string)$routeId));
                if (\preg_match('/\A[a-f0-9]{32}\z/D', $routeId) !== 1
                    || isset($selected[$routeId])
                ) {
                    throw new \RuntimeException('Serving manifest selected route set is malformed.');
                }
                $selected[$routeId] = true;
            }
            \ksort($selected, SORT_STRING);
        }
        if (\count($routeGenerations) > self::MAX_ROUTES) {
            throw new \RuntimeException('Serving route generation set is outside bounds.');
        }
        $normalizedRouteGenerations = [];
        foreach ($routeGenerations as $routeId => $routeGeneration) {
            $this->assertPublicationDeadline($deadlineMonotonic);
            if (!\is_string($routeId)
                || \preg_match('/\A[a-f0-9]{32}\z/D', $routeId) !== 1
                || !\is_int($routeGeneration)
                || $routeGeneration < 1
                || isset($normalizedRouteGenerations[$routeId])
            ) {
                throw new \RuntimeException('Serving route generation set is malformed.');
            }
            $normalizedRouteGenerations[$routeId] = $routeGeneration;
        }
        \ksort($normalizedRouteGenerations, SORT_STRING);
        if (($selected === null && $normalizedRouteGenerations !== [])
            || ($selected !== null
                && \array_keys($normalizedRouteGenerations) !== \array_keys($selected))
        ) {
            throw new \RuntimeException(
                'Serving route generations do not exactly cover the selected route set.',
            );
        }
        $routeGenerations = $normalizedRouteGenerations;
        $routes = [];
        $desiredRouteIds = [];
        $desiredRouteFacts = [];
        foreach ($desiredRoutes as $route) {
            $this->assertPublicationDeadline($deadlineMonotonic);
            if (!\is_array($route)) {
                throw new \RuntimeException('Serving manifest desired route is malformed.');
            }
            $routeId = \strtolower(\trim((string)($route['route_id'] ?? '')));
            $domain = self::normalizeHost((string)($route['domain'] ?? ''));
            $expectedRouteId = \substr(\hash('sha256', $projectUuid . "\0" . $domain), 0, 32);
            if (!\hash_equals($expectedRouteId, $routeId) || isset($desiredRouteIds[$routeId])) {
                throw new \RuntimeException('Serving manifest route identity is invalid.');
            }
            $desiredRouteIds[$routeId] = true;
            $certificate = \is_array($route['certificate'] ?? null)
                ? $route['certificate']
                : [];
            $certificateGeneration = (int)($certificate['generation'] ?? 0);
            $sourceDigest = \strtolower(\trim((string)($certificate['source_digest'] ?? '')));
            $certificateState = \strtolower(\trim((string)(
                $certificate['state']
                    ?? (($certificateGeneration > 0
                        && ($certificate['pending'] ?? true) === false)
                        ? 'active'
                        : 'pending')
            )));
            $pending = $certificate['pending'] ?? true;
            if (!\in_array($certificateState, ['active', 'pending', 'disabled'], true)
                || !\is_bool($pending)
                || \preg_match('/\A[a-f0-9]{64}\z/D', $sourceDigest) !== 1
                || ($certificateState === 'active'
                    && ($certificateGeneration < 1 || $pending !== false))
                || ($certificateState === 'pending'
                    && ($certificateGeneration !== 0
                        || $pending !== true
                        || !\hash_equals(
                            \hash('sha256', "wls-pending-certificate\0" . $domain),
                            $sourceDigest,
                        )))
                || ($certificateState === 'disabled'
                    && ($certificateGeneration < 1
                        || $pending !== true
                        || !\hash_equals(
                            \hash(
                                'sha256',
                                "wls-disabled-certificate\0" . $domain . "\0"
                                    . $certificateGeneration,
                            ),
                            $sourceDigest,
                        )))
            ) {
                throw new \RuntimeException(
                    'Serving manifest certificate lifecycle envelope is inconsistent.',
                );
            }
            if ($certificateState !== 'active'
                && (\str_starts_with($domain, '*.')
                    || (array)($certificate['cert'] ?? []) !== []
                    || (array)($certificate['key'] ?? []) !== []
                    || ($certificate['chain'] ?? null) !== null)
            ) {
                throw new \RuntimeException(
                    'Inactive serving certificate state contains material or a wildcard.',
                );
            }
            $forceHttps = $route['force_https'] ?? null;
            $forceRootToWww = $route['force_root_to_www'] ?? null;
            if (!\is_bool($forceHttps) || !\is_bool($forceRootToWww)) {
                throw new \RuntimeException('Serving route policy is not canonical.');
            }
            if ($certificateState === 'disabled'
                && ($forceHttps !== false || $forceRootToWww !== false)
            ) {
                throw new \RuntimeException(
                    'Disabled certificate routes must retain HTTP without HTTPS redirects.',
                );
            }
            $rootTarget = (string)($route['root_to_www_target'] ?? '');
            if (($forceRootToWww && \str_starts_with($domain, '*.'))
                || ($forceRootToWww && !\hash_equals('www.' . $domain, $rootTarget))
                || (!$forceRootToWww && $rootTarget !== '')
            ) {
                throw new \RuntimeException('Serving root-to-www target is not fixed by desired state.');
            }
            $desiredRouteFacts[$routeId] = [
                'route_id' => $routeId,
                'domain' => $domain,
                'certificate_state' => $certificateState,
                'certificate_generation' => $certificateGeneration,
                'certificate_source_digest' => $sourceDigest,
                'force_https' => $forceHttps,
                'force_root_to_www' => $forceRootToWww,
                'root_to_www_target' => $rootTarget,
                // Recomputed from the complete desired set below. This is
                // HTTP route readiness, not active-TLS subset readiness.
                'root_to_www_target_ready' => !$forceRootToWww,
            ];
            $isSelected = $selected === null
                ? $certificateState === 'active'
                : isset($selected[$routeId]);
            if (!$isSelected) {
                continue;
            }
            if ($certificateState !== 'active') {
                throw new \RuntimeException('Selected serving route has no active certificate generation.');
            }
            $certificatePath = $this->resolveProjectCertificateReference(
                (array)($certificate['cert'] ?? []),
            );
            $keyPath = $this->resolveProjectCertificateReference(
                (array)($certificate['key'] ?? []),
            );
            $this->assertPublicationDeadline($deadlineMonotonic);
            $snapshot = $this->verifiedCertificateSnapshot(
                $sourceDigest,
                $certificatePath,
                $keyPath,
            );
            $this->assertPublicationDeadline($deadlineMonotonic);
            $leafFingerprint = \strtolower(\trim((string)(
                $certificate['leaf_fingerprint_sha256'] ?? ''
            )));
            if (\preg_match('/\A[a-f0-9]{64}\z/D', $leafFingerprint) !== 1
                || !\hash_equals(
                    (string)$snapshot['leaf_fingerprint_sha256'],
                    $leafFingerprint,
                )
            ) {
                throw new \RuntimeException(
                    'Serving certificate snapshot leaf fingerprint is inconsistent.',
                );
            }
            $routeGeneration = $routeGenerations[$routeId] ?? 0;
            if (!\is_int($routeGeneration)
                || $routeGeneration < 0
                || ($selected !== null && $routeGeneration < 1)
            ) {
                throw new \RuntimeException('Serving route generation is invalid.');
            }
            $routes[$routeId] = [
                'route_id' => $routeId,
                'domain' => $domain,
                'route_generation' => $routeGeneration,
                'certificate_generation' => $certificateGeneration,
                'certificate_source_digest' => $sourceDigest,
                'certificate' => $snapshot['certificate'],
                'certificate_chain' => $snapshot['chain'],
                'private_key' => $snapshot['private_key'],
                'certificate_snapshot' => [
                    'manifest' => $snapshot['manifest'],
                    'leaf_fingerprint_sha256' => $leafFingerprint,
                ],
                'policy' => [
                    'force_https' => $forceHttps,
                    'force_root_to_www' => $forceRootToWww,
                    'root_to_www_target' => $rootTarget,
                    // Final readiness is derived below from the exact route
                    // subset and is therefore covered by the manifest digest.
                    'root_to_www_target_ready' => !$forceRootToWww,
                ],
            ];
        }
        \ksort($desiredRouteFacts, SORT_STRING);
        foreach ($desiredRouteFacts as $routeId => $desiredRouteFact) {
            $this->assertPublicationDeadline($deadlineMonotonic);
            if (($desiredRouteFact['force_root_to_www'] ?? false) !== true) {
                continue;
            }
            $targetRouteId = \substr(\hash(
                'sha256',
                $projectUuid . "\0" . (string)$desiredRouteFact['root_to_www_target'],
            ), 0, 32);
            if (!isset($desiredRouteFacts[$targetRouteId])) {
                throw new \RuntimeException(
                    'Serving desired HTTP root-to-www target is absent.',
                );
            }
            $desiredRouteFacts[$routeId]['root_to_www_target_ready'] = true;
        }
        if ($selected !== null && \count($routes) !== \count($selected)) {
            throw new \RuntimeException('Serving route selection is not a subset of current desired state.');
        }
        foreach ($routes as $routeId => $route) {
            $this->assertPublicationDeadline($deadlineMonotonic);
            $policy = (array)$route['policy'];
            if (($policy['force_root_to_www'] ?? false) !== true) {
                continue;
            }
            $targetRouteId = \substr(\hash(
                'sha256',
                $projectUuid . "\0" . (string)$policy['root_to_www_target'],
            ), 0, 32);
            $targetReady = isset($routes[$targetRouteId]);
            if (!$targetReady && $selected !== null) {
                throw new \RuntimeException(
                    'Serving root-to-www target is outside the exact serving subset.',
                );
            }
            // Pure WLS keeps the apex certificate serviceable even while the
            // fixed www target has no active certificate. The Worker consumes
            // this digest-bound false value as a fixed 503 gate; it must never
            // redirect or enter the application until a later manifest makes
            // the exact target route serviceable.
            $routes[$routeId]['policy']['root_to_www_target_ready'] = $targetReady;
        }
        \ksort($routes, SORT_STRING);
        $exactConverged = \count($routes) === \count($desiredRouteIds);
        $converged ??= $exactConverged;
        if ($converged && !$exactConverged) {
            throw new \RuntimeException('Serving manifest cannot mark a partial route subset converged.');
        }
        $this->assertPublicationDeadline($deadlineMonotonic);
        return [
            'project_uuid' => $projectUuid,
            'project_root' => $this->projectRoot,
            'instance_id' => $instanceId,
            'instance_generation' => $instanceGeneration,
            'master_pid' => $masterPid,
            'master_epoch' => $masterEpoch,
            'launch_id' => $launchId,
            'project_generation' => $projectGeneration,
            'request_digest' => $requestDigest,
            'non_certificate_desired_digest' => $desiredDigest,
            'converged' => $converged,
            'desired_route_count' => \count($desiredRouteIds),
            'desired_routes' => \array_values($desiredRouteFacts),
            'routes' => \array_values($routes),
        ];
    }

    /** @param array<string,mixed> $payload */
    private function publishLocked(
        array $payload,
        ?float $deadlineMonotonic = null,
    ): array {
        $this->assertPublicationDeadline($deadlineMonotonic);
        // Candidate construction happens before publish.lock is acquired. GC
        // uses this same lock, so revalidating the complete content-addressed
        // certificate closure here closes the validate/delete/publish window.
        $this->assertPayload($payload, true);
        $this->assertPublicationDeadline($deadlineMonotonic);
        $instanceId = (string)$payload['instance_id'];
        $this->cleanupMutableAtomicWriteRecoveryBackupsLocked($instanceId);
        $factsDigest = \hash('sha256', GatewayClient::canonicalJson($payload));
        $current = null;
        try {
            $current = $this->current($instanceId);
        } catch (\Throwable) {
            // A missing pointer is allowed for the first publication. Corrupt
            // state is not silently used as an LKG or a generation floor.
            if (@\lstat($this->currentPointerPath($instanceId)) !== false
                || \file_exists($this->currentPointerPath($instanceId))
                || \is_link($this->currentPointerPath($instanceId))
            ) {
                throw new \RuntimeException('Current WLS serving manifest pointer is corrupt.');
            }
        }
        if (\is_array($current)) {
            $currentAuthority = $this->authorityFromPublication($current);
        } else {
            $currentAuthority = null;
        }
        // A corrupt retention pointer must be detected before authority or the
        // generation floor can advance. GC then fails closed without stranding
        // the serving pointer behind a newer publication fence.
        $this->readRecentLkgReferences(
            $this->recentLkgFile($instanceId),
            $instanceId,
            true,
        );
        $beforeCertificateReferences = $this
            ->certificateSnapshotReferencesForInstanceUnlocked($instanceId);
        $candidateCertificateReferences = $this
            ->certificateSnapshotDigestsFromPayload($payload);
        $this->transitionCertificateSnapshotReferences(
            $candidateCertificateReferences,
            \array_diff_key(
                $beforeCertificateReferences,
                $candidateCertificateReferences,
            ),
            $deadlineMonotonic,
        );
        $authority = $this->readPublicationAuthority($instanceId);
        if ($authority === null && \is_array($currentAuthority)) {
            // One-time migration from the pointer-only format. Persist the
            // independently recoverable authority before accepting another
            // publication or returning an idempotent success.
            $this->assertPublicationDeadline($deadlineMonotonic);
            $this->writePublicationAuthority($instanceId, $currentAuthority);
            $authority = $currentAuthority;
        } elseif ($authority !== null && \is_array($currentAuthority)) {
            $this->assertAuthorityCoversCurrent($authority, $currentAuthority);
        }
        if ($authority !== null) {
            $this->assertMonotonicPublicationFence($payload, $authority);
        }
        $floor = $this->readGenerationFloor($instanceId);
        if (\is_array($current)) {
            $floor = \max($floor, (int)$current['generation']);
        }
        if ($authority !== null) {
            $floor = \max($floor, (int)$authority['generation']);
        } elseif ($floor > 0) {
            throw new \RuntimeException(
                'WLS serving manifest authority is missing behind its generation floor.',
            );
        }
        if ($authority !== null
            && (int)$authority['generation'] === $floor
            && \hash_equals($factsDigest, (string)$authority['payload_sha256'])
        ) {
            $this->assertPublicationDeadline($deadlineMonotonic);
            $verified = $this->readBound(
                $this->manifestPath(
                    (int)$authority['generation'],
                    (string)$authority['manifest_digest'],
                ),
                (int)$authority['generation'],
                (string)$authority['manifest_digest'],
            );
            if ($this->readGenerationFloor($instanceId) < (int)$authority['generation']) {
                $this->atomicWrite(
                    $this->generationFile($instanceId),
                    (string)$authority['generation'] . "\n",
                    0600,
                );
            }
            if (\is_array($current)
                && !\hash_equals(
                    (string)$current['digest'],
                    (string)$verified['digest'],
                )
            ) {
                $this->writeRecentLkgReferences($instanceId, $current);
            }
            $this->writeCurrentPointer($instanceId, $verified);
            $this->afterCommittedPublication($instanceId);
            return $verified;
        }
        if (\is_array($current)
            && (int)$current['generation'] === $floor
            && \hash_equals(
                $factsDigest,
                \hash('sha256', GatewayClient::canonicalJson($current['payload'])),
            )
        ) {
            $this->assertPublicationDeadline($deadlineMonotonic);
            $this->afterCommittedPublication($instanceId);
            return $current;
        }
        if ($floor >= PHP_INT_MAX) {
            throw new \RuntimeException('WLS serving manifest generation is exhausted.');
        }
        $generation = $floor + 1;
        $unsigned = [
            'schema' => self::SCHEMA,
            'generation' => $generation,
            'payload_sha256' => $factsDigest,
            'payload' => $payload,
        ];
        $digest = \hash('sha256', GatewayClient::canonicalJson($unsigned));
        $envelope = $unsigned + ['digest' => $digest];
        $encoded = \json_encode(
            $envelope,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if (!\is_string($encoded) || \strlen($encoded) > self::MAX_MANIFEST_BYTES) {
            throw new \RuntimeException('Unable to encode bounded WLS serving manifest.');
        }
        $path = $this->manifestPath($generation, $digest);
        if (@\lstat($path) !== false || \file_exists($path) || \is_link($path)) {
            // A crash may have completed the immutable write but not advanced
            // the generation floor/current pointer. Reuse only the exact
            // content-addressed orphan; every other pre-existing object fails.
            $verified = $this->readBound($path, $generation, $digest);
            if (!\hash_equals(
                $factsDigest,
                \hash('sha256', GatewayClient::canonicalJson($verified['payload'])),
            )) {
                throw new \RuntimeException(
                    'Existing WLS serving manifest path belongs to different facts.',
                );
            }
        } else {
            $this->assertPublicationDeadline($deadlineMonotonic);
            $this->assertManifestStoreCapacity(\strlen($encoded));
            $this->atomicWrite($path, $encoded, 0600);
            $verified = $this->readBound($path, $generation, $digest);
        }
        $this->assertPublicationDeadline($deadlineMonotonic);
        $authority = $this->authorityFromPublication($verified);
        // Authority advances before the advisory integer floor and pointer.
        // A crash at either later write remains recoverable from this exact
        // immutable manifest, while a retired launch is already fenced out.
        $this->writePublicationAuthority($instanceId, $authority);
        $this->atomicWrite(
            $this->generationFile($instanceId),
            (string)$generation . "\n",
            0600,
        );
        $this->writeRecentLkgReferences($instanceId, $current);
        $this->writeCurrentPointer($instanceId, $verified);
        $this->afterCommittedPublication($instanceId);
        return $verified;
    }

    /**
     * Collect only replaceable project-state targets. Immutable manifest
     * generations and their first-publication staging leaves are deliberately
     * outside this closure and remain governed by storedManifestInventory().
     *
     * Every official writer of these targets runs below publish.lock. Discover
     * every retained target first, then validate the complete paired-current
     * set before deleting any backup. This prevents a valid early target from
     * being collected when a later target is missing, corrupt, or unbound.
     */
    private function cleanupMutableAtomicWriteRecoveryBackupsLocked(
        string $instanceId,
    ): void {
        $targets = [
            [
                'type' => 'generation',
                'path' => $this->generationFile($instanceId),
                'maximum_bytes' => 32,
                'label' => 'WLS serving manifest generation floor',
            ],
            [
                'type' => 'authority',
                'path' => $this->publicationAuthorityFile($instanceId),
                'maximum_bytes' => 16_384,
                'label' => 'WLS serving manifest authority',
            ],
            [
                'type' => 'pointer',
                'path' => $this->currentPointerPath($instanceId),
                'maximum_bytes' => 16_384,
                'label' => 'WLS serving manifest pointer',
            ],
            [
                'type' => 'lkg',
                'path' => $this->recentLkgFile($instanceId),
                'maximum_bytes' => 32_768,
                'label' => 'WLS serving manifest LKG reference',
            ],
            [
                'type' => 'retirement',
                'path' => $this->manifestRetirementStateFile(),
                'maximum_bytes' => self::MAX_RETIREMENT_STATE_BYTES,
                'label' => 'WLS serving manifest retirement state',
            ],
        ];
        $retained = [];
        foreach ($targets as $target) {
            if (GatewayProjectStateFilesystem::hasAtomicWriteRecoveryBackups(
                (string)$target['path'],
                (int)$target['maximum_bytes'],
                (string)$target['label'],
            )) {
                $retained[] = $target;
            }
        }
        if ($retained === []) {
            return;
        }

        foreach ($retained as $target) {
            $this->validateMutableAtomicRecoveryTarget(
                (string)$target['type'],
                $instanceId,
            );
        }
        foreach ($retained as $target) {
            $type = (string)$target['type'];
            GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
                (string)$target['path'],
                (int)$target['maximum_bytes'],
                (string)$target['label'],
                function (string $_contents) use ($type, $instanceId): void {
                    $this->validateMutableAtomicRecoveryTarget(
                        $type,
                        $instanceId,
                    );
                },
            );
        }
    }

    private function validateMutableAtomicRecoveryTarget(
        string $type,
        string $instanceId,
    ): void {
        if (\hash_equals('retirement', $type)) {
            $state = $this->readManifestRetirementStateUnlocked();
            if (!isset(
                $state['updated_unix'],
                $state['updated_monotonic'],
                $state['host_boot_id'],
            )) {
                throw new \RuntimeException(
                    'WLS serving manifest retirement recovery target is corrupt.',
                );
            }
            return;
        }

        $authority = $this->readPublicationAuthority($instanceId);
        if (!\is_array($authority)) {
            throw new \RuntimeException(
                'WLS serving manifest recovery authority is missing.',
            );
        }
        $this->assertPublicationAuthorityManifestBinding(
            $authority,
            $instanceId,
        );

        if (\hash_equals('authority', $type)) {
            return;
        }
        if (\hash_equals('generation', $type)) {
            $floor = $this->readGenerationFloor($instanceId);
            if ($floor < 1 || $floor > (int)$authority['generation']) {
                throw new \RuntimeException(
                    'WLS serving manifest recovery generation floor is unbound.',
                );
            }
            return;
        }
        if (\hash_equals('pointer', $type)) {
            $current = $this->current($instanceId);
            $this->assertPublicationBelongsToInstance(
                $current,
                $instanceId,
                (string)$authority['project_uuid'],
            );
            $this->assertAuthorityCoversCurrent(
                $authority,
                $this->authorityFromPublication($current),
            );
            return;
        }
        if (\hash_equals('lkg', $type)) {
            foreach ($this->readRecentLkgReferences(
                $this->recentLkgFile($instanceId),
                $instanceId,
            ) as $reference) {
                $publication = $this->readBound(
                    (string)$reference['path'],
                    (int)$reference['generation'],
                    (string)$reference['digest'],
                );
                $this->assertPublicationBelongsToInstance(
                    $publication,
                    $instanceId,
                    (string)$authority['project_uuid'],
                );
                if ((int)$publication['generation'] >= (int)$authority['generation']) {
                    throw new \RuntimeException(
                        'WLS serving manifest recovery LKG generation is unbound.',
                    );
                }
            }
            return;
        }
        throw new \LogicException('Unknown WLS serving manifest recovery target.');
    }

    /** @param array<string,mixed> $authority */
    private function assertPublicationAuthorityManifestBinding(
        array $authority,
        string $instanceId,
    ): void {
        $publication = $this->readBound(
            $this->manifestPath(
                (int)$authority['generation'],
                (string)$authority['manifest_digest'],
            ),
            (int)$authority['generation'],
            (string)$authority['manifest_digest'],
        );
        $this->assertPublicationBelongsToInstance($publication, $instanceId);
        if (!\hash_equals(
            GatewayClient::canonicalJson($authority),
            GatewayClient::canonicalJson(
                $this->authorityFromPublication($publication),
            ),
        )) {
            throw new \RuntimeException(
                'WLS serving manifest recovery authority is unbound.',
            );
        }
    }

    /** @param array<string,mixed> $publication */
    private function assertPublicationBelongsToInstance(
        array $publication,
        string $instanceId,
        ?string $projectUuid = null,
    ): void {
        $payload = \is_array($publication['payload'] ?? null)
            ? $publication['payload']
            : [];
        if (!\hash_equals($instanceId, (string)($payload['instance_id'] ?? ''))
            || ($projectUuid !== null
                && !\hash_equals(
                    $projectUuid,
                    (string)($payload['project_uuid'] ?? ''),
                ))
        ) {
            throw new \RuntimeException(
                'WLS serving manifest recovery target belongs to another instance.',
            );
        }
    }

    /**
     * @param array<string,mixed> $publication
     * @return array<string,mixed>
     */
    private function authorityFromPublication(array $publication): array
    {
        $payload = \is_array($publication['payload'] ?? null)
            ? $publication['payload']
            : [];
        $authority = [
            'schema' => self::AUTHORITY_SCHEMA,
            'generation' => (int)($publication['generation'] ?? 0),
            'manifest_digest' => (string)($publication['digest'] ?? ''),
            'payload_sha256' => \hash(
                'sha256',
                GatewayClient::canonicalJson($payload),
            ),
            'project_uuid' => (string)($payload['project_uuid'] ?? ''),
            'instance_id' => (string)($payload['instance_id'] ?? ''),
            'instance_generation' => (int)($payload['instance_generation'] ?? 0),
            'master_pid' => (int)($payload['master_pid'] ?? 0),
            'master_epoch' => (int)($payload['master_epoch'] ?? 0),
            'launch_id' => (string)($payload['launch_id'] ?? ''),
            'project_generation' => (int)($payload['project_generation'] ?? 0),
            'request_digest' => (string)($payload['request_digest'] ?? ''),
            'non_certificate_desired_digest' => (string)(
                $payload['non_certificate_desired_digest'] ?? ''
            ),
        ];
        $this->assertPublicationAuthority($authority);
        return $authority;
    }

    /** @param array<string,mixed> $authority @param array<string,mixed> $current */
    private function assertAuthorityCoversCurrent(array $authority, array $current): void
    {
        $this->assertMonotonicPublicationFence($authority, $current);
        $authorityGeneration = (int)$authority['generation'];
        $currentGeneration = (int)$current['generation'];
        if ($authorityGeneration < $currentGeneration
            || ($authorityGeneration === $currentGeneration
                && (!\hash_equals(
                    (string)$authority['manifest_digest'],
                    (string)$current['manifest_digest'],
                ) || !\hash_equals(
                    (string)$authority['payload_sha256'],
                    (string)$current['payload_sha256'],
                )))
        ) {
            throw new \RuntimeException(
                'WLS serving manifest authority conflicts with its current pointer.',
            );
        }
    }

    /** @return array<string,mixed>|null */
    private function readPublicationAuthority(string $instanceId): ?array
    {
        $path = $this->publicationAuthorityFile($instanceId);
        $status = @\lstat($path);
        if (!\is_array($status)) {
            if (\file_exists($path) || \is_link($path)) {
                throw new \RuntimeException('WLS serving manifest authority is unsafe.');
            }
            return null;
        }
        $this->assertPrivateStateFile($path, 'WLS serving manifest authority');
        $encoded = GatewayProjectStateFilesystem::read(
            $path,
            16_384,
            'WLS serving manifest authority',
        );
        $authority = \json_decode($encoded, true);
        $unsigned = \is_array($authority) ? $authority : [];
        $sha256 = \strtolower(\trim((string)($unsigned['sha256'] ?? '')));
        unset($unsigned['sha256']);
        if (!\is_array($authority)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1
            || !\hash_equals(
                $sha256,
                \hash('sha256', GatewayClient::canonicalJson($unsigned)),
            )
        ) {
            throw new \RuntimeException('WLS serving manifest authority integrity failed.');
        }
        $this->assertPublicationAuthority($unsigned);
        if (!\hash_equals($instanceId, (string)$unsigned['instance_id'])) {
            throw new \RuntimeException(
                'WLS serving manifest authority belongs to another instance.',
            );
        }
        return $unsigned;
    }

    /** @param array<string,mixed> $authority */
    private function writePublicationAuthority(string $instanceId, array $authority): void
    {
        $this->assertPublicationAuthority($authority);
        if (!\hash_equals($instanceId, (string)$authority['instance_id'])) {
            throw new \RuntimeException(
                'WLS serving manifest authority publication targets another instance.',
            );
        }
        $envelope = $authority;
        $envelope['sha256'] = \hash(
            'sha256',
            GatewayClient::canonicalJson($authority),
        );
        $encoded = \json_encode(
            $envelope,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if (!\is_string($encoded) || \strlen($encoded) > 16_384) {
            throw new \RuntimeException('Unable to encode WLS serving manifest authority.');
        }
        $this->atomicWrite($this->publicationAuthorityFile($instanceId), $encoded, 0600);
    }

    /** @param array<string,mixed> $authority */
    private function assertPublicationAuthority(array $authority): void
    {
        if (!\hash_equals(self::AUTHORITY_SCHEMA, (string)($authority['schema'] ?? ''))
            || (int)($authority['generation'] ?? 0) < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                $authority['manifest_digest'] ?? ''
            )) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                $authority['payload_sha256'] ?? ''
            )) !== 1
            || \preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                (string)($authority['project_uuid'] ?? ''),
            ) !== 1
            || \preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', (string)(
                $authority['instance_id'] ?? ''
            )) !== 1
            || (int)($authority['instance_generation'] ?? 0) < 1
            || (int)($authority['master_pid'] ?? 0) < 1
            || (int)($authority['master_epoch'] ?? 0) < 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)(
                $authority['launch_id'] ?? ''
            )) !== 1
            || (int)($authority['project_generation'] ?? 0) < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                $authority['request_digest'] ?? ''
            )) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                $authority['non_certificate_desired_digest'] ?? ''
            )) !== 1
        ) {
            throw new \RuntimeException('WLS serving manifest authority is invalid.');
        }
    }

    /** @param array<string,mixed> $publication */
    private function writeCurrentPointer(string $instanceId, array $publication): void
    {
        $generation = (int)($publication['generation'] ?? 0);
        $digest = (string)($publication['digest'] ?? '');
        $path = (string)($publication['path'] ?? '');
        if ($generation < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
            || !$this->canonicalManifestPathMatches($path, $generation, $digest)
        ) {
            throw new \RuntimeException('WLS serving manifest pointer publication is invalid.');
        }
        $pointer = [
            'schema' => self::POINTER_SCHEMA,
            'generation' => $generation,
            'digest' => $digest,
            'path' => $path,
        ];
        $pointer['sha256'] = \hash('sha256', GatewayClient::canonicalJson($pointer));
        $encoded = \json_encode(
            $pointer,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if (!\is_string($encoded)) {
            throw new \RuntimeException('Unable to encode WLS serving manifest pointer.');
        }
        $this->atomicWrite($this->currentPointerPath($instanceId), $encoded, 0600);
    }

    /** @param array<string,mixed>|null $previousCurrent */
    private function writeRecentLkgReferences(
        string $instanceId,
        ?array $previousCurrent,
    ): void {
        $path = $this->recentLkgFile($instanceId);
        $references = $this->readRecentLkgReferences($path, $instanceId, true);
        if (\is_array($previousCurrent)) {
            $generation = (int)($previousCurrent['generation'] ?? 0);
            $digest = (string)($previousCurrent['digest'] ?? '');
            $manifestPath = (string)($previousCurrent['path'] ?? '');
            if ($generation < 1
                || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
                || !$this->canonicalManifestPathMatches(
                    $manifestPath,
                    $generation,
                    $digest,
                )
            ) {
                throw new \RuntimeException(
                    'WLS serving manifest LKG publication is invalid.',
                );
            }
            \array_unshift($references, [
                'generation' => $generation,
                'digest' => $digest,
                'path' => $manifestPath,
            ]);
        }
        $unique = [];
        $bounded = [];
        foreach ($references as $reference) {
            $key = $this->pathKey((string)$reference['path']);
            if (isset($unique[$key])) {
                continue;
            }
            $unique[$key] = true;
            $bounded[] = $reference;
            if (\count($bounded) === 2) {
                break;
            }
        }
        if ($bounded === []) {
            return;
        }
        $envelope = [
            'schema' => self::LKG_SCHEMA,
            'instance_id' => $instanceId,
            'manifests' => $bounded,
        ];
        $envelope['sha256'] = \hash(
            'sha256',
            GatewayClient::canonicalJson($envelope),
        );
        $encoded = \json_encode(
            $envelope,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if (!\is_string($encoded) || \strlen($encoded) > 32_768) {
            throw new \RuntimeException('Unable to encode WLS serving manifest LKG references.');
        }
        $this->atomicWrite($path, $encoded, 0600);
    }

    /**
     * The durable authority fence is independent from the replaceable current
     * pointer. A publisher that read an endpoint before a restart must never
     * move authority back to the retired launch after the new endpoint has
     * published, even if pointer replacement was interrupted. This comparison
     * runs under publish.lock before any idempotent return or immutable write.
     *
     * @param array<string,mixed> $incoming
     * @param array<string,mixed> $current
     */
    private function assertMonotonicPublicationFence(
        array $incoming,
        array $current,
    ): void {
        $incomingInstanceGeneration = (int)($incoming['instance_generation'] ?? 0);
        $currentInstanceGeneration = (int)($current['instance_generation'] ?? 0);
        if ($incomingInstanceGeneration < $currentInstanceGeneration) {
            throw new \RuntimeException(
                'WLS serving manifest cannot publish a stale instance generation.',
            );
        }
        if ($incomingInstanceGeneration === $currentInstanceGeneration) {
            $sameMasterLaunch = (int)($incoming['master_pid'] ?? 0)
                    === (int)($current['master_pid'] ?? -1)
                && \hash_equals(
                    (string)($current['launch_id'] ?? ''),
                    (string)($incoming['launch_id'] ?? ''),
                );
            $incomingMasterEpoch = (int)($incoming['master_epoch'] ?? 0);
            $currentMasterEpoch = (int)($current['master_epoch'] ?? 0);
            // One live Master may advance its infrastructure epoch during an
            // in-process full restart. The manifest must advance with it while
            // retaining the exact PID+launch identity; a regressing epoch or a
            // different launch under the same project instance generation is
            // always stale/foreign.
            if (!$sameMasterLaunch || $incomingMasterEpoch < $currentMasterEpoch) {
                throw new \RuntimeException(
                    'WLS serving manifest instance generation belongs to another or stale Master launch.',
                );
            }
        }

        $incomingProjectGeneration = (int)($incoming['project_generation'] ?? 0);
        $currentProjectGeneration = (int)($current['project_generation'] ?? 0);
        if ($incomingProjectGeneration < $currentProjectGeneration) {
            throw new \RuntimeException(
                'WLS serving manifest cannot publish a stale project generation.',
            );
        }
        if ($incomingProjectGeneration === $currentProjectGeneration
            && (!\hash_equals(
                (string)($current['request_digest'] ?? ''),
                (string)($incoming['request_digest'] ?? ''),
            ) || !\hash_equals(
                (string)($current['non_certificate_desired_digest'] ?? ''),
                (string)($incoming['non_certificate_desired_digest'] ?? ''),
            ))
        ) {
            throw new \RuntimeException(
                'WLS serving manifest project generation has conflicting desired-state digests.',
            );
        }
    }

    /** @return array<string,mixed> */
    private function readPointer(string $instanceId): array
    {
        $this->assertPrivateStateFile(
            $this->currentPointerPath($instanceId),
            'WLS serving manifest pointer',
        );
        $encoded = GatewayProjectStateFilesystem::read(
            $this->currentPointerPath($instanceId),
            16_384,
            'WLS serving manifest pointer',
        );
        $pointer = \json_decode($encoded, true);
        $unsigned = \is_array($pointer) ? $pointer : [];
        $digest = \strtolower(\trim((string)($unsigned['sha256'] ?? '')));
        unset($unsigned['sha256']);
        if (!\is_array($pointer)
            || !\hash_equals(self::POINTER_SCHEMA, (string)($pointer['schema'] ?? ''))
            || (int)($pointer['generation'] ?? 0) < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)($pointer['digest'] ?? '')) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
            || !\hash_equals($digest, \hash('sha256', GatewayClient::canonicalJson($unsigned)))
            || !$this->canonicalManifestPathMatches(
                (string)($pointer['path'] ?? ''),
                (int)$pointer['generation'],
                (string)$pointer['digest'],
            )
        ) {
            throw new \RuntimeException('WLS serving manifest pointer integrity failed.');
        }
        return $pointer;
    }

    private function readGenerationFloor(string $instanceId): int
    {
        $path = $this->generationFile($instanceId);
        $status = @\lstat($path);
        if (!\is_array($status)) {
            if (\file_exists($path) || \is_link($path)) {
                throw new \RuntimeException(
                    'WLS serving manifest generation floor is unsafe.',
                );
            }
            return 0;
        }
        $this->assertPrivateStateFile(
            $path,
            'WLS serving manifest generation floor',
        );
        $raw = GatewayProjectStateFilesystem::readOptional(
            $path,
            32,
            'WLS serving manifest generation floor',
        );
        if ($raw === null) {
            throw new \RuntimeException('WLS serving manifest generation floor disappeared.');
        }
        $raw = \trim($raw);
        if (\preg_match('/\A[1-9][0-9]{0,18}\z/D', $raw) !== 1) {
            throw new \RuntimeException('WLS serving manifest generation floor is corrupt.');
        }
        $generation = (int)$raw;
        if ($generation < 1 || (string)$generation !== $raw) {
            throw new \RuntimeException('WLS serving manifest generation floor is out of range.');
        }
        return $generation;
    }

    /** @param array<string,mixed> $payload */
    private function assertPayload(
        array $payload,
        bool $requireLifecycleFacts,
    ): void
    {
        $routes = $payload['routes'] ?? null;
        $desiredRoutes = $payload['desired_routes'] ?? null;
        if (!\is_array($routes)
            || !\array_is_list($routes)
            || \count($routes) > self::MAX_ROUTES
            || !\is_bool($payload['converged'] ?? null)
            || (int)($payload['desired_route_count'] ?? -1) < 1
            || (int)($payload['desired_route_count'] ?? -1) < \count($routes)
            || (int)($payload['desired_route_count'] ?? -1) > self::MAX_ROUTES
            || ($requireLifecycleFacts
                && (!\is_array($desiredRoutes)
                    || !\array_is_list($desiredRoutes)
                    || \count($desiredRoutes)
                        !== (int)($payload['desired_route_count'] ?? -1)))
        ) {
            throw new \RuntimeException('WLS serving manifest route envelope is invalid.');
        }
        foreach ([
            'request_digest',
            'non_certificate_desired_digest',
        ] as $field) {
            if (\preg_match('/\A[a-f0-9]{64}\z/D', (string)($payload[$field] ?? '')) !== 1) {
                throw new \RuntimeException('WLS serving manifest desired-state digest is invalid.');
            }
        }
        if (\preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
            (string)($payload['project_uuid'] ?? ''),
        ) !== 1
            || \preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', (string)(
            $payload['instance_id'] ?? ''
        )) !== 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)($payload['launch_id'] ?? '')) !== 1
            || (int)($payload['instance_generation'] ?? 0) < 1
            || (int)($payload['master_pid'] ?? 0) < 1
            || (int)($payload['master_epoch'] ?? 0) < 1
            || (int)($payload['project_generation'] ?? 0) < 1
            || !$this->samePath((string)($payload['project_root'] ?? ''), $this->projectRoot)
        ) {
            throw new \RuntimeException('WLS serving manifest launch fence is invalid.');
        }
        $desiredFacts = [];
        if ($requireLifecycleFacts) {
            $previousDesiredRouteId = '';
            foreach ($desiredRoutes as $desiredRoute) {
                if (!\is_array($desiredRoute)) {
                    throw new \RuntimeException(
                        'WLS serving manifest desired route lifecycle fact is malformed.',
                    );
                }
                $routeId = (string)($desiredRoute['route_id'] ?? '');
                $domain = self::normalizeHost((string)(
                    $desiredRoute['domain'] ?? ''
                ));
                $expectedRouteId = \substr(\hash(
                    'sha256',
                    (string)$payload['project_uuid'] . "\0" . $domain,
                ), 0, 32);
                $state = \strtolower(\trim((string)(
                    $desiredRoute['certificate_state'] ?? ''
                )));
                $generation = (int)(
                    $desiredRoute['certificate_generation'] ?? -1
                );
                $sourceDigest = \strtolower(\trim((string)(
                    $desiredRoute['certificate_source_digest'] ?? ''
                )));
                $forceHttps = $desiredRoute['force_https'] ?? null;
                $forceRootToWww = $desiredRoute['force_root_to_www'] ?? null;
                $rootTarget = (string)($desiredRoute['root_to_www_target'] ?? '');
                $rootTargetReady = $desiredRoute['root_to_www_target_ready'] ?? null;
                $lifecycleValid = $state === 'active'
                    ? $generation >= 1
                    : ($state === 'pending'
                        ? $generation === 0
                            && !\str_starts_with($domain, '*.')
                            && \hash_equals(
                                \hash(
                                    'sha256',
                                    "wls-pending-certificate\0" . $domain,
                                ),
                                $sourceDigest,
                            )
                        : ($state === 'disabled'
                            && $generation >= 1
                            && !\str_starts_with($domain, '*.')
                            && \hash_equals(
                                \hash(
                                    'sha256',
                                    "wls-disabled-certificate\0" . $domain . "\0"
                                        . $generation,
                                ),
                                $sourceDigest,
                            )));
                if (\preg_match('/\A[a-f0-9]{32}\z/D', $routeId) !== 1
                    || !\hash_equals($expectedRouteId, $routeId)
                    || ($previousDesiredRouteId !== ''
                        && $routeId <= $previousDesiredRouteId)
                    || isset($desiredFacts[$routeId])
                    || \preg_match('/\A[a-f0-9]{64}\z/D', $sourceDigest) !== 1
                    || !$lifecycleValid
                    || !\is_bool($forceHttps)
                    || !\is_bool($forceRootToWww)
                    || !\is_bool($rootTargetReady)
                    || ($state === 'disabled'
                        && ($forceHttps !== false || $forceRootToWww !== false))
                    || ($forceRootToWww
                        && (\str_starts_with($domain, '*.')
                            || !\hash_equals('www.' . $domain, $rootTarget)))
                    || (!$forceRootToWww
                        && ($rootTarget !== '' || $rootTargetReady !== true))
                ) {
                    throw new \RuntimeException(
                        'WLS serving manifest desired route lifecycle fact is invalid.',
                    );
                }
                $desiredFacts[$routeId] = [
                    'domain' => $domain,
                    'state' => $state,
                    'generation' => $generation,
                    'source_digest' => $sourceDigest,
                    'force_https' => $forceHttps,
                    'force_root_to_www' => $forceRootToWww,
                    'root_to_www_target' => $rootTarget,
                    'root_to_www_target_ready' => $rootTargetReady,
                ];
                $previousDesiredRouteId = $routeId;
            }
            foreach ($desiredFacts as $desiredFact) {
                if (($desiredFact['force_root_to_www'] ?? false) !== true) {
                    continue;
                }
                $targetRouteId = \substr(\hash(
                    'sha256',
                    (string)$payload['project_uuid'] . "\0"
                        . (string)$desiredFact['root_to_www_target'],
                ), 0, 32);
                if (!isset($desiredFacts[$targetRouteId])
                    || ($desiredFact['root_to_www_target_ready'] ?? false) !== true
                ) {
                    throw new \RuntimeException(
                        'WLS serving manifest desired HTTP redirect readiness is inconsistent.',
                    );
                }
            }
        }
        $domains = [];
        $ids = [];
        $previousRouteId = '';
        foreach ($routes as $route) {
            if (!\is_array($route)) {
                throw new \RuntimeException('WLS serving manifest route is malformed.');
            }
            $routeId = (string)($route['route_id'] ?? '');
            $domain = self::normalizeHost((string)($route['domain'] ?? ''));
            $expectedRouteId = \substr(\hash(
                'sha256',
                (string)$payload['project_uuid'] . "\0" . $domain,
            ), 0, 32);
            $policy = \is_array($route['policy'] ?? null) ? $route['policy'] : [];
            $sourceDigest = (string)($route['certificate_source_digest'] ?? '');
            $desiredFact = $desiredFacts[$routeId] ?? null;
            if (\preg_match('/\A[a-f0-9]{32}\z/D', $routeId) !== 1
                || !\hash_equals($expectedRouteId, $routeId)
                || ($previousRouteId !== '' && $routeId <= $previousRouteId)
                || isset($ids[$routeId])
                || isset($domains[$domain])
                || (int)($route['route_generation'] ?? -1) < 0
                || (int)($route['certificate_generation'] ?? 0) < 1
                || \preg_match('/\A[a-f0-9]{64}\z/D', $sourceDigest) !== 1
                || ($requireLifecycleFacts
                    && (!\is_array($desiredFact)
                        || !\hash_equals('active', (string)$desiredFact['state'])
                        || !\hash_equals($domain, (string)$desiredFact['domain'])
                        || (int)$desiredFact['generation']
                            !== (int)($route['certificate_generation'] ?? 0)
                        || !\hash_equals(
                            (string)$desiredFact['source_digest'],
                            $sourceDigest,
                        )))
                || !\is_bool($policy['force_https'] ?? null)
                || !\is_bool($policy['force_root_to_www'] ?? null)
                || !\is_bool($policy['root_to_www_target_ready'] ?? null)
                || (($policy['force_root_to_www'] ?? false) === true
                    && \str_starts_with($domain, '*.'))
                || (($policy['force_root_to_www'] ?? false) === true
                    && !\hash_equals('www.' . $domain, (string)($policy['root_to_www_target'] ?? '')))
                || (($policy['force_root_to_www'] ?? false) === false
                    && (string)($policy['root_to_www_target'] ?? '') !== '')
                || (($policy['force_root_to_www'] ?? false) === false
                    && ($policy['root_to_www_target_ready'] ?? false) !== true)
            ) {
                throw new \RuntimeException('WLS serving manifest route identity or policy is invalid.');
            }
            $certificateFact = \is_array($route['certificate'] ?? null)
                ? $route['certificate']
                : [];
            $privateKeyFact = \is_array($route['private_key'] ?? null)
                ? $route['private_key']
                : [];
            $snapshotFact = \is_array($route['certificate_snapshot'] ?? null)
                ? $route['certificate_snapshot']
                : [];
            $snapshot = $this->verifiedCertificateSnapshot(
                $sourceDigest,
                (string)($certificateFact['path'] ?? ''),
                (string)($privateKeyFact['path'] ?? ''),
            );
            $this->assertSameFileFact(
                $certificateFact,
                (array)$snapshot['certificate'],
                'certificate',
            );
            $this->assertSameFileFact(
                $privateKeyFact,
                (array)$snapshot['private_key'],
                'private key',
            );
            $storedChain = $route['certificate_chain'] ?? null;
            if ($snapshot['chain'] === null) {
                if ($storedChain !== null) {
                    throw new \RuntimeException(
                        'WLS serving manifest contains an unexpected certificate chain.',
                    );
                }
            } elseif (!\is_array($storedChain)) {
                throw new \RuntimeException(
                    'WLS serving manifest certificate chain fact is missing.',
                );
            } else {
                $this->assertSameFileFact(
                    $storedChain,
                    (array)$snapshot['chain'],
                    'certificate chain',
                );
            }
            $this->assertSameFileFact(
                \is_array($snapshotFact['manifest'] ?? null)
                    ? $snapshotFact['manifest']
                    : [],
                (array)$snapshot['manifest'],
                'certificate snapshot manifest',
            );
            $leafFingerprint = \strtolower(\trim((string)(
                $snapshotFact['leaf_fingerprint_sha256'] ?? ''
            )));
            if (\preg_match('/\A[a-f0-9]{64}\z/D', $leafFingerprint) !== 1
                || !\hash_equals(
                    (string)$snapshot['leaf_fingerprint_sha256'],
                    $leafFingerprint,
                )
            ) {
                throw new \RuntimeException(
                    'WLS serving manifest certificate snapshot fingerprint changed.',
                );
            }
            $ids[$routeId] = true;
            $domains[$domain] = true;
            $previousRouteId = $routeId;
        }
        foreach ($routes as $route) {
            $policy = (array)$route['policy'];
            if (($policy['force_root_to_www'] ?? false) !== true) {
                continue;
            }
            $targetPresent = isset($domains[(string)$policy['root_to_www_target']]);
            if (($policy['root_to_www_target_ready'] ?? null) !== $targetPresent) {
                throw new \RuntimeException(
                    'WLS serving manifest redirect target readiness is inconsistent.',
                );
            }
        }
        if (($payload['converged'] ?? false) === true
            && \count($routes) !== (int)$payload['desired_route_count']
        ) {
            throw new \RuntimeException('Partial WLS serving manifest is incorrectly converged.');
        }
    }

    private function assertManifestStoreCapacity(int $prospectiveBytes): void
    {
        if ($prospectiveBytes < 1 || $prospectiveBytes > self::MAX_MANIFEST_BYTES) {
            throw new \RuntimeException('WLS serving manifest size is outside its quota.');
        }
        $entries = $this->storedManifestInventory();
        $count = \count($entries);
        $bytes = \array_sum(\array_column($entries, 'size'));
        if ($count < self::MAX_STORED_MANIFESTS
            && $bytes + $prospectiveBytes <= self::MAX_STORED_MANIFEST_BYTES
        ) {
            return;
        }

        $collectable = $this->collectableManifestRetirementEntries(
            $entries,
            $this->referencedManifestPathKeys(),
        );
        $removedDigests = [];
        foreach ($collectable as $entry) {
            GatewayProjectStateFilesystem::removeRegular(
                (string)$entry['path'],
                'expired unreferenced WLS serving manifest',
            );
            $removedDigests[] = (string)$entry['digest'];
            --$count;
            $bytes -= (int)$entry['size'];
            if ($count < self::MAX_STORED_MANIFESTS
                && $bytes + $prospectiveBytes
                    <= self::MAX_STORED_MANIFEST_BYTES
            ) {
                break;
            }
        }
        $this->forgetManifestRetirementDigests($removedDigests);
        if ($count >= self::MAX_STORED_MANIFESTS
            || $bytes + $prospectiveBytes > self::MAX_STORED_MANIFEST_BYTES
        ) {
            throw new \RuntimeException(
                'WLS serving manifest store has no capacity for another generation.',
            );
        }
    }

    /**
     * @return list<array{path:string,size:int,mtime:int,generation:int,digest:string}>
     */
    private function storedManifestInventory(): array
    {
        $handle = @\opendir($this->manifestRoot);
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to enumerate the WLS serving manifest store.');
        }
        $rawCount = 0;
        $manifestCount = 0;
        $entries = [];
        $digests = [];
        $orphanedCandidates = [];
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                if (++$rawCount > self::MAX_RAW_MANIFEST_DIRECTORY_ENTRIES) {
                    throw new \RuntimeException(
                        'WLS serving manifest store exceeds its fixed raw entry limit.',
                    );
                }
                $path = $this->manifestRoot . DIRECTORY_SEPARATOR . $leaf;
                // Every caller reaches this inventory while holding the
                // project-wide publish.lock. The same lock encloses immutable
                // atomicWrite publication, so an exact staging leaf observed
                // here belongs to a crashed writer, never a live instance.
                if (\preg_match(
                    '/\A[1-9][0-9]{0,18}-[a-f0-9]{64}'
                        . '\.json\.tmp-[a-f0-9]{24}\z/D',
                    $leaf,
                ) === 1) {
                    if (\count($orphanedCandidates)
                        >= self::MAX_ORPHANED_MANIFEST_CANDIDATES
                    ) {
                        throw new \RuntimeException(
                            'WLS serving manifest store contains too many orphaned candidates.',
                        );
                    }
                    $status = @\lstat($path);
                    if (!\is_array($status)
                        || \is_link($path)
                        || (((int)$status['mode'] & 0170000) !== 0100000)
                        || (int)$status['nlink'] !== 1
                    ) {
                        throw new \RuntimeException(
                            'WLS serving manifest store contains an unsafe orphaned candidate.',
                        );
                    }
                    $orphanedCandidates[] = [
                        'path' => $path,
                        'identity' => $status,
                    ];
                    continue;
                }
                if (++$manifestCount > self::MAX_STORED_MANIFESTS
                    || \preg_match(
                        '/\A([1-9][0-9]{0,18})-([a-f0-9]{64})\.json\z/D',
                        $leaf,
                        $matches,
                    ) !== 1
                    || isset($digests[(string)($matches[2] ?? '')])
                ) {
                    throw new \RuntimeException(
                        'WLS serving manifest store entry quota or identity is invalid.',
                    );
                }
                $status = @\lstat($path);
                if (!\is_array($status)
                    || \is_link($path)
                    || ((int)($status['mode'] ?? 0) & 0170000) !== 0100000
                    || (int)($status['nlink'] ?? 0) !== 1
                    || (int)($status['size'] ?? -1) < 1
                    || (int)$status['size'] > self::MAX_MANIFEST_BYTES
                    || (\PHP_OS_FAMILY !== 'Windows'
                        && (((int)$status['mode'] & 0777) !== 0600
                            || ($this->projectOwner >= 0
                                && (int)($status['uid'] ?? -1)
                                    !== $this->projectOwner)))
                ) {
                    throw new \RuntimeException(
                        'WLS serving manifest store contains an unsafe entry.',
                    );
                }
                $digest = (string)$matches[2];
                $digests[$digest] = true;
                $entries[] = [
                    'path' => $path,
                    'size' => (int)$status['size'],
                    'mtime' => (int)($status['mtime'] ?? 0),
                    'generation' => (int)$matches[1],
                    'digest' => $digest,
                ];
            }
        } finally {
            @\closedir($handle);
        }
        foreach ($orphanedCandidates as $candidate) {
            GatewayProjectStateFilesystem::removeRegular(
                (string)$candidate['path'],
                'orphaned WLS serving manifest candidate',
                (array)$candidate['identity'],
            );
        }
        return $entries;
    }

    /**
     * @param list<array{path:string,size:int,mtime:int,generation:int,digest:string}> $entries
     * @param array<string,true> $referencedPathKeys
     * @return list<array{path:string,size:int,mtime:int,generation:int,digest:string}>
     */
    private function collectableManifestRetirementEntries(
        array $entries,
        array $referencedPathKeys,
        ?float $deadlineMonotonic = null,
    ): array {
        $this->assertPublicationDeadline($deadlineMonotonic);
        $this->ensureStoreDirectories();
        if (\count($entries) > self::MAX_STORED_MANIFESTS) {
            throw new \RuntimeException(
                'WLS serving manifest retirement inventory exceeds its bound.',
            );
        }
        $inventory = [];
        foreach ($entries as $entry) {
            $digest = \strtolower(\trim((string)($entry['digest'] ?? '')));
            $generation = (int)($entry['generation'] ?? 0);
            $path = (string)($entry['path'] ?? '');
            if ($generation < 1
                || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
                || isset($inventory[$digest])
                || $path === ''
                || \str_contains($path, "\0")
                || !\hash_equals(
                    $generation . '-' . $digest . '.json',
                    \basename($path),
                )
                || (int)($entry['size'] ?? 0) < 1
                || (int)$entry['size'] > self::MAX_MANIFEST_BYTES
            ) {
                throw new \RuntimeException(
                    'WLS serving manifest retirement inventory is malformed.',
                );
            }
            $inventory[$digest] = $entry;
        }

        $clock = $this->manifestRetirementClock();
        $state = $this->readManifestRetirementStateUnlocked();
        $markers = $this->manifestRetirementMarkersForClock($state, $clock);
        foreach (\array_keys($markers) as $digest) {
            $entry = $inventory[$digest] ?? null;
            if (!\is_array($entry)
                || isset($referencedPathKeys[$this->pathKey((string)$entry['path'])])
            ) {
                unset($markers[$digest]);
            }
        }

        $collectable = [];
        foreach ($inventory as $digest => $entry) {
            if (isset($referencedPathKeys[$this->pathKey((string)$entry['path'])])) {
                unset($markers[$digest]);
                continue;
            }
            $marker = \is_array($markers[$digest] ?? null)
                ? $markers[$digest]
                : null;
            $age = $marker === null
                ? null
                : $this->manifestRetirementMarkerAge($marker, $digest, $clock);
            if ($age === null) {
                // Immutable-file mtime is never deletion authority. Missing,
                // damaged, cross-boot or future state starts a complete new
                // current-boot grace window.
                $markers[$digest] = $this->newManifestRetirementMarker(
                    $digest,
                    $clock,
                );
                continue;
            }
            if ($age >= self::MANIFEST_RETENTION_SECONDS) {
                $collectable[] = $entry + [
                    'retired_monotonic' => (float)(
                        $marker['unreferenced_since_monotonic'] ?? 0.0
                    ),
                ];
            }
        }
        \ksort($markers, SORT_STRING);
        $this->assertPublicationDeadline($deadlineMonotonic);
        $this->publishManifestRetirementStateUnlocked($markers, $clock);
        \usort($collectable, static function (array $left, array $right): int {
            $order = (float)($left['retired_monotonic'] ?? 0.0)
                <=> (float)($right['retired_monotonic'] ?? 0.0);
            return $order !== 0
                ? $order
                : (string)$left['path'] <=> (string)$right['path'];
        });
        return $collectable;
    }

    /**
     * @return array{wall_unix:int,monotonic:float,host_boot_id:string}
     */
    private function manifestRetirementClock(): array
    {
        $wall = $this->publicationWallClock !== null
            ? ($this->publicationWallClock)()
            : \time();
        $bootIdentity = $this->publicationBootIdentityResolver !== null
            ? ($this->publicationBootIdentityResolver)()
            : GatewayHostBootIdentity::current();
        if (!\is_int($wall) || $wall < 1 || !\is_string($bootIdentity)) {
            throw new \RuntimeException(
                'WLS serving manifest retirement clock is invalid.',
            );
        }
        return [
            'wall_unix' => $wall,
            'monotonic' => $this->publicationMonotonicNow(),
            'host_boot_id' => GatewayHostBootIdentity::validate($bootIdentity),
        ];
    }

    /**
     * @param array{wall_unix:int,monotonic:float,host_boot_id:string} $clock
     * @return array<string,mixed>
     */
    private function newManifestRetirementMarker(string $digest, array $clock): array
    {
        return [
            'schema' => self::MANIFEST_RETIREMENT_MARKER_SCHEMA,
            'manifest_digest' => $digest,
            'host_boot_id' => $clock['host_boot_id'],
            'unreferenced_since_unix' => $clock['wall_unix'],
            'unreferenced_since_monotonic' => $clock['monotonic'],
        ];
    }

    /**
     * @param array<string,mixed> $marker
     * @param array{wall_unix:int,monotonic:float,host_boot_id:string} $clock
     */
    private function manifestRetirementMarkerAge(
        array $marker,
        string $digest,
        array $clock,
    ): ?float {
        $sinceWall = $marker['unreferenced_since_unix'] ?? null;
        $sinceMonotonic = $marker['unreferenced_since_monotonic'] ?? null;
        if (!\hash_equals(
            self::MANIFEST_RETIREMENT_MARKER_SCHEMA,
            (string)($marker['schema'] ?? ''),
        )
            || !\hash_equals($digest, (string)($marker['manifest_digest'] ?? ''))
            || !\hash_equals(
                $clock['host_boot_id'],
                (string)($marker['host_boot_id'] ?? ''),
            )
            || !\is_int($sinceWall)
            || $sinceWall < 1
            || $sinceWall > $clock['wall_unix']
            || (!\is_int($sinceMonotonic) && !\is_float($sinceMonotonic))
            || !\is_finite((float)$sinceMonotonic)
            || (float)$sinceMonotonic < 0.0
            || (float)$sinceMonotonic > $clock['monotonic']
        ) {
            return null;
        }
        $age = $clock['monotonic'] - (float)$sinceMonotonic;
        return \is_finite($age) && $age >= 0.0 ? $age : null;
    }

    /**
     * @param array<string,mixed> $state
     * @param array{wall_unix:int,monotonic:float,host_boot_id:string} $clock
     * @return array<string,array<string,mixed>>
     */
    private function manifestRetirementMarkersForClock(
        array $state,
        array $clock,
    ): array {
        $updatedWall = $state['updated_unix'] ?? null;
        $updatedMonotonic = $state['updated_monotonic'] ?? null;
        if (!\is_int($updatedWall)
            || $updatedWall < 1
            || $updatedWall > $clock['wall_unix']
            || (!\is_int($updatedMonotonic) && !\is_float($updatedMonotonic))
            || !\is_finite((float)$updatedMonotonic)
            || (float)$updatedMonotonic < 0.0
            || (float)$updatedMonotonic > $clock['monotonic']
            || !\hash_equals(
                $clock['host_boot_id'],
                (string)($state['host_boot_id'] ?? ''),
            )
            || !\is_array($state['markers'] ?? null)
        ) {
            return [];
        }
        return $state['markers'];
    }

    /** @return array<string,mixed> */
    private function readManifestRetirementStateUnlocked(): array
    {
        $empty = ['schema' => self::MANIFEST_RETIREMENT_SCHEMA, 'markers' => []];
        $path = $this->manifestRetirementStateFile();
        $status = @\lstat($path);
        if (!\is_array($status)) {
            if (\file_exists($path) || \is_link($path)) {
                throw new \RuntimeException(
                    'WLS serving manifest retirement state path is unsafe.',
                );
            }
            return $empty;
        }
        $this->assertPrivateStateFile(
            $path,
            'WLS serving manifest retirement state',
        );
        try {
            $encoded = GatewayProjectStateFilesystem::read(
                $path,
                self::MAX_RETIREMENT_STATE_BYTES,
                'WLS serving manifest retirement state',
            );
            $envelope = \json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $empty;
        }
        $state = \is_array($envelope) && \is_array($envelope['payload'] ?? null)
            ? $envelope['payload']
            : null;
        if (!\is_array($state)
            || !\hash_equals(
                (string)($envelope['sha256'] ?? ''),
                \hash('sha256', GatewayClient::canonicalJson($state)),
            )
            || !\hash_equals(
                self::MANIFEST_RETIREMENT_SCHEMA,
                (string)($state['schema'] ?? ''),
            )
            || !\is_array($state['markers'] ?? null)
            || (($state['markers'] ?? []) !== [] && \array_is_list($state['markers']))
            || \count($state['markers']) > self::MAX_STORED_MANIFESTS
            || !\is_int($state['updated_unix'] ?? null)
            || (int)$state['updated_unix'] < 1
            || (!\is_int($state['updated_monotonic'] ?? null)
                && !\is_float($state['updated_monotonic'] ?? null))
            || !\is_finite((float)$state['updated_monotonic'])
            || (float)$state['updated_monotonic'] < 0.0
            || \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($state['host_boot_id'] ?? ''),
            ) !== 1
        ) {
            return $empty;
        }
        $normalized = [];
        foreach ($state['markers'] as $digest => $marker) {
            if (!\is_string($digest)
                || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
                || !\is_array($marker)
                || !\hash_equals(
                    self::MANIFEST_RETIREMENT_MARKER_SCHEMA,
                    (string)($marker['schema'] ?? ''),
                )
                || !\hash_equals($digest, (string)($marker['manifest_digest'] ?? ''))
                || !\hash_equals(
                    (string)$state['host_boot_id'],
                    (string)($marker['host_boot_id'] ?? ''),
                )
                || !\is_int($marker['unreferenced_since_unix'] ?? null)
                || (int)$marker['unreferenced_since_unix'] < 1
                || (int)$marker['unreferenced_since_unix'] > (int)$state['updated_unix']
                || (!\is_int($marker['unreferenced_since_monotonic'] ?? null)
                    && !\is_float($marker['unreferenced_since_monotonic'] ?? null))
                || !\is_finite((float)$marker['unreferenced_since_monotonic'])
                || (float)$marker['unreferenced_since_monotonic'] < 0.0
                || (float)$marker['unreferenced_since_monotonic']
                    > (float)$state['updated_monotonic']
            ) {
                return $empty;
            }
            $normalized[$digest] = $marker;
        }
        \ksort($normalized, SORT_STRING);
        return [
            'schema' => self::MANIFEST_RETIREMENT_SCHEMA,
            'markers' => $normalized,
            'updated_unix' => (int)$state['updated_unix'],
            'updated_monotonic' => (float)$state['updated_monotonic'],
            'host_boot_id' => (string)$state['host_boot_id'],
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $markers
     * @param array{wall_unix:int,monotonic:float,host_boot_id:string} $clock
     */
    private function publishManifestRetirementStateUnlocked(
        array $markers,
        array $clock,
    ): void {
        if (\count($markers) > self::MAX_STORED_MANIFESTS) {
            throw new \RuntimeException(
                'WLS serving manifest retirement marker set exceeds its bound.',
            );
        }
        $payload = [
            'schema' => self::MANIFEST_RETIREMENT_SCHEMA,
            'markers' => $markers,
            'updated_at' => \gmdate(DATE_ATOM, $clock['wall_unix']),
            'updated_unix' => $clock['wall_unix'],
            'updated_monotonic' => $clock['monotonic'],
            'host_boot_id' => $clock['host_boot_id'],
        ];
        $encoded = \json_encode(
            [
                'payload' => $payload,
                'sha256' => \hash(
                    'sha256',
                    GatewayClient::canonicalJson($payload),
                ),
            ],
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR,
        ) . "\n";
        if (\strlen($encoded) > self::MAX_RETIREMENT_STATE_BYTES) {
            throw new \RuntimeException(
                'WLS serving manifest retirement state exceeds its bound.',
            );
        }
        $this->atomicWrite($this->manifestRetirementStateFile(), $encoded, 0600);
    }

    /** @param list<string> $digests */
    private function forgetManifestRetirementDigests(array $digests): void
    {
        if ($digests === []) {
            return;
        }
        $clock = $this->manifestRetirementClock();
        $markers = $this->manifestRetirementMarkersForClock(
            $this->readManifestRetirementStateUnlocked(),
            $clock,
        );
        foreach ($digests as $digest) {
            unset($markers[$digest]);
        }
        \ksort($markers, SORT_STRING);
        $this->publishManifestRetirementStateUnlocked($markers, $clock);
    }

    private function synchronizeManifestRetirementReferencesAfterCommit(
        float $deadlineMonotonic,
    ): void {
        try {
            $this->assertPublicationDeadline($deadlineMonotonic);
            $this->collectableManifestRetirementEntries(
                $this->storedManifestInventory(),
                $this->referencedManifestPathKeys(),
                $deadlineMonotonic,
            );
        } catch (\Throwable) {
            // Marker state is derived. If it cannot be synchronized after the
            // serving pointer commits, discard it so the next successful scan
            // starts a full grace window instead of trusting stale authority.
            try {
                $this->invalidateManifestRetirementStateUnlocked();
            } catch (\Throwable) {
                // An unsafe state path will make future GC fail closed.
            }
        }
    }

    private function invalidateManifestRetirementStateUnlocked(): void
    {
        $path = $this->manifestRetirementStateFile();
        $status = @\lstat($path);
        if (!\is_array($status)) {
            if (\file_exists($path) || \is_link($path)) {
                throw new \RuntimeException(
                    'WLS serving manifest retirement state path is unsafe.',
                );
            }
            return;
        }
        GatewayProjectStateFilesystem::removeRegular(
            $path,
            'WLS serving manifest retirement state',
        );
        GatewayProjectStateFilesystem::syncDirectory(\dirname($path));
    }

    private function manifestRetirementStateFile(): string
    {
        return $this->storeRoot . DIRECTORY_SEPARATOR . 'manifest-retirement.json';
    }

    /** @return array<string,true> */
    private function referencedManifestPathKeys(): array
    {
        return \array_fill_keys(\array_keys($this->referencedManifestPaths()), true);
    }

    /** @return array<string,string> keyed by canonical path key */
    private function referencedManifestPaths(): array
    {
        $handle = @\opendir($this->storeRoot);
        if (!\is_resource($handle)) {
            throw new \RuntimeException(
                'Unable to enumerate WLS serving manifest references.',
            );
        }
        $referenced = [];
        $rawEntries = 0;
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                if (++$rawEntries > self::MAX_STORE_ROOT_ENTRIES) {
                    throw new \RuntimeException(
                        'WLS serving manifest reference store exceeds its bound.',
                    );
                }
                $path = $this->storeRoot . DIRECTORY_SEPARATOR . $leaf;
                if (\preg_match('/\Acurrent-[a-f0-9]{32}\.json\z/D', $leaf) === 1) {
                    $reference = $this->readPointerReference($path);
                } elseif (\preg_match(
                    '/\Aauthority-[a-f0-9]{32}\.json\z/D',
                    $leaf,
                ) === 1) {
                    $reference = $this->readAuthorityReference($path);
                } elseif (\preg_match(
                    '/\Alkg-[a-f0-9]{32}\.json\z/D',
                    $leaf,
                ) === 1) {
                    foreach ($this->readRecentLkgReferences($path) as $lkg) {
                        $reference = (string)$lkg['path'];
                        $referenced[$this->pathKey($reference)] = $reference;
                    }
                    continue;
                } elseif (\hash_equals('manifest-retirement.json', $leaf)) {
                    // Derived GC authority is validated by its own bounded
                    // reader and is not itself a manifest reference.
                    continue;
                } elseif (\str_starts_with($leaf, 'current-')
                    || \str_starts_with($leaf, 'authority-')
                    || \str_starts_with($leaf, 'lkg-')
                    || \str_ends_with($leaf, '.json')
                ) {
                    throw new \RuntimeException(
                        'WLS serving manifest reference filename is corrupt.',
                    );
                } else {
                    continue;
                }
                $referenced[$this->pathKey($reference)] = $reference;
            }
        } finally {
            @\closedir($handle);
        }
        return $referenced;
    }

    /** @return array<string,true> */
    private function certificateSnapshotReferencesUnlocked(): array
    {
        return $this->certificateSnapshotReferencesFromPaths(
            \array_values($this->referencedManifestPaths()),
        );
    }

    /** @return array<string,true> */
    private function certificateSnapshotReferencesForInstanceUnlocked(
        string $instanceId,
    ): array {
        $this->assertInstanceId($instanceId);
        $paths = [];
        foreach ([
            $this->currentPointerPath($instanceId) => 'pointer',
            $this->publicationAuthorityFile($instanceId) => 'authority',
        ] as $referenceFile => $type) {
            $status = @\lstat($referenceFile);
            if (!\is_array($status)) {
                if (\file_exists($referenceFile) || \is_link($referenceFile)) {
                    throw new \RuntimeException(
                        'WLS serving manifest reference path is unsafe.',
                    );
                }
                continue;
            }
            $path = $type === 'pointer'
                ? $this->readPointerReference($referenceFile)
                : $this->readAuthorityReference($referenceFile);
            $paths[$this->pathKey($path)] = $path;
        }
        foreach ($this->readRecentLkgReferences(
            $this->recentLkgFile($instanceId),
            $instanceId,
            true,
        ) as $lkg) {
            $path = (string)$lkg['path'];
            $paths[$this->pathKey($path)] = $path;
        }
        return $this->certificateSnapshotReferencesFromPaths(
            \array_values($paths),
        );
    }

    /**
     * @param list<string> $paths
     * @return array<string,true>
     */
    private function certificateSnapshotReferencesFromPaths(array $paths): array
    {
        $references = [];
        foreach ($paths as $path) {
            $leaf = \basename($path);
            if (\preg_match(
                '/\A([1-9][0-9]{0,18})-([a-f0-9]{64})\.json\z/D',
                $leaf,
                $matches,
            ) !== 1) {
                throw new \RuntimeException(
                    'WLS serving manifest reference has an invalid immutable filename.',
                );
            }
            $manifest = $this->readBound($path, (int)$matches[1], (string)$matches[2]);
            foreach ((array)$manifest['payload']['routes'] as $route) {
                $digest = \strtolower(\trim((string)(
                    \is_array($route) ? ($route['certificate_source_digest'] ?? '') : ''
                )));
                if (\preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1) {
                    throw new \RuntimeException(
                        'WLS serving manifest certificate snapshot reference is corrupt.',
                    );
                }
                $references[$digest] = true;
            }
        }
        \ksort($references, SORT_STRING);
        return $references;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,true>
     */
    private function certificateSnapshotDigestsFromPayload(array $payload): array
    {
        $references = [];
        foreach ((array)($payload['routes'] ?? []) as $route) {
            $digest = \strtolower(\trim((string)(
                \is_array($route) ? ($route['certificate_source_digest'] ?? '') : ''
            )));
            if (\preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1) {
                throw new \RuntimeException(
                    'WLS serving candidate certificate snapshot reference is corrupt.',
                );
            }
            $references[$digest] = true;
        }
        \ksort($references, SORT_STRING);
        return $references;
    }

    private function synchronizeCertificateSnapshotRetirementReferences(
        string $instanceId,
        ?float $deadlineMonotonic = null,
    ): void {
        $this->transitionCertificateSnapshotReferences(
            $this->certificateSnapshotReferencesForInstanceUnlocked($instanceId),
            [],
            $deadlineMonotonic,
        );
    }

    /**
     * @param array<int,string>|array<string,true> $referencedDigests
     * @param array<int,string>|array<string,true> $retiringDigests
     */
    private function transitionCertificateSnapshotReferences(
        array $referencedDigests,
        array $retiringDigests,
        ?float $deadlineMonotonic,
    ): void {
        if ($this->snapshotReferenceTransition !== null) {
            ($this->snapshotReferenceTransition)(
                $referencedDigests,
                $retiringDigests,
                $deadlineMonotonic,
            );
            return;
        }
        (new ProjectCertificateGenerationStore($this->projectRoot))
            ->transitionCertificateSnapshotReferences(
                $referencedDigests,
                $retiringDigests,
                $deadlineMonotonic,
            );
    }

    private function afterCommittedPublication(string $instanceId): void
    {
        try {
            $cleanupDeadline = $this->publicationMonotonicNow()
                + self::POST_COMMIT_CLEANUP_SECONDS;
        } catch (\Throwable) {
            return;
        }
        try {
            $this->synchronizeCertificateSnapshotRetirementReferences(
                $instanceId,
                $cleanupDeadline,
            );
        } catch (\Throwable) {
            // The current pointer is already durable. Pre-commit reference
            // transition protected every candidate snapshot, so a failed
            // derived cleanup can only retain old material longer.
        }
        $this->synchronizeManifestRetirementReferencesAfterCommit(
            $cleanupDeadline,
        );
    }

    /**
     * @return list<array{generation:int,digest:string,path:string}>
     */
    private function readRecentLkgReferences(
        string $path,
        ?string $expectedInstanceId = null,
        bool $missingAllowed = false,
    ): array {
        $status = @\lstat($path);
        if (!\is_array($status)) {
            if (\file_exists($path) || \is_link($path) || !$missingAllowed) {
                throw new \RuntimeException('WLS serving manifest LKG reference is unsafe.');
            }
            return [];
        }
        $this->assertPrivateStateFile($path, 'WLS serving manifest LKG reference');
        $encoded = GatewayProjectStateFilesystem::read(
            $path,
            32_768,
            'WLS serving manifest LKG reference',
        );
        $envelope = \json_decode($encoded, true);
        $unsigned = \is_array($envelope) ? $envelope : [];
        $sha256 = \strtolower(\trim((string)($unsigned['sha256'] ?? '')));
        unset($unsigned['sha256']);
        $instanceId = (string)($unsigned['instance_id'] ?? '');
        $manifests = $unsigned['manifests'] ?? null;
        $validInstanceId = \preg_match(
            '/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D',
            $instanceId,
        ) === 1;
        $expectedPath = $validInstanceId
            ? $this->recentLkgFile($instanceId)
            : '';
        if (!\is_array($envelope)
            || !\hash_equals(self::LKG_SCHEMA, (string)($unsigned['schema'] ?? ''))
            || !$validInstanceId
            || ($expectedInstanceId !== null
                && !\hash_equals($expectedInstanceId, $instanceId))
            || !$this->samePath($expectedPath, $path)
            || !\is_array($manifests)
            || !\array_is_list($manifests)
            || $manifests === []
            || \count($manifests) > 2
            || \preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1
            || !\hash_equals(
                $sha256,
                \hash('sha256', GatewayClient::canonicalJson($unsigned)),
            )
        ) {
            throw new \RuntimeException('WLS serving manifest LKG reference is corrupt.');
        }
        $validated = [];
        $seen = [];
        foreach ($manifests as $reference) {
            $generation = (int)(\is_array($reference)
                ? ($reference['generation'] ?? 0)
                : 0);
            $digest = \strtolower(\trim((string)(\is_array($reference)
                ? ($reference['digest'] ?? '')
                : '')));
            $manifestPath = (string)(\is_array($reference)
                ? ($reference['path'] ?? '')
                : '');
            $key = $this->pathKey($manifestPath);
            if ($generation < 1
                || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
                || !$this->canonicalManifestPathMatches(
                    $manifestPath,
                    $generation,
                    $digest,
                )
                || isset($seen[$key])
            ) {
                throw new \RuntimeException('WLS serving manifest LKG reference is corrupt.');
            }
            $this->readBound($manifestPath, $generation, $digest);
            $seen[$key] = true;
            $validated[] = [
                'generation' => $generation,
                'digest' => $digest,
                'path' => $manifestPath,
            ];
        }
        return $validated;
    }

    private function readPointerReference(string $path): string
    {
        $this->assertPrivateStateFile($path, 'WLS serving manifest pointer');
        $encoded = GatewayProjectStateFilesystem::read(
            $path,
            16_384,
            'WLS serving manifest pointer',
        );
        $pointer = \json_decode($encoded, true);
        $unsigned = \is_array($pointer) ? $pointer : [];
        $sha256 = \strtolower(\trim((string)($unsigned['sha256'] ?? '')));
        unset($unsigned['sha256']);
        $generation = (int)($pointer['generation'] ?? 0);
        $digest = (string)($pointer['digest'] ?? '');
        $reference = (string)($pointer['path'] ?? '');
        if (!\is_array($pointer)
            || !\hash_equals(self::POINTER_SCHEMA, (string)($pointer['schema'] ?? ''))
            || $generation < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1
            || !\hash_equals(
                $sha256,
                \hash('sha256', GatewayClient::canonicalJson($unsigned)),
            )
            || !$this->canonicalManifestPathMatches(
                $reference,
                $generation,
                $digest,
            )
        ) {
            throw new \RuntimeException(
                'WLS serving manifest reference pointer is corrupt.',
            );
        }
        return $reference;
    }

    private function readAuthorityReference(string $path): string
    {
        $this->assertPrivateStateFile($path, 'WLS serving manifest authority');
        $encoded = GatewayProjectStateFilesystem::read(
            $path,
            16_384,
            'WLS serving manifest authority',
        );
        $authority = \json_decode($encoded, true);
        $unsigned = \is_array($authority) ? $authority : [];
        $sha256 = \strtolower(\trim((string)($unsigned['sha256'] ?? '')));
        unset($unsigned['sha256']);
        if (!\is_array($authority)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1
            || !\hash_equals(
                $sha256,
                \hash('sha256', GatewayClient::canonicalJson($unsigned)),
            )
        ) {
            throw new \RuntimeException(
                'WLS serving manifest authority reference is corrupt.',
            );
        }
        $this->assertPublicationAuthority($unsigned);
        $reference = $this->manifestPath(
            (int)$unsigned['generation'],
            (string)$unsigned['manifest_digest'],
        );
        if (!$this->canonicalManifestPathMatches(
            $reference,
            (int)$unsigned['generation'],
            (string)$unsigned['manifest_digest'],
        )) {
            throw new \RuntimeException(
                'WLS serving manifest authority references a missing generation.',
            );
        }
        return $reference;
    }

    /** @param array<string,mixed> $expected @param array<string,mixed> $actual */
    private function assertSameFileFact(array $expected, array $actual, string $label): void
    {
        foreach (['path', 'sha256', 'size', 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink'] as $field) {
            if ((string)($expected[$field] ?? '') !== (string)($actual[$field] ?? '')) {
                throw new \RuntimeException(
                    'WLS serving ' . $label . ' identity changed: '
                    . (string)($expected['path'] ?? ''),
                );
            }
        }
    }

    /**
     * @return array{
     *   fact:array{path:string,sha256:string,size:int,dev:int,ino:int,uid:int,gid:int,mode:int,nlink:int},
     *   contents:string
     * }
     */
    private function stableFileRead(string $path, bool $private): array
    {
        if ($path === '' || \str_contains($path, "\0") || \is_link($path)) {
            throw new \RuntimeException('WLS serving material path is unsafe.');
        }
        $real = \realpath($path);
        if (!\is_string($real)
            || !$this->samePath($path, $real)
            || !$this->pathInside($real, $this->projectRoot)
            || \is_link($real)
        ) {
            throw new \RuntimeException('WLS serving material escaped the project.');
        }
        $before = @\lstat($real);
        $handle = @\fopen($real, 'rb');
        if (!\is_array($before) || !\is_resource($handle)) {
            throw new \RuntimeException('Unable to open WLS serving material safely.');
        }
        try {
            $opened = @\fstat($handle);
            $contents = @\stream_get_contents($handle, self::MAX_CERTIFICATE_BYTES + 1);
            $after = @\fstat($handle);
        } finally {
            @\fclose($handle);
        }
        $latest = @\lstat($real);
        if (!\is_array($opened)
            || !\is_array($after)
            || !\is_array($latest)
            || !\is_string($contents)
            || $contents === ''
            || \strlen($contents) > self::MAX_CERTIFICATE_BYTES
            || ((int)($opened['mode'] ?? 0) & 0170000) !== 0100000
            || (int)($opened['nlink'] ?? 0) !== 1
            || (int)($opened['size'] ?? -1) !== \strlen($contents)
            || (\PHP_OS_FAMILY !== 'Windows'
                && $private
                && (((int)$opened['mode'] & 0077) !== 0))
            || (\PHP_OS_FAMILY !== 'Windows'
                && (((int)$opened['mode'] & 0022) !== 0))
            || (\PHP_OS_FAMILY !== 'Windows'
                && $this->projectOwner >= 0
                && (int)($opened['uid'] ?? -1) !== $this->projectOwner)
        ) {
            throw new \RuntimeException('WLS serving material type, size, owner or mode is invalid.');
        }
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $field) {
            if ((int)($before[$field] ?? -1) !== (int)($opened[$field] ?? -2)
                || (int)($opened[$field] ?? -1) !== (int)($after[$field] ?? -2)
                || (int)($after[$field] ?? -1) !== (int)($latest[$field] ?? -2)
            ) {
                throw new \RuntimeException('WLS serving material changed while being read.');
            }
        }
        return [
            'fact' => [
                'path' => $real,
                'sha256' => \hash('sha256', $contents),
                'size' => (int)$opened['size'],
                'dev' => (int)($opened['dev'] ?? 0),
                'ino' => (int)($opened['ino'] ?? 0),
                'uid' => (int)($opened['uid'] ?? 0),
                'gid' => (int)($opened['gid'] ?? 0),
                'mode' => (int)$opened['mode'],
                'nlink' => (int)$opened['nlink'],
            ],
            'contents' => $contents,
        ];
    }

    /**
     * Verify the complete project-owned certificate generation closure. The
     * registration references ProjectCertificateGenerationStore output, not
     * mutable renewal source files.
     *
     * @return array{
     *   certificate:array<string,mixed>,
     *   private_key:array<string,mixed>,
     *   chain:array<string,mixed>|null,
     *   manifest:array<string,mixed>,
     *   leaf_fingerprint_sha256:string
     * }
     */
    private function verifiedCertificateSnapshot(
        string $sourceDigest,
        string $certificatePath,
        string $privateKeyPath,
    ): array {
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $sourceDigest) !== 1) {
            throw new \RuntimeException('WLS serving certificate snapshot digest is invalid.');
        }
        $directory = $this->projectRoot . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'ssl'
            . DIRECTORY_SEPARATOR . '.wls-generations'
            . DIRECTORY_SEPARATOR . 'snapshots'
            . DIRECTORY_SEPARATOR . $sourceDigest;
        $expectedCertificate = $directory . DIRECTORY_SEPARATOR . 'fullchain.pem';
        $expectedPrivateKey = $directory . DIRECTORY_SEPARATOR . 'privkey.pem';
        if (!$this->samePath($certificatePath, $expectedCertificate)
            || !$this->samePath($privateKeyPath, $expectedPrivateKey)
        ) {
            throw new \RuntimeException(
                'WLS serving manifest cannot bind mutable certificate source paths.',
            );
        }
        $directoryStatus = @\lstat($directory);
        $directoryReal = \realpath($directory);
        if (!\is_array($directoryStatus)
            || \is_link($directory)
            || (((int)($directoryStatus['mode'] ?? 0) & 0170000) !== 0040000)
            || !\is_string($directoryReal)
            || !$this->samePath($directory, $directoryReal)
            || !$this->pathInside($directoryReal, $this->projectRoot)
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((((int)$directoryStatus['mode']) & 0777) !== 0700
                    || ($this->projectOwner >= 0
                        && (int)($directoryStatus['uid'] ?? -1) !== $this->projectOwner)))
        ) {
            throw new \RuntimeException('WLS serving certificate snapshot directory is unsafe.');
        }
        $certificate = $this->stableFileRead($expectedCertificate, false);
        $privateKey = $this->stableFileRead($expectedPrivateKey, true);
        $manifest = $this->stableFileRead(
            $directory . DIRECTORY_SEPARATOR . 'snapshot.json',
            false,
        );
        $envelope = \json_decode((string)$manifest['contents'], true);
        $payload = \is_array($envelope) && \is_array($envelope['payload'] ?? null)
            ? $envelope['payload']
            : null;
        if (!\is_array($payload)
            || !\hash_equals(
                (string)($envelope['sha256'] ?? ''),
                \hash('sha256', GatewayClient::canonicalJson($payload)),
            )
            || (int)($payload['schema_version'] ?? 0) !== 1
            || !\hash_equals($sourceDigest, (string)($payload['source_digest'] ?? ''))
            || !\hash_equals(
                (string)$certificate['fact']['sha256'],
                (string)($payload['cert_sha256'] ?? ''),
            )
            || !\hash_equals(
                (string)$privateKey['fact']['sha256'],
                (string)($payload['key_sha256'] ?? ''),
            )
            || !\hash_equals(
                $sourceDigest,
                \hash(
                    'sha256',
                    (string)$certificate['fact']['sha256'] . ':'
                        . (string)$privateKey['fact']['sha256'] . ':',
                ),
            )
        ) {
            throw new \RuntimeException(
                'WLS serving certificate snapshot manifest integrity failed.',
            );
        }
        $leafFingerprint = \strtolower(\trim((string)(
            $payload['leaf_fingerprint_sha256'] ?? ''
        )));
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $leafFingerprint) !== 1) {
            throw new \RuntimeException(
                'WLS serving certificate snapshot leaf fingerprint is invalid.',
            );
        }
        $chainHash = \strtolower(\trim((string)($payload['chain_sha256'] ?? '')));
        $chainPath = $directory . DIRECTORY_SEPARATOR . 'chain.pem';
        $chain = null;
        if ($chainHash !== '') {
            if (\preg_match('/\A[a-f0-9]{64}\z/D', $chainHash) !== 1) {
                throw new \RuntimeException(
                    'WLS serving certificate snapshot chain digest is invalid.',
                );
            }
            $chainRead = $this->stableFileRead($chainPath, false);
            if (!\hash_equals($chainHash, (string)$chainRead['fact']['sha256'])) {
                throw new \RuntimeException(
                    'WLS serving certificate snapshot chain integrity failed.',
                );
            }
            $chain = $chainRead['fact'];
        } elseif (@\lstat($chainPath) !== false
            || \file_exists($chainPath)
            || \is_link($chainPath)
        ) {
            throw new \RuntimeException(
                'WLS serving certificate snapshot has an unbound chain file.',
            );
        }
        return [
            'certificate' => $certificate['fact'],
            'private_key' => $privateKey['fact'],
            'chain' => $chain,
            'manifest' => $manifest['fact'],
            'leaf_fingerprint_sha256' => $leafFingerprint,
        ];
    }

    /** @param array<string,mixed> $fence */
    private function assertLaunchFence(array $payload, array $fence): void
    {
        if (!\hash_equals(
            (string)($payload['instance_id'] ?? ''),
            (string)($fence['instance_id'] ?? ''),
        )) {
            throw new \RuntimeException('WLS serving manifest launch identity is stale.');
        }
        // Project endpoint observers know the stable instance launch ID and
        // must verify it. Worker --launch-id is a different per-child IPC
        // identity, so Workers intentionally omit this optional field and use
        // the Master/instance generation fence below.
        if (\array_key_exists('launch_id', $fence)
            && !\hash_equals(
                (string)($payload['launch_id'] ?? ''),
                (string)$fence['launch_id'],
            )
        ) {
            throw new \RuntimeException('WLS serving manifest instance launch is stale.');
        }
        foreach (['instance_generation', 'master_pid', 'master_epoch'] as $field) {
            if ((int)($payload[$field] ?? 0) !== (int)($fence[$field] ?? -1)) {
                throw new \RuntimeException('WLS serving manifest Master fence is stale.');
            }
        }
    }

    private function resolveProjectCertificateReference(array $reference): string
    {
        if (!\hash_equals('project_ssl', (string)($reference['root_alias'] ?? ''))) {
            throw new \RuntimeException('Serving manifest requires project-owned certificate snapshots.');
        }
        $relative = \str_replace('\\', '/', \trim((string)(
            $reference['relative_path'] ?? ''
        )));
        if ($relative === '' || \strlen($relative) > 4096 || \str_starts_with($relative, '/')) {
            throw new \RuntimeException('Serving manifest certificate reference is invalid.');
        }
        $candidate = $this->projectRoot . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'ssl';
        foreach (\explode('/', $relative) as $segment) {
            if ($segment === ''
                || $segment === '.'
                || $segment === '..'
                || \strlen($segment) > 255
                || \str_contains($segment, "\0")
            ) {
                throw new \RuntimeException('Serving manifest certificate reference is unsafe.');
            }
            $candidate .= DIRECTORY_SEPARATOR . $segment;
            if (\is_link($candidate)) {
                throw new \RuntimeException('Serving manifest certificate reference crosses a link.');
            }
        }
        $real = \realpath($candidate);
        if (!\is_string($real) || !$this->pathInside($real, $this->projectRoot)) {
            throw new \RuntimeException('Serving manifest certificate reference is unavailable.');
        }
        return $real;
    }

    private function ensureStoreDirectories(): void
    {
        $current = $this->projectRoot;
        foreach (['var', 'server', 'serving-manifest', 'manifests'] as $index => $leaf) {
            $path = $current . DIRECTORY_SEPARATOR . $leaf;
            $status = @\lstat($path);
            if (!\is_array($status)) {
                if (\file_exists($path) || \is_link($path) || !@\mkdir($path, $index >= 2 ? 0700 : 0755)) {
                    throw new \RuntimeException('Unable to create WLS serving manifest directory.');
                }
            }
            $status = @\lstat($path);
            $real = \realpath($path);
            if (!\is_array($status)
                || \is_link($path)
                || ((int)($status['mode'] ?? 0) & 0170000) !== 0040000
                || !\is_string($real)
                || !$this->pathInside($real, $this->projectRoot)
                || (\PHP_OS_FAMILY !== 'Windows'
                    && $index >= 2
                    && (!@\chmod($path, 0700)
                        || (((int)(@\fileperms($path) ?: 0) & 0777) !== 0700)))
            ) {
                throw new \RuntimeException('WLS serving manifest directory is unsafe.');
            }
            $current = \rtrim($real, '/\\');
            if ($index >= 2) {
                $this->preserveOwnership($current);
                $privateStatus = @\lstat($current);
                if (!\is_array($privateStatus)
                    || (\PHP_OS_FAMILY !== 'Windows'
                        && ((((int)$privateStatus['mode']) & 0777) !== 0700
                            || ($this->projectOwner >= 0
                                && (int)($privateStatus['uid'] ?? -1)
                                    !== $this->projectOwner)))
                ) {
                    throw new \RuntimeException(
                        'WLS serving manifest private directory owner or mode is unsafe.',
                    );
                }
            }
        }
    }

    private function generationFile(string $instanceId): string
    {
        $this->assertInstanceId($instanceId);
        return $this->storeRoot . DIRECTORY_SEPARATOR . 'generation-'
            . \substr(\hash('sha256', $instanceId), 0, 32);
    }

    private function publicationAuthorityFile(string $instanceId): string
    {
        $this->assertInstanceId($instanceId);
        return $this->storeRoot . DIRECTORY_SEPARATOR . 'authority-'
            . \substr(\hash('sha256', $instanceId), 0, 32) . '.json';
    }

    private function recentLkgFile(string $instanceId): string
    {
        $this->assertInstanceId($instanceId);
        return $this->storeRoot . DIRECTORY_SEPARATOR . 'lkg-'
            . \substr(\hash('sha256', $instanceId), 0, 32) . '.json';
    }

    private function manifestPath(int $generation, string $digest): string
    {
        return $this->manifestRoot . DIRECTORY_SEPARATOR . $generation . '-' . $digest . '.json';
    }

    private function canonicalManifestPathMatches(string $path, int $generation, string $digest): bool
    {
        if ($path === '' || \str_contains($path, "\0") || \is_link($path)) {
            return false;
        }
        $expected = $this->manifestPath($generation, $digest);
        $real = \realpath($path);
        $root = \realpath($this->manifestRoot);
        return \is_string($real)
            && \is_string($root)
            && $this->samePath($path, $real)
            && $this->samePath($expected, $real)
            && $this->pathInside($real, $root);
    }

    private function atomicWrite(string $path, string $contents, int $mode): void
    {
        GatewayProjectStateFilesystem::atomicWrite(
            $path,
            $contents,
            $mode,
            fn ($handle, string $candidate): mixed => $this->preserveOwnership(
                $candidate,
                $handle,
            ),
        );
    }

    private function assertInstanceId(string $instanceId): void
    {
        if (\preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $instanceId) !== 1) {
            throw new \InvalidArgumentException('WLS serving manifest instance ID is invalid.');
        }
    }

    private function assertPrivateStateFile(string $path, string $label): void
    {
        $status = @\lstat($path);
        if (!\is_array($status)
            || \is_link($path)
            || ((int)($status['mode'] ?? 0) & 0170000) !== 0100000
            || (int)($status['nlink'] ?? 0) !== 1
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((((int)$status['mode']) & 0777) !== 0600
                    || ($this->projectOwner >= 0
                        && (int)($status['uid'] ?? -1) !== $this->projectOwner)))
        ) {
            throw new \RuntimeException($label . ' owner, mode or identity is unsafe.');
        }
    }

    private function preserveOwnership(string $path, mixed $handle = null): void
    {
        if (\PHP_OS_FAMILY === 'Windows'
            || $this->projectOwner < 0
            || $this->projectGroup < 0
            || !\function_exists('posix_geteuid')
            || \posix_geteuid() !== 0
        ) {
            return;
        }
        $real = \realpath($path);
        if (!\is_string($real)
            || !$this->pathInside($real, $this->storeRoot)
            || \is_link($path)
        ) {
            throw new \RuntimeException('WLS serving manifest ownership target is unsafe.');
        }
        $owner = \is_resource($handle) && \function_exists('fchown')
            ? @\fchown($handle, $this->projectOwner)
            : false;
        if (!$owner) {
            $owner = \function_exists('lchown')
                ? @\lchown($path, $this->projectOwner)
                : @\chown($path, $this->projectOwner);
        }
        $group = \is_resource($handle) && \function_exists('fchgrp')
            ? @\fchgrp($handle, $this->projectGroup)
            : false;
        if (!$group) {
            $group = \function_exists('lchgrp')
                ? @\lchgrp($path, $this->projectGroup)
                : @\chgrp($path, $this->projectGroup);
        }
        if (!$owner || !$group) {
            throw new \RuntimeException('Unable to preserve WLS serving manifest ownership.');
        }
    }

    private function samePath(string $left, string $right): bool
    {
        return \hash_equals($this->pathKey($left), $this->pathKey($right));
    }

    private function pathKey(string $path): string
    {
        $path = \str_replace('\\', '/', \rtrim($path, '/\\'));
        return \PHP_OS_FAMILY === 'Windows' ? \strtolower($path) : $path;
    }

    private function pathInside(string $path, string $root): bool
    {
        $path = \str_replace('\\', '/', \rtrim($path, '/\\'));
        $root = \str_replace('\\', '/', \rtrim($root, '/\\'));
        if (\PHP_OS_FAMILY === 'Windows') {
            $path = \strtolower($path);
            $root = \strtolower($root);
        }
        return $path === $root || \str_starts_with($path, $root . '/');
    }

    private function isFilesystemRoot(string $path): bool
    {
        $normalized = \str_replace('\\', '/', \trim($path));
        if (\preg_match('#\A/+\z#D', $normalized) === 1) {
            return true;
        }
        $normalized = \rtrim($normalized, '/');
        return \preg_match('/\A[A-Za-z]:\z/D', $normalized) === 1
            || \preg_match('#\A//(?![?.](?:/|\z))[^/]+(?:/[^/]+)?\z#D', $normalized) === 1
            || \preg_match('#\A//[?.]/[A-Za-z]:\z#Di', $normalized) === 1
            || \preg_match('#\A//[?.]/UNC(?:/[^/]+(?:/[^/]+)?)?\z#Di', $normalized) === 1
            || \preg_match(
                '#\A//[?.]/Volume\{[0-9A-Fa-f-]+\}\z#Di',
                $normalized,
            ) === 1;
    }
}
