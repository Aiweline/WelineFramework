<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Project-owned desired state for exact ACME HTTP-01 leases.
 *
 * The envelope is authoritative for gateway replay. Per-domain JSON files are
 * an atomic compatibility projection consumed by pure WLS workers, so moving
 * the project never carries host registration state with it.
 */
final class ProjectAcmeHttp01ChallengeStore
{
    private const SCHEMA_VERSION = 1;
    private const LEASE_SECONDS = 900;
    private const MAX_LEASES = 32;
    private const STATE_FILE = '.desired.json';
    private const LOCK_FILE = '.desired.lock';

    public function __construct(
        private readonly ?string $directory = null,
        private readonly ?\Closure $clock = null,
    ) {
    }

    /**
     * @return array{generation:int,digest:string,challenges:list<array{domain:string,token:string,key_authorization:string,expires_at:int}>}
     */
    public function register(string $domain, string $token, string $keyAuthorization): array
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

        return $this->withLock(function () use ($domain, $token, $keyAuthorization): array {
            $state = $this->readStateOrMigrate();
            $state = $this->pruneExpired($state, false);
            $challenges = (array)($state['challenges'] ?? []);
            if (!isset($challenges[$domain]) && \count($challenges) >= self::MAX_LEASES) {
                throw new \RuntimeException(
                    'A project may hold at most 32 ACME HTTP-01 challenges.'
                );
            }
            $generation = \max(0, (int)($state['generation'] ?? 0)) + 1;
            $lease = [
                'domain' => $domain,
                'token' => $token,
                'key_authorization' => $keyAuthorization,
                'expires_at' => $this->now() + self::LEASE_SECONDS,
                'generation' => $generation,
            ];
            $this->publishLegacyProjection($lease);
            $challenges[$domain] = $lease;
            \ksort($challenges, SORT_STRING);
            $next = [
                'schema_version' => self::SCHEMA_VERSION,
                'generation' => $generation,
                'challenges' => $challenges,
                'updated_at' => \gmdate(DATE_ATOM, $this->now()),
            ];
            $this->writeState($next);
            return $this->projectDesired($next, null);
        });
    }

    /**
     * @return array{generation:int,digest:string,challenges:list<array{domain:string,token:string,key_authorization:string,expires_at:int}>}
     */
    public function remove(string $domain): array
    {
        $domain = $this->normalizeExactDomain($domain);
        return $this->withLock(function () use ($domain): array {
            $state = $this->pruneExpired($this->readStateOrMigrate(), true);
            $challenges = (array)($state['challenges'] ?? []);
            $legacy = $this->legacyFile($domain);
            if (!isset($challenges[$domain]) && !\is_file($legacy)) {
                return $this->projectDesired($state, null);
            }
            unset($challenges[$domain]);
            $generation = \max(0, (int)($state['generation'] ?? 0)) + 1;
            $next = [
                'schema_version' => self::SCHEMA_VERSION,
                'generation' => $generation,
                'challenges' => $challenges,
                'updated_at' => \gmdate(DATE_ATOM, $this->now()),
            ];
            $this->writeState($next);
            $this->removeLegacyProjection($domain);
            return $this->projectDesired($next, null);
        });
    }

    /**
     * @param list<string>|null $allowedDomains
     * @return array{generation:int,digest:string,challenges:list<array{domain:string,token:string,key_authorization:string,expires_at:int}>}
     */
    public function desired(?array $allowedDomains = null): array
    {
        return $this->withLock(function () use ($allowedDomains): array {
            $state = $this->readStateOrMigrate($allowedDomains);
            $next = $this->pruneExpired($state, true);
            foreach ((array)($next['challenges'] ?? []) as $lease) {
                if (\is_array($lease)) {
                    $this->publishLegacyProjection($lease);
                }
            }
            return $this->projectDesired($next, $allowedDomains);
        });
    }

    /**
     * @param array<string,mixed> $state
     * @param list<string>|null $allowedDomains
     * @return array{generation:int,digest:string,challenges:list<array{domain:string,token:string,key_authorization:string,expires_at:int}>}
     */
    private function projectDesired(array $state, ?array $allowedDomains): array
    {
        $allowed = null;
        if ($allowedDomains !== null) {
            $allowed = [];
            foreach ($allowedDomains as $domain) {
                try {
                    $allowed[$this->normalizeExactDomain((string)$domain)] = true;
                } catch (\InvalidArgumentException) {
                }
            }
        }
        $challenges = [];
        foreach ((array)($state['challenges'] ?? []) as $domain => $lease) {
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
    private function readStateOrMigrate(?array $legacyDomains = null): array
    {
        $file = $this->stateFile();
        if (!\is_file($file)) {
            return $this->migrateLegacyState($legacyDomains);
        }
        if (\is_link($file) || (int)@\filesize($file) > 131_072) {
            throw new \RuntimeException('ACME HTTP-01 desired state is unsafe or oversized.');
        }
        $encoded = @\file_get_contents($file);
        $envelope = \is_string($encoded) ? \json_decode($encoded, true) : null;
        $payload = \is_array($envelope['payload'] ?? null) ? $envelope['payload'] : null;
        $digest = \strtolower(\trim((string)($envelope['sha256'] ?? '')));
        if (!\is_array($payload)
            || (int)($payload['schema_version'] ?? 0) !== self::SCHEMA_VERSION
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
        foreach ($payload['challenges'] as $domain => $lease) {
            if (!\is_array($lease)
                || !\hash_equals((string)$domain, (string)($lease['domain'] ?? ''))
                || !$this->validPersistedLease($lease)
            ) {
                throw new \RuntimeException('ACME HTTP-01 desired lease is invalid.');
            }
        }
        return $payload;
    }

    /** @param list<string>|null $legacyDomains @return array<string,mixed> */
    private function migrateLegacyState(?array $legacyDomains): array
    {
        $legacyFileDomains = [];
        foreach ($legacyDomains ?? [] as $legacyDomain) {
            try {
                $normalized = $this->normalizeExactDomain((string)$legacyDomain);
                $legacyFileDomains[$this->domainFilename($normalized) . '.json'] = $normalized;
            } catch (\InvalidArgumentException) {
            }
        }
        $challenges = [];
        $stateGeneration = 0;
        foreach (\glob($this->resolvedDirectory() . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            if (\str_starts_with(\basename((string)$file), '.')
                || \is_link((string)$file)
                || (int)@\filesize((string)$file) > 4096
            ) {
                continue;
            }
            $encoded = @\file_get_contents((string)$file);
            $legacy = \is_string($encoded) ? \json_decode($encoded, true) : null;
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
            $expiresAt = (int)($legacy['expires_at'] ?? ((int)@\filemtime((string)$file) + self::LEASE_SECONDS));
            $leaseGeneration = \max(1, (int)($legacy['generation'] ?? 1));
            $lease = [
                'domain' => $domain,
                'token' => $token,
                'key_authorization' => $authorization,
                'expires_at' => $expiresAt,
                'generation' => $leaseGeneration,
            ];
            if ($expiresAt > $this->now() && $this->validPersistedLease($lease)) {
                $challenges[$domain] = $lease;
                $stateGeneration = \max($stateGeneration, $leaseGeneration);
            }
        }
        \ksort($challenges, SORT_STRING);
        $state = [
            'schema_version' => self::SCHEMA_VERSION,
            'generation' => $stateGeneration,
            'challenges' => $challenges,
            'updated_at' => \gmdate(DATE_ATOM, $this->now()),
        ];
        if ($challenges !== []) {
            $this->writeState($state);
        }
        return $state;
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function pruneExpired(array $state, bool $persist): array
    {
        $challenges = (array)($state['challenges'] ?? []);
        $changed = false;
        foreach ($challenges as $domain => $lease) {
            if (!\is_array($lease) || (int)($lease['expires_at'] ?? 0) <= $this->now()) {
                unset($challenges[$domain]);
                if (\is_string($domain) && $domain !== '') {
                    $this->removeLegacyProjection($domain);
                }
                $changed = true;
            }
        }
        if (!$changed) {
            return $state;
        }
        $next = [
            'schema_version' => self::SCHEMA_VERSION,
            'generation' => \max(0, (int)($state['generation'] ?? 0)) + 1,
            'challenges' => $challenges,
            'updated_at' => \gmdate(DATE_ATOM, $this->now()),
        ];
        if ($persist) {
            $this->writeState($next);
        }
        return $next;
    }

    /** @param array<string,mixed> $lease */
    private function validPersistedLease(array $lease): bool
    {
        $domain = (string)($lease['domain'] ?? '');
        $token = (string)($lease['token'] ?? '');
        $authorization = (string)($lease['key_authorization'] ?? '');
        try {
            if (!\hash_equals($domain, $this->normalizeExactDomain($domain))) {
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
            && (int)($lease['expires_at'] ?? 0) > 0
            && (int)($lease['generation'] ?? 0) > 0;
    }

    /** @param array<string,mixed> $lease */
    private function publishLegacyProjection(array $lease): void
    {
        if (!$this->validPersistedLease($lease)) {
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
        ];
        $this->atomicWrite(
            $this->legacyFile((string)$lease['domain']),
            GatewayClient::canonicalJson($payload),
        );
    }

    private function removeLegacyProjection(string $domain): void
    {
        $file = $this->legacyFile($domain);
        if (\is_link($file)) {
            throw new \RuntimeException('Symbolic-link ACME HTTP-01 projection is forbidden.');
        }
        if (\is_file($file) && !@\unlink($file)) {
            throw new \RuntimeException('Unable to remove ACME HTTP-01 projection.');
        }
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
        if (\is_link($file)) {
            throw new \RuntimeException('Symbolic-link ACME HTTP-01 target is forbidden.');
        }
        $temporary = $file . '.tmp.' . \bin2hex(\random_bytes(8));
        $handle = @\fopen($temporary, 'x+b');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to create ACME HTTP-01 staging file.');
        }
        $staged = false;
        try {
            if (PHP_OS_FAMILY !== 'Windows' && !@\chmod($temporary, 0600)) {
                throw new \RuntimeException(
                    'Unable to secure ACME HTTP-01 staging file.'
                );
            }
            $offset = 0;
            $length = \strlen($content);
            while ($offset < $length) {
                $written = @\fwrite($handle, \substr($content, $offset));
                if (!\is_int($written) || $written < 1) {
                    throw new \RuntimeException('Unable to write ACME HTTP-01 staging file.');
                }
                $offset += $written;
            }
            if (!@\fflush($handle)) {
                throw new \RuntimeException('Unable to flush ACME HTTP-01 staging file.');
            }
            if (\function_exists('fsync') && !@\fsync($handle)) {
                throw new \RuntimeException('Unable to sync ACME HTTP-01 staging file.');
            }
            $staged = true;
        } finally {
            @\fclose($handle);
            if (!$staged) {
                @\unlink($temporary);
            }
        }
        $attempts = PHP_OS_FAMILY === 'Windows' ? 5 : 1;
        for ($attempt = 0; $attempt < $attempts; ++$attempt) {
            if (@\rename($temporary, $file)) {
                @\chmod($file, 0600);
                return;
            }
            if ($attempt + 1 < $attempts) {
                \usleep(10_000);
            }
        }
        @\unlink($temporary);
        throw new \RuntimeException('Unable to publish ACME HTTP-01 state atomically.');
    }

    /** @template T @param \Closure():T $callback @return T */
    private function withLock(\Closure $callback): mixed
    {
        $directory = $this->resolvedDirectory();
        $lockFile = $directory . DIRECTORY_SEPARATOR . self::LOCK_FILE;
        if (\is_link($lockFile)) {
            throw new \RuntimeException('Symbolic-link ACME HTTP-01 lock is forbidden.');
        }
        $lock = @\fopen($lockFile, 'c+b');
        if (!\is_resource($lock) || !@\flock($lock, LOCK_EX)) {
            if (\is_resource($lock)) {
                @\fclose($lock);
            }
            throw new \RuntimeException('Unable to lock ACME HTTP-01 desired state.');
        }
        @\chmod($lockFile, 0600);
        try {
            return $callback();
        } finally {
            @\flock($lock, LOCK_UN);
            @\fclose($lock);
        }
    }

    private function resolvedDirectory(): string
    {
        $directory = $this->directory ?? (
            \rtrim(\defined('BP') ? BP : \dirname(__DIR__, 7), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'generated'
            . DIRECTORY_SEPARATOR . 'acme-http01'
        );
        $directory = \rtrim($directory, DIRECTORY_SEPARATOR);
        if ($directory === '' || \is_link($directory)) {
            throw new \RuntimeException('ACME HTTP-01 desired directory is unsafe.');
        }
        if (!\is_dir($directory)
            && !@\mkdir($directory, 0700, true)
            && !\is_dir($directory)
        ) {
            throw new \RuntimeException('Unable to create ACME HTTP-01 desired directory.');
        }
        @\chmod($directory, 0700);
        return $directory;
    }

    private function stateFile(): string
    {
        return $this->resolvedDirectory() . DIRECTORY_SEPARATOR . self::STATE_FILE;
    }

    private function legacyFile(string $domain): string
    {
        return $this->resolvedDirectory()
            . DIRECTORY_SEPARATOR
            . $this->domainFilename($domain)
            . '.json';
    }

    private function domainFilename(string $domain): string
    {
        $filename = \preg_replace(
            '/[^a-z0-9_]/',
            '',
            \str_replace('.', '_', \strtolower($domain)),
        );
        return $filename !== '' ? $filename : 'default';
    }

    private function normalizeExactDomain(string $domain): string
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
}
