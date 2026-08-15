<?php

declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\App\Env;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;

class SharedStateServiceRegistry
{
    private const REGISTRY_DIR = 'server' . DIRECTORY_SEPARATOR . 'shared-services' . DIRECTORY_SEPARATOR;
    private const REGISTRY_SCHEMA = 'wls-shared-registry/2';
    private const LIFECYCLE_SCHEMA = 'wls-shared-lifecycle/2';
    private const MAX_REGISTRY_BYTES = 4 * 1024 * 1024;
    private const MAX_SAFE_GENERATION = 9_007_199_254_740_991;
    private const READ_RACE_MAX_ATTEMPTS = 3;
    private const READ_RACE_RETRY_DELAY_MICROSECONDS = 1_000;

    /**
     * @return array<string, mixed>
     */
    public function getRecord(string $role): array
    {
        $role = $this->normalizeRole($role);
        $data = $this->readData();
        $record = $data['services'][$role] ?? [];

        return \is_array($record) ? $record : [];
    }

    /**
     * @param array<string, mixed> $record
     */
    public function putRecord(string $role, array $record): void
    {
        $role = $this->normalizeRole($role);
        $file = $this->getRegistryFile();

        $published = ServerInstanceManager::updateValidatedJsonFileAtomically(
            $file,
            static function (array $data) use ($role, $record): array {
                $services = \is_array($data['services'] ?? null) ? $data['services'] : [];
                $previous = \is_array($services[$role] ?? null)
                    ? $services[$role]
                    : [];
                $services[$role] = self::bindLifecycleGeneration(
                    $role,
                    $record,
                    $previous,
                );

                return self::nextRegistryDocument($data, $services);
            },
            self::registryRecoveryValidator(),
            'WLS shared-state service registry',
            self::MAX_REGISTRY_BYTES,
        );
        if (!$published) {
            throw new \RuntimeException(
                'Failed to atomically publish shared-state service registry.'
            );
        }
    }

    /**
     * @param callable(array<string, mixed>):array<string, mixed> $updater
     * @return array<string, mixed>
     */
    public function updateRecord(string $role, callable $updater): array
    {
        $role = $this->normalizeRole($role);
        $updatedRecord = $this->updateRecordWithin($role, $updater, 5.0);
        if ($updatedRecord === null) {
            throw new \RuntimeException(
                'Failed to atomically publish shared-state service registry.'
            );
        }

        return $updatedRecord;
    }

    public function removeRecord(string $role): void
    {
        $role = $this->normalizeRole($role);
        $file = $this->getRegistryFile();
        $callerPid = \getmypid();

        $published = ServerInstanceManager::updateValidatedJsonFileAtomically(
            $file,
            static function (array $data) use ($role, $callerPid): array {
                $services = \is_array($data['services'] ?? null) ? $data['services'] : [];
                $record = \is_array($services[$role] ?? null) ? $services[$role] : [];
                $recordPid = (int)($record['pid'] ?? 0);
                $generation = (int)($record['lifecycle_generation'] ?? 0);
                if ($record !== []
                    && $generation > 0
                    && $recordPid > 0
                    && $recordPid !== $callerPid
                ) {
                    // A sidecar may finish after its replacement has already
                    // published. An unqualified self-cleanup can only remove
                    // the generation owned by this exact process.
                    return $data;
                }
                unset($services[$role]);

                return self::nextRegistryDocument($data, $services);
            },
            self::registryRecoveryValidator(),
            'WLS shared-state service registry',
            self::MAX_REGISTRY_BYTES,
        );
        if (!$published) {
            throw new \RuntimeException(
                'Failed to atomically publish shared-state service registry.'
            );
        }
    }

    public function removeRecordIfGeneration(
        string $role,
        int $expectedGeneration,
        string $expectedIdentityDigest,
    ): bool {
        $role = $this->normalizeRole($role);
        if ($expectedGeneration < 1
            || $expectedGeneration > self::MAX_SAFE_GENERATION
            || \preg_match('/\A[a-f0-9]{64}\z/D', $expectedIdentityDigest) !== 1
        ) {
            return false;
        }
        $removed = false;
        $published = ServerInstanceManager::updateValidatedJsonFileAtomically(
            $this->getRegistryFile(),
            static function (array $data) use (
                $role,
                $expectedGeneration,
                $expectedIdentityDigest,
                &$removed,
            ): array {
                $services = \is_array($data['services'] ?? null) ? $data['services'] : [];
                $record = \is_array($services[$role] ?? null) ? $services[$role] : [];
                if ((int)($record['lifecycle_generation'] ?? 0) !== $expectedGeneration
                    || !\hash_equals(
                        (string)($record['lifecycle_identity_digest'] ?? ''),
                        $expectedIdentityDigest,
                    )
                ) {
                    return $data;
                }
                unset($services[$role]);
                $removed = true;
                return self::nextRegistryDocument($data, $services);
            },
            self::registryRecoveryValidator(),
            'WLS shared-state service registry',
            self::MAX_REGISTRY_BYTES,
        );
        if (!$published) {
            throw new \RuntimeException(
                'Failed to atomically publish shared-state service registry.'
            );
        }
        return $removed;
    }

    public function touchConsumer(string $role, string $instanceName): void
    {
        $instanceName = \trim($instanceName);
        if ($instanceName === '') {
            return;
        }

        $this->upsertConsumer($role, $instanceName, [
            'consumer_code' => $instanceName,
            'owner_type' => 'instance',
            'last_ensured_at' => \date('c'),
        ]);
    }

    /**
     * Best-effort heartbeat publication. Unlike the lifecycle mutation APIs,
     * this path has a caller-supplied lock budget and reports contention
     * instead of waiting for the normal five-second registry transaction.
     */
    public function touchConsumerIfAvailable(
        string $role,
        string $instanceName,
        float $timeoutSeconds,
    ): bool {
        $role = $this->normalizeRole($role);
        $instanceName = \trim($instanceName);
        if ($instanceName === '') {
            return false;
        }
        if (!\is_finite($timeoutSeconds) || $timeoutSeconds <= 0.0) {
            return false;
        }

        $now = \date('c');
        return $this->updateRecordWithoutRecoveryWithin(
            $role,
            static function (array $record) use ($instanceName, $now): array {
                $consumers = self::normalizeConsumersArray($record['consumers'] ?? []);
                $existing = \is_array($consumers[$instanceName] ?? null)
                    ? $consumers[$instanceName]
                    : [];
                $existing['consumer_code'] = $instanceName;
                $existing['owner_type'] = 'instance';
                $existing['last_ensured_at'] = $now;
                $existing['last_seen_at'] = $now;
                if (!\array_key_exists('lease_expires_at', $existing)) {
                    $existing['lease_expires_at'] = null;
                }
                $consumers[$instanceName] = $existing;
                $record['consumers'] = $consumers;
                $record['last_ensured_by_instance'] = $instanceName;
                $record['last_ensured_at'] = $now;
                unset($record['shutdown_due_at'], $record['shutdown_requested_at']);
                return $record;
            },
            $timeoutSeconds,
        ) !== null;
    }

    public function releaseConsumer(string $role, string $instanceName): void
    {
        $instanceName = \trim($instanceName);
        if ($instanceName === '') {
            return;
        }

        $this->removeConsumer($role, $instanceName);
    }

    /**
     * @param array<string, mixed> $consumer
     * @return array<string, mixed>
     */
    public function upsertConsumer(string $role, string $consumerCode, array $consumer = []): array
    {
        $role = $this->normalizeRole($role);
        $consumerCode = \trim($consumerCode);
        if ($consumerCode === '') {
            return $this->getRecord($role);
        }

        $now = \date('c');

        return $this->updateRecord($role, static function (array $record) use ($consumerCode, $consumer, $now): array {
            $consumers = self::normalizeConsumersArray($record['consumers'] ?? []);
            $existing = \is_array($consumers[$consumerCode] ?? null) ? $consumers[$consumerCode] : [];

            $payload = \array_merge($existing, $consumer);
            $payload['consumer_code'] = $consumerCode;
            $payload['owner_type'] = \trim((string) ($payload['owner_type'] ?? 'instance')) ?: 'instance';
            $payload['last_seen_at'] = (string) ($payload['last_seen_at'] ?? $payload['last_ensured_at'] ?? $now);

            if (!\array_key_exists('lease_expires_at', $payload)) {
                $payload['lease_expires_at'] = null;
            }

            $consumers[$consumerCode] = $payload;
            $record['consumers'] = $consumers;
            $record['last_ensured_by_instance'] = $consumerCode;
            $record['last_ensured_at'] = $now;
            unset($record['shutdown_due_at']);
            unset($record['shutdown_requested_at']);

            return $record;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function removeConsumer(string $role, string $consumerCode): array
    {
        $role = $this->normalizeRole($role);
        $consumerCode = \trim($consumerCode);
        if ($consumerCode === '') {
            return $this->getRecord($role);
        }

        return $this->updateRecord($role, static function (array $record) use ($consumerCode): array {
            $consumers = self::normalizeConsumersArray($record['consumers'] ?? []);
            unset($consumers[$consumerCode]);
            $record['consumers'] = $consumers;

            return $record;
        });
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getConsumers(string $role): array
    {
        $record = $this->getRecord($role);

        return self::normalizeConsumersArray($record['consumers'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function setShutdownDueAt(string $role, ?string $dueAt): array
    {
        $role = $this->normalizeRole($role);

        return $this->updateRecord($role, static function (array $record) use ($dueAt): array {
            if ($dueAt === null || \trim($dueAt) === '') {
                unset($record['shutdown_due_at']);
                unset($record['shutdown_requested_at']);
            } else {
                $record['shutdown_due_at'] = $dueAt;
                $record['shutdown_requested_at'] = \date('c');
            }

            return $record;
        });
    }

    public function getRegistryFile(): string
    {
        return Env::VAR_DIR . self::REGISTRY_DIR
            . SharedStateRuntimeScope::scopeDefaultFileName('registry.json');
    }

    /**
     * Bind one service incarnation to a monotonic generation and canonical
     * identity digest. Consumer-only pending records remain unbound until a
     * sidecar publishes a complete authenticated runtime identity.
     *
     * @param array<string,mixed> $record
     * @param array<string,mixed> $previous
     * @return array<string,mixed>
     */
    public static function bindLifecycleGeneration(
        string $role,
        array $record,
        array $previous = [],
    ): array {
        $role = \trim($role);
        $digest = self::lifecycleIdentityDigest($role, $record);
        if ($digest === '') {
            unset(
                $record['lifecycle_schema'],
                $record['lifecycle_generation'],
                $record['lifecycle_identity_digest'],
            );
            return $record;
        }

        $previousDigest = self::lifecycleIdentityDigest($role, $previous);
        $previousGeneration = self::safeGeneration(
            $previous['lifecycle_generation'] ?? 0,
        );
        $previousBindingValid = $previousDigest !== ''
            && $previousGeneration > 0
            && \hash_equals(
                $previousDigest,
                (string)($previous['lifecycle_identity_digest'] ?? ''),
            );
        if ($previousBindingValid) {
            $candidateGeneration = self::safeGeneration(
                $record['lifecycle_generation'] ?? 0,
            );
            $candidateValid = $candidateGeneration > 0
                && self::hasExactLifecycleBinding($role, $record);
            if (\hash_equals($previousDigest, $digest)) {
                $generation = $candidateValid
                    ? \max($previousGeneration, $candidateGeneration)
                    : $previousGeneration;
            } elseif ($candidateValid
                && $candidateGeneration > $previousGeneration
            ) {
                // A newer, already-bound counterpart (registry or runtime)
                // may repair the older file without manufacturing another
                // generation. Equal or lower caller generations can never
                // roll the selected authority back.
                $generation = $candidateGeneration;
            } elseif ($candidateValid) {
                throw new \RuntimeException(
                    'Stale WLS shared-state lifecycle binding cannot replace the current generation.'
                );
            } elseif (self::hasLifecycleBindingFields($record)) {
                throw new \RuntimeException(
                    'Invalid WLS shared-state lifecycle binding cannot replace the current generation.'
                );
            } else {
                $generation = $previousGeneration >= self::MAX_SAFE_GENERATION
                    ? self::MAX_SAFE_GENERATION + 1
                    : $previousGeneration + 1;
            }
        } else {
            $candidateGeneration = self::safeGeneration(
                $record['lifecycle_generation'] ?? 0,
            );
            $candidateValid = $candidateGeneration > 0
                && self::hasExactLifecycleBinding($role, $record);
            $generation = $candidateValid
                ? $candidateGeneration
                : ($previousGeneration >= self::MAX_SAFE_GENERATION
                    ? self::MAX_SAFE_GENERATION + 1
                    : \max(1, $previousGeneration + 1));
            if ($generation > self::MAX_SAFE_GENERATION) {
                throw new \RuntimeException(
                    'WLS shared-state lifecycle generation is exhausted.'
                );
            }
        }

        $record['role'] = $role;
        $record['lifecycle_schema'] = self::LIFECYCLE_SCHEMA;
        $record['lifecycle_generation'] = $generation;
        $record['lifecycle_identity_digest'] = $digest;
        return $record;
    }

    /**
     * @param callable(array<string,mixed>):array<string,mixed> $updater
     * @return array<string,mixed>|null
     */
    private function updateRecordWithin(
        string $role,
        callable $updater,
        float $timeoutSeconds,
    ): ?array {
        $file = $this->getRegistryFile();
        $updatedRecord = [];
        $published = ServerInstanceManager::updateValidatedJsonFileAtomically(
            $file,
            static function (array $data) use ($role, $updater, &$updatedRecord): array {
                $services = \is_array($data['services'] ?? null) ? $data['services'] : [];
                $record = \is_array($services[$role] ?? null) ? $services[$role] : [];
                $nextRecord = $updater($record);
                $nextRecord = self::discardCarriedLifecycleBinding(
                    $role,
                    $nextRecord,
                    $record,
                );
                $updatedRecord = self::bindLifecycleGeneration(
                    $role,
                    $nextRecord,
                    $record,
                );
                $services[$role] = $updatedRecord;

                return self::nextRegistryDocument($data, $services);
            },
            self::registryRecoveryValidator(),
            'WLS shared-state service registry',
            self::MAX_REGISTRY_BYTES,
            $timeoutSeconds,
        );
        return $published ? $updatedRecord : null;
    }

    /**
     * Heartbeats must never turn a short lease refresh into registry recovery.
     * This mutation accepts only the currently committed, valid document and
     * leaves missing/corrupt state and atomic recovery artifacts untouched.
     *
     * @param callable(array<string,mixed>):array<string,mixed> $updater
     * @return array<string,mixed>|null
     */
    private function updateRecordWithoutRecoveryWithin(
        string $role,
        callable $updater,
        float $timeoutSeconds,
    ): ?array {
        $file = $this->getRegistryFile();
        if (!\is_dir(\dirname($file))) {
            return null;
        }
        $deadline = (\hrtime(true) / 1_000_000_000) + $timeoutSeconds;
        try {
            return GatewayProjectStateFilesystem::withExclusiveLock(
                $file . '.lock',
                static function () use ($file, $role, $updater, $deadline): ?array {
                    if ((\hrtime(true) / 1_000_000_000) >= $deadline) {
                        return null;
                    }
                    $raw = GatewayProjectStateFilesystem::readOptional(
                        $file,
                        self::MAX_REGISTRY_BYTES,
                        'WLS shared-state service registry',
                    );
                    if ($raw === null) {
                        return null;
                    }
                    $validator = self::registryRecoveryValidator();
                    $validator($raw);
                    $data = \json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    if (!\is_array($data)) {
                        return null;
                    }
                    $services = \is_array($data['services'] ?? null)
                        ? $data['services']
                        : [];
                    $record = \is_array($services[$role] ?? null)
                        ? $services[$role]
                        : [];
                    $nextRecord = self::discardCarriedLifecycleBinding(
                        $role,
                        $updater($record),
                        $record,
                    );
                    $updatedRecord = self::bindLifecycleGeneration(
                        $role,
                        $nextRecord,
                        $record,
                    );
                    $services[$role] = $updatedRecord;
                    $next = self::nextRegistryDocument($data, $services);
                    $json = \json_encode(
                        $next,
                        JSON_PRETTY_PRINT
                            | JSON_UNESCAPED_UNICODE
                            | JSON_UNESCAPED_SLASHES
                            | JSON_THROW_ON_ERROR,
                    );
                    if (\strlen($json) > self::MAX_REGISTRY_BYTES
                        || (\hrtime(true) / 1_000_000_000) >= $deadline
                    ) {
                        return null;
                    }
                    $validator($json);
                    GatewayProjectStateFilesystem::atomicWrite($file, $json, 0600);
                    return $updatedRecord;
                },
                waitTimeoutSeconds: \min(300.0, $timeoutSeconds),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $record */
    private static function hasLifecycleBindingFields(array $record): bool
    {
        return \array_key_exists('lifecycle_schema', $record)
            || \array_key_exists('lifecycle_generation', $record)
            || \array_key_exists('lifecycle_identity_digest', $record);
    }

    /**
     * An update callback commonly merges a fresh sidecar identity into the
     * current record. That merge carries the old binding triplet even though
     * it no longer authenticates the new identity. Treat only that exact
     * carried-forward triplet as unbound; independently bound stale tuples
     * remain subject to the generation CAS in bindLifecycleGeneration().
     *
     * @param array<string,mixed> $record
     * @param array<string,mixed> $previous
     * @return array<string,mixed>
     */
    private static function discardCarriedLifecycleBinding(
        string $role,
        array $record,
        array $previous,
    ): array {
        if (!self::hasExactLifecycleBinding($role, $previous)) {
            return $record;
        }
        foreach ([
            'lifecycle_schema',
            'lifecycle_generation',
            'lifecycle_identity_digest',
        ] as $field) {
            if (!\array_key_exists($field, $record)
                || $record[$field] !== $previous[$field]
            ) {
                return $record;
            }
        }
        $recordDigest = self::lifecycleIdentityDigest($role, $record);
        $previousDigest = self::lifecycleIdentityDigest($role, $previous);
        if ($recordDigest !== '' && \hash_equals($recordDigest, $previousDigest)) {
            return $record;
        }
        unset(
            $record['lifecycle_schema'],
            $record['lifecycle_generation'],
            $record['lifecycle_identity_digest'],
        );
        return $record;
    }

    /** @param array<string,mixed> $record */
    public static function lifecycleIdentityDigest(
        string $role,
        array $record,
    ): string {
        $role = \trim($role);
        $recordRole = \trim((string)($record['role'] ?? $role));
        $host = \strtolower(\trim((string)($record['host'] ?? '')));
        $port = (int)($record['port'] ?? 0);
        $pid = (int)($record['pid'] ?? 0);
        $token = \trim((string)($record['token_file_name'] ?? ''));
        if ($role === ''
            || !\hash_equals($role, $recordRole)
            || \strlen($role) > 128
            || \preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/D', $role) !== 1
            || $host === ''
            || \strlen($host) > 255
            || $port < 1
            || $port > 65535
            || $pid < 1
            || $token === ''
            || $token === '.'
            || $token === '..'
            || \str_contains($token, "\0")
            || \strlen($token) > 255
            || !\hash_equals(\basename(\str_replace('\\', '/', $token)), $token)
        ) {
            return '';
        }
        $identity = [
            'role' => $role,
            'host' => $host,
            'port' => $port,
            'pid' => $pid,
            'token_file_name' => $token,
            'started_at' => self::nullableIdentityString($record['started_at'] ?? null),
            'process_name' => \trim((string)($record['process_name'] ?? '')),
            'instance_name' => \trim((string)($record['instance_name'] ?? '')),
            'service_instance_name' => \trim((string)(
                $record['service_instance_name']
                    ?? $record['instance_name']
                    ?? ''
            )),
        ];
        try {
            return \hash('sha256', \json_encode(
                $identity,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR,
            ));
        } catch (\Throwable) {
            return '';
        }
    }

    /** @param array<string,mixed> $record */
    public static function hasExactLifecycleBinding(
        string $role,
        array $record,
    ): bool {
        $generation = self::safeGeneration(
            $record['lifecycle_generation'] ?? 0,
        );
        $digest = self::lifecycleIdentityDigest($role, $record);
        return $generation > 0
            && $digest !== ''
            && \hash_equals(
                self::LIFECYCLE_SCHEMA,
                (string)($record['lifecycle_schema'] ?? ''),
            )
            && \hash_equals(
                $digest,
                (string)($record['lifecycle_identity_digest'] ?? ''),
            );
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,array<string,mixed>> $services
     * @return array<string,mixed>
     */
    private static function nextRegistryDocument(array $data, array $services): array
    {
        $revision = self::safeGeneration($data['revision'] ?? 0);
        if ($revision >= self::MAX_SAFE_GENERATION) {
            throw new \RuntimeException(
                'WLS shared-state registry revision is exhausted.'
            );
        }
        $data['schema'] = self::REGISTRY_SCHEMA;
        $data['revision'] = $revision + 1;
        $data['services'] = $services;
        $data['updated_at'] = \date('c');
        return $data;
    }

    /** @return \Closure(string):void */
    private static function registryRecoveryValidator(): \Closure
    {
        return static function (string $raw): void {
            if ($raw === '' || \strlen($raw) > self::MAX_REGISTRY_BYTES) {
                throw new \RuntimeException(
                    'WLS shared-state registry size is invalid.'
                );
            }
            $data = \json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $hasSchema = \is_array($data) && \array_key_exists('schema', $data);
            $hasRevision = \is_array($data) && \array_key_exists('revision', $data);
            if (!\is_array($data)
                || $hasSchema !== $hasRevision
                || ($hasSchema && !\hash_equals(
                    self::REGISTRY_SCHEMA,
                    (string)$data['schema'],
                ))
            ) {
                throw new \RuntimeException(
                    'WLS shared-state registry schema is invalid.'
                );
            }
            if ($hasRevision && self::safeGeneration($data['revision']) < 1) {
                throw new \RuntimeException(
                    'WLS shared-state registry revision is invalid.'
                );
            }
            $services = $data['services'] ?? [];
            if (!\is_array($services) || \count($services) > 128) {
                throw new \RuntimeException(
                    'WLS shared-state registry services are invalid.'
                );
            }
            foreach ($services as $role => $record) {
                $role = (string)$role;
                if (!\is_array($record)
                    || \preg_match(
                        '/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/D',
                        $role,
                    ) !== 1
                ) {
                    throw new \RuntimeException(
                        'WLS shared-state registry role is invalid.'
                    );
                }
                $recordRole = \trim((string)($record['role'] ?? $role));
                $token = \trim((string)($record['token_file_name'] ?? ''));
                if (!\hash_equals($role, $recordRole)
                    || ($token !== '' && ($token === '.'
                        || $token === '..'
                        || \str_contains($token, "\0")
                        || \strlen($token) > 255
                        || !\hash_equals(
                            \basename(\str_replace('\\', '/', $token)),
                            $token,
                        )))
                ) {
                    throw new \RuntimeException(
                        'WLS shared-state registry identity fields are invalid.'
                    );
                }
                $hasLifecycleField = \array_key_exists('lifecycle_schema', $record)
                    || \array_key_exists('lifecycle_generation', $record)
                    || \array_key_exists('lifecycle_identity_digest', $record);
                if ($hasLifecycleField
                    && !self::hasExactLifecycleBinding($role, $record)
                ) {
                    throw new \RuntimeException(
                        'WLS shared-state registry lifecycle binding is invalid.'
                    );
                }
                $consumers = $record['consumers'] ?? [];
                if (!\is_array($consumers) || \count($consumers) > 4096) {
                    throw new \RuntimeException(
                        'WLS shared-state registry consumers are invalid.'
                    );
                }
                foreach ($consumers as $consumerCode => $consumer) {
                    if (\trim((string)$consumerCode) === ''
                        || \strlen((string)$consumerCode) > 255
                        || !\is_array($consumer)
                    ) {
                        throw new \RuntimeException(
                            'WLS shared-state registry consumer is invalid.'
                        );
                    }
                }
            }
        };
    }

    /**
     * @return array{services: array<string, array<string, mixed>>}
     */
    private function readData(): array
    {
        $file = $this->getRegistryFile();
        for ($attempt = 1; ; ++$attempt) {
            try {
                $data = $this->readValidatedRegistryDocument($file);
                break;
            } catch (\RuntimeException $exception) {
                if ($attempt >= self::READ_RACE_MAX_ATTEMPTS
                    || !self::isAtomicReplacementReadRace($exception)
                ) {
                    throw $exception;
                }
                \usleep(self::READ_RACE_RETRY_DELAY_MICROSECONDS);
            }
        }
        if ($data === null) {
            return ['services' => []];
        }

        $services = \is_array($data['services'] ?? null) ? $data['services'] : [];

        return ['services' => $services];
    }

    /** @return array<string, mixed>|null */
    protected function readValidatedRegistryDocument(string $file): ?array
    {
        return ServerInstanceManager::readValidatedJsonStatic(
            $file,
            self::registryRecoveryValidator(),
            'WLS shared-state service registry',
            self::MAX_REGISTRY_BYTES,
        );
    }

    private static function isAtomicReplacementReadRace(\RuntimeException $exception): bool
    {
        return \in_array($exception->getMessage(), [
            'WLS shared-state service registry is missing or unsafe.',
            'WLS shared-state service registry path is indeterminate or unsafe.',
            'Unable to open WLS shared-state service registry.',
            'WLS shared-state service registry changed before reading.',
            'WLS shared-state service registry changed while being read.',
        ], true);
    }

    private static function safeGeneration(mixed $generation): int
    {
        if (!\is_int($generation) && !\is_string($generation)) {
            return 0;
        }
        $text = (string)$generation;
        if (\preg_match('/\A(?:0|[1-9][0-9]{0,15})\z/D', $text) !== 1) {
            return 0;
        }
        $value = (int)$text;
        return $value >= 0 && $value <= self::MAX_SAFE_GENERATION ? $value : 0;
    }

    private static function nullableIdentityString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return \is_string($value) && \strlen($value) <= 128 ? $value : null;
    }

    private function normalizeRole(string $role): string
    {
        $role = \trim($role);
        if ($role === '') {
            throw new \InvalidArgumentException('Shared-service role cannot be empty.');
        }

        return $role;
    }

    /**
     * @param mixed $consumers
     * @return array<string, array<string, mixed>>
     */
    private static function normalizeConsumersArray(mixed $consumers): array
    {
        if (!\is_array($consumers)) {
            return [];
        }

        $normalized = [];
        foreach ($consumers as $consumerCode => $consumer) {
            $code = \trim((string) $consumerCode);
            if ($code === '') {
                continue;
            }

            if (!\is_array($consumer)) {
                $consumer = [];
            }

            $normalized[$code] = \array_merge(
                [
                    'consumer_code' => $code,
                    'owner_type' => 'instance',
                    'last_seen_at' => (string) ($consumer['last_ensured_at'] ?? \date('c')),
                    'lease_expires_at' => null,
                ],
                $consumer,
            );

            $normalized[$code]['consumer_code'] = $code;
            $normalized[$code]['owner_type'] = \trim((string) ($normalized[$code]['owner_type'] ?? 'instance')) ?: 'instance';
        }

        return $normalized;
    }
}
