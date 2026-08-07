<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Project-owned desired state for exact ACME HTTP-01 leases.
 *
 * The digest-protected envelope is authoritative for gateway replay and both
 * pure-WLS transports. Per-domain JSON files exist only for unambiguous legacy
 * migration and are never a current serving source.
 */
final class ProjectAcmeHttp01ChallengeStore
{
    private const SCHEMA_VERSION = 2;
    private const LEASE_SECONDS = 900;
    private const MAX_WALL_EXPIRY = 253_402_300_799;
    private const MAX_LEASES = 32;
    private const MAX_LEGACY_DIRECTORY_ENTRIES = 256;
    private const STATE_FILE = '.desired.json';
    private const LOCK_FILE = '.desired.lock';
    private readonly string $hostBootId;

    public function __construct(
        private readonly ?string $directory = null,
        private readonly ?\Closure $clock = null,
        private readonly ?\Closure $monotonicClock = null,
        ?string $hostBootId = null,
    ) {
        if ($this->directory !== null
            && (\str_contains($this->directory, "\0")
                || $this->isFilesystemRoot($this->directory))
        ) {
            throw new \RuntimeException(
                'ACME HTTP-01 desired directory cannot be a filesystem root.',
            );
        }
        if ($this->directory === null) {
            $defaultRoot = \realpath(
                \defined('BP') ? (string)BP : \dirname(__DIR__, 7),
            );
            if (\is_string($defaultRoot) && $this->isFilesystemRoot($defaultRoot)) {
                throw new \RuntimeException('ACME HTTP-01 project root is unsafe.');
            }
        }
        $this->hostBootId = GatewayHostBootIdentity::validate(
            $hostBootId ?? GatewayHostBootIdentity::current(),
        );
    }

    public static function projectionFilename(string $domain): string
    {
        $domain = self::normalizeExactDomain($domain);
        return 'domain-' . \hash('sha256', $domain);
    }

    /** Only for reading unambiguous pre-WLS-2.0 project projections. */
    public static function legacyProjectionFilename(string $domain): string
    {
        $domain = self::normalizeExactDomain($domain);
        $filename = \preg_replace(
            '/[^a-z0-9_]/',
            '',
            \str_replace('.', '_', $domain),
        );
        return $filename !== '' ? $filename : 'default';
    }

    /**
     * Resolve one exact Host/token pair from the digest-protected project fact.
     * Compatibility projections are deliberately never consulted here.
     * `$now` remains only for source compatibility; wall time never authorizes
     * or prolongs a challenge lease.
     */
    public static function resolvePublishedChallenge(
        string $directory,
        string $host,
        string $token,
        ?int $now = null,
        ?float $monotonicNow = null,
        ?string $hostBootId = null,
    ): ?string {
        if (\preg_match('/\A[A-Za-z0-9_-]{1,256}\z/D', $token) !== 1) {
            return null;
        }
        try {
            $host = self::normalizeExactDomain($host);
        } catch (\Throwable) {
            return null;
        }
        $directory = \rtrim($directory, '/\\');
        $directoryStatus = @\lstat($directory);
        if ($directory === ''
            || !\is_array($directoryStatus)
            || \is_link($directory)
            || ((((int)($directoryStatus['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            return null;
        }
        try {
            $encoded = GatewayProjectStateFilesystem::readOptional(
                $directory . DIRECTORY_SEPARATOR . self::STATE_FILE,
                131_072,
                'ACME HTTP-01 desired state',
            );
        } catch (\Throwable) {
            return null;
        }
        if ($encoded === null) {
            return null;
        }
        $envelope = \json_decode($encoded, true);
        $payload = \is_array($envelope)
            && \is_array($envelope['payload'] ?? null)
                ? $envelope['payload']
                : null;
        $digest = \is_array($envelope)
            ? \strtolower((string)($envelope['sha256'] ?? ''))
            : '';
        if (!\is_array($payload)
            || (int)($payload['schema_version'] ?? 0) !== self::SCHEMA_VERSION
            || (int)($payload['generation'] ?? -1) < 0
            || !\is_array($payload['challenges'] ?? null)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
            || !\hash_equals(
                $digest,
                \hash('sha256', GatewayClient::canonicalJson($payload)),
            )
        ) {
            return null;
        }
        try {
            $hostBootId = GatewayHostBootIdentity::validate(
                $hostBootId ?? GatewayHostBootIdentity::current(),
            );
            $monotonicNow ??= \hrtime(true) / 1_000_000_000;
            if (!\is_finite($monotonicNow) || $monotonicNow <= 0.0) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }
        $lease = $payload['challenges'][$host] ?? null;
        if (!\is_array($lease)
            || !\hash_equals($host, (string)($lease['domain'] ?? ''))
            || !\hash_equals($token, (string)($lease['token'] ?? ''))
            || !self::validPersistedLeaseCore($lease)
            || !self::leaseFenceActive($lease, $monotonicNow, $hostBootId)
        ) {
            return null;
        }
        $authorization = (string)($lease['key_authorization'] ?? '');
        return \preg_match(
            '/\A[A-Za-z0-9_-]{1,256}\.[A-Za-z0-9_-]{20,256}\z/D',
            $authorization,
        ) === 1 && \str_starts_with($authorization, $token . '.')
            ? $authorization
            : null;
    }

    /**
     * @return array{generation:int,digest:string,challenges:list<array{domain:string,token:string,key_authorization:string,expires_at:int}>}
     */
    public function register(
        string $domain,
        string $token,
        string $keyAuthorization,
        ?float $deadlineMonotonic = null,
    ): array
    {
        $domain = $this->normalizeExactDomain($domain);
        $token = \trim($token);
        $keyAuthorization = \trim($keyAuthorization);
        if (\preg_match('/\A[A-Za-z0-9_-]{1,256}\z/D', $token) !== 1
            || \preg_match(
                '/\A[A-Za-z0-9_-]{1,256}\.[A-Za-z0-9_-]{20,256}\z/D',
                $keyAuthorization,
            ) !== 1
            || !\str_starts_with($keyAuthorization, $token . '.')
        ) {
            throw new \InvalidArgumentException(
                'ACME HTTP-01 token or key authorization is invalid.'
            );
        }

        return $this->withLock(function () use (
            $domain,
            $token,
            $keyAuthorization,
            $deadlineMonotonic,
        ): array {
            $this->assertOperationDeadline($deadlineMonotonic);
            $state = $this->readStateOrMigrate(null, $deadlineMonotonic);
            $state = $this->pruneExpired($state, false, $deadlineMonotonic);
            $challenges = (array)($state['challenges'] ?? []);
            if (!isset($challenges[$domain]) && \count($challenges) >= self::MAX_LEASES) {
                throw new \RuntimeException(
                    'A project may hold at most 32 ACME HTTP-01 challenges.'
                );
            }
            $generation = self::nextGeneration($state['generation'] ?? 0);
            $wallNow = $this->now();
            if ($wallNow <= 0
                || $wallNow > self::MAX_WALL_EXPIRY - self::LEASE_SECONDS
            ) {
                throw new \RuntimeException(
                    'ACME HTTP-01 wall-clock display expiry is invalid.',
                );
            }
            $issuedMonotonic = $this->monotonicNow();
            $leaseDeadlineMonotonic = $issuedMonotonic + self::LEASE_SECONDS;
            if (!\is_finite($leaseDeadlineMonotonic)) {
                throw new \RuntimeException(
                    'ACME HTTP-01 monotonic lease deadline is invalid.',
                );
            }
            $lease = [
                'domain' => $domain,
                'token' => $token,
                'key_authorization' => $keyAuthorization,
                'expires_at' => $wallNow + self::LEASE_SECONDS,
                'generation' => $generation,
                'host_boot_id' => $this->hostBootId,
                'issued_monotonic' => $issuedMonotonic,
                'deadline_monotonic' => $leaseDeadlineMonotonic,
            ];
            $challenges[$domain] = $lease;
            \ksort($challenges, SORT_STRING);
            $next = [
                'schema_version' => self::SCHEMA_VERSION,
                'generation' => $generation,
                'challenges' => $challenges,
                'updated_at' => \gmdate(DATE_ATOM, $wallNow),
            ];
            $this->assertOperationDeadline($deadlineMonotonic);
            $this->writeState($next);
            try {
                $this->publishLegacyProjection($lease);
            } catch (\Throwable) {
                // The digest-protected desired envelope is authoritative for
                // both current pure-WLS workers and gateway replay. A stale
                // compatibility projection must never roll back that fact.
            }
            return $this->projectDesired($next, null, $deadlineMonotonic);
        }, $deadlineMonotonic);
    }

    /**
     * @return array{generation:int,digest:string,challenges:list<array{domain:string,token:string,key_authorization:string,expires_at:int}>}
     */
    public function remove(
        string $domain,
        ?float $deadlineMonotonic = null,
    ): array
    {
        $domain = $this->normalizeExactDomain($domain);
        return $this->withLock(function () use (
            $domain,
            $deadlineMonotonic,
        ): array {
            $this->assertOperationDeadline($deadlineMonotonic);
            $state = $this->pruneExpired(
                $this->readStateOrMigrate(null, $deadlineMonotonic),
                true,
                $deadlineMonotonic,
            );
            $challenges = (array)($state['challenges'] ?? []);
            if (!isset($challenges[$domain])) {
                try {
                    $this->removeLegacyProjection($domain);
                } catch (\Throwable) {
                }
                return $this->projectDesired($state, null, $deadlineMonotonic);
            }
            unset($challenges[$domain]);
            $generation = self::nextGeneration($state['generation'] ?? 0);
            $next = [
                'schema_version' => self::SCHEMA_VERSION,
                'generation' => $generation,
                'challenges' => $challenges,
                'updated_at' => \gmdate(DATE_ATOM, $this->now()),
            ];
            $this->assertOperationDeadline($deadlineMonotonic);
            $this->writeState($next);
            try {
                $this->removeLegacyProjection($domain);
            } catch (\Throwable) {
                // Current runtimes consult only the authoritative envelope.
            }
            return $this->projectDesired($next, null, $deadlineMonotonic);
        }, $deadlineMonotonic);
    }

    /**
     * @param list<string>|null $allowedDomains
     * @return array{generation:int,digest:string,challenges:list<array{domain:string,token:string,key_authorization:string,expires_at:int}>}
     */
    public function desired(
        ?array $allowedDomains = null,
        ?float $deadlineMonotonic = null,
    ): array
    {
        return $this->withLock(function () use (
            $allowedDomains,
            $deadlineMonotonic,
        ): array {
            $this->assertOperationDeadline($deadlineMonotonic);
            $state = $this->readStateOrMigrate(
                $allowedDomains,
                $deadlineMonotonic,
            );
            $next = $this->pruneExpired($state, true, $deadlineMonotonic);
            foreach ((array)($next['challenges'] ?? []) as $lease) {
                $this->assertOperationDeadline($deadlineMonotonic);
                if (\is_array($lease)) {
                    try {
                        $this->publishLegacyProjection($lease);
                    } catch (\Throwable) {
                    }
                }
            }
            return $this->projectDesired(
                $next,
                $allowedDomains,
                $deadlineMonotonic,
            );
        }, $deadlineMonotonic);
    }

    /**
     * @param array<string,mixed> $state
     * @param list<string>|null $allowedDomains
     * @return array{generation:int,digest:string,challenges:list<array{domain:string,token:string,key_authorization:string,expires_at:int}>}
     */
    private function projectDesired(
        array $state,
        ?array $allowedDomains,
        ?float $deadlineMonotonic = null,
    ): array
    {
        $allowed = null;
        if ($allowedDomains !== null) {
            $allowed = [];
            foreach ($allowedDomains as $domain) {
                $this->assertOperationDeadline($deadlineMonotonic);
                try {
                    $allowed[$this->normalizeExactDomain((string)$domain)] = true;
                } catch (\InvalidArgumentException) {
                }
            }
        }
        $challenges = [];
        foreach ((array)($state['challenges'] ?? []) as $domain => $lease) {
            $this->assertOperationDeadline($deadlineMonotonic);
            if (!\is_array($lease)
                || ($allowed !== null && !isset($allowed[(string)$domain]))
            ) {
                continue;
            }
            $challenges[] = [
                'domain' => (string)$lease['domain'],
                'token' => (string)$lease['token'],
                'key_authorization' => (string)$lease['key_authorization'],
                'expires_at' => (int)$lease['expires_at'],
            ];
        }
        $this->assertOperationDeadline($deadlineMonotonic);
        \usort(
            $challenges,
            static fn (array $left, array $right): int => [
                $left['domain'],
                $left['token'],
            ] <=> [
                $right['domain'],
                $right['token'],
            ],
        );
        $digest = \hash(
            'sha256',
            GatewayClient::canonicalJson($challenges),
        );
        return [
            'generation' => \max(0, (int)($state['generation'] ?? 0)),
            'digest' => $digest,
            'challenges' => $challenges,
        ];
    }

    /** @param list<string>|null $legacyDomains @return array<string,mixed> */
    private function readStateOrMigrate(
        ?array $legacyDomains = null,
        ?float $deadlineMonotonic = null,
    ): array
    {
        $this->assertOperationDeadline($deadlineMonotonic);
        $file = $this->stateFile();
        $encoded = GatewayProjectStateFilesystem::readOptional(
            $file,
            131_072,
            'ACME HTTP-01 desired state',
        );
        $this->assertOperationDeadline($deadlineMonotonic);
        if ($encoded === null) {
            return $this->migrateLegacyState(
                $legacyDomains,
                $deadlineMonotonic,
            );
        }
        $envelope = \json_decode($encoded, true);
        $payload = \is_array($envelope)
            && \is_array($envelope['payload'] ?? null)
                ? $envelope['payload']
                : null;
        $digest = \is_array($envelope)
            ? \strtolower(\trim((string)($envelope['sha256'] ?? '')))
            : '';
        if (!\is_array($payload)
            || (int)($payload['generation'] ?? 0) < 0
            || !\is_array($payload['challenges'] ?? null)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
            || !\hash_equals(
                $digest,
                \hash('sha256', GatewayClient::canonicalJson($payload)),
            )
        ) {
            throw new \RuntimeException('ACME HTTP-01 desired state integrity check failed.');
        }
        $schemaVersion = (int)($payload['schema_version'] ?? 0);
        if ($schemaVersion === 1) {
            return $this->retireLegacyState($payload, $deadlineMonotonic);
        }
        if ($schemaVersion !== self::SCHEMA_VERSION) {
            throw new \RuntimeException(
                'ACME HTTP-01 desired state schema is unsupported.',
            );
        }
        foreach ($payload['challenges'] as $domain => $lease) {
            $this->assertOperationDeadline($deadlineMonotonic);
            if (!\is_array($lease)
                || !\hash_equals((string)$domain, (string)($lease['domain'] ?? ''))
                || !self::validPersistedLeaseCore($lease)
            ) {
                throw new \RuntimeException('ACME HTTP-01 desired lease is invalid.');
            }
        }
        return $payload;
    }

    /** @param array<string,mixed> $legacy @return array<string,mixed> */
    private function retireLegacyState(
        array $legacy,
        ?float $deadlineMonotonic = null,
    ): array
    {
        $generationFloor = \max(0, (int)($legacy['generation'] ?? 0));
        foreach ((array)($legacy['challenges'] ?? []) as $lease) {
            $this->assertOperationDeadline($deadlineMonotonic);
            if (\is_array($lease)) {
                $generationFloor = \max(
                    $generationFloor,
                    (int)($lease['generation'] ?? 0),
                );
            }
        }
        $generation = self::nextGeneration($generationFloor);
        $next = [
            'schema_version' => self::SCHEMA_VERSION,
            'generation' => $generation,
            'challenges' => [],
            'updated_at' => \gmdate(DATE_ATOM, $this->now()),
        ];
        $this->assertOperationDeadline($deadlineMonotonic);
        $this->writeState($next);
        foreach ((array)($legacy['challenges'] ?? []) as $domain => $lease) {
            $this->assertOperationDeadline($deadlineMonotonic);
            if (!\is_string($domain) || $domain === '' || !\is_array($lease)) {
                continue;
            }
            try {
                $this->removeLegacyProjection($domain);
            } catch (\Throwable) {
            }
        }
        return $next;
    }

    /** @param list<string>|null $legacyDomains @return array<string,mixed> */
    private function migrateLegacyState(
        ?array $legacyDomains,
        ?float $deadlineMonotonic = null,
    ): array
    {
        /** @var array<string,string|null> $legacyFileDomains */
        $legacyFileDomains = [];
        foreach ($legacyDomains ?? [] as $legacyDomain) {
            $this->assertOperationDeadline($deadlineMonotonic);
            try {
                $normalized = $this->normalizeExactDomain((string)$legacyDomain);
                $legacyName = self::legacyProjectionFilename($normalized) . '.json';
                if (isset($legacyFileDomains[$legacyName])
                    && !\hash_equals((string)$legacyFileDomains[$legacyName], $normalized)
                ) {
                    // Legacy sanitization was not injective. Never infer a
                    // domain from an ambiguous filename; only an explicit
                    // domain inside the file may migrate it.
                    $legacyFileDomains[$legacyName] = null;
                } elseif (!\array_key_exists($legacyName, $legacyFileDomains)) {
                    $legacyFileDomains[$legacyName] = $normalized;
                }
            } catch (\InvalidArgumentException) {
            }
        }
        $stateGeneration = 0;
        $foundLegacyLease = false;
        $directory = $this->resolvedDirectory();
        foreach ($this->boundedLegacyProjectionFiles(
            $directory,
            $deadlineMonotonic,
        ) as $file) {
            $this->assertOperationDeadline($deadlineMonotonic);
            if (\str_starts_with(\basename($file), '.')) {
                continue;
            }
            try {
                $encoded = GatewayProjectStateFilesystem::read(
                    (string)$file,
                    4096,
                    'legacy ACME HTTP-01 projection',
                );
            } catch (\Throwable) {
                continue;
            }
            $legacy = \json_decode($encoded, true);
            $token = \is_array($legacy) ? \trim((string)($legacy['token'] ?? '')) : '';
            $authorization = \is_array($legacy)
                ? \trim((string)($legacy['keyAuth'] ?? $legacy['key_authorization'] ?? ''))
                : '';
            $domain = \is_array($legacy) ? \trim((string)($legacy['domain'] ?? '')) : '';
            if ($domain === '') {
                $domain = (string)($legacyFileDomains[\basename((string)$file)] ?? '');
            }
            if ($domain === '') {
                continue;
            }
            try {
                $domain = $this->normalizeExactDomain($domain);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $fileStatus = @\lstat((string)$file);
            $expiresAt = (int)($legacy['expires_at'] ?? (is_array($fileStatus)
                ? (int)($fileStatus['mtime'] ?? 0) + self::LEASE_SECONDS
                : 0));
            $leaseGeneration = \max(1, (int)($legacy['generation'] ?? 1));
            $lease = [
                'domain' => $domain,
                'token' => $token,
                'key_authorization' => $authorization,
                'expires_at' => $expiresAt,
                'generation' => $leaseGeneration,
            ];
            if (self::validPersistedLeaseCore($lease)) {
                $foundLegacyLease = true;
                $stateGeneration = \max($stateGeneration, $leaseGeneration);
            }
        }
        $state = [
            'schema_version' => self::SCHEMA_VERSION,
            'generation' => $foundLegacyLease
                ? self::nextGeneration($stateGeneration)
                : 0,
            'challenges' => [],
            'updated_at' => \gmdate(DATE_ATOM, $this->now()),
        ];
        if ($foundLegacyLease) {
            $this->assertOperationDeadline($deadlineMonotonic);
            $this->writeState($state);
        }
        return $state;
    }

    /**
     * Legacy migration is a one-time compatibility boundary, not permission to
     * enumerate an attacker-inflated directory. Count every raw directory entry
     * (including irrelevant names), reject links/special files, and fail closed
     * before parsing any projection when the fixed bound is exceeded.
     *
     * @return list<string>
     */
    private function boundedLegacyProjectionFiles(
        string $directory,
        ?float $deadlineMonotonic = null,
    ): array
    {
        $handle = @\opendir($directory);
        if (!\is_resource($handle)) {
            throw new \RuntimeException(
                'Unable to enumerate the ACME HTTP-01 legacy projection directory.'
            );
        }
        $files = [];
        $entries = 0;
        try {
            while (($entry = @\readdir($handle)) !== false) {
                $this->assertOperationDeadline($deadlineMonotonic);
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $entries++;
                if ($entries > self::MAX_LEGACY_DIRECTORY_ENTRIES) {
                    throw new \RuntimeException(
                        'ACME HTTP-01 legacy projection directory exceeds its fixed entry limit.'
                    );
                }
                if (\str_starts_with($entry, '.')
                    || \preg_match('~\A[^\x00/\\\\]{1,255}\.json\z~D', $entry) !== 1
                ) {
                    continue;
                }
                $path = $directory . DIRECTORY_SEPARATOR . $entry;
                $status = @\lstat($path);
                if (!\is_array($status)
                    || \is_link($path)
                    || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0100000)
                    || (int)($status['nlink'] ?? 0) !== 1
                ) {
                    throw new \RuntimeException(
                        'ACME HTTP-01 legacy projection directory contains an unsafe JSON entry.'
                    );
                }
                $files[] = $path;
            }
        } finally {
            @\closedir($handle);
        }
        \sort($files, SORT_STRING);
        return $files;
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function pruneExpired(
        array $state,
        bool $persist,
        ?float $deadlineMonotonic = null,
    ): array
    {
        $challenges = (array)($state['challenges'] ?? []);
        $changed = false;
        $monotonicNow = $this->monotonicNow();
        foreach ($challenges as $domain => $lease) {
            $this->assertOperationDeadline($deadlineMonotonic);
            if (!\is_array($lease)
                || !self::leaseFenceActive(
                    $lease,
                    $monotonicNow,
                    $this->hostBootId,
                )
            ) {
                unset($challenges[$domain]);
                $changed = true;
            }
        }
        if (!$changed) {
            return $state;
        }
        $next = [
            'schema_version' => self::SCHEMA_VERSION,
            'generation' => self::nextGeneration($state['generation'] ?? 0),
            'challenges' => $challenges,
            'updated_at' => \gmdate(DATE_ATOM, $this->now()),
        ];
        if ($persist) {
            $this->assertOperationDeadline($deadlineMonotonic);
            $this->writeState($next);
            foreach (\array_keys($challenges + (array)($state['challenges'] ?? [])) as $domain) {
                $this->assertOperationDeadline($deadlineMonotonic);
                if (isset($challenges[$domain]) || !\is_string($domain) || $domain === '') {
                    continue;
                }
                try {
                    $this->removeLegacyProjection($domain);
                } catch (\Throwable) {
                }
            }
        }
        return $next;
    }

    /** @param array<string,mixed> $lease */
    private static function validPersistedLeaseCore(array $lease): bool
    {
        $domain = (string)($lease['domain'] ?? '');
        $token = (string)($lease['token'] ?? '');
        $authorization = (string)($lease['key_authorization'] ?? '');
        try {
            if (!\hash_equals($domain, self::normalizeExactDomain($domain))) {
                return false;
            }
        } catch (\InvalidArgumentException) {
            return false;
        }
        return \preg_match('/\A[A-Za-z0-9_-]{1,256}\z/D', $token) === 1
            && \preg_match(
                '/\A[A-Za-z0-9_-]{1,256}\.[A-Za-z0-9_-]{20,256}\z/D',
                $authorization,
            ) === 1
            && \str_starts_with($authorization, $token . '.')
            && \is_int($lease['expires_at'] ?? null)
            && $lease['expires_at'] > 0
            && \is_int($lease['generation'] ?? null)
            && $lease['generation'] > 0;
    }

    /** @param array<string,mixed> $lease */
    private static function leaseFenceWellFormed(array $lease): bool
    {
        $bootId = \strtolower(\trim((string)($lease['host_boot_id'] ?? '')));
        $issued = self::finitePositiveMonotonic($lease['issued_monotonic'] ?? null);
        $deadline = self::finitePositiveMonotonic($lease['deadline_monotonic'] ?? null);
        return \preg_match('/\A[a-f0-9]{64}\z/D', $bootId) === 1
            && $issued !== null
            && $deadline !== null
            && $deadline === $issued + self::LEASE_SECONDS;
    }

    /** @param array<string,mixed> $lease */
    private static function leaseFenceActive(
        array $lease,
        float $monotonicNow,
        string $hostBootId,
    ): bool {
        if (!self::leaseFenceWellFormed($lease)
            || !\hash_equals(
                $hostBootId,
                \strtolower((string)($lease['host_boot_id'] ?? '')),
            )
        ) {
            return false;
        }
        $issued = (float)$lease['issued_monotonic'];
        $deadline = (float)$lease['deadline_monotonic'];
        return $monotonicNow >= $issued && $monotonicNow < $deadline;
    }

    private static function finitePositiveMonotonic(mixed $value): ?float
    {
        if (!\is_int($value) && !\is_float($value)) {
            return null;
        }
        $value = (float)$value;
        return \is_finite($value) && $value > 0.0 ? $value : null;
    }

    private static function nextGeneration(mixed $current): int
    {
        $current = \max(0, (int)$current);
        if ($current >= \PHP_INT_MAX) {
            throw new \RuntimeException(
                'ACME HTTP-01 generation exhausted its integer range.',
            );
        }
        return $current + 1;
    }

    /** @param array<string,mixed> $lease */
    private function publishLegacyProjection(array $lease): void
    {
        if (!self::validPersistedLeaseCore($lease)
            || !self::leaseFenceWellFormed($lease)
        ) {
            throw new \RuntimeException('Cannot publish an invalid ACME HTTP-01 projection.');
        }
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'domain' => (string)$lease['domain'],
            'token' => (string)$lease['token'],
            'keyAuth' => (string)$lease['key_authorization'],
            'key_authorization' => (string)$lease['key_authorization'],
            'expires_at' => (int)$lease['expires_at'],
            'generation' => (int)$lease['generation'],
            'host_boot_id' => (string)$lease['host_boot_id'],
            'issued_monotonic' => (float)$lease['issued_monotonic'],
            'deadline_monotonic' => (float)$lease['deadline_monotonic'],
        ];
        $this->atomicWrite(
            $this->projectionFile((string)$lease['domain']),
            GatewayClient::canonicalJson($payload),
        );
    }

    private function removeLegacyProjection(string $domain): void
    {
        GatewayProjectStateFilesystem::removeRegular(
            $this->projectionFile($domain),
            'ACME HTTP-01 compatibility projection',
        );
    }

    /** @param array<string,mixed> $payload */
    private function writeState(array $payload): void
    {
        $envelope = [
            'payload' => $payload,
            'sha256' => \hash('sha256', GatewayClient::canonicalJson($payload)),
        ];
        $this->atomicWrite(
            $this->stateFile(),
            GatewayClient::canonicalJson($envelope),
        );
    }

    private function atomicWrite(string $file, string $content): void
    {
        GatewayProjectStateFilesystem::atomicWrite($file, $content, 0600);
    }

    /**
     * @template T
     * @param \Closure():T $callback
     * @return T
     */
    private function withLock(
        \Closure $callback,
        ?float $deadlineMonotonic = null,
    ): mixed
    {
        $waitTimeout = $this->operationLockWaitTimeout($deadlineMonotonic);
        $directory = $this->resolvedDirectory();
        $lockFile = $directory . DIRECTORY_SEPARATOR . self::LOCK_FILE;
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $lockFile,
            function () use ($callback, $deadlineMonotonic): mixed {
                $this->assertOperationDeadline($deadlineMonotonic);
                $this->cleanupAtomicRecoveryBackups($deadlineMonotonic);
                return $callback();
            },
            waitTimeoutSeconds: $waitTimeout,
        );
    }

    private function cleanupAtomicRecoveryBackups(
        ?float $deadlineMonotonic = null,
    ): void {
        $directory = $this->resolvedDirectory();
        $handle = @\opendir($directory);
        if (!\is_resource($handle)) {
            throw new \RuntimeException(
                'Unable to enumerate the ACME HTTP-01 recovery directory.'
            );
        }
        $targets = [];
        $visited = 0;
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                $this->assertOperationDeadline($deadlineMonotonic);
                if (++$visited > self::MAX_LEGACY_DIRECTORY_ENTRIES) {
                    throw new \RuntimeException(
                        'ACME HTTP-01 recovery directory exceeds its fixed raw entry quota.'
                    );
                }
                if (!\str_contains($leaf, '.wls-backup-')) {
                    continue;
                }
                $match = [];
                if (\preg_match(
                    '/\A(.+)\.wls-backup-[a-f0-9]{16}\z/D',
                    $leaf,
                    $match,
                ) !== 1) {
                    throw new \RuntimeException(
                        'ACME HTTP-01 recovery directory contains a malformed reserved leaf.'
                    );
                }
                $targetLeaf = (string)$match[1];
                if ($targetLeaf !== self::STATE_FILE
                    && \preg_match(
                        '/\Adomain-[a-f0-9]{64}\.json\z/D',
                        $targetLeaf,
                    ) !== 1
                ) {
                    throw new \RuntimeException(
                        'ACME HTTP-01 recovery artifact target is not a current semantic file.'
                    );
                }
                $targets[$directory . DIRECTORY_SEPARATOR . $targetLeaf] = true;
            }
        } finally {
            @\closedir($handle);
        }
        $targets = \array_keys($targets);
        \sort($targets, SORT_STRING);

        // Validate every paired target before deleting any prior generation.
        // This preserves the complete recovery set when one target is missing
        // or damaged, instead of partially erasing crash evidence.
        foreach ($targets as $target) {
            $this->assertOperationDeadline($deadlineMonotonic);
            $maximum = \hash_equals($this->stateFile(), $target) ? 131_072 : 16_384;
            GatewayProjectStateFilesystem::hasAtomicWriteRecoveryBackups(
                $target,
                $maximum,
                'ACME HTTP-01 desired state',
            );
            $raw = GatewayProjectStateFilesystem::read(
                $target,
                $maximum,
                'ACME HTTP-01 recovery backup paired target',
            );
            $this->validateRecoveryTargetContents($target, $raw);
        }
        foreach ($targets as $target) {
            $this->assertOperationDeadline($deadlineMonotonic);
            $maximum = \hash_equals($this->stateFile(), $target) ? 131_072 : 16_384;
            GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
                $target,
                $maximum,
                'ACME HTTP-01 desired state',
                function (string $raw) use ($target): void {
                    $this->validateRecoveryTargetContents($target, $raw);
                },
            );
        }
    }

    private function validateRecoveryTargetContents(string $target, string $raw): void
    {
        if (\hash_equals($this->stateFile(), $target)) {
            $envelope = \json_decode($raw, true);
            $expectedEnvelope = ['payload', 'sha256'];
            $actualEnvelope = \is_array($envelope) ? \array_keys($envelope) : [];
            \sort($expectedEnvelope, SORT_STRING);
            \sort($actualEnvelope, SORT_STRING);
            $payload = \is_array($envelope)
                && \is_array($envelope['payload'] ?? null)
                    ? $envelope['payload']
                    : null;
            $digest = \is_array($envelope)
                ? \strtolower((string)($envelope['sha256'] ?? ''))
                : '';
            $expectedPayload = [
                'schema_version',
                'generation',
                'challenges',
                'updated_at',
            ];
            $actualPayload = \is_array($payload) ? \array_keys($payload) : [];
            \sort($expectedPayload, SORT_STRING);
            \sort($actualPayload, SORT_STRING);
            $schema = \is_array($payload) ? ($payload['schema_version'] ?? null) : null;
            if ($actualEnvelope !== $expectedEnvelope
                || !\is_array($payload)
                || $actualPayload !== $expectedPayload
                || !\in_array($schema, [1, self::SCHEMA_VERSION], true)
                || !\is_int($payload['generation'] ?? null)
                || (int)$payload['generation'] < 0
                || !\is_array($payload['challenges'] ?? null)
                || \count($payload['challenges']) > self::MAX_LEASES
                || !\is_string($payload['updated_at'] ?? null)
                || \strlen((string)$payload['updated_at']) > 128
                || \strtotime((string)$payload['updated_at']) === false
                || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
                || !\hash_equals(
                    $digest,
                    \hash('sha256', GatewayClient::canonicalJson($payload)),
                )
            ) {
                throw new \RuntimeException(
                    'ACME HTTP-01 desired recovery target is invalid.'
                );
            }
            foreach ($payload['challenges'] as $domain => $lease) {
                if (!\is_array($lease)
                    || !\hash_equals((string)$domain, (string)($lease['domain'] ?? ''))
                    || !self::validPersistedLeaseCore($lease)
                    || ($schema === self::SCHEMA_VERSION
                        && !self::leaseFenceWellFormed($lease))
                ) {
                    throw new \RuntimeException(
                        'ACME HTTP-01 desired recovery lease is invalid.'
                    );
                }
            }
            return;
        }

        $projection = \json_decode($raw, true);
        $expected = [
            'schema_version',
            'domain',
            'token',
            'keyAuth',
            'key_authorization',
            'expires_at',
            'generation',
            'host_boot_id',
            'issued_monotonic',
            'deadline_monotonic',
        ];
        $actual = \is_array($projection) ? \array_keys($projection) : [];
        \sort($expected, SORT_STRING);
        \sort($actual, SORT_STRING);
        $domain = \is_array($projection) ? (string)($projection['domain'] ?? '') : '';
        $expectedFile = $this->projectionFile($domain);
        if (!\is_array($projection)
            || $actual !== $expected
            || ($projection['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || !\hash_equals($expectedFile, $target)
            || !\hash_equals(
                (string)($projection['keyAuth'] ?? ''),
                (string)($projection['key_authorization'] ?? ''),
            )
            || !self::validPersistedLeaseCore($projection)
            || !self::leaseFenceWellFormed($projection)
        ) {
            throw new \RuntimeException(
                'ACME HTTP-01 projection recovery target is invalid.'
            );
        }
    }

    private function operationLockWaitTimeout(?float $deadlineMonotonic): float
    {
        if ($deadlineMonotonic === null) {
            return 300.0;
        }
        return \min(0.25, $this->assertOperationDeadline($deadlineMonotonic));
    }

    private function assertOperationDeadline(?float $deadlineMonotonic): float
    {
        if ($deadlineMonotonic === null) {
            return 300.0;
        }
        if (!\is_finite($deadlineMonotonic) || $deadlineMonotonic < 0.0) {
            throw new \RuntimeException('ACME HTTP-01 state deadline is invalid.');
        }
        $remaining = $deadlineMonotonic - $this->monotonicNow();
        if ($remaining <= 0.0) {
            throw new \RuntimeException('ACME HTTP-01 state deadline was exhausted.');
        }
        return $remaining;
    }

    private function resolvedDirectory(): string
    {
        if ($this->directory === null) {
            $projectRoot = \realpath(\defined('BP') ? (string)BP : \dirname(__DIR__, 7));
            $rootStatus = \is_string($projectRoot) ? @\lstat($projectRoot) : false;
            if (!\is_string($projectRoot)
                || !\is_array($rootStatus)
                || \is_link($projectRoot)
                || ((((int)($rootStatus['mode'] ?? 0)) & 0170000) !== 0040000)
                || $this->isFilesystemRoot($projectRoot)
            ) {
                throw new \RuntimeException('ACME HTTP-01 project root is unsafe.');
            }
            $directory = \rtrim($projectRoot, '/\\');
            foreach (['generated', 'acme-http01'] as $segment) {
                $directory .= DIRECTORY_SEPARATOR . $segment;
                $this->ensureDirectoryLeaf($directory);
                $real = \realpath($directory);
                if (!\is_string($real) || !$this->pathInside($real, $projectRoot)) {
                    throw new \RuntimeException('ACME HTTP-01 desired directory escapes the project.');
                }
                $directory = \rtrim($real, '/\\');
            }
        } else {
            if ($this->isFilesystemRoot($this->directory)) {
                throw new \RuntimeException(
                    'ACME HTTP-01 desired directory cannot be a filesystem root.'
                );
            }
            $directory = \rtrim($this->directory, '/\\');
            if (!$this->isAbsolutePath($directory)) {
                throw new \RuntimeException('ACME HTTP-01 desired directory must be absolute.');
            }
            $this->ensureDirectoryLeaf($directory);
            $real = \realpath($directory);
            if (!\is_string($real) || $real === '') {
                throw new \RuntimeException('Unable to resolve ACME HTTP-01 desired directory.');
            }
            if ($this->isFilesystemRoot($real)) {
                throw new \RuntimeException(
                    'ACME HTTP-01 desired directory cannot be a filesystem root.'
                );
            }
            $directory = \rtrim($real, '/\\');
        }
        $status = @\lstat($directory);
        if (!\is_array($status)
            || \is_link($directory)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
            || (\PHP_OS_FAMILY !== 'Windows' && !@\chmod($directory, 0700))
        ) {
            throw new \RuntimeException('ACME HTTP-01 desired directory is unsafe.');
        }
        return $directory;
    }

    private function ensureDirectoryLeaf(string $directory): void
    {
        if ($directory === '' || \str_contains($directory, "\0") || \is_link($directory)) {
            throw new \RuntimeException('ACME HTTP-01 desired directory is unsafe.');
        }
        $status = @\lstat($directory);
        if (!\is_array($status)) {
            if (\file_exists($directory) || \is_link($directory)) {
                throw new \RuntimeException('ACME HTTP-01 desired directory is unsafe.');
            }
            $parent = \dirname($directory);
            if ($parent === $directory) {
                throw new \RuntimeException('ACME HTTP-01 desired directory has no safe parent.');
            }
            $this->ensureDirectoryLeaf($parent);
            if (!@\mkdir($directory, 0700)) {
                throw new \RuntimeException('Unable to create ACME HTTP-01 desired directory.');
            }
            $status = @\lstat($directory);
        }
        if (!\is_array($status)
            || \is_link($directory)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException('ACME HTTP-01 desired directory is unsafe.');
        }
    }

    private function isAbsolutePath(string $path): bool
    {
        return \str_starts_with($path, '/')
            || \preg_match('/\A[A-Za-z]:[\\\\\/]/D', $path) === 1
            || \str_starts_with($path, '\\\\');
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

    private function pathInside(string $path, string $root): bool
    {
        $normalize = static function (string $value): string {
            $value = \rtrim(\str_replace('\\', '/', $value), '/');
            return \PHP_OS_FAMILY === 'Windows' ? \strtolower($value) : $value;
        };
        $path = $normalize($path);
        $root = $normalize($root);
        return $path === $root || \str_starts_with($path, $root . '/');
    }

    private function stateFile(): string
    {
        return $this->resolvedDirectory() . DIRECTORY_SEPARATOR . self::STATE_FILE;
    }

    private function projectionFile(string $domain): string
    {
        return $this->resolvedDirectory()
            . DIRECTORY_SEPARATOR
            . self::projectionFilename($domain)
            . '.json';
    }

    public static function normalizeExactDomain(string $domain): string
    {
        $domain = \strtolower(\rtrim(\trim($domain), '.'));
        if ($domain === '' || \str_starts_with($domain, '*.')) {
            throw new \InvalidArgumentException(
                'ACME HTTP-01 requires an exact domain.'
            );
        }
        if (\function_exists('idn_to_ascii')) {
            $ascii = \idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (!\is_string($ascii) || $ascii === '') {
                throw new \InvalidArgumentException('ACME HTTP-01 domain is invalid.');
            }
            $domain = \strtolower($ascii);
        }
        if (\strlen($domain) > 253
            || \preg_match(
                '/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D',
                $domain,
            ) !== 1
        ) {
            throw new \InvalidArgumentException('ACME HTTP-01 domain is invalid.');
        }
        return $domain;
    }

    private function now(): int
    {
        return $this->clock !== null ? (int)($this->clock)() : \time();
    }

    private function monotonicNow(): float
    {
        $now = $this->monotonicClock !== null
            ? ($this->monotonicClock)()
            : \hrtime(true) / 1_000_000_000;
        $now = self::finitePositiveMonotonic($now);
        if ($now === null) {
            throw new \RuntimeException('ACME HTTP-01 monotonic clock is invalid.');
        }
        return $now;
    }
}
