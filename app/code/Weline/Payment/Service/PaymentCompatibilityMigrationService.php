<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Database\Migration\MigrationManifest;
use Weline\Framework\Database\Migration\Service\DatabaseFingerprintGuard;
use Weline\Framework\Database\Migration\Service\MigrationCheckpointJournalStore;
use Weline\Framework\Database\Migration\Service\MigrationCheckpointService;
use Weline\Framework\Database\Migration\Service\MigrationCloneService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Model\PaymentAttempt;
use Weline\Payment\Model\PaymentIntent;
use Weline\Payment\Model\PaymentTransaction;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;

/**
 * 历史 Payment Transaction → Intent/Attempt 兼容映射。
 *
 * 生产路径只接受 migration registry 登记 clone，并把 manifest/journal
 * 持久化后在单个数据库事务内写兼容 reader。Memory harness 仅用于纯映射单测。
 */
final class PaymentCompatibilityMigrationService
{
    public const PHASE = 'p2-payment';
    public const ERROR_SHARED_DB = 'mig_p2_payment_requires_isolated_database';
    public const ERROR_CLONE_NOT_REGISTERED = 'mig_p2_payment_clone_not_registered';
    public const ERROR_MODE_OFF = 'mig_p2_payment_rollout_off';
    public const ERROR_CHECKPOINT = 'mig_p2_payment_checkpoint_required';
    public const ERROR_FINGERPRINT = 'mig_p2_payment_checkpoint_fingerprint_mismatch';
    public const ERROR_CONFLICT = 'mig_p2_payment_conflicts_block_apply';

    public const MAP_STATUS_ALREADY = 'already';
    public const MAP_STATUS_MAPPED = 'mapped';
    public const MAP_STATUS_CONFLICT = 'conflict';

    /**
     * @var array{
     *   transactions: array<string, array<string, mixed>>,
     *   intents: array<string, array<string, mixed>>,
     *   attempts: array<string, array<string, mixed>>,
     *   mapping: array<string, string>,
     *   audit: list<array<string, mixed>>,
     *   provider_calls: int,
     *   outbox: list<array<string, mixed>>
     * }
     */
    private array $memory = [
        'transactions' => [],
        'intents' => [],
        'attempts' => [],
        'mapping' => [],
        'audit' => [],
        'provider_calls' => 0,
        'outbox' => [],
    ];

    private bool $rolloutOn = true;
    private ?string $lastCheckpointId = null;

    /** @var array<string, mixed>|null */
    private ?array $lastTargetDb = null;

    public function __construct(
        private readonly ?DatabaseFingerprintGuard $fingerprintGuard = null,
        private readonly ?MigrationCheckpointService $checkpointService = null,
        private readonly ?CommerceRolloutGateInterface $rolloutGate = null,
        private readonly ?PaymentCompatibilityDatabaseProbe $databaseProbe = null,
        private readonly bool $memoryProbe = false,
    ) {
    }

    public static function forTesting(?string $journalDir = null): self
    {
        $guard = new DatabaseFingerprintGuard();
        $store = new MigrationCheckpointJournalStore(
            $journalDir ?? (\sys_get_temp_dir() . '/mig_p2pay_' . \uniqid('', true)),
        );

        return new self(
            fingerprintGuard: $guard,
            checkpointService: new MigrationCheckpointService($guard, $store),
            memoryProbe: true,
        );
    }

    public function setRolloutOn(bool $on): void
    {
        $this->rolloutOn = $on;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public function seedTransaction(
        string $transactionNo,
        string $status,
        string $amount,
        string $currency = 'CNY',
        ?string $providerReference = null,
        array $extra = [],
    ): array {
        $row = array_merge([
            'transaction_id' => count($this->memory['transactions']) + 1,
            'transaction_no' => $transactionNo,
            'order_id' => (string) ($extra['order_id'] ?? 'ord-' . $transactionNo),
            'method_code' => (string) ($extra['method_code'] ?? 'fake_card'),
            'provider_code' => (string) ($extra['provider_code'] ?? 'fake'),
            'merchant_account' => (string) ($extra['merchant_account'] ?? 'acct_legacy'),
            'environment' => (string) ($extra['environment'] ?? 'sandbox'),
            'scope' => (string) ($extra['scope'] ?? 'default.default.default'),
            'amount' => $amount,
            'currency' => $currency,
            'precision' => (int) ($extra['precision'] ?? 2),
            'status' => $status,
            'provider_reference' => $providerReference,
            'payable_type' => (string) ($extra['payable_type'] ?? 'weline_order'),
            'request_data' => $extra['request_data'] ?? null,
            'response_data' => $extra['response_data'] ?? null,
            'callback_data' => $extra['callback_data'] ?? null,
            'created_at' => (string) ($extra['created_at'] ?? \gmdate('Y-m-d H:i:s')),
        ], $extra);
        $this->memory['transactions'][$transactionNo] = $row;

        return $row;
    }

    /**
     * Deterministic pure mapping; no Provider call and no persistence.
     *
     * @param array<string, mixed> $tx
     * @return array{
     *   status:string,
     *   intent:array<string,mixed>,
     *   attempt:array<string,mixed>,
     *   conservation:array<string,mixed>
     * }
     */
    public function mapTransaction(array $tx): array
    {
        $normalized = $this->normalizeTransaction($tx);
        $transactionNo = $normalized['transaction_no'];
        $amountMinor = $this->decimalToMinor($normalized['amount'], $normalized['precision']);
        $mapped = $this->mapStatuses($normalized['status']);
        $intentCode = $this->compatIntentCode($transactionNo);
        $attemptCode = $this->compatAttemptCode($transactionNo);
        $idempotencyKey = 'mig:p2-payment:' . substr(hash('sha256', $transactionNo), 0, 32);
        $createdAt = $normalized['created_at'];

        $conservation = [
            'transaction_no' => $transactionNo,
            'amount_minor' => $amountMinor,
            'currency' => $normalized['currency'],
            'environment' => $normalized['environment'],
            'legacy_status' => $normalized['status'],
            'intent_status' => $mapped['intent_status'],
            'attempt_status' => $mapped['attempt_status'],
            'provider_reference' => $normalized['provider_reference'],
            'payable_type' => $normalized['payable_type'],
            'payable_id' => $normalized['payable_id'],
            'scope' => $normalized['scope'],
        ];
        $requestHash = $this->rowHash($conservation);
        $snapshot = json_encode([
            'source' => 'MIG-P2-PAYMENT',
            'legacy_transaction_no' => $transactionNo,
            'amount_minor' => $amountMinor,
            'currency_code' => $normalized['currency'],
            'precision' => $normalized['precision'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $configSnapshot = json_encode([
            'environment' => $normalized['environment'],
            'method_code' => $normalized['method_code'],
            'provider_code' => $normalized['provider_code'],
            'merchant_account' => $normalized['merchant_account'],
            'scope' => $normalized['scope'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $intent = [
            'intent_code' => $intentCode,
            'environment' => $normalized['environment'],
            'payable_type' => $normalized['payable_type'],
            'payable_id' => $normalized['payable_id'],
            'method_code' => $normalized['method_code'],
            'provider_code' => $normalized['provider_code'],
            'merchant_account' => $normalized['merchant_account'],
            'scope' => $normalized['scope'],
            'amount_minor' => $amountMinor,
            'currency_code' => $normalized['currency'],
            'precision' => $normalized['precision'],
            'status' => $mapped['intent_status'],
            'active_flag' => 0,
            'active_guard' => null,
            'request_hash' => $requestHash,
            'idempotency_key' => $idempotencyKey,
            'amount_snapshot' => $snapshot,
            'config_snapshot' => $configSnapshot,
            'terms_snapshot' => json_encode(
                ['compatibility_reader' => true, 'legacy_transaction_no' => $transactionNo],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
        $providerReference = $normalized['provider_reference'];
        $attempt = [
            'attempt_code' => $attemptCode,
            'intent_code' => $intentCode,
            'environment' => $normalized['environment'],
            'payable_type' => $normalized['payable_type'],
            'payable_id' => $normalized['payable_id'],
            'method_code' => $normalized['method_code'],
            'provider_code' => $normalized['provider_code'],
            'merchant_account' => $normalized['merchant_account'],
            'scope' => $normalized['scope'],
            'payment_currency_code' => $normalized['currency'],
            'amount_minor' => $amountMinor,
            'precision' => $normalized['precision'],
            'status' => $mapped['attempt_status'],
            'nonterminal_guard' => $mapped['nonterminal']
                ? PaymentAttempt::NONTERMINAL_GUARD_VALUE
                : null,
            'version' => 1,
            'cas_token' => 'mig-p2-payment',
            'idempotency_key' => $idempotencyKey,
            'provider_reference' => $providerReference,
            'provider_reference_guard' => $providerReference === null
                ? null
                : $this->providerReferenceGuard(
                    $normalized['merchant_account'],
                    $normalized['environment'],
                    $providerReference,
                ),
            'provider_request_key' => 'migration:' . $attemptCode,
            'request_snapshot' => $snapshot,
            'response_snapshot' => json_encode([
                'source' => 'legacy_transaction',
                'legacy_status' => $normalized['status'],
                'provider_reference' => $providerReference,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'created_at' => $createdAt,
            'closed_at' => $mapped['nonterminal'] ? null : $createdAt,
        ];

        return [
            'status' => self::MAP_STATUS_MAPPED,
            'intent' => $intent,
            'attempt' => $attempt,
            'conservation' => $conservation,
        ];
    }

    /**
     * @param array<string, mixed>|null $targetDb
     * @return array<string, mixed>
     */
    public function preflight(?array $targetDb = null): array
    {
        if ($this->memoryProbe) {
            return $this->publicPreflight(
                $this->buildPreflight($this->memorySnapshot(), null),
            );
        }

        try {
            $db = $this->requireIsolatedTarget($targetDb);
            $guard = $this->resolveFingerprintGuard($db);
            $fingerprint = $guard->assertIsolatedDatabase($db);
            $snapshot = ($this->databaseProbe ?? new PaymentCompatibilityDatabaseProbe())->inspect($db);
            $this->lastTargetDb = $db;

            return $this->publicPreflight(
                $this->buildPreflight($snapshot, $fingerprint),
            );
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'phase' => self::PHASE,
                'error' => $e->getMessage(),
                'apply_ready' => false,
                'conflict_count' => 0,
                'shared_db_apply_forbidden' => true,
            ];
        }
    }

    /**
     * @param array<string, mixed>|null $targetDb
     * @return array<string, mixed>
     */
    public function apply(?array $targetDb = null): array
    {
        if (!$this->rolloutOn) {
            return ['ok' => false, 'error' => self::ERROR_MODE_OFF, 'phase' => self::PHASE];
        }
        try {
            $db = $this->requireIsolatedTarget($targetDb);
            $guard = $this->resolveFingerprintGuard($db);
            $fingerprint = $guard->assertIsolatedDatabase($db);
            $preflight = $this->memoryProbe
                ? $this->buildPreflight($this->memorySnapshot(), $fingerprint)
                : $this->buildPreflight(
                    ($this->databaseProbe ?? new PaymentCompatibilityDatabaseProbe())->inspect($db),
                    $fingerprint,
                );
            if (empty($preflight['ok']) || empty($preflight['apply_ready'])) {
                return [
                    'ok' => false,
                    'phase' => self::PHASE,
                    'error' => (string) ($preflight['error'] ?? self::ERROR_CONFLICT),
                    'mapped' => 0,
                    'preflight' => $this->publicPreflight($preflight),
                ];
            }

            $checkpoint = $this->checkpoint($guard);
            $checkpointId = 'p2pay-' . \gmdate('YmdHis') . '-'
                . \substr(\bin2hex(\random_bytes(3)), 0, 6);
            $manifest = $this->manifest($checkpointId, $fingerprint, $db, $preflight);
            $checkpoint->checkpoint($manifest);
            $checkpoint->appendJournal($checkpointId, 'p2_payment_preflight_snapshot', [
                'database' => (string) $db['database'],
                'fingerprint' => $fingerprint,
                'transaction_count' => (int) $preflight['transaction_count'],
                'already_mapped' => (int) $preflight['already_mapped'],
                'conflict_count' => (int) $preflight['conflict_count'],
            ]);
            $checkpoint->applyGuard($db, $checkpointId, $manifest);

            /** @var list<array{intent:array<string,mixed>,attempt:array<string,mixed>}> $plans */
            $plans = $preflight['_plans'];
            if ($this->memoryProbe) {
                $write = $this->applyMemory($plans);
            } else {
                $write = ($this->databaseProbe ?? new PaymentCompatibilityDatabaseProbe())
                    ->applyMappings($db, $plans);
            }
            $checkpoint->appendJournal($checkpointId, 'p2_payment_apply_done', [
                'database' => (string) $db['database'],
                'fingerprint' => $fingerprint,
                'mapped' => (int) $write['mapped'],
                'already' => (int) $write['already'],
                'provider_calls' => 0,
                'business_outbox_delta' => 0,
                'history_retained' => true,
            ]);
            $this->lastCheckpointId = $checkpointId;
            $this->lastTargetDb = $db;
            $this->memory['audit'][] = [
                'type' => 'apply',
                'checkpoint_id' => $checkpointId,
                'mapped' => (int) $write['mapped'],
                'at' => time(),
            ];

            return [
                'ok' => true,
                'phase' => self::PHASE,
                'checkpoint_id' => $checkpointId,
                'manifest_hash' => $manifest->hash(),
                'database' => (string) $db['database'],
                'fingerprint' => $fingerprint,
                'mapped' => (int) $write['mapped'],
                'already' => (int) $write['already'],
                'intent_count' => (int) $write['intent_count'],
                'attempt_count' => (int) $write['attempt_count'],
                'provider_calls' => 0,
                'outbox_count' => 0,
                'outbox_delta' => 0,
                'watermark' => (int) ($preflight['watermarks']['transaction'] ?? 0),
                'history_retained' => true,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'phase' => self::PHASE,
                'error' => $e->getMessage(),
                'mapped' => 0,
            ];
        }
    }

    /**
     * @param array<string, mixed>|null $targetDb
     * @return array<string, mixed>
     */
    public function verify(?array $targetDb = null, string $checkpointId = ''): array
    {
        try {
            $db = $this->requireIsolatedTarget($targetDb ?? $this->lastTargetDb);
            $guard = $this->resolveFingerprintGuard($db);
            $fingerprint = $guard->assertIsolatedDatabase($db);
            $checkpointId = $this->resolveCheckpointId($checkpointId);
            $checkpoint = $this->checkpoint($guard);
            $fresh = $checkpoint->verifyFresh($checkpointId);
            $stored = $checkpoint->store()?->load($checkpointId);
            if (empty($fresh['ok']) || $stored === null) {
                return [
                    'ok' => false,
                    'phase' => self::PHASE,
                    'checkpoint_id' => $checkpointId,
                    'error' => (string) ($fresh['error'] ?? 'migration_checkpoint_missing'),
                    'fresh_journal' => $fresh,
                ];
            }
            $manifest = MigrationManifest::fromArray($stored['manifest']);
            $diffs = [];
            if (!hash_equals($manifest->connectorFingerprint, $fingerprint)) {
                $diffs[] = ['code' => self::ERROR_FINGERPRINT];
            }

            $snapshot = $this->memoryProbe
                ? $this->memorySnapshot()
                : ($this->databaseProbe ?? new PaymentCompatibilityDatabaseProbe())->inspect($db);
            $current = $this->buildPreflight($snapshot, $fingerprint);
            if (empty($current['ok'])) {
                foreach ((array) ($current['conflicts'] ?? []) as $conflict) {
                    $diffs[] = ['code' => 'mapping_conflict', 'detail' => $conflict];
                }
                if (!empty($current['error']) && empty($current['conflicts'])) {
                    $diffs[] = ['code' => 'database_probe_failed', 'error' => $current['error']];
                }
            }
            $this->compareManifest($manifest, $current, $stored['journal'], $diffs);

            return [
                'ok' => $diffs === [],
                'phase' => self::PHASE,
                'checkpoint_id' => $checkpointId,
                'manifest_hash' => $manifest->hash(),
                'database' => (string) $db['database'],
                'fingerprint' => $fingerprint,
                'diff_count' => count($diffs),
                'diffs' => $diffs,
                'mapped_count' => (int) ($current['already_mapped'] ?? 0),
                'transaction_count' => (int) ($current['transaction_count'] ?? 0),
                'provider_calls' => 0,
                'outbox_count' => 0,
                'outbox_delta' => 0,
                'watermark' => (int) ($current['watermarks']['transaction'] ?? 0),
                'watermarks' => (array) ($current['watermarks'] ?? []),
                'history_retained' => $this->historyRetained($manifest, $current),
                'fresh_journal' => $fresh,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'phase' => self::PHASE,
                'checkpoint_id' => $checkpointId,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * mode off only; retain all historical and compatibility facts.
     *
     * @param array<string, mixed>|null $targetDb
     * @return array<string, mixed>
     */
    public function rollbackToModeOff(?array $targetDb = null, string $checkpointId = ''): array
    {
        try {
            if (!$this->memoryProbe) {
                $db = $this->requireIsolatedTarget($targetDb ?? $this->lastTargetDb);
                $guard = $this->resolveFingerprintGuard($db);
                $guard->assertIsolatedDatabase($db);
                $checkpointId = $this->resolveCheckpointId($checkpointId);
                $checkpoint = $this->checkpoint($guard);
                if (!$checkpoint->hasCheckpoint($checkpointId)) {
                    throw new \RuntimeException('migration_checkpoint_missing:' . $checkpointId);
                }
                $checkpoint->rollbackGuard($checkpointId);
                $checkpoint->appendJournal($checkpointId, 'p2_payment_mode_off', [
                    'database' => (string) $db['database'],
                    'history_retained' => true,
                    'mapping_retained' => true,
                    'continue_forward' => true,
                ]);
            } elseif ($checkpointId === '' && $this->lastCheckpointId !== null) {
                $checkpointId = $this->lastCheckpointId;
                $checkpoint = $this->checkpoint($this->fingerprintGuard ?? new DatabaseFingerprintGuard());
                $checkpoint->rollbackGuard($checkpointId);
                $checkpoint->appendJournal($checkpointId, 'p2_payment_mode_off', [
                    'history_retained' => true,
                    'mapping_retained' => true,
                    'continue_forward' => true,
                ]);
            }
            $this->rolloutOn = false;
            if ($this->rolloutGate !== null) {
                $this->rolloutGate->setMode('payment', CommerceRolloutGateInterface::MODE_OFF);
            }
            $this->memory['audit'][] = [
                'type' => 'mode_off',
                'at' => time(),
                'history_retained' => true,
                'mapping_retained' => true,
            ];

            $snapshot = $this->memoryProbe
                ? $this->memorySnapshot()
                : ($this->databaseProbe ?? new PaymentCompatibilityDatabaseProbe())
                    ->inspect($targetDb ?? $this->lastTargetDb ?? []);

            return [
                'ok' => true,
                'checkpoint_id' => $checkpointId,
                'mode' => CommerceRolloutGateInterface::MODE_OFF,
                'history_retained' => true,
                'mapping_retained' => true,
                'intent_count' => (int) ($snapshot['snapshots']['intent']['count']
                    ?? count($this->memory['intents'])),
                'attempt_count' => (int) ($snapshot['snapshots']['attempt']['count']
                    ?? count($this->memory['attempts'])),
                'transaction_count' => (int) ($snapshot['snapshots']['transaction']['count']
                    ?? count($this->memory['transactions'])),
                'continue_forward' => true,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'checkpoint_id' => $checkpointId,
                'error' => $e->getMessage(),
                'continue_forward' => true,
            ];
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function intents(): array
    {
        return $this->memory['intents'];
    }

    /** @return array<string, array<string, mixed>> */
    public function attempts(): array
    {
        return $this->memory['attempts'];
    }

    /** @return array<string, array<string, mixed>> */
    public function transactions(): array
    {
        return $this->memory['transactions'];
    }

    public function providerCallCount(): int
    {
        return $this->memory['provider_calls'];
    }

    public function outboxCount(): int
    {
        return count($this->memory['outbox']);
    }

    public function compatIntentCode(string $transactionNo): string
    {
        return 'compat_intent_' . substr(hash('sha256', 'p2pay|intent|' . $transactionNo), 0, 24);
    }

    public function compatAttemptCode(string $transactionNo): string
    {
        return 'compat_attempt_' . substr(hash('sha256', 'p2pay|attempt|' . $transactionNo), 0, 24);
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function buildPreflight(array $snapshot, ?string $fingerprint): array
    {
        if (isset($snapshot['ok']) && $snapshot['ok'] !== true) {
            return [
                'ok' => false,
                'phase' => self::PHASE,
                'fingerprint' => $fingerprint,
                'error' => (string) ($snapshot['error'] ?? 'mig_p2_payment_probe_failed'),
                'apply_ready' => false,
                'conflict_count' => 0,
                'conflicts' => [],
                '_plans' => [],
            ];
        }
        $transactions = (array) ($snapshot['transactions'] ?? []);
        $existingIntents = (array) ($snapshot['compat_intents'] ?? []);
        $existingAttempts = (array) ($snapshot['compat_attempts'] ?? []);
        $providerOwners = [];
        foreach ((array) ($snapshot['provider_reference_owners'] ?? []) as $owner) {
            if (!is_array($owner)) {
                continue;
            }
            $reference = trim((string) ($owner['provider_reference'] ?? ''));
            if ($reference === '') {
                continue;
            }
            $providerOwners[$this->providerOwnerKey(
                (string) ($owner['merchant_account'] ?? ''),
                (string) ($owner['environment'] ?? ''),
                $reference,
            )] = (string) ($owner['attempt_code'] ?? '');
        }

        $plans = [];
        $publicPlans = [];
        $conflicts = [];
        $already = 0;
        foreach ($transactions as $tx) {
            if (!is_array($tx)) {
                continue;
            }
            $txNo = (string) ($tx['transaction_no'] ?? '');
            try {
                $plan = $this->mapTransaction($tx);
                $intentCode = (string) $plan['intent']['intent_code'];
                $attemptCode = (string) $plan['attempt']['attempt_code'];
                $existingIntent = $existingIntents[$intentCode] ?? null;
                $existingAttempt = $existingAttempts[$attemptCode] ?? null;
                if ($existingIntent !== null || $existingAttempt !== null) {
                    $readerDiffs = $this->readerDiffs(
                        $plan,
                        is_array($existingIntent) ? $existingIntent : null,
                        is_array($existingAttempt) ? $existingAttempt : null,
                    );
                    if ($readerDiffs !== []) {
                        $conflicts[] = [
                            'code' => 'existing_reader_conflict',
                            'transaction_no' => $txNo,
                            'diffs' => $readerDiffs,
                        ];
                        continue;
                    }
                    $plan['status'] = self::MAP_STATUS_ALREADY;
                    $already++;
                }

                $providerReference = $plan['attempt']['provider_reference'];
                if (is_string($providerReference) && $providerReference !== '') {
                    $ownerKey = $this->providerOwnerKey(
                        (string) $plan['attempt']['merchant_account'],
                        (string) $plan['attempt']['environment'],
                        $providerReference,
                    );
                    $owner = $providerOwners[$ownerKey] ?? null;
                    if ($owner !== null && $owner !== $attemptCode) {
                        $conflicts[] = [
                            'code' => 'provider_reference_conflict',
                            'transaction_no' => $txNo,
                            'owner_attempt_code' => $owner,
                        ];
                        continue;
                    }
                    $providerOwners[$ownerKey] = $attemptCode;
                }
                $plans[] = ['intent' => $plan['intent'], 'attempt' => $plan['attempt']];
                $publicPlans[] = [
                    'status' => $plan['status'],
                    'intent_code' => $intentCode,
                    'attempt_code' => $attemptCode,
                    'conservation' => $plan['conservation'],
                ];
            } catch (\Throwable $e) {
                $conflicts[] = [
                    'code' => $e->getMessage(),
                    'transaction_no' => $txNo,
                ];
            }
        }

        $snapshots = (array) ($snapshot['snapshots'] ?? []);
        $rowCounts = [];
        $rowHashes = [];
        $watermarks = [];
        foreach ($snapshots as $name => $vector) {
            if (!is_array($vector)) {
                continue;
            }
            $rowCounts[(string) $name] = (int) ($vector['count'] ?? 0);
            $rowHashes[(string) $name] = (string) ($vector['digest'] ?? '');
            $watermarks[(string) $name] = (int) ($vector['watermark'] ?? 0);
        }
        $ok = $conflicts === [];

        return [
            'ok' => $ok,
            'phase' => self::PHASE,
            'fingerprint' => $fingerprint,
            'error' => $ok ? null : self::ERROR_CONFLICT,
            'transaction_count' => count($transactions),
            'already_mapped' => $already,
            'planned_count' => count($plans) - $already,
            'conflict_count' => count($conflicts),
            'conflicts' => array_slice($conflicts, 0, 100),
            'plans' => $publicPlans,
            'schema_fingerprints' => (array) ($snapshot['schema_fingerprints'] ?? []),
            'row_counts' => $rowCounts,
            'row_hashes' => $rowHashes,
            'watermarks' => $watermarks,
            'provider_calls' => 0,
            'outbox_count' => 0,
            'outbox_delta' => 0,
            'delete_history_forbidden' => true,
            'shared_db_apply_forbidden' => true,
            'apply_ready' => $ok,
            '_plans' => $plans,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function memorySnapshot(): array
    {
        $transactions = array_values($this->memory['transactions']);
        $snapshots = [];
        foreach ([
            'transaction' => $transactions,
            'intent' => array_values($this->memory['intents']),
            'attempt' => array_values($this->memory['attempts']),
            'refund' => [],
            'inbox' => [],
            'outbox' => $this->memory['outbox'],
            'ledger' => [],
            'reconciliation' => [],
        ] as $name => $rows) {
            $snapshots[$name] = [
                'count' => count($rows),
                'watermark' => count($rows),
                'digest' => $this->rowsDigest($rows),
            ];
        }
        $owners = [];
        foreach ($this->memory['attempts'] as $attempt) {
            if (!empty($attempt['provider_reference'])) {
                $owners[] = $attempt;
            }
        }

        return [
            'ok' => true,
            'schema_fingerprints' => [
                'transaction' => hash('sha256', 'memory-payment-transaction'),
                'intent' => hash('sha256', 'memory-payment-intent'),
                'attempt' => hash('sha256', 'memory-payment-attempt'),
                'refund' => hash('sha256', 'memory-payment-refund'),
                'inbox' => hash('sha256', 'memory-payment-inbox'),
                'outbox' => hash('sha256', 'memory-payment-outbox'),
                'ledger' => hash('sha256', 'memory-payment-ledger'),
                'reconciliation' => hash('sha256', 'memory-payment-reconciliation'),
            ],
            'snapshots' => $snapshots,
            'transactions' => $transactions,
            'compat_intents' => $this->memory['intents'],
            'compat_attempts' => $this->memory['attempts'],
            'provider_reference_owners' => $owners,
        ];
    }

    /**
     * @param list<array{intent:array<string,mixed>,attempt:array<string,mixed>}> $plans
     * @return array{mapped:int,already:int,intent_count:int,attempt_count:int}
     */
    private function applyMemory(array $plans): array
    {
        $mapped = 0;
        $already = 0;
        foreach ($plans as $plan) {
            $intentCode = (string) $plan['intent']['intent_code'];
            $attemptCode = (string) $plan['attempt']['attempt_code'];
            if (isset($this->memory['intents'][$intentCode], $this->memory['attempts'][$attemptCode])) {
                $already++;
                continue;
            }
            $this->memory['intents'][$intentCode] = $plan['intent'];
            $this->memory['attempts'][$attemptCode] = $plan['attempt'];
            $transactionNo = $this->transactionNoFromPlan($plan);
            $this->memory['mapping'][$transactionNo] = $intentCode;
            $mapped++;
        }

        return [
            'mapped' => $mapped,
            'already' => $already,
            'intent_count' => count($this->memory['intents']),
            'attempt_count' => count($this->memory['attempts']),
        ];
    }

    /**
     * @param array<string, mixed> $preflight
     */
    private function manifest(
        string $checkpointId,
        string $fingerprint,
        array $db,
        array $preflight,
    ): MigrationManifest {
        return MigrationManifest::fromArray([
            'checkpoint_id' => $checkpointId,
            'phase' => self::PHASE . '-apply',
            'repo' => 'WelineFramework',
            'branch' => 'working-tree',
            'commit' => 'current-source',
            'connector_fingerprint' => $fingerprint,
            'schema_fingerprints' => (array) $preflight['schema_fingerprints'],
            'row_counts' => (array) $preflight['row_counts'],
            'row_hashes' => (array) $preflight['row_hashes'],
            'watermarks' => (array) $preflight['watermarks'],
            'backup_ref' => 'clone:' . (string) $db['database'],
            'created_at' => \gmdate('c'),
        ]);
    }

    /**
     * @param list<array{at:string,event:string,detail:array<string,mixed>}> $journal
     * @param list<array<string, mixed>> $diffs
     */
    private function compareManifest(
        MigrationManifest $manifest,
        array $current,
        array $journal,
        array &$diffs,
    ): void {
        foreach ($manifest->schemaFingerprints as $name => $expected) {
            $actual = (string) ($current['schema_fingerprints'][$name] ?? '');
            if ($actual === '' || !hash_equals((string) $expected, $actual)) {
                $diffs[] = ['code' => 'schema_fingerprint_changed', 'table' => (string) $name];
            }
        }
        foreach (['transaction', 'refund', 'inbox', 'outbox', 'ledger', 'reconciliation'] as $name) {
            $expectedCount = (int) ($manifest->rowCounts[$name] ?? -1);
            $actualCount = (int) ($current['row_counts'][$name] ?? -2);
            if ($expectedCount !== $actualCount) {
                $diffs[] = [
                    'code' => $name . '_count_changed',
                    'expected' => $expectedCount,
                    'actual' => $actualCount,
                ];
            }
            $expectedHash = (string) ($manifest->rowHashes[$name] ?? '');
            $actualHash = (string) ($current['row_hashes'][$name] ?? '');
            if ($expectedHash === '' || !hash_equals($expectedHash, $actualHash)) {
                $diffs[] = ['code' => $name . '_digest_changed'];
            }
        }

        $applied = null;
        foreach ($journal as $entry) {
            if (($entry['event'] ?? '') === 'p2_payment_apply_done') {
                $applied = (array) ($entry['detail'] ?? []);
            }
        }
        if ($applied === null) {
            $diffs[] = ['code' => 'p2_payment_apply_journal_missing'];
            return;
        }
        $mapped = (int) ($applied['mapped'] ?? 0);
        foreach (['intent', 'attempt'] as $name) {
            $before = (int) ($manifest->rowCounts[$name] ?? 0);
            $after = (int) ($current['row_counts'][$name] ?? 0);
            if ($after !== $before + $mapped) {
                $diffs[] = [
                    'code' => $name . '_mapped_count_mismatch',
                    'expected' => $before + $mapped,
                    'actual' => $after,
                ];
            }
        }
    }

    /**
     * @return list<string>
     */
    private function readerDiffs(array $plan, ?array $intent, ?array $attempt): array
    {
        if ($intent === null || $attempt === null) {
            return ['partial_reader'];
        }
        $diffs = [];
        foreach ([
            'intent_code', 'environment', 'payable_type', 'payable_id', 'method_code',
            'provider_code', 'merchant_account', 'scope', 'currency_code', 'status',
            'request_hash', 'idempotency_key',
        ] as $field) {
            if ((string) ($intent[$field] ?? '') !== (string) ($plan['intent'][$field] ?? '')) {
                $diffs[] = 'intent.' . $field;
            }
        }
        foreach (['amount_minor', 'precision', 'active_flag'] as $field) {
            if ((int) ($intent[$field] ?? -1) !== (int) ($plan['intent'][$field] ?? -2)) {
                $diffs[] = 'intent.' . $field;
            }
        }
        foreach ([
            'attempt_code', 'intent_code', 'environment', 'payable_type', 'payable_id',
            'method_code', 'provider_code', 'merchant_account', 'scope',
            'payment_currency_code', 'status', 'idempotency_key', 'provider_reference',
            'provider_reference_guard',
        ] as $field) {
            if ((string) ($attempt[$field] ?? '') !== (string) ($plan['attempt'][$field] ?? '')) {
                $diffs[] = 'attempt.' . $field;
            }
        }
        foreach (['amount_minor', 'precision', 'version'] as $field) {
            if ((int) ($attempt[$field] ?? -1) !== (int) ($plan['attempt'][$field] ?? -2)) {
                $diffs[] = 'attempt.' . $field;
            }
        }

        return $diffs;
    }

    /**
     * @param array<string, mixed> $tx
     * @return array{
     *   transaction_no:string,payable_type:string,payable_id:string,method_code:string,
     *   provider_code:string,merchant_account:string,environment:string,scope:string,
     *   amount:string,currency:string,precision:int,status:string,
     *   provider_reference:?string,created_at:string
     * }
     */
    private function normalizeTransaction(array $tx): array
    {
        $transactionNo = trim((string) ($tx['transaction_no'] ?? ''));
        if ($transactionNo === '') {
            throw new \InvalidArgumentException('transaction_no_required');
        }
        $request = $this->decodeJson($tx['request_data'] ?? null);
        $response = $this->decodeJson($tx['response_data'] ?? null);
        $callback = $this->decodeJson($tx['callback_data'] ?? null);
        $metadata = array_merge($request, $response, $callback);
        $environment = strtolower(trim((string) ($tx['environment']
            ?? $metadata['environment']
            ?? $metadata['mode']
            ?? '')));
        if (!in_array($environment, ['sandbox', 'live'], true)) {
            throw new \InvalidArgumentException('environment_ambiguous');
        }
        $methodCode = trim((string) ($tx['method_code'] ?? $metadata['method_code'] ?? ''));
        if ($methodCode === '') {
            throw new \InvalidArgumentException('method_code_required');
        }
        $providerCode = trim((string) ($tx['provider_code']
            ?? $metadata['provider_code']
            ?? $methodCode));
        $merchantAccount = trim((string) ($tx['merchant_account']
            ?? $metadata['merchant_account']
            ?? $metadata['merchant_account_id']
            ?? ''));
        if ($merchantAccount === '') {
            throw new \InvalidArgumentException('merchant_account_ambiguous');
        }
        $providerReference = $tx['provider_reference']
            ?? $metadata['provider_reference']
            ?? $metadata['provider_transaction_id']
            ?? $metadata['provider_txn_id']
            ?? null;
        $providerReference = is_scalar($providerReference)
            ? trim((string) $providerReference)
            : null;
        if ($providerReference === '') {
            $providerReference = null;
        }
        $status = strtolower(trim((string) ($tx['status'] ?? '')));
        if (in_array($status, [
            PaymentTransaction::STATUS_SUCCESS,
            PaymentTransaction::STATUS_REFUNDED,
        ], true) && $providerReference === null) {
            throw new \InvalidArgumentException('provider_reference_required_for_terminal_success');
        }
        $currency = strtoupper(trim((string) ($tx['currency'] ?? '')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('currency_invalid');
        }
        $precision = (int) ($tx['precision'] ?? 2);
        if ($precision !== 2) {
            throw new \InvalidArgumentException('legacy_precision_must_be_2');
        }
        $payableId = trim((string) ($tx['order_id'] ?? $metadata['payable_id'] ?? ''));
        if ($payableId === '') {
            throw new \InvalidArgumentException('payable_id_required');
        }
        $scope = trim((string) ($tx['scope'] ?? $metadata['scope'] ?? ''));
        if ($scope === '') {
            throw new \InvalidArgumentException('scope_required');
        }
        $createdAt = trim((string) ($tx['created_at'] ?? ''));
        if ($createdAt === '' || strtotime($createdAt) === false) {
            $createdAt = \gmdate('Y-m-d H:i:s');
        }

        return [
            'transaction_no' => $transactionNo,
            'payable_type' => trim((string) ($tx['payable_type']
                ?? $metadata['payable_type']
                ?? 'weline_order')),
            'payable_id' => $payableId,
            'method_code' => $methodCode,
            'provider_code' => $providerCode,
            'merchant_account' => $merchantAccount,
            'environment' => $environment,
            'scope' => $scope,
            'amount' => trim((string) ($tx['amount'] ?? '')),
            'currency' => $currency,
            'precision' => $precision,
            'status' => $status,
            'provider_reference' => $providerReference,
            'created_at' => $createdAt,
        ];
    }

    /**
     * @return array{intent_status:string,attempt_status:string,nonterminal:bool}
     */
    private function mapStatuses(string $legacy): array
    {
        return match ($legacy) {
            PaymentTransaction::STATUS_SUCCESS => [
                'intent_status' => PaymentIntent::STATUS_PAID,
                'attempt_status' => PaymentAttempt::STATUS_SUCCEEDED,
                'nonterminal' => false,
            ],
            PaymentTransaction::STATUS_FAILED => [
                'intent_status' => PaymentIntent::STATUS_FAILED,
                'attempt_status' => PaymentAttempt::STATUS_FAILED,
                'nonterminal' => false,
            ],
            PaymentTransaction::STATUS_REFUNDED => [
                'intent_status' => PaymentIntent::STATUS_REFUNDED,
                'attempt_status' => PaymentAttempt::STATUS_SUCCEEDED,
                'nonterminal' => false,
            ],
            PaymentTransaction::STATUS_PENDING => [
                'intent_status' => PaymentIntent::STATUS_PENDING,
                'attempt_status' => PaymentAttempt::STATUS_CREATED,
                'nonterminal' => true,
            ],
            PaymentTransaction::STATUS_PROCESSING => [
                'intent_status' => PaymentIntent::STATUS_PROCESSING,
                'attempt_status' => PaymentAttempt::STATUS_PROVIDER_PENDING,
                'nonterminal' => true,
            ],
            'unknown' => [
                'intent_status' => PaymentIntent::STATUS_PROCESSING,
                'attempt_status' => PaymentAttempt::STATUS_PROCESSING,
                'nonterminal' => true,
            ],
            default => throw new \InvalidArgumentException('unsupported_legacy_status:' . $legacy),
        };
    }

    private function decimalToMinor(string $amount, int $precision): int
    {
        $amount = trim($amount);
        if (!preg_match('/^(0|[1-9][0-9]*)(?:\\.([0-9]+))?$/', $amount, $matches)) {
            throw new \InvalidArgumentException('amount_invalid');
        }
        $fraction = (string) ($matches[2] ?? '');
        if (strlen($fraction) > $precision) {
            throw new \InvalidArgumentException('amount_precision_exceeded');
        }
        $whole = (string) $matches[1];
        $fraction = str_pad($fraction, $precision, '0');
        $minor = ltrim($whole . $fraction, '0');
        if ($minor === '') {
            return 0;
        }
        if (strlen($minor) > strlen((string) PHP_INT_MAX)
            || (strlen($minor) === strlen((string) PHP_INT_MAX)
                && strcmp($minor, (string) PHP_INT_MAX) > 0)
        ) {
            throw new \OverflowException('amount_minor_overflow');
        }

        return (int) $minor;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function providerReferenceGuard(
        string $merchantAccount,
        string $environment,
        string $providerReference,
    ): string {
        return hash('sha256', $this->providerOwnerKey(
            $merchantAccount,
            $environment,
            $providerReference,
        ));
    }

    private function providerOwnerKey(
        string $merchantAccount,
        string $environment,
        string $providerReference,
    ): string {
        return strtolower(trim($merchantAccount)) . '|'
            . strtolower(trim($environment)) . '|'
            . trim($providerReference);
    }

    /**
     * @param array<string, mixed> $conservation
     */
    private function rowHash(array $conservation): string
    {
        ksort($conservation);

        return hash(
            'sha256',
            json_encode($conservation, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function rowsDigest(array $rows): string
    {
        return hash(
            'sha256',
            json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array{intent:array<string,mixed>,attempt:array<string,mixed>} $plan
     */
    private function transactionNoFromPlan(array $plan): string
    {
        $snapshot = $this->decodeJson($plan['intent']['terms_snapshot'] ?? null);

        return (string) ($snapshot['legacy_transaction_no'] ?? '');
    }

    private function checkpoint(DatabaseFingerprintGuard $guard): MigrationCheckpointService
    {
        return $this->checkpointService ?? new MigrationCheckpointService(
            $guard,
            new MigrationCheckpointJournalStore(),
        );
    }

    private function resolveCheckpointId(string $checkpointId): string
    {
        $checkpointId = trim($checkpointId);
        if ($checkpointId !== '') {
            return $checkpointId;
        }
        if ($this->lastCheckpointId !== null && $this->lastCheckpointId !== '') {
            return $this->lastCheckpointId;
        }
        throw new \RuntimeException(self::ERROR_CHECKPOINT . ': pass --checkpoint=ID');
    }

    /**
     * @param array<string, mixed> $targetDb
     */
    private function resolveFingerprintGuard(array $targetDb): DatabaseFingerprintGuard
    {
        if ($this->fingerprintGuard !== null) {
            return $this->fingerprintGuard;
        }
        /** @var MigrationCloneService $clones */
        $clones = ObjectManager::getInstance(MigrationCloneService::class);
        $database = (string) ($targetDb['database'] ?? '');
        foreach ($clones->list() as $handle) {
            if ($handle->database === $database) {
                return $clones->guardedFingerprint();
            }
        }
        throw new \RuntimeException(self::ERROR_CLONE_NOT_REGISTERED . ':' . $database);
    }

    /**
     * @param array<string, mixed>|null $targetDb
     * @return array{
     *   hostname:string,hostport:string,database:string,username:string,password:string,
     *   type:string,prefix:string
     * }
     */
    private function requireIsolatedTarget(?array $targetDb): array
    {
        $database = trim((string) ($targetDb['database'] ?? ''));
        if ($database === '') {
            throw new \RuntimeException(
                self::ERROR_SHARED_DB
                . ': pass --database=mig_clone_* (create via php bin/w mig:foundation clone-create --mode=schema --purpose=p2payment)',
            );
        }
        $config = [
            'type' => (string) ($targetDb['type'] ?? 'pgsql'),
            'hostname' => (string) ($targetDb['hostname'] ?? '127.0.0.1'),
            'hostport' => (string) ($targetDb['hostport'] ?? '5432'),
            'database' => $database,
            'username' => (string) ($targetDb['username'] ?? 'weline'),
            'password' => (string) ($targetDb['password'] ?? ''),
            'prefix' => (string) ($targetDb['prefix'] ?? ''),
        ];
        ($this->fingerprintGuard ?? new DatabaseFingerprintGuard())->assertIsolatedDatabase($config);

        return $config;
    }

    /**
     * @param array<string, mixed> $preflight
     * @return array<string, mixed>
     */
    private function publicPreflight(array $preflight): array
    {
        unset($preflight['_plans']);
        $plans = (array) ($preflight['plans'] ?? []);
        $preflight['plan_report_count'] = min(100, count($plans));
        $preflight['plans_truncated'] = count($plans) > 100;
        $preflight['plans'] = array_slice($plans, 0, 100);

        return $preflight;
    }

    private function historyRetained(MigrationManifest $manifest, array $current): bool
    {
        return (int) ($manifest->rowCounts['transaction'] ?? -1)
            === (int) ($current['row_counts']['transaction'] ?? -2)
            && hash_equals(
                (string) ($manifest->rowHashes['transaction'] ?? ''),
                (string) ($current['row_hashes']['transaction'] ?? ''),
            );
    }
}
