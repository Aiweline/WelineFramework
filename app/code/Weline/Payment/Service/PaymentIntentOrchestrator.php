<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Payment\Api\Data\Actor;
use Weline\Payment\Api\Data\PayableSnapshot;
use Weline\Payment\Api\Data\PaymentEffectRecord;
use Weline\Payment\Api\Data\PaymentOperationResult;
use Weline\Payment\Api\Data\PaymentStartCommand;
use Weline\Payment\Model\PaymentAttempt;
use Weline\Payment\Model\PaymentIntent;
use Weline\Payment\Model\PaymentProviderCommandOutbox;

/**
 * Intent/Attempt 唯一入口 + 第一事务 outbox + 事务外 Provider + 第二事务 CAS（MOD-P2F-002）。
 * 生产委托 PaymentIntentPersistenceService；memory 仅保留为显式 forTesting harness。
 */
final class PaymentIntentOrchestrator
{
    public const ERROR_NEW_ATTEMPT_DISABLED = 'payment_new_attempt_disabled';
    public const ERROR_ACTIVE_INTENT_EXISTS = 'payment_active_intent_exists';
    public const ERROR_NONTERMINAL_ATTEMPT = 'payment_nonterminal_attempt_blocks_retry';
    public const ERROR_SNAPSHOT_CHANGED = 'payment_snapshot_changed';
    public const ERROR_CAS_CONFLICT = 'payment_attempt_cas_conflict';
    public const ERROR_RESERVATION_EXPIRED = 'payment_reservation_expired';
    public const ERROR_INVENTORY_CONFLICT = 'paid_inventory_conflict';
    public const ERROR_IDEMPOTENCY_IN_PROGRESS = 'payment_idempotency_in_progress';
    public const ERROR_PERSISTENCE = 'payment_persistence_failed';

    public const STATUS_LOCAL_ACCEPTED = 'local_accepted';
    public const STATUS_AWAITING_PROVIDER = 'awaiting_provider';
    public const STATUS_PROVIDER_PENDING = 'provider_pending';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_UNKNOWN = 'unknown';

    /** Lease：检查窗口 5m，延长到 min(now+30m, started+2h)。 */
    public const LEASE_CHECK_SECONDS = 300;
    public const LEASE_EXTEND_SECONDS = 1800;
    public const LEASE_HARD_CAP_SECONDS = 7200;

    /**
     * @var array{
     *   intents: array<string, array<string, mixed>>,
     *   attempts: array<string, array<string, mixed>>,
     *   outbox: array<string, array<string, mixed>>,
     *   by_idem: array<string, array{request_hash:string,intent_code:string}>,
     *   by_payable_active: array<string, string>,
     *   ledger: list<array<string, mixed>>,
     *   provider_calls: list<array<string, mixed>>,
     *   provider_results: array<string, array<string, mixed>>,
     *   effect_outbox: list<array<string, mixed>>
     * }
     */
    private array $memory;

    private ?PaymentIntentPersistenceService $persistence;
    private int $now;
    private bool $newAttemptsEnabled = true;
    private bool $crashBeforeSecondTx = false;
    private bool $reservationValid = true;

    /** @var (\Closure(array<string,mixed>): array{status:string,provider_reference?:string,error_code?:string})|null */
    private $providerHandler = null;

    public function __construct(
        ?PaymentIntentPersistenceService $persistence,
        int $now = 0,
    )
    {
        $this->persistence = $persistence;
        $this->now = $now > 0 ? $now : time();
        $this->memory = [
            'intents' => [],
            'attempts' => [],
            'outbox' => [],
            'by_idem' => [],
            'by_payable_active' => [],
            'ledger' => [],
            'provider_calls' => [],
            'provider_results' => [],
            'effect_outbox' => [],
        ];
    }

    public static function forTesting(int $now = 0): self
    {
        return new self(null, $now > 0 ? $now : 1_700_000_000);
    }

    public function isPersistent(): bool
    {
        return $this->persistence !== null;
    }

    public function setNow(int $timestamp): void
    {
        if ($this->persistence !== null) {
            $this->persistence->setNow($timestamp);
        }
        $this->now = $timestamp;
    }

    public function now(): int
    {
        return $this->persistence?->now() ?? $this->now;
    }

    public function setNewAttemptsEnabled(bool $enabled): void
    {
        if ($this->persistence !== null) {
            $this->persistence->setNewAttemptsEnabled($enabled);
        }
        $this->newAttemptsEnabled = $enabled;
    }

    public function setCrashBeforeSecondTx(bool $crash): void
    {
        if ($this->persistence !== null) {
            $this->persistence->setCrashBeforeSecondTx($crash);
        }
        $this->crashBeforeSecondTx = $crash;
    }

    public function setReservationValid(bool $valid): void
    {
        if ($this->persistence !== null) {
            $this->persistence->setReservationValid($valid);
        }
        $this->reservationValid = $valid;
    }

    /**
     * @param (\Closure(array<string,mixed>): array{status:string,provider_reference?:string,error_code?:string}) $handler
     */
    public function setProviderHandler(callable $handler): void
    {
        if ($this->persistence !== null) {
            $this->persistence->setProviderHandler($handler);
        }
        $this->providerHandler = $handler;
    }

    /**
     * First transaction only：Intent + Attempt + command outbox；不调用 Provider。
     *
     * @return array{ok:bool,error_code:?string,intent:?array,attempt:?array,outbox:?array}
     */
    public function beginStart(
        PaymentStartCommand $command,
        PayableSnapshot $snapshot,
        string $merchantAccount,
        string $environment = 'sandbox',
    ): array {
        if ($this->persistence !== null) {
            return $this->persistence->beginStart($command, $snapshot, $merchantAccount, $environment);
        }
        $idem = $command->getIdempotencyKey();
        $hash = $command->getRequestHash();
        $payableType = $command->getPayableType();
        $payableId = $command->getPayableId();
        $methodCode = $command->getMethodCode();
        $payableKey = $environment . ':' . $payableType . ':' . $payableId;

        if (isset($this->memory['by_idem'][$idem])) {
            $prev = $this->memory['by_idem'][$idem];
            if ($prev['request_hash'] !== $hash) {
                return $this->fail(PaymentFacadeV2::ERROR_IDEMPOTENCY_CONFLICT);
            }
            $intent = $this->memory['intents'][$prev['intent_code']] ?? null;
            $attempt = $intent !== null
                ? ($this->memory['attempts'][(string) ($intent['current_attempt_code'] ?? '')] ?? null)
                : null;

            return [
                'ok' => true,
                'error_code' => null,
                'intent' => $intent,
                'attempt' => $attempt,
                'outbox' => null,
                'replayed' => true,
            ];
        }

        $snapshotVersion = $snapshot->getVersion() !== ''
            ? $snapshot->getVersion()
            : hash('sha256', json_encode([
                'amount' => $snapshot->getAmountMinor(),
                'currency' => $snapshot->getCurrencyCode(),
                'items' => $snapshot->getItems(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

        // Reuse active Intent when snapshot unchanged and last attempt terminal failed.
        $activeIntentCode = $this->memory['by_payable_active'][$payableKey] ?? null;
        if ($activeIntentCode !== null) {
            $intent = $this->memory['intents'][$activeIntentCode];
            $currentAttemptCode = (string) ($intent['current_attempt_code'] ?? '');
            $currentAttempt = $this->memory['attempts'][$currentAttemptCode] ?? null;

            if ($currentAttempt !== null && ($currentAttempt['nonterminal_guard'] ?? null) === PaymentAttempt::NONTERMINAL_GUARD_VALUE) {
                return $this->fail(self::ERROR_NONTERMINAL_ATTEMPT);
            }

            if (($intent['snapshot_version'] ?? '') !== $snapshotVersion) {
                return $this->fail(self::ERROR_SNAPSHOT_CHANGED);
            }

            if ($currentAttempt !== null
                && ($currentAttempt['status'] ?? '') === PaymentAttempt::STATUS_FAILED
                && ($currentAttempt['nonterminal_guard'] ?? null) === null
            ) {
                if (!$this->newAttemptsEnabled) {
                    return $this->fail(self::ERROR_NEW_ATTEMPT_DISABLED);
                }

                return $this->openNewAttemptOnIntent($intent, $command, $snapshot, $merchantAccount, $environment, $hash, $idem);
            }

            return $this->fail(self::ERROR_ACTIVE_INTENT_EXISTS);
        }

        if (!$this->newAttemptsEnabled) {
            return $this->fail(self::ERROR_NEW_ATTEMPT_DISABLED);
        }

        $intentCode = 'pi_' . bin2hex(random_bytes(8));
        $attemptCode = 'pa_' . bin2hex(random_bytes(8));
        $providerKey = PaymentProviderCommandOutbox::buildProviderRequestKey($attemptCode);
        $leaseExpires = $this->now + self::LEASE_CHECK_SECONDS;

        $intent = [
            'intent_code' => $intentCode,
            'environment' => $environment,
            'payable_type' => $payableType,
            'payable_id' => $payableId,
            'method_code' => $methodCode,
            'provider_code' => $methodCode,
            'merchant_account' => $merchantAccount,
            'status' => self::STATUS_LOCAL_ACCEPTED,
            'terminal' => false,
            'active_flag' => 1,
            'active_guard' => PaymentIntent::ACTIVE_GUARD_VALUE,
            'amount_minor' => $snapshot->getAmountMinor(),
            'currency_code' => $snapshot->getCurrencyCode(),
            'snapshot_version' => $snapshotVersion,
            'scope' => $snapshot->getArray('scope'),
            'idempotency_key' => $idem,
            'request_hash' => $hash,
            'current_attempt_code' => $attemptCode,
            'payment_status' => 'unpaid',
            'attention' => null,
            'asset_allocations' => $snapshot->getArray('asset_allocations'),
        ];

        $attempt = [
            'attempt_code' => $attemptCode,
            'intent_code' => $intentCode,
            'environment' => $environment,
            'payable_type' => $payableType,
            'payable_id' => $payableId,
            'method_code' => $methodCode,
            'provider_code' => $methodCode,
            'merchant_account' => $merchantAccount,
            'status' => PaymentAttempt::STATUS_CREATED,
            'nonterminal_guard' => PaymentAttempt::NONTERMINAL_GUARD_VALUE,
            'version' => 0,
            'amount_minor' => $snapshot->getAmountMinor(),
            'currency_code' => $snapshot->getCurrencyCode(),
            'provider_request_key' => $providerKey,
            'provider_reference' => null,
            'started_at' => $this->now,
            'reservation_expires_at' => $leaseExpires,
            'response_snapshot' => null,
            'failure_reason_code' => null,
        ];

        $commandCode = 'pc_' . bin2hex(random_bytes(6));
        $outbox = [
            'command_code' => $commandCode,
            'intent_code' => $intentCode,
            'attempt_code' => $attemptCode,
            'command_type' => PaymentProviderCommandOutbox::COMMAND_SUBMIT,
            'provider_request_key' => $providerKey,
            'status' => PaymentProviderCommandOutbox::STATUS_PENDING,
            'expected_attempt_version' => 0,
            'payload' => [
                'amount_minor' => $snapshot->getAmountMinor(),
                'currency_code' => $snapshot->getCurrencyCode(),
                'merchant_account' => $merchantAccount,
            ],
            'response' => null,
            'error_code' => null,
            'attempt_count' => 0,
            'created_at' => $this->now,
            'processed_at' => null,
        ];

        $this->memory['intents'][$intentCode] = $intent;
        $this->memory['attempts'][$attemptCode] = $attempt;
        $this->memory['outbox'][$commandCode] = $outbox;
        $this->memory['by_idem'][$idem] = ['request_hash' => $hash, 'intent_code' => $intentCode];
        $this->memory['by_payable_active'][$payableKey] = $intentCode;

        return [
            'ok' => true,
            'error_code' => null,
            'intent' => $intent,
            'attempt' => $attempt,
            'outbox' => $outbox,
            'replayed' => false,
        ];
    }

    /**
     * Create a durable zero-cash intent and asset-commit effect without a
     * PaymentAttempt or provider command.
     *
     * @return array{ok:bool,error_code:?string,intent:?array,attempt:null,outbox:?array,replayed?:bool}
     */
    public function beginZeroAmount(
        PaymentStartCommand $command,
        PayableSnapshot $snapshot,
        string $merchantAccount,
        string $environment = 'sandbox',
    ): array {
        if ($snapshot->getAmountMinor() !== 0) {
            return $this->fail('payment_zero_amount_snapshot_required');
        }
        if ($this->persistence !== null) {
            return $this->persistence->beginZeroAmount(
                $command,
                $snapshot,
                $merchantAccount,
                $environment,
            );
        }
        $idem = $command->getIdempotencyKey();
        $hash = $command->getRequestHash();
        $payableKey = implode(':', [
            $environment,
            $command->getPayableType(),
            $command->getPayableId(),
        ]);
        if (isset($this->memory['by_idem'][$idem])) {
            $previous = $this->memory['by_idem'][$idem];
            if (!hash_equals((string) $previous['request_hash'], $hash)) {
                return $this->fail(PaymentFacadeV2::ERROR_IDEMPOTENCY_CONFLICT);
            }
            $intent = $this->memory['intents'][$previous['intent_code']] ?? null;
            return [
                'ok' => $intent !== null,
                'error_code' => $intent !== null ? null : self::ERROR_PERSISTENCE,
                'intent' => $intent,
                'attempt' => null,
                'outbox' => null,
                'replayed' => true,
            ];
        }
        if (isset($this->memory['by_payable_active'][$payableKey])) {
            return $this->fail(self::ERROR_ACTIVE_INTENT_EXISTS);
        }

        $intentCode = 'pi_' . bin2hex(random_bytes(8));
        $snapshotVersion = $snapshot->getVersion() !== ''
            ? $snapshot->getVersion()
            : hash('sha256', json_encode($snapshot->getData()) ?: '{}');
        $intent = [
            'intent_code' => $intentCode,
            'environment' => $environment,
            'payable_type' => $command->getPayableType(),
            'payable_id' => $command->getPayableId(),
            'method_code' => $command->getMethodCode(),
            'provider_code' => null,
            'merchant_account' => $merchantAccount,
            'status' => PaymentIntent::STATUS_ZERO_AMOUNT_READY,
            'terminal' => false,
            'active_flag' => 1,
            'active_guard' => PaymentIntent::ACTIVE_GUARD_VALUE,
            'amount_minor' => 0,
            'currency_code' => $snapshot->getCurrencyCode(),
            'snapshot_version' => $snapshotVersion,
            'scope' => $snapshot->getArray('scope'),
            'idempotency_key' => $idem,
            'request_hash' => $hash,
            'current_attempt_code' => '',
            'payment_status' => 'asset_commit_pending',
            'attention' => null,
        ];
        $effectType = 'asset:commit:v1';
        $effectKey = PaymentEffectRecord::buildKey($intentCode, '', $effectType);
        $outbox = [
            'outbox_code' => 'po_' . substr(hash('sha256', $effectKey), 0, 40),
            'effect_key' => $effectKey,
            'intent_code' => $intentCode,
            'attempt_code' => '',
            'effect_type' => $effectType,
            'status' => 'pending',
            'payload' => [
                'payable_type' => $command->getPayableType(),
                'payable_id' => $command->getPayableId(),
                'schema_version' => '1',
            ],
            'created_at' => $this->now,
        ];
        $this->memory['intents'][$intentCode] = $intent;
        $this->memory['by_idem'][$idem] = [
            'request_hash' => $hash,
            'intent_code' => $intentCode,
        ];
        $this->memory['by_payable_active'][$payableKey] = $intentCode;
        $this->memory['effect_outbox'][$effectKey] = $outbox;

        return [
            'ok' => true,
            'error_code' => null,
            'intent' => $intent,
            'attempt' => null,
            'outbox' => $outbox,
            'replayed' => false,
        ];
    }

    /**
     * @param array<string, mixed> $intent
     * @return array{ok:bool,error_code:?string,intent:?array,attempt:?array,outbox:?array}
     */
    private function openNewAttemptOnIntent(
        array $intent,
        PaymentStartCommand $command,
        PayableSnapshot $snapshot,
        string $merchantAccount,
        string $environment,
        string $hash,
        string $idem,
    ): array {
        $intentCode = (string) $intent['intent_code'];
        $attemptCode = 'pa_' . bin2hex(random_bytes(8));
        $providerKey = PaymentProviderCommandOutbox::buildProviderRequestKey($attemptCode);

        $attempt = [
            'attempt_code' => $attemptCode,
            'intent_code' => $intentCode,
            'environment' => $environment,
            'payable_type' => $command->getPayableType(),
            'payable_id' => $command->getPayableId(),
            'method_code' => $command->getMethodCode(),
            'provider_code' => $command->getMethodCode(),
            'merchant_account' => $merchantAccount,
            'status' => PaymentAttempt::STATUS_CREATED,
            'nonterminal_guard' => PaymentAttempt::NONTERMINAL_GUARD_VALUE,
            'version' => 0,
            'amount_minor' => $snapshot->getAmountMinor(),
            'currency_code' => $snapshot->getCurrencyCode(),
            'provider_request_key' => $providerKey,
            'provider_reference' => null,
            'started_at' => $this->now,
            'reservation_expires_at' => $this->now + self::LEASE_CHECK_SECONDS,
            'response_snapshot' => null,
            'failure_reason_code' => null,
        ];

        $commandCode = 'pc_' . bin2hex(random_bytes(6));
        $outbox = [
            'command_code' => $commandCode,
            'intent_code' => $intentCode,
            'attempt_code' => $attemptCode,
            'command_type' => PaymentProviderCommandOutbox::COMMAND_SUBMIT,
            'provider_request_key' => $providerKey,
            'status' => PaymentProviderCommandOutbox::STATUS_PENDING,
            'expected_attempt_version' => 0,
            'payload' => [
                'amount_minor' => $snapshot->getAmountMinor(),
                'currency_code' => $snapshot->getCurrencyCode(),
                'merchant_account' => $merchantAccount,
            ],
            'response' => null,
            'error_code' => null,
            'attempt_count' => 0,
            'created_at' => $this->now,
            'processed_at' => null,
        ];

        $intent['current_attempt_code'] = $attemptCode;
        $intent['status'] = self::STATUS_LOCAL_ACCEPTED;
        $intent['terminal'] = false;
        $intent['request_hash'] = $hash;
        $this->memory['intents'][$intentCode] = $intent;
        $this->memory['attempts'][$attemptCode] = $attempt;
        $this->memory['outbox'][$commandCode] = $outbox;
        $this->memory['by_idem'][$idem] = ['request_hash' => $hash, 'intent_code' => $intentCode];

        return [
            'ok' => true,
            'error_code' => null,
            'intent' => $intent,
            'attempt' => $attempt,
            'outbox' => $outbox,
            'replayed' => false,
        ];
    }

    /**
     * Consumer：事务外调 Provider，第二事务 CAS 落响应。
     *
     * @return list<array<string, mixed>>
     */
    public function processPendingOutbox(int $limit = 20): array
    {
        if ($this->persistence !== null) {
            return $this->persistence->processPendingOutbox($limit);
        }
        $processed = [];
        $count = 0;
        foreach ($this->memory['outbox'] as $commandCode => $row) {
            if ($count >= $limit) {
                break;
            }
            if (($row['status'] ?? '') !== PaymentProviderCommandOutbox::STATUS_PENDING
                && ($row['status'] ?? '') !== PaymentProviderCommandOutbox::STATUS_PROCESSING
            ) {
                continue;
            }
            $processed[] = $this->processOneOutbox($commandCode);
            $count++;
        }

        return $processed;
    }

    /**
     * @return array<string, mixed>
     */
    public function processOneOutbox(string $commandCode): array
    {
        if ($this->persistence !== null) {
            return $this->persistence->processOneOutbox($commandCode);
        }
        $row = $this->memory['outbox'][$commandCode] ?? null;
        if ($row === null) {
            return ['ok' => false, 'error_code' => 'outbox_not_found'];
        }

        $attemptCode = (string) $row['attempt_code'];
        $attempt = $this->memory['attempts'][$attemptCode] ?? null;
        if ($attempt === null) {
            $row['status'] = PaymentProviderCommandOutbox::STATUS_DEAD;
            $row['error_code'] = 'attempt_missing';
            $this->memory['outbox'][$commandCode] = $row;

            return ['ok' => false, 'error_code' => 'attempt_missing', 'command_code' => $commandCode];
        }

        $row['status'] = PaymentProviderCommandOutbox::STATUS_PROCESSING;
        $row['attempt_count'] = (int) ($row['attempt_count'] ?? 0) + 1;
        $this->memory['outbox'][$commandCode] = $row;

        $providerKey = (string) $row['provider_request_key'];
        $providerResult = $this->callProviderIdempotent($providerKey, $attempt, \is_array($row['payload'] ?? null) ? $row['payload'] : []);

        if ($this->crashBeforeSecondTx) {
            // Provider 已接受；本地第二事务未提交 — 保留 PROCESSING 供重放。
            return [
                'ok' => false,
                'error_code' => 'crash_before_second_tx',
                'command_code' => $commandCode,
                'provider_key' => $providerKey,
                'provider_calls' => count($this->memory['provider_calls']),
            ];
        }

        return $this->applyProviderResultSecondTx($commandCode, $attemptCode, $providerResult, (int) $row['expected_attempt_version']);
    }

    /**
     * @param array<string, mixed> $attempt
     * @param array<string, mixed> $payload
     * @return array{status:string,provider_reference?:string,error_code?:string}
     */
    private function callProviderIdempotent(string $providerKey, array $attempt, array $payload): array
    {
        if (isset($this->memory['provider_results'][$providerKey])) {
            return $this->memory['provider_results'][$providerKey];
        }

        $this->memory['provider_calls'][] = [
            'provider_request_key' => $providerKey,
            'attempt_code' => $attempt['attempt_code'],
            'at' => $this->now,
        ];

        if ($this->providerHandler !== null) {
            $result = ($this->providerHandler)([
                'attempt' => $attempt,
                'payload' => $payload,
                'provider_request_key' => $providerKey,
            ]);
        } else {
            $result = [
                'status' => self::STATUS_SUCCEEDED,
                'provider_reference' => 'pref_' . substr(hash('sha256', $providerKey), 0, 12),
            ];
        }

        $this->memory['provider_results'][$providerKey] = $result;

        return $result;
    }

    /**
     * @param array{status:string,provider_reference?:string,error_code?:string} $providerResult
     * @return array<string, mixed>
     */
    private function applyProviderResultSecondTx(
        string $commandCode,
        string $attemptCode,
        array $providerResult,
        int $expectedVersion,
    ): array {
        $attempt = $this->memory['attempts'][$attemptCode];
        if ((int) ($attempt['version'] ?? 0) !== $expectedVersion) {
            return ['ok' => false, 'error_code' => self::ERROR_CAS_CONFLICT, 'command_code' => $commandCode];
        }

        $intentCode = (string) $attempt['intent_code'];
        $intent = $this->memory['intents'][$intentCode];
        $status = (string) ($providerResult['status'] ?? self::STATUS_UNKNOWN);

        if ($status === self::STATUS_SUCCEEDED && !$this->reservationValid) {
            $attempt['status'] = PaymentAttempt::STATUS_SUCCEEDED;
            $attempt['nonterminal_guard'] = null;
            $attempt['version'] = $expectedVersion + 1;
            $attempt['provider_reference'] = (string) ($providerResult['provider_reference'] ?? '');
            $attempt['response_snapshot'] = $providerResult;
            $intent['status'] = self::ERROR_INVENTORY_CONFLICT;
            $intent['attention'] = 'attention_required';
            $intent['payment_status'] = 'paid_inventory_conflict';
            $this->memory['effect_outbox'][] = [
                'type' => 'compensation',
                'intent_code' => $intentCode,
                'attempt_code' => $attemptCode,
                'reason' => self::ERROR_INVENTORY_CONFLICT,
            ];
            if (($intent['asset_allocations'] ?? []) !== []) {
                $this->enqueueMemoryEffect(
                    $intent,
                    $attemptCode,
                    'asset:commit:v1',
                    self::ERROR_INVENTORY_CONFLICT,
                );
            }
            $this->memory['attempts'][$attemptCode] = $attempt;
            $this->memory['intents'][$intentCode] = $intent;
            $this->finishOutbox($commandCode, $providerResult, null);

            return [
                'ok' => true,
                'error_code' => self::ERROR_INVENTORY_CONFLICT,
                'command_code' => $commandCode,
                'attempt' => $attempt,
                'intent' => $intent,
            ];
        }

        if ($status === self::STATUS_SUCCEEDED) {
            $attempt['status'] = PaymentAttempt::STATUS_SUCCEEDED;
            $attempt['nonterminal_guard'] = null;
            $attempt['version'] = $expectedVersion + 1;
            $attempt['provider_reference'] = (string) ($providerResult['provider_reference'] ?? '');
            $attempt['response_snapshot'] = $providerResult;
            $intent['status'] = self::STATUS_SUCCEEDED;
            $intent['terminal'] = true;
            $intent['payment_status'] = 'paid';
            // Keep active_guard until settle/close path; for paid Intent stay active until superseded.
            $this->memory['ledger'][] = [
                'ledger_code' => 'pl_' . bin2hex(random_bytes(4)),
                'intent_code' => $intentCode,
                'attempt_code' => $attemptCode,
                'direction' => 'debit',
                'amount_minor' => (int) $attempt['amount_minor'],
                'currency' => (string) $attempt['currency_code'],
            ];
            $this->memory['effect_outbox'][] = [
                'type' => 'paid_notify',
                'intent_code' => $intentCode,
                'attempt_code' => $attemptCode,
            ];
            if (($intent['asset_allocations'] ?? []) !== []) {
                $this->enqueueMemoryEffect(
                    $intent,
                    $attemptCode,
                    'asset:commit:v1',
                );
            }
        } elseif ($status === self::STATUS_FAILED) {
            $attempt['status'] = PaymentAttempt::STATUS_FAILED;
            $attempt['nonterminal_guard'] = null;
            $attempt['version'] = $expectedVersion + 1;
            $attempt['failure_reason_code'] = (string) ($providerResult['error_code'] ?? 'provider_failed');
            $attempt['response_snapshot'] = $providerResult;
            $intent['status'] = self::STATUS_FAILED;
            $intent['terminal'] = false; // Intent still active for retry
            $intent['current_attempt_code'] = $attemptCode;
            if (($intent['asset_allocations'] ?? []) !== []) {
                $this->enqueueMemoryEffect(
                    $intent,
                    $attemptCode,
                    'asset:release:v1',
                    (string) ($providerResult['error_code'] ?? 'provider_failed'),
                );
            }
        } else {
            // Unknown：继续占用 nonterminal_guard，禁止新 Attempt。
            $attempt['status'] = self::STATUS_UNKNOWN;
            $attempt['nonterminal_guard'] = PaymentAttempt::NONTERMINAL_GUARD_VALUE;
            $attempt['version'] = $expectedVersion + 1;
            $attempt['response_snapshot'] = $providerResult;
            $intent['status'] = self::STATUS_UNKNOWN;
            $intent['terminal'] = false;
        }

        $this->memory['attempts'][$attemptCode] = $attempt;
        $this->memory['intents'][$intentCode] = $intent;
        $this->finishOutbox($commandCode, $providerResult, null);

        return [
            'ok' => true,
            'error_code' => null,
            'command_code' => $commandCode,
            'attempt' => $attempt,
            'intent' => $intent,
        ];
    }

    /** @param array<string, mixed> $intent */
    private function enqueueMemoryEffect(
        array $intent,
        string $attemptCode,
        string $effectType,
        ?string $reason = null,
    ): void {
        $intentCode = (string) ($intent['intent_code'] ?? '');
        $effectKey = PaymentEffectRecord::buildKey(
            $intentCode,
            $attemptCode,
            $effectType,
        );
        foreach ($this->memory['effect_outbox'] as $existing) {
            if (($existing['effect_key'] ?? null) === $effectKey) {
                return;
            }
        }
        $this->memory['effect_outbox'][] = [
            'outbox_code' => 'po_' . substr(hash('sha256', $effectKey), 0, 40),
            'effect_key' => $effectKey,
            'intent_code' => $intentCode,
            'attempt_code' => $attemptCode,
            'effect_type' => $effectType,
            'status' => 'pending',
            'payable_type' => (string) ($intent['payable_type'] ?? ''),
            'payable_id' => (string) ($intent['payable_id'] ?? ''),
            'schema_version' => '1',
            'reason' => $reason,
        ];
    }

    /**
     * @param array<string, mixed> $providerResult
     */
    private function finishOutbox(string $commandCode, array $providerResult, ?string $errorCode): void
    {
        $row = $this->memory['outbox'][$commandCode];
        $row['status'] = PaymentProviderCommandOutbox::STATUS_DONE;
        $row['response'] = $providerResult;
        $row['error_code'] = $errorCode;
        $row['processed_at'] = $this->now;
        $this->memory['outbox'][$commandCode] = $row;
    }

    public function extendLease(string $attemptCode): bool
    {
        if ($this->persistence !== null) {
            return $this->persistence->extendLease($attemptCode);
        }
        $attempt = $this->memory['attempts'][$attemptCode] ?? null;
        if ($attempt === null) {
            return false;
        }
        if (($attempt['nonterminal_guard'] ?? null) !== PaymentAttempt::NONTERMINAL_GUARD_VALUE) {
            return false;
        }
        $started = (int) ($attempt['started_at'] ?? $this->now);
        $hardCap = $started + self::LEASE_HARD_CAP_SECONDS;
        if ($this->now >= $hardCap) {
            return false;
        }
        $extended = min($this->now + self::LEASE_EXTEND_SECONDS, $hardCap);
        $attempt['reservation_expires_at'] = $extended;
        $this->memory['attempts'][$attemptCode] = $attempt;

        return true;
    }

    public function isLeaseExpired(string $attemptCode): bool
    {
        if ($this->persistence !== null) {
            return $this->persistence->isLeaseExpired($attemptCode);
        }
        $attempt = $this->memory['attempts'][$attemptCode] ?? null;
        if ($attempt === null) {
            return true;
        }
        $started = (int) ($attempt['started_at'] ?? 0);
        if ($this->now >= $started + self::LEASE_HARD_CAP_SECONDS) {
            return true;
        }

        return $this->now >= (int) ($attempt['reservation_expires_at'] ?? 0);
    }

    /**
     * @return array{ok:bool,error_code:?string,intent:?array,attempt:?array,outbox:?array,replayed?:bool}
     */
    private function fail(string $code): array
    {
        return [
            'ok' => false,
            'error_code' => $code,
            'intent' => null,
            'attempt' => null,
            'outbox' => null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findIntentByPayable(string $payableType, string $payableId, string $environment = 'sandbox'): ?array
    {
        if ($this->persistence !== null) {
            return $this->persistence->findIntentByPayable($payableType, $payableId, $environment);
        }
        $key = $environment . ':' . $payableType . ':' . $payableId;
        $code = $this->memory['by_payable_active'][$key] ?? null;
        if ($code !== null) {
            return $this->memory['intents'][$code] ?? null;
        }
        foreach ($this->memory['intents'] as $intent) {
            if (($intent['payable_type'] ?? '') === $payableType
                && ($intent['payable_id'] ?? '') === $payableId
            ) {
                return $intent;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $intent
     */
    public function updateIntent(array $intent): void
    {
        if ($this->persistence !== null) {
            $this->persistence->updateIntent($intent);

            return;
        }
        $code = (string) ($intent['intent_code'] ?? '');
        if ($code === '') {
            throw new \InvalidArgumentException('intent_code_required');
        }
        $this->memory['intents'][$code] = $intent;
    }

    /**
     * Bind reservation for webhook inventory commit（P2F-004）。
     */
    public function bindReservation(string $attemptCode, string $reservationUuid): void
    {
        if ($this->persistence !== null) {
            $this->persistence->bindReservation($attemptCode, $reservationUuid);

            return;
        }
        if (!isset($this->memory['attempts'][$attemptCode])) {
            throw new \InvalidArgumentException('attempt_not_found:' . $attemptCode);
        }
        $this->memory['attempts'][$attemptCode]['reservation_uuid'] = $reservationUuid;
    }

    /**
     * Apply webhook status transition with monotonic CAS（P2F-004）。
     *
     * @return array{ok:bool,error_code:?string,ignored?:bool,intent:?array,attempt:?array}
     */
    public function applyWebhookTransition(
        string $intentCode,
        string $transition,
        ?int $expectedAttemptVersion = null,
        ?string $expectedAttemptCode = null,
    ): array
    {
        if ($this->persistence !== null) {
            return $this->persistence->applyWebhookTransition(
                $intentCode,
                $transition,
                $expectedAttemptVersion,
                $expectedAttemptCode,
            );
        }
        $intent = $this->memory['intents'][$intentCode] ?? null;
        if ($intent === null) {
            return ['ok' => false, 'error_code' => 'intent_not_found', 'intent' => null, 'attempt' => null];
        }
        $attemptCode = (string) ($intent['current_attempt_code'] ?? '');
        $attempt = $this->memory['attempts'][$attemptCode] ?? null;
        if ($attempt === null) {
            return ['ok' => false, 'error_code' => 'attempt_not_found', 'intent' => $intent, 'attempt' => null];
        }
        if ($expectedAttemptCode !== null
            && trim($expectedAttemptCode) !== ''
            && !hash_equals(trim($expectedAttemptCode), $attemptCode)
        ) {
            return [
                'ok' => false,
                'error_code' => 'payment_webhook_attempt_mismatch',
                'intent' => $intent,
                'attempt' => $attempt,
            ];
        }
        if ($expectedAttemptVersion !== null && (int) ($attempt['version'] ?? 0) !== $expectedAttemptVersion) {
            return ['ok' => false, 'error_code' => self::ERROR_CAS_CONFLICT, 'intent' => $intent, 'attempt' => $attempt];
        }

        $current = (string) ($attempt['status'] ?? '');
        $rank = static function (string $status): int {
            return match ($status) {
                PaymentAttempt::STATUS_SUCCEEDED => 100,
                self::STATUS_SUCCEEDED => 100,
                PaymentAttempt::STATUS_FAILED, self::STATUS_FAILED => 50,
                self::STATUS_UNKNOWN, PaymentAttempt::STATUS_PROCESSING, PaymentAttempt::STATUS_PROVIDER_PENDING => 20,
                PaymentAttempt::STATUS_CREATED, self::STATUS_LOCAL_ACCEPTED => 10,
                default => 0,
            };
        };

        $incoming = match (strtolower($transition)) {
            'paid', 'succeeded', 'captured', 'success' => PaymentAttempt::STATUS_SUCCEEDED,
            'failed', 'fail' => PaymentAttempt::STATUS_FAILED,
            'pending', 'processing' => PaymentAttempt::STATUS_PROCESSING,
            default => strtolower($transition),
        };

        // Monotonic：任何较低 rank 的旧事件都只记录 ignored，不倒退状态。
        if ($rank($current) > $rank($incoming)) {
            return [
                'ok' => true,
                'error_code' => null,
                'ignored' => true,
                'intent' => $intent,
                'attempt' => $attempt,
            ];
        }
        if ($current === $incoming) {
            return [
                'ok' => true,
                'error_code' => null,
                'ignored' => false,
                'replayed' => true,
                'intent' => $intent,
                'attempt' => $attempt,
            ];
        }

        if ($incoming === PaymentAttempt::STATUS_SUCCEEDED) {
            $attempt['status'] = PaymentAttempt::STATUS_SUCCEEDED;
            $attempt['nonterminal_guard'] = null;
            $attempt['version'] = (int) ($attempt['version'] ?? 0) + 1;
            $intent['status'] = self::STATUS_SUCCEEDED;
            $intent['terminal'] = true;
            $intent['payment_status'] = 'paid';
        } elseif ($incoming === PaymentAttempt::STATUS_FAILED) {
            $attempt['status'] = PaymentAttempt::STATUS_FAILED;
            $attempt['nonterminal_guard'] = null;
            $attempt['version'] = (int) ($attempt['version'] ?? 0) + 1;
            $intent['status'] = self::STATUS_FAILED;
            $intent['terminal'] = false;
        } else {
            $attempt['status'] = $incoming;
            $attempt['version'] = (int) ($attempt['version'] ?? 0) + 1;
            $intent['status'] = $incoming;
        }

        $this->memory['attempts'][$attemptCode] = $attempt;
        $this->memory['intents'][$intentCode] = $intent;

        return [
            'ok' => true,
            'error_code' => null,
            'ignored' => false,
            'intent' => $intent,
            'attempt' => $attempt,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getIntent(string $intentCode): ?array
    {
        if ($this->persistence !== null) {
            return $this->persistence->getIntent($intentCode);
        }
        return $this->memory['intents'][$intentCode] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAttempt(string $attemptCode): ?array
    {
        if ($this->persistence !== null) {
            return $this->persistence->getAttempt($attemptCode);
        }
        return $this->memory['attempts'][$attemptCode] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAttemptsForIntent(string $intentCode): array
    {
        if ($this->persistence !== null) {
            return $this->persistence->listAttemptsForIntent($intentCode);
        }
        $out = [];
        foreach ($this->memory['attempts'] as $attempt) {
            if (($attempt['intent_code'] ?? '') === $intentCode) {
                $out[] = $attempt;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function providerCalls(): array
    {
        if ($this->persistence !== null) {
            return $this->persistence->providerCalls();
        }
        return $this->memory['provider_calls'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function ledgerEntries(): array
    {
        if ($this->persistence !== null) {
            return $this->persistence->ledgerEntries();
        }
        return $this->memory['ledger'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function effectOutbox(): array
    {
        if ($this->persistence !== null) {
            return $this->persistence->effectOutbox();
        }
        return $this->memory['effect_outbox'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pendingOutbox(): array
    {
        if ($this->persistence !== null) {
            return $this->persistence->pendingOutbox();
        }
        return array_values(array_filter(
            $this->memory['outbox'],
            static fn (array $r): bool => \in_array((string) ($r['status'] ?? ''), [
                PaymentProviderCommandOutbox::STATUS_PENDING,
                PaymentProviderCommandOutbox::STATUS_PROCESSING,
            ], true)
        ));
    }

    public function countActiveGuardsForPayable(string $environment, string $payableType, string $payableId): int
    {
        if ($this->persistence !== null) {
            return $this->persistence->countActiveGuardsForPayable(
                $environment,
                $payableType,
                $payableId,
            );
        }
        $n = 0;
        foreach ($this->memory['intents'] as $intent) {
            if (($intent['environment'] ?? '') === $environment
                && ($intent['payable_type'] ?? '') === $payableType
                && ($intent['payable_id'] ?? '') === $payableId
                && ($intent['active_guard'] ?? null) === PaymentIntent::ACTIVE_GUARD_VALUE
            ) {
                $n++;
            }
        }

        return $n;
    }

    public function countOpenNonterminalGuards(string $intentCode): int
    {
        if ($this->persistence !== null) {
            return $this->persistence->countOpenNonterminalGuards($intentCode);
        }
        $n = 0;
        foreach ($this->memory['attempts'] as $attempt) {
            if (($attempt['intent_code'] ?? '') === $intentCode
                && ($attempt['nonterminal_guard'] ?? null) === PaymentAttempt::NONTERMINAL_GUARD_VALUE
            ) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param array<string, mixed> $intent
     * @param array<string, mixed>|null $attempt
     */
    public function toOperationResult(array $intent, ?array $attempt = null, ?string $errorCode = null): PaymentOperationResult
    {
        if ($this->persistence !== null) {
            return $this->persistence->toOperationResult($intent, $attempt, $errorCode);
        }
        $attempt ??= isset($intent['current_attempt_code'])
            ? ($this->memory['attempts'][(string) $intent['current_attempt_code']] ?? null)
            : null;

        return PaymentOperationResult::create(
            intentCode: (string) $intent['intent_code'],
            attemptCode: $attempt !== null ? (string) $attempt['attempt_code'] : (string) ($intent['current_attempt_code'] ?? ''),
            status: (string) ($attempt['status'] ?? $intent['status']),
            terminal: (bool) ($intent['terminal'] ?? false),
            nextActionType: PaymentOperationResult::NEXT_POLL,
            nextAction: ['hint' => 'await_provider_command'],
            errorCode: $errorCode,
            snapshotVersion: (string) ($intent['snapshot_version'] ?? ''),
            amountMinor: (int) ($intent['amount_minor'] ?? 0),
            currencyCode: (string) ($intent['currency_code'] ?? ''),
            scope: \is_array($intent['scope'] ?? null) ? $intent['scope'] : [],
            merchantAccount: (string) ($intent['merchant_account'] ?? ''),
            payableType: (string) ($intent['payable_type'] ?? ''),
            payableId: (string) ($intent['payable_id'] ?? ''),
        );
    }
}
