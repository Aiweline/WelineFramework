<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Query\Store;

use Weline\Framework\Service\Query\FrontendQueryException;

/**
 * Compatibility adapter over the existing local/snapshot state shape.
 *
 * Existing top-level key names and SHA-256 credential indexes remain readable.
 * New nonce indexes are type/scope bound so the raw nonce is no longer written.
 */
final class ArrayFrontendWorkerCredentialTransaction implements FrontendWorkerCredentialTransactionInterface
{
    private const ENCRYPTED_NONCE_BYTES = 24;
    private const ENCRYPTED_AUTH_TAG_BYTES = 16;
    private const TYPE_BUCKETS = [
        FrontendWorkerCredentialType::SESSION => 'weline_frontend_worker_sessions',
        FrontendWorkerCredentialType::NONCE => 'weline_frontend_worker_nonces',
        FrontendWorkerCredentialType::SCOPE_BOOTSTRAP => 'weline_frontend_worker_scope_bootstraps',
        FrontendWorkerCredentialType::BACKEND_BOOTSTRAP => 'weline_frontend_worker_backend_bootstraps',
        FrontendWorkerCredentialType::STREAM_TICKET => 'weline_frontend_worker_stream_tickets',
    ];

    /** @var array<string, mixed> */
    private array $store;

    /** @param array<string, mixed> $store */
    public function __construct(array &$store)
    {
        $this->store =& $store;
    }

    public function now(): int
    {
        return \time();
    }

    public function find(
        string $type,
        string $credential,
        ?string $scope,
        int $now,
    ): ?array {
        $bucket = $this->bucket($type);
        if ($type === FrontendWorkerCredentialType::NONCE) {
            $scopeHash = $this->scopeHash($scope);
            $entries = \is_array($bucket[$scopeHash] ?? null) ? $bucket[$scopeHash] : [];
            $value = $entries[$this->credentialHash($type, $credential, $scopeHash)]
                ?? $entries[$credential]
                ?? null;
            if (\is_int($value)) {
                return $value > $now ? ['expires_at' => $value] : null;
            }
            return $this->activePayload($value, $now);
        }

        $legacyHash = \hash('sha256', $credential);
        $value = $bucket[$legacyHash]
            ?? $bucket[$this->credentialHash($type, $credential, '')]
            ?? null;
        return $this->activePayload($value, $now);
    }

    public function insert(
        string $type,
        string $credential,
        ?string $scope,
        array $payload,
        int $createdAt,
        int $expiresAt,
    ): void {
        if ($credential === '' || $expiresAt < 1 || $expiresAt <= $createdAt) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential record is invalid.',
                503,
            );
        }

        $byteLimit = FrontendWorkerCredentialType::retainedByteLimit($type);
        if ($byteLimit !== null
            && $this->retainedBytes($type, $scope, $createdAt) + $this->encodedPayloadBytes($payload) > $byteLimit) {
            throw new FrontendQueryException(
                'worker_capacity_exhausted',
                'Worker stream ticket storage capacity is exhausted.',
                503,
            );
        }

        $bucketKey = self::TYPE_BUCKETS[$this->validatedType($type)];
        $bucket = $this->bucket($type);
        if ($type === FrontendWorkerCredentialType::NONCE) {
            $scopeHash = $this->scopeHash($scope);
            $entries = \is_array($bucket[$scopeHash] ?? null) ? $bucket[$scopeHash] : [];
            $key = $this->credentialHash($type, $credential, $scopeHash);
            if (\array_key_exists($key, $entries) || \array_key_exists($credential, $entries)) {
                throw new FrontendQueryException('auth_error', 'Worker nonce has already been used.', 401);
            }
            $entries[$key] = $expiresAt;
            $bucket[$scopeHash] = $entries;
            $this->store[$bucketKey] = $bucket;
            return;
        }

        if ($scope !== null) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential scope is invalid.',
                503,
            );
        }
        $key = \hash('sha256', $credential);
        if (\array_key_exists($key, $bucket)) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential identity collision.',
                503,
            );
        }
        $bucket[$key] = $payload;
        $this->store[$bucketKey] = $bucket;
    }

    public function replaceActive(
        string $type,
        string $credential,
        ?string $scope,
        array $payload,
        int $expiresAt,
    ): void {
        if ($credential === '' || $expiresAt < 1) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential record is invalid.',
                503,
            );
        }
        if ($type === FrontendWorkerCredentialType::NONCE || $scope !== null) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential replace target is invalid.',
                503,
            );
        }
        $now = $this->now();
        $existing = $this->find($type, $credential, $scope, $now);
        if ($existing === null) {
            throw new FrontendQueryException('auth_error', 'Invalid worker session token.', 401);
        }
        $payload['expires_at'] = $expiresAt;
        $bucketKey = self::TYPE_BUCKETS[$this->validatedType($type)];
        $bucket = $this->bucket($type);
        $key = \hash('sha256', $credential);
        if (!\array_key_exists($key, $bucket)) {
            $key = $this->credentialHash($type, $credential, '');
        }
        if (!\array_key_exists($key, $bucket)) {
            throw new FrontendQueryException('auth_error', 'Invalid worker session token.', 401);
        }
        $bucket[$key] = $payload;
        $this->store[$bucketKey] = $bucket;
    }

    public function consume(string $type, string $credential, ?string $scope): bool
    {
        $bucketKey = self::TYPE_BUCKETS[$this->validatedType($type)];
        $bucket = $this->bucket($type);
        if ($type === FrontendWorkerCredentialType::NONCE) {
            $scopeHash = $this->scopeHash($scope);
            $entries = \is_array($bucket[$scopeHash] ?? null) ? $bucket[$scopeHash] : [];
            $keys = [$this->credentialHash($type, $credential, $scopeHash), $credential];
            $removed = false;
            foreach ($keys as $key) {
                if (\array_key_exists($key, $entries)) {
                    unset($entries[$key]);
                    $removed = true;
                }
            }
            if ($entries === []) {
                unset($bucket[$scopeHash]);
            } else {
                $bucket[$scopeHash] = $entries;
            }
            $this->store[$bucketKey] = $bucket;
            return $removed;
        }

        $keys = [
            \hash('sha256', $credential),
            $this->credentialHash($type, $credential, ''),
        ];
        $removed = false;
        foreach ($keys as $key) {
            if (\array_key_exists($key, $bucket)) {
                unset($bucket[$key]);
                $removed = true;
            }
        }
        $this->store[$bucketKey] = $bucket;
        return $removed;
    }

    public function countRetained(string $type, ?string $scope, int $now): int
    {
        $bucket = $this->bucket($type);
        if ($type === FrontendWorkerCredentialType::NONCE) {
            $scopeHash = $this->scopeHash($scope);
            $bucket = \is_array($bucket[$scopeHash] ?? null) ? $bucket[$scopeHash] : [];
        } elseif ($scope !== null) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential scope is invalid.',
                503,
            );
        }

        $count = 0;
        foreach ($bucket as $value) {
            if (\is_int($value) ? $value > $now : $this->activePayload($value, $now) !== null) {
                $count++;
            }
        }
        return $count;
    }

    public function countRetainedInCapacityBucket(string $type, ?string $scope, int $now): int
    {
        if ($type !== FrontendWorkerCredentialType::NONCE) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential capacity bucket is invalid.',
                503,
            );
        }
        $scopeHash = $this->scopeHash($scope);
        $shard = \substr($scopeHash, 0, 1);
        if ($this->find(FrontendWorkerCredentialType::SESSION, (string)$scope, null, $now) === null) {
            throw new FrontendQueryException('auth_error', 'Invalid worker session token.', 401);
        }
        $bucketKey = self::TYPE_BUCKETS[$type];
        $bucket = $this->bucket($type);
        $count = 0;
        foreach ($bucket as $candidateScopeHash => $entries) {
            if (!\is_string($candidateScopeHash)
                || !\str_starts_with($candidateScopeHash, $shard)
                || !\is_array($entries)) {
                continue;
            }
            foreach ($entries as $key => $value) {
                $expiresAt = \is_int($value)
                    ? $value
                    : (\is_array($value) ? (int)($value['expires_at'] ?? 0) : 0);
                if ($expiresAt <= $now) {
                    unset($entries[$key]);
                    continue;
                }
                if (\is_int($value) ? $value > $now : $this->activePayload($value, $now) !== null) {
                    $count++;
                }
            }
            if ($entries === []) {
                unset($bucket[$candidateScopeHash]);
            } else {
                $bucket[$candidateScopeHash] = $entries;
            }
        }
        $this->store[$bucketKey] = $bucket;
        return $count;
    }

    public function retainedBytes(string $type, ?string $scope, int $now): int
    {
        $bucket = $this->bucket($type);
        if ($type === FrontendWorkerCredentialType::NONCE) {
            $scopeHash = $this->scopeHash($scope);
            $bucket = \is_array($bucket[$scopeHash] ?? null) ? $bucket[$scopeHash] : [];
        } elseif ($scope !== null) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential scope is invalid.',
                503,
            );
        }

        $bytes = 0;
        foreach ($bucket as $value) {
            $payload = \is_int($value)
                ? ($value > $now ? ['expires_at' => $value] : null)
                : $this->activePayload($value, $now);
            if (\is_array($payload)) {
                $bytes += $this->encodedPayloadBytes($payload);
            }
        }
        return $bytes;
    }

    public function deleteExpired(int $now, ?string $type = null, ?string $scope = null): void
    {
        $types = $type === null ? FrontendWorkerCredentialType::all() : [$this->validatedType($type)];
        foreach ($types as $currentType) {
            $bucketKey = self::TYPE_BUCKETS[$currentType];
            $bucket = $this->bucket($currentType);
            if ($currentType === FrontendWorkerCredentialType::NONCE) {
                $scopeHashes = $scope === null ? \array_keys($bucket) : [$this->scopeHash($scope)];
                foreach ($scopeHashes as $scopeHash) {
                    $entries = \is_array($bucket[$scopeHash] ?? null) ? $bucket[$scopeHash] : [];
                    foreach ($entries as $key => $value) {
                        $expiresAt = \is_int($value)
                            ? $value
                            : (\is_array($value) ? (int)($value['expires_at'] ?? 0) : 0);
                        if ($expiresAt <= $now) {
                            unset($entries[$key]);
                        }
                    }
                    if ($entries === []) {
                        unset($bucket[$scopeHash]);
                    } else {
                        $bucket[$scopeHash] = $entries;
                    }
                }
                $this->store[$bucketKey] = $bucket;
                continue;
            }
            if ($scope !== null) {
                throw new FrontendQueryException(
                    'worker_store_unavailable',
                    'Worker credential scope is invalid.',
                    503,
                );
            }
            foreach ($bucket as $key => $value) {
                if ($this->activePayload($value, $now) === null) {
                    unset($bucket[$key]);
                }
            }
            $this->store[$bucketKey] = $bucket;
        }
    }

    /** @return array<string, mixed> */
    private function bucket(string $type): array
    {
        $key = self::TYPE_BUCKETS[$this->validatedType($type)];
        $bucket = $this->store[$key] ?? [];
        return \is_array($bucket) ? $bucket : [];
    }

    private function validatedType(string $type): string
    {
        FrontendWorkerCredentialType::assert($type);
        return $type;
    }

    private function scopeHash(?string $scope): string
    {
        if (!\is_string($scope) || $scope === '') {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker nonce scope is invalid.',
                503,
            );
        }
        return \hash('sha256', $scope);
    }

    private function credentialHash(string $type, string $credential, string $scopeHash): string
    {
        return \hash('sha256', $type . "\0" . $scopeHash . "\0" . $credential);
    }

    /** @param array<string, mixed> $payload */
    private function encodedPayloadBytes(array $payload): int
    {
        try {
            $json = \json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (\Throwable $exception) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker credential payload is not serializable.',
                503,
                $exception,
            );
        }
        $sealedBytes = \strlen($json)
            + self::ENCRYPTED_NONCE_BYTES
            + self::ENCRYPTED_AUTH_TAG_BYTES;
        return \intdiv($sealedBytes * 4 + 2, 3);
    }

    /** @return array<string, mixed>|null */
    private function activePayload(mixed $value, int $now): ?array
    {
        if (!\is_array($value) || (int)($value['expires_at'] ?? 0) <= $now) {
            return null;
        }
        return $value;
    }
}
