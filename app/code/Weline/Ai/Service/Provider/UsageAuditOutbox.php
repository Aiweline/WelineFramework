<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Provider;

use Weline\Ai\Model\Provider\UsageRecord;
use Weline\Framework\App\Env;

/**
 * Filesystem fallback for provider usage writes that cannot reach the database.
 *
 * The outbox deliberately stores only metering/audit scalars. Provider prompts,
 * responses, credentials and model configuration never enter this directory.
 * Fixed lock shards plus atomic rename make one shared runtime var directory
 * safe for concurrent WLS and Scheduler processes without unbounded lock files.
 */
final class UsageAuditOutbox
{
    private const SCHEMA = 'weline.ai.provider_usage_outbox.v1';
    private const MAX_RECOVERY_ATTEMPTS = 10;
    private const LOCK_SHARDS = 16;
    private const LEGACY_LOCK_RETENTION_SECONDS = 604800;
    private const LEGACY_LOCK_CLEANUP_LIMIT = 32;

    /** @var list<string> */
    private const PAYLOAD_FIELDS = [
        UsageRecord::schema_fields_ACCOUNT_ID,
        UsageRecord::schema_fields_PROVIDER_CODE,
        UsageRecord::schema_fields_MODEL_CODE,
        UsageRecord::schema_fields_MODEL_NAME,
        UsageRecord::schema_fields_REQUEST_ID,
        UsageRecord::schema_fields_REQUEST_TYPE,
        UsageRecord::schema_fields_PROMPT_TOKENS,
        UsageRecord::schema_fields_COMPLETION_TOKENS,
        UsageRecord::schema_fields_TOTAL_TOKENS,
        UsageRecord::schema_fields_INPUT_COST,
        UsageRecord::schema_fields_OUTPUT_COST,
        UsageRecord::schema_fields_TOTAL_COST,
        UsageRecord::schema_fields_CURRENCY,
        UsageRecord::schema_fields_REQUEST_TIME,
        UsageRecord::schema_fields_STATUS,
        UsageRecord::schema_fields_CREATED_AT,
    ];

    private readonly string $directory;

    public function __construct(?string $directory = null)
    {
        $base = $directory ?? (Env::VAR_DIR . 'ai' . DS . 'provider-usage-outbox');
        $this->directory = rtrim($base, '/\\');
    }

    public function defer(array $payload, \Throwable $failure): string
    {
        $payload = $this->normalizePayload($payload);
        $requestId = (string)$payload[UsageRecord::schema_fields_REQUEST_ID];
        $payloadHash = $this->payloadHash($payload);
        $identityHash = $this->identityHash($payload);
        $path = $this->pendingPath($requestId);
        $lock = $this->openRequestLock($requestId);

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Unable to lock AI provider usage outbox request.');
            }
            if (is_file($path)) {
                $existing = $this->readEnvelope($path);
                $existingPayload = $this->normalizePayload(
                    is_array($existing['payload'] ?? null) ? $existing['payload'] : [],
                );
                $this->assertEnvelopeIdentity($path, $existing, $existingPayload);
                $existingIdentityHash = (string)$existing['identity_sha256'];
                if (!hash_equals($existingIdentityHash, $identityHash)) {
                    throw new \RuntimeException('AI provider usage outbox request payload conflict.');
                }

                return $path;
            }

            $now = time();
            $envelope = [
                'schema' => self::SCHEMA,
                'request_key_sha256' => hash('sha256', $requestId),
                'payload_sha256' => $payloadHash,
                'identity_sha256' => $identityHash,
                'payload' => $payload,
                'deferred_at' => $now,
                'updated_at' => $now,
                'attempt_count' => 0,
                'last_failure' => $this->safeFailure($failure),
            ];
            $this->atomicWrite($path, $envelope);

            return $path;
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    public function acknowledge(array $payload): void
    {
        $payload = $this->normalizePayload($payload);
        $requestId = (string)$payload[UsageRecord::schema_fields_REQUEST_ID];
        $path = $this->pendingPath($requestId);
        if (!is_file($path)) {
            return;
        }
        $lock = $this->openRequestLock($requestId);

        try {
            if (!flock($lock, LOCK_EX)) {
                return;
            }
            if (!is_file($path)) {
                return;
            }
            $existing = $this->readEnvelope($path);
            $existingPayload = $this->normalizePayload(
                is_array($existing['payload'] ?? null) ? $existing['payload'] : [],
            );
            $this->assertEnvelopeIdentity($path, $existing, $existingPayload);
            $existingIdentityHash = (string)$existing['identity_sha256'];
            if (hash_equals(
                $existingIdentityHash,
                $this->identityHash($payload),
            )) {
                unlink($path);
            }
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * @param callable(array<string,mixed>):void $consumer
     * @return array{recovered:int,failed:int,dead:int,skipped:int}
     */
    public function recover(callable $consumer, int $limit = 50): array
    {
        $result = ['recovered' => 0, 'failed' => 0, 'dead' => 0, 'skipped' => 0];
        $limit = max(1, min(500, $limit));
        $paths = glob($this->directory . DS . '*.pending.json') ?: [];
        sort($paths, SORT_STRING);

        foreach (array_slice($paths, 0, $limit) as $path) {
            // Never carry a successfully decoded envelope into the next file.
            $envelope = null;
            $envelopeValid = false;
            $requestHash = substr(basename($path), 0, -strlen('.pending.json'));
            $lock = $this->openHashedRequestLock($requestHash);
            try {
                if (!flock($lock, LOCK_EX | LOCK_NB)) {
                    ++$result['skipped'];
                    continue;
                }
                if (!is_file($path)) {
                    ++$result['skipped'];
                    continue;
                }

                try {
                    $envelope = $this->readEnvelope($path);
                    $payload = $this->normalizePayload(
                        is_array($envelope['payload'] ?? null) ? $envelope['payload'] : [],
                    );
                    $this->assertEnvelopeIdentity($path, $envelope, $payload);
                    $envelopeValid = true;
                    $consumer($payload);
                    if (!unlink($path)) {
                        throw new \RuntimeException('Unable to acknowledge AI provider usage outbox event.');
                    }
                    ++$result['recovered'];
                } catch (\Throwable $failure) {
                    ++$result['failed'];
                    if (!$envelopeValid || !is_array($envelope)) {
                        if ($this->quarantineCorrupt($path)) {
                            ++$result['dead'];
                        } else {
                            ++$result['skipped'];
                        }
                        continue;
                    }

                    $attempts = (int)($envelope['attempt_count'] ?? 0) + 1;
                    $envelope['attempt_count'] = $attempts;
                    $envelope['updated_at'] = time();
                    $envelope['last_failure'] = $this->safeFailure($failure);
                    if ($attempts >= self::MAX_RECOVERY_ATTEMPTS) {
                        try {
                            // Persist the terminal attempt first, then make one
                            // same-filesystem atomic transition. A failed move
                            // leaves the bounded, auditable pending envelope in
                            // place for a later recovery run.
                            $this->atomicWrite($path, $envelope);
                            if ($this->movePendingToDead($path)) {
                                ++$result['dead'];
                            } else {
                                ++$result['skipped'];
                            }
                        } catch (\Throwable) {
                            ++$result['skipped'];
                        }
                    } else {
                        $this->atomicWrite($path, $envelope);
                    }
                }
            } finally {
                if (is_resource($lock)) {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
            }
        }
        $this->pruneLegacyRequestLocks();

        return $result;
    }

    public function pendingCount(): int
    {
        return count(glob($this->directory . DS . '*.pending.json') ?: []);
    }

    private function movePendingToDead(string $pendingPath): bool
    {
        $deadPath = substr($pendingPath, 0, -strlen('.pending.json')) . '.dead.json';
        if (!@rename($pendingPath, $deadPath)) {
            return false;
        }
        clearstatcache(true, $pendingPath);
        clearstatcache(true, $deadPath);

        return !is_file($pendingPath) && is_file($deadPath);
    }

    /** @return array<string,mixed> */
    private function normalizePayload(array $payload): array
    {
        $normalized = [];
        foreach (self::PAYLOAD_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                $value = $payload[$field];
                if (!is_scalar($value) && $value !== null) {
                    throw new \InvalidArgumentException('AI provider usage outbox payload must be scalar.');
                }
                $normalized[$field] = $value;
            }
        }

        $requestId = trim((string)($normalized[UsageRecord::schema_fields_REQUEST_ID] ?? ''));
        if ($requestId === '' || strlen($requestId) > 100) {
            throw new \InvalidArgumentException('AI provider usage outbox request_id is invalid.');
        }
        if ((int)($normalized[UsageRecord::schema_fields_ACCOUNT_ID] ?? 0) < 1) {
            throw new \InvalidArgumentException('AI provider usage outbox account_id is invalid.');
        }
        if (trim((string)($normalized[UsageRecord::schema_fields_MODEL_CODE] ?? '')) === '') {
            throw new \InvalidArgumentException('AI provider usage outbox model_code is invalid.');
        }
        $status = (string)($normalized[UsageRecord::schema_fields_STATUS] ?? '');
        if (!in_array($status, ['success', 'failed'], true)) {
            throw new \InvalidArgumentException('AI provider usage outbox status is invalid.');
        }
        $normalized[UsageRecord::schema_fields_REQUEST_ID] = $requestId;

        return $normalized;
    }

    /** @return array<string,mixed> */
    private function readEnvelope(string $path): array
    {
        $raw = file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded) || ($decoded['schema'] ?? '') !== self::SCHEMA) {
            throw new \UnexpectedValueException('AI provider usage outbox envelope is invalid.');
        }

        return $decoded;
    }

    /**
     * @param array<string,mixed> $envelope
     * @param array<string,mixed> $payload
     */
    private function assertEnvelopeIdentity(string $path, array $envelope, array $payload): void
    {
        $filenameHash = substr(basename($path), 0, -strlen('.pending.json'));
        $requestIdHash = hash(
            'sha256',
            (string)$payload[UsageRecord::schema_fields_REQUEST_ID],
        );
        $envelopeRequestHash = (string)($envelope['request_key_sha256'] ?? '');
        if (
            preg_match('/^[a-f0-9]{64}$/', $filenameHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $envelopeRequestHash) !== 1
            || !hash_equals($filenameHash, $envelopeRequestHash)
            || !hash_equals($requestIdHash, $envelopeRequestHash)
        ) {
            throw new \UnexpectedValueException('AI provider usage outbox request identity mismatch.');
        }

        $payloadHash = (string)($envelope['payload_sha256'] ?? '');
        if (
            preg_match('/^[a-f0-9]{64}$/', $payloadHash) !== 1
            || !hash_equals($payloadHash, $this->payloadHash($payload))
        ) {
            throw new \UnexpectedValueException('AI provider usage outbox payload checksum mismatch.');
        }
        $identityHash = (string)($envelope['identity_sha256'] ?? '');
        if (
            preg_match('/^[a-f0-9]{64}$/', $identityHash) !== 1
            || !hash_equals($identityHash, $this->identityHash($payload))
        ) {
            throw new \UnexpectedValueException('AI provider usage outbox identity checksum mismatch.');
        }
    }

    /** @param array<string,mixed> $envelope */
    private function atomicWrite(string $path, array $envelope): void
    {
        $this->ensureDirectory();
        $json = json_encode(
            $envelope,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
        $temporary = $path . '.tmp.' . bin2hex(random_bytes(8));
        try {
            if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) {
                throw new \RuntimeException('Unable to persist AI provider usage outbox event.');
            }
            chmod($temporary, 0660);
            if (!rename($temporary, $path)) {
                throw new \RuntimeException('Unable to publish AI provider usage outbox event.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** @return resource */
    private function openRequestLock(string $requestId)
    {
        return $this->openHashedRequestLock(hash('sha256', $requestId));
    }

    /** @return resource */
    private function openHashedRequestLock(string $requestHash)
    {
        $this->ensureDirectory();
        $requestHash = strtolower($requestHash);
        if (preg_match('/^[a-f0-9]{64}$/', $requestHash) !== 1) {
            $requestHash = hash('sha256', $requestHash);
        }
        $shard = dechex(hexdec($requestHash[0]) % self::LOCK_SHARDS);
        $handle = fopen(
            $this->directory . DS . '.usage-audit-lock-shard-' . $shard . '.lock',
            'c+b',
        );
        if (!is_resource($handle)) {
            throw new \RuntimeException('Unable to open AI provider usage outbox lock.');
        }

        return $handle;
    }

    private function pendingPath(string $requestId): string
    {
        $this->ensureDirectory();
        return $this->directory . DS . hash('sha256', $requestId) . '.pending.json';
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0770, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Unable to create AI provider usage outbox directory.');
        }
    }

    /** @param array<string,mixed> $payload */
    private function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /** @param array<string,mixed> $payload */
    private function identityHash(array $payload): string
    {
        unset(
            $payload[UsageRecord::schema_fields_REQUEST_TIME],
            $payload[UsageRecord::schema_fields_CREATED_AT],
        );

        return $this->payloadHash($payload);
    }

    /** @return array{class:string,category:string,message_sha256:string} */
    private function safeFailure(\Throwable $failure): array
    {
        $message = strtolower($failure->getMessage());
        $category = match (true) {
            str_contains($message, 'database is locked'),
            str_contains($message, 'database table is locked'),
            str_contains($message, 'lock wait timeout'),
            str_contains($message, 'deadlock') => 'transient_lock',
            str_contains($message, 'checksum'),
            str_contains($message, 'identity mismatch'),
            str_contains($message, 'envelope is invalid') => 'integrity',
            str_contains($message, 'permission denied'),
            str_contains($message, 'read-only'),
            str_contains($message, 'readonly') => 'storage_permission',
            default => 'unexpected',
        };

        return [
            'class' => $failure::class,
            'category' => $category,
            'message_sha256' => hash('sha256', $failure->getMessage()),
        ];
    }

    private function quarantineCorrupt(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }
        $deadPath = substr($path, 0, -strlen('.pending.json'))
            . '.corrupt.'
            . time()
            . '.'
            . bin2hex(random_bytes(4))
            . '.dead.json';

        return rename($path, $deadPath);
    }

    /**
     * Older releases created one lock inode per request. New work uses sixteen
     * stable shards. This conservative sweep only touches week-old, inactive
     * legacy names, acquires each inode non-blockingly and verifies that the
     * locked inode is still the path being removed. Work is bounded per run.
     */
    private function pruneLegacyRequestLocks(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }
        $cleanupPath = $this->directory . DS . '.usage-audit-legacy-cleanup.lock';
        $cleanup = fopen($cleanupPath, 'c+b');
        if (!is_resource($cleanup)) {
            return;
        }
        try {
            if (!flock($cleanup, LOCK_EX | LOCK_NB)) {
                return;
            }
            $cutoff = time() - self::LEGACY_LOCK_RETENTION_SECONDS;
            $examined = 0;
            $cleaned = 0;
            foreach (new \FilesystemIterator(
                $this->directory,
                \FilesystemIterator::SKIP_DOTS,
            ) as $entry) {
                if (++$examined > 256 || $cleaned >= self::LEGACY_LOCK_CLEANUP_LIMIT) {
                    break;
                }
                $name = $entry->getFilename();
                if (
                    preg_match('/^([a-f0-9]{64})\.lock$/', $name, $matches) !== 1
                    || !$entry->isFile()
                    || $entry->getMTime() > $cutoff
                    || is_file($this->directory . DS . $matches[1] . '.pending.json')
                ) {
                    continue;
                }
                $path = $entry->getPathname();
                $legacy = fopen($path, 'c+b');
                if (!is_resource($legacy)) {
                    continue;
                }
                try {
                    if (!flock($legacy, LOCK_EX | LOCK_NB)) {
                        continue;
                    }
                    $lockedStat = fstat($legacy);
                    $pathStat = lstat($path);
                    if (
                        !is_array($lockedStat)
                        || !is_array($pathStat)
                        || ($lockedStat['dev'] ?? null) !== ($pathStat['dev'] ?? null)
                        || ($lockedStat['ino'] ?? null) !== ($pathStat['ino'] ?? null)
                        || (int)($pathStat['mtime'] ?? time()) > $cutoff
                    ) {
                        continue;
                    }
                    if (unlink($path)) {
                        ++$cleaned;
                    }
                } finally {
                    flock($legacy, LOCK_UN);
                    fclose($legacy);
                }
            }
        } catch (\UnexpectedValueException) {
            // Directory disappeared between the initial check and iteration.
        } finally {
            flock($cleanup, LOCK_UN);
            fclose($cleanup);
        }
    }
}
