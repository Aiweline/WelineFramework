<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Payment\Api\Data\PayableSnapshot;
use Weline\Payment\Api\Data\PaymentOperationRequest;
use Weline\Payment\Api\Data\PaymentOperationResult;
use Weline\Payment\Api\Data\PaymentRequest;
use Weline\Payment\Api\Data\PaymentResult;
use Weline\Payment\Api\Data\PaymentStartCommand;
use Weline\Payment\Api\Data\PaymentEffectRecord;
use Weline\Payment\Model\PaymentAttempt;
use Weline\Payment\Model\PaymentIdempotency;
use Weline\Payment\Model\PaymentIntent;
use Weline\Payment\Model\PaymentLedger;
use Weline\Payment\Model\PaymentOutbox;
use Weline\Payment\Model\PaymentProviderCommandOutbox;

/**
 * Payment V2 durable state engine.
 *
 * Coordination precondition: acquire the short-lived payable lock.
 * Transaction 1: idempotency + Intent + Attempt + provider command outbox.
 * Outside transaction: Provider call with a stable provider_request_key.
 * Transaction 2: Attempt CAS + Intent + ledger/effect outbox + command completion.
 */
final class PaymentIntentPersistenceService
{
    /** @var (\Closure(array<string,mixed>): array{status:string,provider_reference?:string,error_code?:string})|null */
    private $providerHandler = null;

    /** @var list<array<string, mixed>> */
    private array $providerCalls = [];

    private int $now;
    private bool $newAttemptsEnabled = true;
    private bool $crashBeforeSecondTx = false;
    private bool $reservationValid = true;

    public function __construct(
        private readonly ObjectManager $objectManager,
        private readonly DatabaseTransactionRunnerInterface $transactions,
        private readonly PaymentMethodManager $methodManager,
        private readonly PaymentIdempotencyService $idempotency,
        private readonly PaymentLockService $locks,
        int $now = 0,
    ) {
        $this->now = $now > 0 ? $now : time();
    }

    public function setNow(int $timestamp): void
    {
        $this->now = $timestamp;
    }

    public function now(): int
    {
        return $this->now;
    }

    public function setNewAttemptsEnabled(bool $enabled): void
    {
        $this->newAttemptsEnabled = $enabled;
    }

    public function setCrashBeforeSecondTx(bool $crash): void
    {
        $this->crashBeforeSecondTx = $crash;
    }

    public function setReservationValid(bool $valid): void
    {
        $this->reservationValid = $valid;
    }

    /**
     * Testing seam only. Production resolves ProviderInterface through PaymentMethodManager.
     *
     * @param (\Closure(array<string,mixed>): array{status:string,provider_reference?:string,error_code?:string}) $handler
     */
    public function setProviderHandler(callable $handler): void
    {
        $this->providerHandler = $handler;
    }

    /**
     * @return array{ok:bool,error_code:?string,intent:?array,attempt:?array,outbox:?array,replayed?:bool}
     */
    public function beginStart(
        PaymentStartCommand $command,
        PayableSnapshot $snapshot,
        string $merchantAccount,
        string $environment = 'sandbox',
    ): array {
        $intentModel = $this->newModel(PaymentIntent::class);
        $lockContext = [
            'environment' => $environment,
            'merchant_account' => $merchantAccount,
            'payable_type' => $command->getPayableType(),
            'provider_code' => $command->getMethodCode(),
        ];
        $lockKey = 'intent:' . $command->getPayableType() . ':' . $command->getPayableId();
        $lockOwner = bin2hex(random_bytes(32));

        try {
            $this->acquireStartLock(
                $command,
                $lockKey,
                $lockOwner,
                $lockContext,
            );
            try {
                return $this->transactions->run(
                    $intentModel->getConnection(),
                    fn (): array => $this->beginStartTransaction(
                        $command,
                        $snapshot,
                        $merchantAccount,
                        $environment,
                    ),
                );
            } finally {
                try {
                    $this->locks->release(
                        $command->getPayableId(),
                        $command->getMethodCode(),
                        'payment_start',
                        $lockKey,
                        $lockOwner,
                        $lockContext,
                    );
                } catch (\Throwable) {
                }
            }
        } catch (\Throwable $throwable) {
            $message = $throwable->getMessage();
            if (str_contains($message, PaymentFacadeV2::ERROR_IDEMPOTENCY_CONFLICT)) {
                return $this->fail(PaymentFacadeV2::ERROR_IDEMPOTENCY_CONFLICT);
            }
            if (str_contains($message, 'payment_lock_already_acquired')) {
                return $this->fail(PaymentIntentOrchestrator::ERROR_ACTIVE_INTENT_EXISTS);
            }
            if (function_exists('w_log_error')) {
                w_log_error(
                    '[PaymentIntentPersistence] first_transaction_failed',
                    ['error_code' => 'payment_persistence_failed'],
                    'payment',
                );
            }

            return $this->fail(PaymentIntentOrchestrator::ERROR_PERSISTENCE);
        }
    }

    /**
     * Create a zero-cash intent and canonical asset-commit outbox without a
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
        $intentModel = $this->newModel(PaymentIntent::class);
        $context = [
            'environment' => $environment,
            'merchant_account' => $merchantAccount,
            'payable_type' => $command->getPayableType(),
            'provider_code' => 'asset',
        ];
        $lockKey = 'intent:' . $command->getPayableType() . ':' . $command->getPayableId();
        $lockOwner = bin2hex(random_bytes(32));

        try {
            $this->acquireStartLock($command, $lockKey, $lockOwner, $context);
            try {
                return $this->transactions->run(
                    $intentModel->getConnection(),
                    function () use (
                        $command,
                        $snapshot,
                        $merchantAccount,
                        $environment,
                        $context,
                    ): array {
                        $begin = $this->idempotency->begin(
                            $command->getIdempotencyKey(),
                            $command->getPayableId(),
                            $command->getMethodCode(),
                            'payment_start',
                            $command->getRequestHash(),
                            $context,
                        );
                        if ($begin['state'] === PaymentIdempotencyService::STATE_REPLAY) {
                            /** @var PaymentIdempotency $record */
                            $record = $begin['record'];
                            $result = $record->getResultPayload();
                            $intent = $this->loadIntent((string) ($result['intent_code'] ?? ''));
                            if (!$intent instanceof PaymentIntent) {
                                return $this->fail(PaymentIntentOrchestrator::ERROR_PERSISTENCE);
                            }
                            return [
                                'ok' => true,
                                'error_code' => null,
                                'intent' => $this->intentToArray($intent),
                                'attempt' => null,
                                'outbox' => null,
                                'replayed' => true,
                            ];
                        }
                        if ($begin['state'] === PaymentIdempotencyService::STATE_FAILED) {
                            $failure = $begin['failure'] ?? [];
                            return $this->fail((string) (
                                $failure['error_code']
                                ?? PaymentIntentOrchestrator::ERROR_ACTIVE_INTENT_EXISTS
                            ));
                        }
                        if (!$begin['should_execute']) {
                            return $this->fail(
                                PaymentIntentOrchestrator::ERROR_IDEMPOTENCY_IN_PROGRESS,
                            );
                        }
                        if ($this->loadActiveIntent(
                            $environment,
                            $command->getPayableType(),
                            $command->getPayableId(),
                        ) instanceof PaymentIntent) {
                            $this->failIdempotency(
                                $command,
                                $context,
                                PaymentIntentOrchestrator::ERROR_ACTIVE_INTENT_EXISTS,
                            );
                            return $this->fail(
                                PaymentIntentOrchestrator::ERROR_ACTIVE_INTENT_EXISTS,
                            );
                        }
                        if (!$this->newAttemptsEnabled) {
                            $this->failIdempotency(
                                $command,
                                $context,
                                PaymentIntentOrchestrator::ERROR_NEW_ATTEMPT_DISABLED,
                            );
                            return $this->fail(
                                PaymentIntentOrchestrator::ERROR_NEW_ATTEMPT_DISABLED,
                            );
                        }

                        $intentCode = 'pi_' . bin2hex(random_bytes(8));
                        $snapshotVersion = $snapshot->getVersion() !== ''
                            ? $snapshot->getVersion()
                            : $this->snapshotVersion($snapshot);
                        $scope = $snapshot->getArray('scope');
                        $scopeCode = $this->scopeCode(
                            $scope,
                            $snapshot->getCurrencyCode(),
                        );
                        $now = $this->dateTime($this->now);
                        $amountSnapshot = $this->amountSnapshot(
                            $snapshot,
                            $snapshotVersion,
                        );
                        $amountSnapshot['amounts'] = $snapshot->getArray('amounts');
                        $amountSnapshot['asset_allocations'] = $snapshot->getArray(
                            'asset_allocations',
                        );
                        $amountSnapshot['payment_status'] = 'asset_commit_pending';

                        $intent = $this->newModel(PaymentIntent::class);
                        $intent->setData([
                            PaymentIntent::schema_fields_INTENT_CODE => $intentCode,
                            PaymentIntent::schema_fields_ENVIRONMENT => $environment,
                            PaymentIntent::schema_fields_PAYABLE_TYPE =>
                                $command->getPayableType(),
                            PaymentIntent::schema_fields_PAYABLE_ID =>
                                $command->getPayableId(),
                            PaymentIntent::schema_fields_METHOD_CODE => 'asset',
                            PaymentIntent::schema_fields_PROVIDER_CODE => null,
                            PaymentIntent::schema_fields_MERCHANT_ACCOUNT => null,
                            PaymentIntent::schema_fields_SCOPE => $scopeCode,
                            PaymentIntent::schema_fields_SCOPE_CHAIN_HASH =>
                                hash('sha256', $this->json($scope)),
                            PaymentIntent::schema_fields_SCOPE_VERSION => $snapshotVersion,
                            PaymentIntent::schema_fields_EFFECTIVE_CONFIG_SNAPSHOT_CODE =>
                                substr(hash('sha256', 'asset|' . $scopeCode), 0, 64),
                            PaymentIntent::schema_fields_AMOUNT_MINOR => 0,
                            PaymentIntent::schema_fields_CURRENCY_CODE =>
                                $snapshot->getCurrencyCode(),
                            PaymentIntent::schema_fields_PRECISION => $snapshot->getPrecision(),
                            PaymentIntent::schema_fields_STATUS =>
                                PaymentIntent::STATUS_ZERO_AMOUNT_READY,
                            PaymentIntent::schema_fields_ACTIVE_FLAG => 1,
                            PaymentIntent::schema_fields_ACTIVE_GUARD =>
                                PaymentIntent::ACTIVE_GUARD_VALUE,
                            PaymentIntent::schema_fields_REQUEST_HASH =>
                                $command->getRequestHash(),
                            PaymentIntent::schema_fields_IDEMPOTENCY_KEY =>
                                $command->getIdempotencyKey(),
                            PaymentIntent::schema_fields_AMOUNT_SNAPSHOT =>
                                $this->json($amountSnapshot),
                            PaymentIntent::schema_fields_CONFIG_SNAPSHOT => $this->json([
                                'method_code' => 'asset',
                                'provider_code' => null,
                                'merchant_account' => null,
                                'scope' => $scopeCode,
                            ]),
                            PaymentIntent::schema_fields_TERMS_SNAPSHOT => $this->json([
                                'return_url' => $command->getReturnUrl(),
                            ]),
                            PaymentIntent::schema_fields_CREATED_AT => $now,
                            PaymentIntent::schema_fields_UPDATED_AT => $now,
                        ])->save();

                        $effectType = 'asset:commit:v1';
                        $effectKey = PaymentEffectRecord::buildKey(
                            $intentCode,
                            '',
                            $effectType,
                        );
                        $outbox = $this->newModel(PaymentOutbox::class);
                        $outbox->setData([
                            PaymentOutbox::schema_fields_OUTBOX_CODE =>
                                'po_' . substr(hash('sha256', $effectKey), 0, 40),
                            PaymentOutbox::schema_fields_EFFECT_KEY => $effectKey,
                            PaymentOutbox::schema_fields_INBOX_CODE => null,
                            PaymentOutbox::schema_fields_INTENT_CODE => $intentCode,
                            PaymentOutbox::schema_fields_ATTEMPT_CODE => null,
                            PaymentOutbox::schema_fields_EFFECT_TYPE => $effectType,
                            PaymentOutbox::schema_fields_STATUS =>
                                PaymentOutbox::STATUS_PENDING,
                            PaymentOutbox::schema_fields_PAYLOAD_JSON => $this->json([
                                'payable_type' => $command->getPayableType(),
                                'payable_id' => $command->getPayableId(),
                                'schema_version' => '1',
                            ]),
                            PaymentOutbox::schema_fields_CREATED_AT => $now,
                        ])->save();

                        $this->idempotency->complete(
                            $command->getIdempotencyKey(),
                            $command->getPayableId(),
                            $command->getMethodCode(),
                            'payment_start',
                            [
                                'result_code' => 'payment_zero_amount_ready',
                                'request_hash' => $command->getRequestHash(),
                                'intent_code' => $intentCode,
                                'attempt_code' => null,
                                'command_code' => null,
                            ],
                            $command->getRequestHash(),
                            $context,
                        );

                        return [
                            'ok' => true,
                            'error_code' => null,
                            'intent' => $this->intentToArray($intent),
                            'attempt' => null,
                            'outbox' => [
                                'outbox_code' => (string) $outbox->getData(
                                    PaymentOutbox::schema_fields_OUTBOX_CODE,
                                ),
                                'effect_key' => $effectKey,
                                'effect_type' => $effectType,
                                'status' => PaymentOutbox::STATUS_PENDING,
                            ],
                            'replayed' => false,
                        ];
                    },
                );
            } finally {
                try {
                    $this->locks->release(
                        $command->getPayableId(),
                        $command->getMethodCode(),
                        'payment_start',
                        $lockKey,
                        $lockOwner,
                        $context,
                    );
                } catch (Throwable) {
                }
            }
        } catch (Throwable $throwable) {
            if (str_contains(
                $throwable->getMessage(),
                PaymentFacadeV2::ERROR_IDEMPOTENCY_CONFLICT,
            )) {
                return $this->fail(PaymentFacadeV2::ERROR_IDEMPOTENCY_CONFLICT);
            }
            return $this->fail(PaymentIntentOrchestrator::ERROR_PERSISTENCE);
        }
    }

    /**
     * The lock uses its own short transaction so a unique-key race cannot mark
     * the Payment first transaction rollback-only.
     *
     * @param array<string, mixed> $context
     */
    private function acquireStartLock(
        PaymentStartCommand $command,
        string $lockKey,
        string $ownerToken,
        array $context,
    ): void {
        $deadline = microtime(true) + 5.0;
        do {
            try {
                $this->locks->acquire(
                    $command->getPayableId(),
                    $command->getMethodCode(),
                    'payment_start',
                    $lockKey,
                    $context,
                    $ownerToken,
                    30,
                    ['idempotency_key_hash' => hash('sha256', $command->getIdempotencyKey())],
                );

                return;
            } catch (\Throwable $exception) {
                $retryableContention = str_contains($exception->getMessage(), 'payment_lock_already_acquired')
                    || str_contains($exception->getMessage(), 'uniq_payment_lock')
                    || str_contains($exception->getMessage(), 'duplicate key value');
                if (!$retryableContention
                    || microtime(true) >= $deadline
                ) {
                    throw $exception;
                }
                SchedulerSystem::usleep(20_000);
            }
        } while (true);
    }

    /**
     * @return array{ok:bool,error_code:?string,intent:?array,attempt:?array,outbox:?array,replayed?:bool}
     */
    private function beginStartTransaction(
        PaymentStartCommand $command,
        PayableSnapshot $snapshot,
        string $merchantAccount,
        string $environment,
    ): array {
        $idem = $command->getIdempotencyKey();
        $hash = $command->getRequestHash();
        $payableType = $command->getPayableType();
        $payableId = $command->getPayableId();
        $methodCode = $command->getMethodCode();
        $context = [
            'environment' => $environment,
            'merchant_account' => $merchantAccount,
            'payable_type' => $payableType,
            'provider_code' => $methodCode,
        ];
        $begin = $this->idempotency->begin(
            $idem,
            $payableId,
            $methodCode,
            'payment_start',
            $hash,
            $context,
        );
        if ($begin['state'] === PaymentIdempotencyService::STATE_REPLAY) {
            return $this->replayFromIdempotency($begin['record']);
        }
        if ($begin['state'] === PaymentIdempotencyService::STATE_FAILED) {
            $failure = $begin['failure'] ?? [];

            return $this->fail((string) ($failure['error_code'] ?? PaymentIntentOrchestrator::ERROR_ACTIVE_INTENT_EXISTS));
        }
        if (!$begin['should_execute']) {
            return $this->fail(PaymentIntentOrchestrator::ERROR_IDEMPOTENCY_IN_PROGRESS);
        }

        $snapshotVersion = $snapshot->getVersion() !== ''
            ? $snapshot->getVersion()
            : $this->snapshotVersion($snapshot);
        $active = $this->loadActiveIntent($environment, $payableType, $payableId);
        if ($active instanceof PaymentIntent) {
            $intent = $this->intentToArray($active);
            $attemptModel = $this->loadLatestAttempt((string) $intent['intent_code']);
            $attempt = $attemptModel instanceof PaymentAttempt
                ? $this->attemptToArray($attemptModel)
                : null;

            if ($attempt !== null
                && ($attempt['nonterminal_guard'] ?? null) === PaymentAttempt::NONTERMINAL_GUARD_VALUE
            ) {
                $this->failIdempotency(
                    $command,
                    $context,
                    PaymentIntentOrchestrator::ERROR_NONTERMINAL_ATTEMPT,
                );

                return $this->fail(PaymentIntentOrchestrator::ERROR_NONTERMINAL_ATTEMPT);
            }
            if ((string) ($intent['snapshot_version'] ?? '') !== $snapshotVersion) {
                $this->failIdempotency(
                    $command,
                    $context,
                    PaymentIntentOrchestrator::ERROR_SNAPSHOT_CHANGED,
                );

                return $this->fail(PaymentIntentOrchestrator::ERROR_SNAPSHOT_CHANGED);
            }
            if ($attempt !== null
                && ($attempt['status'] ?? '') === PaymentAttempt::STATUS_FAILED
                && ($attempt['nonterminal_guard'] ?? null) === null
            ) {
                if (!$this->newAttemptsEnabled) {
                    $this->failIdempotency(
                        $command,
                        $context,
                        PaymentIntentOrchestrator::ERROR_NEW_ATTEMPT_DISABLED,
                    );

                    return $this->fail(PaymentIntentOrchestrator::ERROR_NEW_ATTEMPT_DISABLED);
                }

                return $this->createAttemptOnExistingIntent(
                    $active,
                    $command,
                    $snapshot,
                    $merchantAccount,
                    $environment,
                    $snapshotVersion,
                    $context,
                );
            }

            $this->failIdempotency(
                $command,
                $context,
                PaymentIntentOrchestrator::ERROR_ACTIVE_INTENT_EXISTS,
            );

            return $this->fail(PaymentIntentOrchestrator::ERROR_ACTIVE_INTENT_EXISTS);
        }

        if (!$this->newAttemptsEnabled) {
            $this->failIdempotency(
                $command,
                $context,
                PaymentIntentOrchestrator::ERROR_NEW_ATTEMPT_DISABLED,
            );

            return $this->fail(PaymentIntentOrchestrator::ERROR_NEW_ATTEMPT_DISABLED);
        }

        return $this->createIntentAttemptAndOutbox(
            $command,
            $snapshot,
            $merchantAccount,
            $environment,
            $snapshotVersion,
            $context,
        );
    }

    /**
     * @param array<string, mixed> $context
     * @return array{ok:bool,error_code:?string,intent:?array,attempt:?array,outbox:?array,replayed?:bool}
     */
    private function createIntentAttemptAndOutbox(
        PaymentStartCommand $command,
        PayableSnapshot $snapshot,
        string $merchantAccount,
        string $environment,
        string $snapshotVersion,
        array $context,
    ): array {
        $intentCode = 'pi_' . bin2hex(random_bytes(8));
        $attemptCode = 'pa_' . bin2hex(random_bytes(8));
        $commandCode = 'pc_' . bin2hex(random_bytes(6));
        $providerKey = PaymentProviderCommandOutbox::buildProviderRequestKey($attemptCode);
        $now = $this->dateTime($this->now);
        $scope = $snapshot->getArray('scope');
        $scopeCode = $this->scopeCode($scope, $snapshot->getCurrencyCode());
        $amountSnapshot = $this->amountSnapshot($snapshot, $snapshotVersion);

        $intentModel = $this->newModel(PaymentIntent::class);
        $intentModel->setData([
            PaymentIntent::schema_fields_INTENT_CODE => $intentCode,
            PaymentIntent::schema_fields_ENVIRONMENT => $environment,
            PaymentIntent::schema_fields_PAYABLE_TYPE => $command->getPayableType(),
            PaymentIntent::schema_fields_PAYABLE_ID => $command->getPayableId(),
            PaymentIntent::schema_fields_METHOD_CODE => $command->getMethodCode(),
            PaymentIntent::schema_fields_PROVIDER_CODE => $command->getMethodCode(),
            PaymentIntent::schema_fields_MERCHANT_ACCOUNT => $merchantAccount,
            PaymentIntent::schema_fields_SCOPE => $scopeCode,
            PaymentIntent::schema_fields_SCOPE_CHAIN_HASH => hash('sha256', $this->json($scope)),
            PaymentIntent::schema_fields_SCOPE_VERSION => $snapshotVersion,
            PaymentIntent::schema_fields_EFFECTIVE_CONFIG_SNAPSHOT_CODE => substr(hash('sha256', $command->getMethodCode() . '|' . $merchantAccount . '|' . $scopeCode), 0, 64),
            PaymentIntent::schema_fields_AMOUNT_MINOR => $snapshot->getAmountMinor(),
            PaymentIntent::schema_fields_CURRENCY_CODE => $snapshot->getCurrencyCode(),
            PaymentIntent::schema_fields_PRECISION => $snapshot->getPrecision(),
            PaymentIntent::schema_fields_STATUS => PaymentIntentOrchestrator::STATUS_LOCAL_ACCEPTED,
            PaymentIntent::schema_fields_ACTIVE_FLAG => 1,
            PaymentIntent::schema_fields_ACTIVE_GUARD => PaymentIntent::ACTIVE_GUARD_VALUE,
            PaymentIntent::schema_fields_REQUEST_HASH => $command->getRequestHash(),
            PaymentIntent::schema_fields_IDEMPOTENCY_KEY => $command->getIdempotencyKey(),
            PaymentIntent::schema_fields_AMOUNT_SNAPSHOT => $this->json($amountSnapshot),
            PaymentIntent::schema_fields_CONFIG_SNAPSHOT => $this->json([
                'method_code' => $command->getMethodCode(),
                'provider_code' => $command->getMethodCode(),
                'merchant_account' => $merchantAccount,
                'scope' => $scopeCode,
            ]),
            PaymentIntent::schema_fields_TERMS_SNAPSHOT => $this->json([
                'return_url' => $command->getReturnUrl(),
            ]),
            PaymentIntent::schema_fields_CREATED_AT => $now,
            PaymentIntent::schema_fields_UPDATED_AT => $now,
        ])->save();

        $attemptModel = $this->createAttempt(
            $intentCode,
            $attemptCode,
            $providerKey,
            $command,
            $snapshot,
            $merchantAccount,
            $environment,
            $scopeCode,
            $now,
        );
        $outboxModel = $this->createCommandOutbox(
            $commandCode,
            $intentCode,
            $attemptCode,
            $providerKey,
            $snapshot,
            $merchantAccount,
            $scopeCode,
            $now,
        );

        return $this->completeStartIdempotency(
            $command,
            $context,
            $intentModel,
            $attemptModel,
            $outboxModel,
        );
    }

    /**
     * @param array<string, mixed> $context
     * @return array{ok:bool,error_code:?string,intent:?array,attempt:?array,outbox:?array,replayed?:bool}
     */
    private function createAttemptOnExistingIntent(
        PaymentIntent $intentModel,
        PaymentStartCommand $command,
        PayableSnapshot $snapshot,
        string $merchantAccount,
        string $environment,
        string $snapshotVersion,
        array $context,
    ): array {
        $intentCode = (string) $intentModel->getData(PaymentIntent::schema_fields_INTENT_CODE);
        $attemptCode = 'pa_' . bin2hex(random_bytes(8));
        $commandCode = 'pc_' . bin2hex(random_bytes(6));
        $providerKey = PaymentProviderCommandOutbox::buildProviderRequestKey($attemptCode);
        $scope = $snapshot->getArray('scope');
        $scopeCode = $this->scopeCode($scope, $snapshot->getCurrencyCode());
        $now = $this->dateTime($this->now);

        $attemptModel = $this->createAttempt(
            $intentCode,
            $attemptCode,
            $providerKey,
            $command,
            $snapshot,
            $merchantAccount,
            $environment,
            $scopeCode,
            $now,
        );
        $outboxModel = $this->createCommandOutbox(
            $commandCode,
            $intentCode,
            $attemptCode,
            $providerKey,
            $snapshot,
            $merchantAccount,
            $scopeCode,
            $now,
        );
        $intentModel->setData(PaymentIntent::schema_fields_STATUS, PaymentIntentOrchestrator::STATUS_LOCAL_ACCEPTED)
            ->setData(PaymentIntent::schema_fields_REQUEST_HASH, $command->getRequestHash())
            ->setData(PaymentIntent::schema_fields_IDEMPOTENCY_KEY, $command->getIdempotencyKey())
            ->setData(PaymentIntent::schema_fields_SCOPE_VERSION, $snapshotVersion)
            ->setData(PaymentIntent::schema_fields_UPDATED_AT, $now)
            ->save();

        return $this->completeStartIdempotency(
            $command,
            $context,
            $intentModel,
            $attemptModel,
            $outboxModel,
        );
    }

    private function createAttempt(
        string $intentCode,
        string $attemptCode,
        string $providerKey,
        PaymentStartCommand $command,
        PayableSnapshot $snapshot,
        string $merchantAccount,
        string $environment,
        string $scopeCode,
        string $now,
    ): PaymentAttempt {
        $attempt = $this->newModel(PaymentAttempt::class);
        $attempt->setData([
            PaymentAttempt::schema_fields_ATTEMPT_CODE => $attemptCode,
            PaymentAttempt::schema_fields_INTENT_CODE => $intentCode,
            PaymentAttempt::schema_fields_ENVIRONMENT => $environment,
            PaymentAttempt::schema_fields_PAYABLE_TYPE => $command->getPayableType(),
            PaymentAttempt::schema_fields_PAYABLE_ID => $command->getPayableId(),
            PaymentAttempt::schema_fields_METHOD_CODE => $command->getMethodCode(),
            PaymentAttempt::schema_fields_PROVIDER_CODE => $command->getMethodCode(),
            PaymentAttempt::schema_fields_MERCHANT_ACCOUNT => $merchantAccount,
            PaymentAttempt::schema_fields_SCOPE => $scopeCode,
            PaymentAttempt::schema_fields_PAYMENT_CURRENCY_CODE => $snapshot->getCurrencyCode(),
            PaymentAttempt::schema_fields_AMOUNT_MINOR => $snapshot->getAmountMinor(),
            PaymentAttempt::schema_fields_PRECISION => $snapshot->getPrecision(),
            PaymentAttempt::schema_fields_STATUS => PaymentAttempt::STATUS_CREATED,
            PaymentAttempt::schema_fields_NONTERMINAL_GUARD => PaymentAttempt::NONTERMINAL_GUARD_VALUE,
            PaymentAttempt::schema_fields_VERSION => 0,
            PaymentAttempt::schema_fields_CAS_TOKEN => bin2hex(random_bytes(32)),
            PaymentAttempt::schema_fields_IDEMPOTENCY_KEY => $command->getIdempotencyKey(),
            PaymentAttempt::schema_fields_PROVIDER_REQUEST_KEY => $providerKey,
            PaymentAttempt::schema_fields_STARTED_AT => $now,
            PaymentAttempt::schema_fields_RESERVATION_EXPIRES_AT => $this->dateTime($this->now + PaymentIntentOrchestrator::LEASE_CHECK_SECONDS),
            PaymentAttempt::schema_fields_REQUEST_SNAPSHOT => $this->json([
                'amount_minor' => $snapshot->getAmountMinor(),
                'currency_code' => $snapshot->getCurrencyCode(),
                'precision' => $snapshot->getPrecision(),
                'scope' => $snapshot->getArray('scope'),
                'snapshot_version' => $snapshot->getVersion(),
                'items' => $snapshot->getItems(),
            ]),
            PaymentAttempt::schema_fields_CREATED_AT => $now,
        ])->save();

        return $attempt;
    }

    private function createCommandOutbox(
        string $commandCode,
        string $intentCode,
        string $attemptCode,
        string $providerKey,
        PayableSnapshot $snapshot,
        string $merchantAccount,
        string $scopeCode,
        string $now,
    ): PaymentProviderCommandOutbox {
        $outbox = $this->newModel(PaymentProviderCommandOutbox::class);
        $outbox->setData([
            PaymentProviderCommandOutbox::schema_fields_COMMAND_CODE => $commandCode,
            PaymentProviderCommandOutbox::schema_fields_INTENT_CODE => $intentCode,
            PaymentProviderCommandOutbox::schema_fields_ATTEMPT_CODE => $attemptCode,
            PaymentProviderCommandOutbox::schema_fields_COMMAND_TYPE => PaymentProviderCommandOutbox::COMMAND_SUBMIT,
            PaymentProviderCommandOutbox::schema_fields_PROVIDER_REQUEST_KEY => $providerKey,
            PaymentProviderCommandOutbox::schema_fields_STATUS => PaymentProviderCommandOutbox::STATUS_PENDING,
            PaymentProviderCommandOutbox::schema_fields_EXPECTED_ATTEMPT_VERSION => 0,
            PaymentProviderCommandOutbox::schema_fields_PAYLOAD_JSON => $this->json([
                'amount_minor' => $snapshot->getAmountMinor(),
                'currency_code' => $snapshot->getCurrencyCode(),
                'precision' => $snapshot->getPrecision(),
                'merchant_account' => $merchantAccount,
                'scope' => $scopeCode,
            ]),
            PaymentProviderCommandOutbox::schema_fields_ATTEMPT_COUNT => 0,
            PaymentProviderCommandOutbox::schema_fields_CLAIM_TOKEN => bin2hex(random_bytes(32)),
            PaymentProviderCommandOutbox::schema_fields_CREATED_AT => $now,
        ])->save();

        return $outbox;
    }

    /**
     * @param array<string, mixed> $context
     * @return array{ok:bool,error_code:?string,intent:?array,attempt:?array,outbox:?array,replayed?:bool}
     */
    private function completeStartIdempotency(
        PaymentStartCommand $command,
        array $context,
        PaymentIntent $intent,
        PaymentAttempt $attempt,
        PaymentProviderCommandOutbox $outbox,
    ): array {
        $payload = [
            'result_code' => 'payment_start_accepted',
            'request_hash' => $command->getRequestHash(),
            'intent_code' => (string) $intent->getData(PaymentIntent::schema_fields_INTENT_CODE),
            'attempt_code' => (string) $attempt->getData(PaymentAttempt::schema_fields_ATTEMPT_CODE),
            'command_code' => (string) $outbox->getData(PaymentProviderCommandOutbox::schema_fields_COMMAND_CODE),
        ];
        $this->idempotency->complete(
            $command->getIdempotencyKey(),
            $command->getPayableId(),
            $command->getMethodCode(),
            'payment_start',
            $payload,
            $command->getRequestHash(),
            $context,
        );

        return [
            'ok' => true,
            'error_code' => null,
            'intent' => $this->intentToArray($intent, $attempt),
            'attempt' => $this->attemptToArray($attempt),
            'outbox' => $this->outboxToArray($outbox),
            'replayed' => false,
        ];
    }

    /**
     * @return array{ok:bool,error_code:?string,intent:?array,attempt:?array,outbox:?array,replayed?:bool}
     */
    private function replayFromIdempotency(PaymentIdempotency $record): array
    {
        $result = $record->getResultPayload();
        $intent = $this->loadIntent((string) ($result['intent_code'] ?? ''));
        $attempt = $this->loadAttempt((string) ($result['attempt_code'] ?? ''));
        $outbox = $this->loadCommandOutbox((string) ($result['command_code'] ?? ''));
        if (!$intent instanceof PaymentIntent || !$attempt instanceof PaymentAttempt) {
            return $this->fail(PaymentIntentOrchestrator::ERROR_PERSISTENCE);
        }

        return [
            'ok' => true,
            'error_code' => null,
            'intent' => $this->intentToArray($intent, $attempt),
            'attempt' => $this->attemptToArray($attempt),
            'outbox' => $outbox instanceof PaymentProviderCommandOutbox
                ? $this->outboxToArray($outbox)
                : null,
            'replayed' => true,
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function failIdempotency(
        PaymentStartCommand $command,
        array $context,
        string $errorCode,
    ): void {
        $this->idempotency->fail(
            $command->getIdempotencyKey(),
            $command->getPayableId(),
            $command->getMethodCode(),
            'payment_start',
            $errorCode,
            ['error_code' => $errorCode],
            $command->getRequestHash(),
            $context,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function processPendingOutbox(int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        $rows = array_merge(
            $this->commandRowsByStatus(PaymentProviderCommandOutbox::STATUS_PENDING, $limit),
            $this->commandRowsByStatus(PaymentProviderCommandOutbox::STATUS_PROCESSING, $limit),
        );
        usort(
            $rows,
            static fn (array $left, array $right): int =>
                (int) ($left[PaymentProviderCommandOutbox::schema_fields_ID] ?? 0)
                <=> (int) ($right[PaymentProviderCommandOutbox::schema_fields_ID] ?? 0),
        );
        $rows = array_slice($rows, 0, $limit);
        $processed = [];
        foreach ($rows as $row) {
            $commandCode = trim((string) ($row[PaymentProviderCommandOutbox::schema_fields_COMMAND_CODE] ?? ''));
            if ($commandCode !== '') {
                $processed[] = $this->processOneOutbox($commandCode);
            }
        }

        return $processed;
    }

    /**
     * @return array<string, mixed>
     */
    public function processOneOutbox(string $commandCode): array
    {
        $commandCode = trim($commandCode);
        if ($commandCode === '') {
            return ['ok' => false, 'error_code' => 'outbox_not_found'];
        }
        $claim = $this->claimCommand($commandCode);
        if (!($claim['ok'] ?? false)) {
            return $claim;
        }
        if (($claim['already_done'] ?? false) === true) {
            return $claim;
        }

        /** @var array<string, mixed> $attempt */
        $attempt = $claim['attempt'];
        /** @var array<string, mixed> $outbox */
        $outbox = $claim['outbox'];
        $providerKey = (string) $outbox['provider_request_key'];
        $providerResult = $this->callProvider(
            $providerKey,
            $attempt,
            \is_array($outbox['payload'] ?? null) ? $outbox['payload'] : [],
        );

        if ($this->crashBeforeSecondTx) {
            return [
                'ok' => false,
                'error_code' => 'crash_before_second_tx',
                'command_code' => $commandCode,
                'provider_key' => $providerKey,
                'provider_calls' => count($this->providerCalls),
            ];
        }

        return $this->applyProviderResultSecondTransaction(
            $commandCode,
            (string) $attempt['attempt_code'],
            $providerResult,
            (int) $outbox['expected_attempt_version'],
            (string) $claim['claim_token'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function claimCommand(string $commandCode): array
    {
        $model = $this->newModel(PaymentProviderCommandOutbox::class);

        return $this->transactions->run($model->getConnection(), function () use ($commandCode): array {
            $outbox = $this->loadCommandOutbox($commandCode);
            if (!$outbox instanceof PaymentProviderCommandOutbox) {
                return ['ok' => false, 'error_code' => 'outbox_not_found'];
            }
            $status = (string) $outbox->getData(PaymentProviderCommandOutbox::schema_fields_STATUS);
            if ($status === PaymentProviderCommandOutbox::STATUS_DONE) {
                return [
                    'ok' => true,
                    'error_code' => null,
                    'already_done' => true,
                    'command_code' => $commandCode,
                ];
            }
            if ($status === PaymentProviderCommandOutbox::STATUS_DEAD) {
                return [
                    'ok' => false,
                    'error_code' => (string) (
                        $outbox->getData(PaymentProviderCommandOutbox::schema_fields_ERROR_CODE)
                        ?: 'outbox_dead'
                    ),
                    'command_code' => $commandCode,
                ];
            }
            $claimedAt = strtotime((string) $outbox->getData(
                PaymentProviderCommandOutbox::schema_fields_CLAIMED_AT,
            )) ?: 0;
            if ($status === PaymentProviderCommandOutbox::STATUS_PROCESSING
                && $claimedAt + PaymentProviderCommandOutbox::CLAIM_LEASE_SECONDS > $this->now
            ) {
                return [
                    'ok' => false,
                    'error_code' => 'outbox_claim_in_progress',
                    'command_code' => $commandCode,
                ];
            }

            $attempt = $this->loadAttempt(
                (string) $outbox->getData(PaymentProviderCommandOutbox::schema_fields_ATTEMPT_CODE),
            );
            if (!$attempt instanceof PaymentAttempt) {
                $outbox->setData(PaymentProviderCommandOutbox::schema_fields_STATUS, PaymentProviderCommandOutbox::STATUS_DEAD)
                    ->setData(PaymentProviderCommandOutbox::schema_fields_ERROR_CODE, 'attempt_missing')
                    ->save();

                return ['ok' => false, 'error_code' => 'attempt_missing', 'command_code' => $commandCode];
            }

            $oldToken = (string) $outbox->getData(PaymentProviderCommandOutbox::schema_fields_CLAIM_TOKEN);
            $claimToken = bin2hex(random_bytes(32));
            $candidate = $this->newModel(PaymentProviderCommandOutbox::class);
            $candidate->getQuery(false)
                ->where(PaymentProviderCommandOutbox::schema_fields_COMMAND_CODE, $commandCode)
                ->where(PaymentProviderCommandOutbox::schema_fields_CLAIM_TOKEN, $oldToken)
                ->update([
                    PaymentProviderCommandOutbox::schema_fields_STATUS => PaymentProviderCommandOutbox::STATUS_PROCESSING,
                    PaymentProviderCommandOutbox::schema_fields_CLAIM_TOKEN => $claimToken,
                    PaymentProviderCommandOutbox::schema_fields_CLAIMED_AT => $this->dateTime($this->now),
                    PaymentProviderCommandOutbox::schema_fields_ATTEMPT_COUNT => (int) $outbox->getData(PaymentProviderCommandOutbox::schema_fields_ATTEMPT_COUNT) + 1,
                ])
                ->fetch();
            $claimed = $this->loadCommandOutbox($commandCode);
            if (!$claimed instanceof PaymentProviderCommandOutbox
                || !hash_equals(
                    $claimToken,
                    (string) $claimed->getData(PaymentProviderCommandOutbox::schema_fields_CLAIM_TOKEN),
                )
            ) {
                return ['ok' => false, 'error_code' => 'outbox_claim_conflict', 'command_code' => $commandCode];
            }

            return [
                'ok' => true,
                'error_code' => null,
                'claim_token' => $claimToken,
                'attempt' => $this->attemptToArray($attempt),
                'outbox' => $this->outboxToArray($claimed),
            ];
        });
    }

    /**
     * @param array<string, mixed> $attempt
     * @param array<string, mixed> $payload
     * @return array{status:string,provider_reference?:string,error_code?:string,response?:array<string,mixed>}
     */
    private function callProvider(string $providerKey, array $attempt, array $payload): array
    {
        $this->providerCalls[] = [
            'provider_request_key' => $providerKey,
            'attempt_code' => (string) $attempt['attempt_code'],
            'at' => $this->now,
        ];
        if ($this->providerHandler !== null) {
            return ($this->providerHandler)([
                'attempt' => $attempt,
                'payload' => $payload,
                'provider_request_key' => $providerKey,
            ]);
        }

        try {
            $route = $this->methodManager->resolveProviderRoute(
                (string) $attempt['method_code'],
                [
                    'scope' => (string) ($payload['scope'] ?? ''),
                    'environment' => (string) $attempt['environment'],
                    'merchant_account' => (string) $attempt['merchant_account'],
                ],
            );
            $provider = $route['provider'];
            $result = $provider->createPayment(PaymentRequest::fromArray([
                PaymentOperationRequest::FIELD_INTENT_CODE => (string) $attempt['intent_code'],
                PaymentOperationRequest::FIELD_ATTEMPT_CODE => (string) $attempt['attempt_code'],
                PaymentOperationRequest::FIELD_PAYABLE_TYPE => (string) $attempt['payable_type'],
                PaymentOperationRequest::FIELD_PAYABLE_ID => (string) $attempt['payable_id'],
                PaymentOperationRequest::FIELD_METHOD_CODE => (string) $attempt['method_code'],
                PaymentOperationRequest::FIELD_PROVIDER_CODE => $provider->getProviderCode(),
                PaymentOperationRequest::FIELD_MERCHANT_ACCOUNT => (string) $attempt['merchant_account'],
                PaymentOperationRequest::FIELD_SCOPE => (string) ($payload['scope'] ?? ''),
                PaymentOperationRequest::FIELD_AMOUNT_MINOR => (int) $attempt['amount_minor'],
                PaymentOperationRequest::FIELD_CURRENCY_CODE => (string) $attempt['currency_code'],
                PaymentOperationRequest::FIELD_IDEMPOTENCY_KEY => $providerKey,
                PaymentOperationRequest::FIELD_PROVIDER_REFERENCE => $providerKey,
                PaymentOperationRequest::FIELD_CONTEXT => [
                    'provider_request_key' => $providerKey,
                    'command_type' => PaymentProviderCommandOutbox::COMMAND_SUBMIT,
                ],
            ]));

            return [
                'status' => match ($result->getStatus()) {
                    PaymentResult::STATUS_PAID,
                    PaymentResult::STATUS_CAPTURED,
                    PaymentResult::STATUS_AUTHORIZED => PaymentIntentOrchestrator::STATUS_SUCCEEDED,
                    PaymentResult::STATUS_FAILED,
                    PaymentResult::STATUS_UNSUPPORTED => PaymentIntentOrchestrator::STATUS_FAILED,
                    default => PaymentIntentOrchestrator::STATUS_PROVIDER_PENDING,
                },
                'provider_reference' => (string) ($result->getProviderReference() ?? ''),
                'error_code' => $result->getStatus() === PaymentResult::STATUS_FAILED ? 'provider_failed' : null,
                'response' => $result->getData(),
            ];
        } catch (\Throwable $throwable) {
            return [
                'status' => PaymentIntentOrchestrator::STATUS_UNKNOWN,
                'error_code' => 'provider_result_unknown',
                'response' => ['exception_class' => $throwable::class],
            ];
        }
    }

    /**
     * @param array<string, mixed> $providerResult
     * @return array<string, mixed>
     */
    private function applyProviderResultSecondTransaction(
        string $commandCode,
        string $attemptCode,
        array $providerResult,
        int $expectedVersion,
        string $claimToken,
    ): array {
        $model = $this->newModel(PaymentAttempt::class);

        return $this->transactions->run(
            $model->getConnection(),
            function () use (
                $commandCode,
                $attemptCode,
                $providerResult,
                $expectedVersion,
                $claimToken,
            ): array {
                $outbox = $this->loadCommandOutbox($commandCode);
                $attempt = $this->loadAttempt($attemptCode);
                if (!$outbox instanceof PaymentProviderCommandOutbox || !$attempt instanceof PaymentAttempt) {
                    return ['ok' => false, 'error_code' => 'payment_second_tx_fact_missing'];
                }
                if ((string) $outbox->getData(PaymentProviderCommandOutbox::schema_fields_STATUS)
                    === PaymentProviderCommandOutbox::STATUS_DONE
                ) {
                    return [
                        'ok' => true,
                        'error_code' => null,
                        'command_code' => $commandCode,
                        'attempt' => $this->attemptToArray($attempt),
                        'intent' => $this->getIntent((string) $attempt->getData(PaymentAttempt::schema_fields_INTENT_CODE)),
                        'replayed' => true,
                    ];
                }
                if (!hash_equals(
                    $claimToken,
                    (string) $outbox->getData(PaymentProviderCommandOutbox::schema_fields_CLAIM_TOKEN),
                )) {
                    return ['ok' => false, 'error_code' => 'outbox_claim_conflict', 'command_code' => $commandCode];
                }
                if ((int) $attempt->getData(PaymentAttempt::schema_fields_VERSION) !== $expectedVersion) {
                    return [
                        'ok' => false,
                        'error_code' => PaymentIntentOrchestrator::ERROR_CAS_CONFLICT,
                        'command_code' => $commandCode,
                    ];
                }

                $intent = $this->loadIntent(
                    (string) $attempt->getData(PaymentAttempt::schema_fields_INTENT_CODE),
                );
                if (!$intent instanceof PaymentIntent) {
                    return ['ok' => false, 'error_code' => 'intent_not_found', 'command_code' => $commandCode];
                }

                $status = (string) ($providerResult['status'] ?? PaymentIntentOrchestrator::STATUS_UNKNOWN);
                $oldCasToken = (string) $attempt->getData(PaymentAttempt::schema_fields_CAS_TOKEN);
                $writerToken = bin2hex(random_bytes(32));
                $providerReference = $this->nullable($providerResult['provider_reference'] ?? null);
                $attemptPatch = [
                    PaymentAttempt::schema_fields_VERSION => $expectedVersion + 1,
                    PaymentAttempt::schema_fields_CAS_TOKEN => $writerToken,
                    PaymentAttempt::schema_fields_RESPONSE_SNAPSHOT => $this->json($providerResult),
                    PaymentAttempt::schema_fields_PROVIDER_REFERENCE => $providerReference,
                    PaymentAttempt::schema_fields_PROVIDER_REFERENCE_GUARD => $this->providerReferenceGuard(
                        $attempt,
                        $providerReference,
                    ),
                ];
                $intentStatus = PaymentIntentOrchestrator::STATUS_UNKNOWN;
                $effectType = null;
                $effectReason = null;

                if ($status === PaymentIntentOrchestrator::STATUS_SUCCEEDED) {
                    $attemptPatch[PaymentAttempt::schema_fields_STATUS] = PaymentAttempt::STATUS_SUCCEEDED;
                    $attemptPatch[PaymentAttempt::schema_fields_NONTERMINAL_GUARD] = null;
                    $attemptPatch[PaymentAttempt::schema_fields_FAILURE_REASON_CODE] = null;
                    $attemptPatch[PaymentAttempt::schema_fields_CLOSED_AT] = $this->dateTime($this->now);
                    $intentStatus = $this->reservationValid
                        ? PaymentIntentOrchestrator::STATUS_SUCCEEDED
                        : PaymentIntentOrchestrator::ERROR_INVENTORY_CONFLICT;
                    $effectType = $this->reservationValid ? 'paid_notify' : 'compensation';
                    $effectReason = $this->reservationValid
                        ? null
                        : PaymentIntentOrchestrator::ERROR_INVENTORY_CONFLICT;
                } elseif ($status === PaymentIntentOrchestrator::STATUS_FAILED) {
                    $attemptPatch[PaymentAttempt::schema_fields_STATUS] = PaymentAttempt::STATUS_FAILED;
                    $attemptPatch[PaymentAttempt::schema_fields_NONTERMINAL_GUARD] = null;
                    $attemptPatch[PaymentAttempt::schema_fields_FAILURE_REASON_CODE] = (string) ($providerResult['error_code'] ?? 'provider_failed');
                    $attemptPatch[PaymentAttempt::schema_fields_CLOSED_AT] = $this->dateTime($this->now);
                    $intentStatus = PaymentIntentOrchestrator::STATUS_FAILED;
                } else {
                    $attemptPatch[PaymentAttempt::schema_fields_STATUS] = PaymentIntentOrchestrator::STATUS_UNKNOWN;
                    $attemptPatch[PaymentAttempt::schema_fields_NONTERMINAL_GUARD] = PaymentAttempt::NONTERMINAL_GUARD_VALUE;
                    $attemptPatch[PaymentAttempt::schema_fields_FAILURE_REASON_CODE] = $this->nullable(
                        $providerResult['error_code'] ?? null,
                    );
                }

                $candidate = $this->newModel(PaymentAttempt::class);
                $candidate->getQuery(false)
                    ->where(PaymentAttempt::schema_fields_ATTEMPT_CODE, $attemptCode)
                    ->where(PaymentAttempt::schema_fields_VERSION, $expectedVersion)
                    ->where(PaymentAttempt::schema_fields_CAS_TOKEN, $oldCasToken)
                    ->update($attemptPatch)
                    ->fetch();
                $appliedAttempt = $this->loadAttempt($attemptCode);
                if (!$appliedAttempt instanceof PaymentAttempt
                    || !hash_equals(
                        $writerToken,
                        (string) $appliedAttempt->getData(PaymentAttempt::schema_fields_CAS_TOKEN),
                    )
                ) {
                    return [
                        'ok' => false,
                        'error_code' => PaymentIntentOrchestrator::ERROR_CAS_CONFLICT,
                        'command_code' => $commandCode,
                    ];
                }

                $amountSnapshot = $this->decodeJson(
                    $intent->getData(PaymentIntent::schema_fields_AMOUNT_SNAPSHOT),
                );
                $amountSnapshot['payment_status'] = $status === PaymentIntentOrchestrator::STATUS_SUCCEEDED
                    ? ($this->reservationValid ? 'paid' : 'paid_inventory_conflict')
                    : 'unpaid';
                $amountSnapshot['attention'] = !$this->reservationValid
                    && $status === PaymentIntentOrchestrator::STATUS_SUCCEEDED
                    ? 'attention_required'
                    : null;
                $intent->setData(PaymentIntent::schema_fields_STATUS, $intentStatus)
                    ->setData(PaymentIntent::schema_fields_AMOUNT_SNAPSHOT, $this->json($amountSnapshot))
                    ->setData(PaymentIntent::schema_fields_FAILURE_REASON_CODE, $status === PaymentIntentOrchestrator::STATUS_FAILED
                        ? (string) ($providerResult['error_code'] ?? 'provider_failed')
                        : null)
                    ->setData(PaymentIntent::schema_fields_UPDATED_AT, $this->dateTime($this->now))
                    ->save();

                if ($status === PaymentIntentOrchestrator::STATUS_SUCCEEDED) {
                    $this->writePaymentLedger($intent, $appliedAttempt);
                }
                if ($effectType !== null) {
                    $this->writeEffectOutbox($intent, $appliedAttempt, $effectType, $effectReason);
                }
                if ($this->intentHasAssetAllocations($intent)) {
                    if ($status === PaymentIntentOrchestrator::STATUS_SUCCEEDED) {
                        $this->writeEffectOutbox(
                            $intent,
                            $appliedAttempt,
                            'asset:commit:v1',
                            null,
                        );
                    } elseif ($status === PaymentIntentOrchestrator::STATUS_FAILED) {
                        $this->writeEffectOutbox(
                            $intent,
                            $appliedAttempt,
                            'asset:release:v1',
                            (string) ($providerResult['error_code'] ?? 'provider_failed'),
                        );
                    }
                }

                $outbox->setData(PaymentProviderCommandOutbox::schema_fields_STATUS, PaymentProviderCommandOutbox::STATUS_DONE)
                    ->setData(PaymentProviderCommandOutbox::schema_fields_RESPONSE_JSON, $this->json($providerResult))
                    ->setData(PaymentProviderCommandOutbox::schema_fields_ERROR_CODE, null)
                    ->setData(PaymentProviderCommandOutbox::schema_fields_PROCESSED_AT, $this->dateTime($this->now))
                    ->save();

                return [
                    'ok' => true,
                    'error_code' => !$this->reservationValid
                        && $status === PaymentIntentOrchestrator::STATUS_SUCCEEDED
                        ? PaymentIntentOrchestrator::ERROR_INVENTORY_CONFLICT
                        : null,
                    'command_code' => $commandCode,
                    'attempt' => $this->attemptToArray($appliedAttempt),
                    'intent' => $this->intentToArray($intent, $appliedAttempt),
                ];
            },
        );
    }

    private function writePaymentLedger(PaymentIntent $intent, PaymentAttempt $attempt): void
    {
        $attemptCode = (string) $attempt->getData(PaymentAttempt::schema_fields_ATTEMPT_CODE);
        $ledgerCode = 'pl_' . substr(hash('sha256', 'payment|' . $attemptCode), 0, 40);
        $existing = $this->newModel(PaymentLedger::class);
        $existing->where(PaymentLedger::schema_fields_LEDGER_CODE, $ledgerCode)
            ->find()
            ->fetch();
        if ($existing->getId()) {
            return;
        }
        $amountMinor = (int) $attempt->getData(PaymentAttempt::schema_fields_AMOUNT_MINOR);
        $precision = (int) $attempt->getData(PaymentAttempt::schema_fields_PRECISION);
        $existing->setData([
            PaymentLedger::schema_fields_LEDGER_CODE => $ledgerCode,
            PaymentLedger::schema_fields_LEDGER_TYPE => PaymentLedger::TYPE_PAYMENT,
            PaymentLedger::schema_fields_DIRECTION => PaymentLedger::DIRECTION_DEBIT,
            PaymentLedger::schema_fields_DEBIT => $this->minorToDecimal($amountMinor, $precision),
            PaymentLedger::schema_fields_CREDIT => $this->minorToDecimal(0, $precision),
            PaymentLedger::schema_fields_DEBIT_MINOR => $amountMinor,
            PaymentLedger::schema_fields_CREDIT_MINOR => 0,
            PaymentLedger::schema_fields_CURRENCY => (string) $attempt->getData(PaymentAttempt::schema_fields_PAYMENT_CURRENCY_CODE),
            PaymentLedger::schema_fields_PRECISION => $precision,
            PaymentLedger::schema_fields_INTENT_CODE => (string) $intent->getData(PaymentIntent::schema_fields_INTENT_CODE),
            PaymentLedger::schema_fields_ATTEMPT_CODE => $attemptCode,
            PaymentLedger::schema_fields_LINKED_ATTEMPT_ID => (int) $attempt->getId(),
            PaymentLedger::schema_fields_METHOD_CODE => (string) $attempt->getData(PaymentAttempt::schema_fields_METHOD_CODE),
            PaymentLedger::schema_fields_PROVIDER_CODE => (string) $attempt->getData(PaymentAttempt::schema_fields_PROVIDER_CODE),
            PaymentLedger::schema_fields_MERCHANT_ACCOUNT => (string) $attempt->getData(PaymentAttempt::schema_fields_MERCHANT_ACCOUNT),
            PaymentLedger::schema_fields_PAYABLE_TYPE => (string) $attempt->getData(PaymentAttempt::schema_fields_PAYABLE_TYPE),
            PaymentLedger::schema_fields_PAYABLE_ID => (string) $attempt->getData(PaymentAttempt::schema_fields_PAYABLE_ID),
            PaymentLedger::schema_fields_METADATA => $this->json([
                'provider_request_key' => (string) $attempt->getData(PaymentAttempt::schema_fields_PROVIDER_REQUEST_KEY),
                'provider_reference' => (string) $attempt->getData(PaymentAttempt::schema_fields_PROVIDER_REFERENCE),
            ]),
            PaymentLedger::schema_fields_CREATED_AT => $this->dateTime($this->now),
        ])->save();
    }

    private function writeEffectOutbox(
        PaymentIntent $intent,
        PaymentAttempt $attempt,
        string $effectType,
        ?string $reason,
    ): void {
        $attemptCode = (string) $attempt->getData(PaymentAttempt::schema_fields_ATTEMPT_CODE);
        $intentCode = (string) $intent->getData(PaymentIntent::schema_fields_INTENT_CODE);
        $effectKey = PaymentEffectRecord::buildKey(
            $intentCode,
            $attemptCode,
            $effectType,
        );
        $outbox = $this->newModel(PaymentOutbox::class);
        $outbox->where(PaymentOutbox::schema_fields_EFFECT_KEY, $effectKey)
            ->find()
            ->fetch();
        if ($outbox->getId()) {
            return;
        }
        $outbox->setData([
            PaymentOutbox::schema_fields_OUTBOX_CODE => 'po_' . substr(hash('sha256', $effectKey), 0, 40),
            PaymentOutbox::schema_fields_EFFECT_KEY => $effectKey,
            PaymentOutbox::schema_fields_INTENT_CODE => $intentCode,
            PaymentOutbox::schema_fields_ATTEMPT_CODE => $attemptCode,
            PaymentOutbox::schema_fields_EFFECT_TYPE => $effectType,
            PaymentOutbox::schema_fields_STATUS => PaymentOutbox::STATUS_PENDING,
            PaymentOutbox::schema_fields_PAYLOAD_JSON => $this->json([
                'payable_type' => (string) $intent->getData(PaymentIntent::schema_fields_PAYABLE_TYPE),
                'payable_id' => (string) $intent->getData(PaymentIntent::schema_fields_PAYABLE_ID),
                'reason' => $reason,
                'schema_version' => '1',
            ]),
            PaymentOutbox::schema_fields_CREATED_AT => $this->dateTime($this->now),
        ])->save();
    }

    public function extendLease(string $attemptCode): bool
    {
        $attempt = $this->loadAttempt($attemptCode);
        if (!$attempt instanceof PaymentAttempt
            || (string) $attempt->getData(PaymentAttempt::schema_fields_NONTERMINAL_GUARD)
                !== PaymentAttempt::NONTERMINAL_GUARD_VALUE
        ) {
            return false;
        }
        $started = strtotime((string) $attempt->getData(PaymentAttempt::schema_fields_STARTED_AT)) ?: $this->now;
        $hardCap = $started + PaymentIntentOrchestrator::LEASE_HARD_CAP_SECONDS;
        if ($this->now >= $hardCap) {
            return false;
        }
        $attempt->setData(
            PaymentAttempt::schema_fields_RESERVATION_EXPIRES_AT,
            $this->dateTime(min(
                $this->now + PaymentIntentOrchestrator::LEASE_EXTEND_SECONDS,
                $hardCap,
            )),
        )->save();

        return true;
    }

    public function isLeaseExpired(string $attemptCode): bool
    {
        $attempt = $this->loadAttempt($attemptCode);
        if (!$attempt instanceof PaymentAttempt) {
            return true;
        }
        $started = strtotime((string) $attempt->getData(PaymentAttempt::schema_fields_STARTED_AT)) ?: 0;
        $expires = strtotime((string) $attempt->getData(PaymentAttempt::schema_fields_RESERVATION_EXPIRES_AT)) ?: 0;

        return $this->now >= $started + PaymentIntentOrchestrator::LEASE_HARD_CAP_SECONDS
            || $this->now >= $expires;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findIntentByPayable(
        string $payableType,
        string $payableId,
        string $environment = 'sandbox',
    ): ?array {
        $intent = $this->loadActiveIntent($environment, $payableType, $payableId);

        return $intent instanceof PaymentIntent ? $this->intentToArray($intent) : null;
    }

    /**
     * @param array<string, mixed> $intent
     */
    public function updateIntent(array $intent): void
    {
        $model = $this->loadIntent((string) ($intent['intent_code'] ?? ''));
        if (!$model instanceof PaymentIntent) {
            throw new \InvalidArgumentException('intent_not_found');
        }
        if (isset($intent['status'])) {
            $model->setData(PaymentIntent::schema_fields_STATUS, (string) $intent['status']);
        }
        $amountSnapshot = $this->decodeJson($model->getData(PaymentIntent::schema_fields_AMOUNT_SNAPSHOT));
        foreach (['payment_status', 'attention'] as $field) {
            if (array_key_exists($field, $intent)) {
                $amountSnapshot[$field] = $intent[$field];
            }
        }
        $model->setData(PaymentIntent::schema_fields_AMOUNT_SNAPSHOT, $this->json($amountSnapshot))
            ->setData(PaymentIntent::schema_fields_UPDATED_AT, $this->dateTime($this->now))
            ->save();
    }

    public function bindReservation(string $attemptCode, string $reservationUuid): void
    {
        $attempt = $this->loadAttempt($attemptCode);
        if (!$attempt instanceof PaymentAttempt) {
            throw new \InvalidArgumentException('attempt_not_found:' . $attemptCode);
        }
        $request = $this->decodeJson($attempt->getData(PaymentAttempt::schema_fields_REQUEST_SNAPSHOT));
        $request['reservation_uuid'] = trim($reservationUuid);
        $attempt->setData(PaymentAttempt::schema_fields_REQUEST_SNAPSHOT, $this->json($request))->save();
    }

    /**
     * P2F-004 owns the durable webhook transition transaction.
     *
     * @return array{ok:bool,error_code:?string,ignored?:bool,intent:?array,attempt:?array}
     */
    public function applyWebhookTransition(
        string $intentCode,
        string $transition,
        ?int $expectedAttemptVersion = null,
        ?string $expectedAttemptCode = null,
    ): array {
        $transactionModel = $this->newModel(PaymentIntent::class);

        return $this->transactions->run(
            $transactionModel->getConnection(),
            function () use ($intentCode, $transition, $expectedAttemptVersion, $expectedAttemptCode): array {
                $intent = $this->loadIntentForUpdate($intentCode);
                if (!$intent instanceof PaymentIntent) {
                    return [
                        'ok' => false,
                        'error_code' => 'intent_not_found',
                        'ignored' => false,
                        'intent' => null,
                        'attempt' => null,
                    ];
                }
                $attempt = $this->loadLatestAttemptForUpdate($intentCode);
                if (!$attempt instanceof PaymentAttempt) {
                    return [
                        'ok' => false,
                        'error_code' => 'attempt_not_found',
                        'ignored' => false,
                        'intent' => $this->intentToArray($intent),
                        'attempt' => null,
                    ];
                }
                if ($expectedAttemptCode !== null
                    && trim($expectedAttemptCode) !== ''
                    && !hash_equals(
                        trim($expectedAttemptCode),
                        (string) $attempt->getData(PaymentAttempt::schema_fields_ATTEMPT_CODE),
                    )
                ) {
                    return [
                        'ok' => false,
                        'error_code' => 'payment_webhook_attempt_mismatch',
                        'ignored' => false,
                        'intent' => $this->intentToArray($intent, $attempt),
                        'attempt' => $this->attemptToArray($attempt),
                    ];
                }

                $expectedVersion = (int) $attempt->getData(PaymentAttempt::schema_fields_VERSION);
                if ($expectedAttemptVersion !== null && $expectedAttemptVersion !== $expectedVersion) {
                    return [
                        'ok' => false,
                        'error_code' => PaymentIntentOrchestrator::ERROR_CAS_CONFLICT,
                        'ignored' => false,
                        'intent' => $this->intentToArray($intent, $attempt),
                        'attempt' => $this->attemptToArray($attempt),
                    ];
                }

                $incoming = $this->normalizeWebhookAttemptStatus($transition);
                if ($incoming === null) {
                    return [
                        'ok' => false,
                        'error_code' => 'payment_webhook_transition_unsupported',
                        'ignored' => false,
                        'intent' => $this->intentToArray($intent, $attempt),
                        'attempt' => $this->attemptToArray($attempt),
                    ];
                }

                $current = (string) $attempt->getData(PaymentAttempt::schema_fields_STATUS);
                $currentRank = $this->webhookStatusRank($current);
                $incomingRank = $this->webhookStatusRank($incoming);
                if ($currentRank > $incomingRank) {
                    return [
                        'ok' => true,
                        'error_code' => null,
                        'ignored' => true,
                        'intent' => $this->intentToArray($intent, $attempt),
                        'attempt' => $this->attemptToArray($attempt),
                    ];
                }
                if ($current === $incoming) {
                    return [
                        'ok' => true,
                        'error_code' => null,
                        'ignored' => false,
                        'replayed' => true,
                        'intent' => $this->intentToArray($intent, $attempt),
                        'attempt' => $this->attemptToArray($attempt),
                    ];
                }

                $oldCasToken = (string) $attempt->getData(PaymentAttempt::schema_fields_CAS_TOKEN);
                $writerToken = bin2hex(random_bytes(32));
                $attemptPatch = [
                    PaymentAttempt::schema_fields_STATUS => $incoming,
                    PaymentAttempt::schema_fields_VERSION => $expectedVersion + 1,
                    PaymentAttempt::schema_fields_CAS_TOKEN => $writerToken,
                    PaymentAttempt::schema_fields_FAILURE_REASON_CODE => null,
                ];
                if (\in_array($incoming, [
                    PaymentAttempt::STATUS_SUCCEEDED,
                    PaymentAttempt::STATUS_FAILED,
                ], true)) {
                    $attemptPatch[PaymentAttempt::schema_fields_NONTERMINAL_GUARD] = null;
                    $attemptPatch[PaymentAttempt::schema_fields_CLOSED_AT] = $this->dateTime($this->now);
                } else {
                    $attemptPatch[PaymentAttempt::schema_fields_NONTERMINAL_GUARD]
                        = PaymentAttempt::NONTERMINAL_GUARD_VALUE;
                }
                if ($incoming === PaymentAttempt::STATUS_FAILED) {
                    $attemptPatch[PaymentAttempt::schema_fields_FAILURE_REASON_CODE]
                        = 'provider_webhook_failed';
                }

                $candidate = $this->newModel(PaymentAttempt::class);
                $candidate->getQuery(false)
                    ->where(PaymentAttempt::schema_fields_ATTEMPT_CODE, (string) $attempt->getData(
                        PaymentAttempt::schema_fields_ATTEMPT_CODE,
                    ))
                    ->where(PaymentAttempt::schema_fields_VERSION, $expectedVersion)
                    ->where(PaymentAttempt::schema_fields_CAS_TOKEN, $oldCasToken)
                    ->update($attemptPatch)
                    ->fetch();
                $appliedAttempt = $this->loadAttempt(
                    (string) $attempt->getData(PaymentAttempt::schema_fields_ATTEMPT_CODE),
                );
                if (!$appliedAttempt instanceof PaymentAttempt
                    || !hash_equals(
                        $writerToken,
                        (string) $appliedAttempt->getData(PaymentAttempt::schema_fields_CAS_TOKEN),
                    )
                ) {
                    return [
                        'ok' => false,
                        'error_code' => PaymentIntentOrchestrator::ERROR_CAS_CONFLICT,
                        'ignored' => false,
                        'intent' => $this->intentToArray($intent, $attempt),
                        'attempt' => $this->attemptToArray($attempt),
                    ];
                }

                $amountSnapshot = $this->decodeJson(
                    $intent->getData(PaymentIntent::schema_fields_AMOUNT_SNAPSHOT),
                );
                $amountSnapshot['payment_status'] = $incoming === PaymentAttempt::STATUS_SUCCEEDED
                    ? 'paid'
                    : 'unpaid';
                $amountSnapshot['attention'] = null;
                $intentStatus = match ($incoming) {
                    PaymentAttempt::STATUS_SUCCEEDED => PaymentIntentOrchestrator::STATUS_SUCCEEDED,
                    PaymentAttempt::STATUS_FAILED => PaymentIntentOrchestrator::STATUS_FAILED,
                    PaymentAttempt::STATUS_PROCESSING => PaymentIntentOrchestrator::STATUS_PROVIDER_PENDING,
                    default => $incoming,
                };
                $intent->setData(PaymentIntent::schema_fields_STATUS, $intentStatus)
                    ->setData(PaymentIntent::schema_fields_AMOUNT_SNAPSHOT, $this->json($amountSnapshot))
                    ->setData(
                        PaymentIntent::schema_fields_FAILURE_REASON_CODE,
                        $incoming === PaymentAttempt::STATUS_FAILED ? 'provider_webhook_failed' : null,
                    )
                    ->setData(PaymentIntent::schema_fields_UPDATED_AT, $this->dateTime($this->now))
                    ->save();

                if ($incoming === PaymentAttempt::STATUS_SUCCEEDED) {
                    $this->writePaymentLedger($intent, $appliedAttempt);
                }

                return [
                    'ok' => true,
                    'error_code' => null,
                    'ignored' => false,
                    'intent' => $this->intentToArray($intent, $appliedAttempt),
                    'attempt' => $this->attemptToArray($appliedAttempt),
                ];
            },
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getIntent(string $intentCode): ?array
    {
        $intent = $this->loadIntent($intentCode);

        return $intent instanceof PaymentIntent ? $this->intentToArray($intent) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAttempt(string $attemptCode): ?array
    {
        $attempt = $this->loadAttempt($attemptCode);

        return $attempt instanceof PaymentAttempt ? $this->attemptToArray($attempt) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAttemptsForIntent(string $intentCode): array
    {
        $rows = $this->newModel(PaymentAttempt::class)
            ->where(PaymentAttempt::schema_fields_INTENT_CODE, $intentCode)
            ->order(PaymentAttempt::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        return array_map(fn (array $row): array => $this->attemptRowToArray($row), $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function providerCalls(): array
    {
        return $this->providerCalls;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function ledgerEntries(): array
    {
        return $this->newModel(PaymentLedger::class)
            ->order(PaymentLedger::schema_fields_ID, 'ASC')
            ->select()
            ->limit(200)
            ->fetchArray();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function effectOutbox(): array
    {
        $rows = $this->newModel(PaymentOutbox::class)
            ->order(PaymentOutbox::schema_fields_ID, 'ASC')
            ->select()
            ->limit(200)
            ->fetchArray();
        foreach ($rows as &$row) {
            $row['type'] = (string) ($row[PaymentOutbox::schema_fields_EFFECT_TYPE] ?? '');
            $row['payload'] = $this->decodeJson($row[PaymentOutbox::schema_fields_PAYLOAD_JSON] ?? null);
        }
        unset($row);

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pendingOutbox(): array
    {
        $rows = array_merge(
            $this->commandRowsByStatus(PaymentProviderCommandOutbox::STATUS_PENDING, 200),
            $this->commandRowsByStatus(PaymentProviderCommandOutbox::STATUS_PROCESSING, 200),
        );
        usort(
            $rows,
            static fn (array $left, array $right): int =>
                (int) ($left[PaymentProviderCommandOutbox::schema_fields_ID] ?? 0)
                <=> (int) ($right[PaymentProviderCommandOutbox::schema_fields_ID] ?? 0),
        );
        $rows = array_slice($rows, 0, 200);

        return array_map(fn (array $row): array => $this->outboxRowToArray($row), $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function commandRowsByStatus(string $status, int $limit): array
    {
        return $this->newModel(PaymentProviderCommandOutbox::class)
            ->where(PaymentProviderCommandOutbox::schema_fields_STATUS, $status)
            ->order(PaymentProviderCommandOutbox::schema_fields_ID, 'ASC')
            ->select()
            ->limit($limit)
            ->fetchArray();
    }

    public function countActiveGuardsForPayable(
        string $environment,
        string $payableType,
        string $payableId,
    ): int {
        return (int) $this->newModel(PaymentIntent::class)
            ->where(PaymentIntent::schema_fields_ENVIRONMENT, $environment)
            ->where(PaymentIntent::schema_fields_PAYABLE_TYPE, $payableType)
            ->where(PaymentIntent::schema_fields_PAYABLE_ID, $payableId)
            ->where(PaymentIntent::schema_fields_ACTIVE_GUARD, PaymentIntent::ACTIVE_GUARD_VALUE)
            ->total();
    }

    public function countOpenNonterminalGuards(string $intentCode): int
    {
        return (int) $this->newModel(PaymentAttempt::class)
            ->where(PaymentAttempt::schema_fields_INTENT_CODE, $intentCode)
            ->where(PaymentAttempt::schema_fields_NONTERMINAL_GUARD, PaymentAttempt::NONTERMINAL_GUARD_VALUE)
            ->total();
    }

    /**
     * @param array<string, mixed> $intent
     * @param array<string, mixed>|null $attempt
     */
    public function toOperationResult(
        array $intent,
        ?array $attempt = null,
        ?string $errorCode = null,
    ): PaymentOperationResult {
        $attempt ??= isset($intent['current_attempt_code'])
            ? $this->getAttempt((string) $intent['current_attempt_code'])
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

    private function loadIntent(string $intentCode): ?PaymentIntent
    {
        if (trim($intentCode) === '') {
            return null;
        }
        $model = $this->newModel(PaymentIntent::class);
        $model->where(PaymentIntent::schema_fields_INTENT_CODE, $intentCode)
            ->find()
            ->fetch();

        return $model->getId() ? $model : null;
    }

    private function loadIntentForUpdate(string $intentCode): ?PaymentIntent
    {
        if (trim($intentCode) === '') {
            return null;
        }
        $model = $this->newModel(PaymentIntent::class)
            ->where(PaymentIntent::schema_fields_INTENT_CODE, $intentCode);
        if (!$this->isSqliteModel($model)) {
            $model->additional('FOR UPDATE');
        }
        $model->find()->fetch();

        return $model->getId() ? $model : null;
    }

    private function loadActiveIntent(
        string $environment,
        string $payableType,
        string $payableId,
    ): ?PaymentIntent {
        $model = $this->newModel(PaymentIntent::class);
        $model->where(PaymentIntent::schema_fields_ENVIRONMENT, $environment)
            ->where(PaymentIntent::schema_fields_PAYABLE_TYPE, $payableType)
            ->where(PaymentIntent::schema_fields_PAYABLE_ID, $payableId)
            ->where(PaymentIntent::schema_fields_ACTIVE_GUARD, PaymentIntent::ACTIVE_GUARD_VALUE)
            ->find()
            ->fetch();

        return $model->getId() ? $model : null;
    }

    private function loadAttempt(string $attemptCode): ?PaymentAttempt
    {
        if (trim($attemptCode) === '') {
            return null;
        }
        $model = $this->newModel(PaymentAttempt::class);
        $model->where(PaymentAttempt::schema_fields_ATTEMPT_CODE, $attemptCode)
            ->find()
            ->fetch();

        return $model->getId() ? $model : null;
    }

    private function loadLatestAttempt(string $intentCode): ?PaymentAttempt
    {
        $model = $this->newModel(PaymentAttempt::class);
        $model->where(PaymentAttempt::schema_fields_INTENT_CODE, $intentCode)
            ->order(PaymentAttempt::schema_fields_ID, 'DESC')
            ->find()
            ->fetch();

        return $model->getId() ? $model : null;
    }

    private function loadLatestAttemptForUpdate(string $intentCode): ?PaymentAttempt
    {
        $model = $this->newModel(PaymentAttempt::class)
            ->where(PaymentAttempt::schema_fields_INTENT_CODE, $intentCode)
            ->order(PaymentAttempt::schema_fields_ID, 'DESC');
        if (!$this->isSqliteModel($model)) {
            $model->additional('FOR UPDATE');
        }
        $model->find()->fetch();

        return $model->getId() ? $model : null;
    }

    private function normalizeWebhookAttemptStatus(string $transition): ?string
    {
        return match (strtolower(trim($transition))) {
            'paid', 'succeeded', 'captured', 'success' => PaymentAttempt::STATUS_SUCCEEDED,
            'failed', 'fail' => PaymentAttempt::STATUS_FAILED,
            'pending', 'processing', 'provider_pending' => PaymentAttempt::STATUS_PROCESSING,
            default => null,
        };
    }

    private function webhookStatusRank(string $status): int
    {
        return match ($status) {
            PaymentAttempt::STATUS_SUCCEEDED => 100,
            PaymentAttempt::STATUS_FAILED => 50,
            PaymentIntentOrchestrator::STATUS_UNKNOWN,
            PaymentAttempt::STATUS_PROCESSING,
            PaymentAttempt::STATUS_PROVIDER_PENDING => 20,
            PaymentAttempt::STATUS_CREATED,
            PaymentIntentOrchestrator::STATUS_LOCAL_ACCEPTED => 10,
            default => 0,
        };
    }

    private function isSqliteModel(Model $model): bool
    {
        return strtolower((string) $model->getConnection()
            ->getConnector()
            ->getConfigProvider()
            ->getDbType()) === 'sqlite';
    }

    private function loadCommandOutbox(string $commandCode): ?PaymentProviderCommandOutbox
    {
        if (trim($commandCode) === '') {
            return null;
        }
        $model = $this->newModel(PaymentProviderCommandOutbox::class);
        $model->where(PaymentProviderCommandOutbox::schema_fields_COMMAND_CODE, $commandCode)
            ->find()
            ->fetch();

        return $model->getId() ? $model : null;
    }

    /**
     * @param PaymentAttempt|null $attempt
     * @return array<string, mixed>
     */
    private function intentToArray(PaymentIntent $intent, ?PaymentAttempt $attempt = null): array
    {
        $attempt ??= $this->loadLatestAttempt(
            (string) $intent->getData(PaymentIntent::schema_fields_INTENT_CODE),
        );
        $snapshot = $this->decodeJson($intent->getData(PaymentIntent::schema_fields_AMOUNT_SNAPSHOT));
        $status = (string) $intent->getData(PaymentIntent::schema_fields_STATUS);

        return [
            'intent_code' => (string) $intent->getData(PaymentIntent::schema_fields_INTENT_CODE),
            'environment' => (string) $intent->getData(PaymentIntent::schema_fields_ENVIRONMENT),
            'payable_type' => (string) $intent->getData(PaymentIntent::schema_fields_PAYABLE_TYPE),
            'payable_id' => (string) $intent->getData(PaymentIntent::schema_fields_PAYABLE_ID),
            'method_code' => (string) $intent->getData(PaymentIntent::schema_fields_METHOD_CODE),
            'provider_code' => (string) $intent->getData(PaymentIntent::schema_fields_PROVIDER_CODE),
            'merchant_account' => (string) $intent->getData(PaymentIntent::schema_fields_MERCHANT_ACCOUNT),
            'status' => $status,
            'terminal' => \in_array($status, [
                PaymentIntentOrchestrator::STATUS_SUCCEEDED,
                PaymentIntentOrchestrator::ERROR_INVENTORY_CONFLICT,
                PaymentIntent::STATUS_CANCELLED,
                PaymentIntent::STATUS_CLOSED,
                PaymentIntent::STATUS_REFUNDED,
            ], true),
            'active_flag' => (int) $intent->getData(PaymentIntent::schema_fields_ACTIVE_FLAG),
            'active_guard' => $intent->getData(PaymentIntent::schema_fields_ACTIVE_GUARD),
            'amount_minor' => (int) $intent->getData(PaymentIntent::schema_fields_AMOUNT_MINOR),
            'currency_code' => (string) $intent->getData(PaymentIntent::schema_fields_CURRENCY_CODE),
            'precision' => (int) $intent->getData(PaymentIntent::schema_fields_PRECISION),
            'snapshot_version' => (string) $intent->getData(PaymentIntent::schema_fields_SCOPE_VERSION),
            'scope' => \is_array($snapshot['scope'] ?? null) ? $snapshot['scope'] : [],
            'idempotency_key' => (string) $intent->getData(PaymentIntent::schema_fields_IDEMPOTENCY_KEY),
            'request_hash' => (string) $intent->getData(PaymentIntent::schema_fields_REQUEST_HASH),
            'current_attempt_code' => $attempt instanceof PaymentAttempt
                ? (string) $attempt->getData(PaymentAttempt::schema_fields_ATTEMPT_CODE)
                : '',
            'payment_status' => (string) ($snapshot['payment_status'] ?? 'unpaid'),
            'attention' => $snapshot['attention'] ?? null,
            'asset_allocations' => is_array($snapshot['asset_allocations'] ?? null)
                ? $snapshot['asset_allocations']
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attemptToArray(PaymentAttempt $attempt): array
    {
        return $this->attemptRowToArray($attempt->getData());
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function attemptRowToArray(array $row): array
    {
        $request = $this->decodeJson($row[PaymentAttempt::schema_fields_REQUEST_SNAPSHOT] ?? null);

        return [
            'attempt_code' => (string) ($row[PaymentAttempt::schema_fields_ATTEMPT_CODE] ?? ''),
            'intent_code' => (string) ($row[PaymentAttempt::schema_fields_INTENT_CODE] ?? ''),
            'environment' => (string) ($row[PaymentAttempt::schema_fields_ENVIRONMENT] ?? ''),
            'payable_type' => (string) ($row[PaymentAttempt::schema_fields_PAYABLE_TYPE] ?? ''),
            'payable_id' => (string) ($row[PaymentAttempt::schema_fields_PAYABLE_ID] ?? ''),
            'method_code' => (string) ($row[PaymentAttempt::schema_fields_METHOD_CODE] ?? ''),
            'provider_code' => (string) ($row[PaymentAttempt::schema_fields_PROVIDER_CODE] ?? ''),
            'merchant_account' => (string) ($row[PaymentAttempt::schema_fields_MERCHANT_ACCOUNT] ?? ''),
            'status' => (string) ($row[PaymentAttempt::schema_fields_STATUS] ?? ''),
            'nonterminal_guard' => $row[PaymentAttempt::schema_fields_NONTERMINAL_GUARD] ?? null,
            'version' => (int) ($row[PaymentAttempt::schema_fields_VERSION] ?? 0),
            'cas_token' => (string) ($row[PaymentAttempt::schema_fields_CAS_TOKEN] ?? ''),
            'amount_minor' => (int) ($row[PaymentAttempt::schema_fields_AMOUNT_MINOR] ?? 0),
            'currency_code' => (string) ($row[PaymentAttempt::schema_fields_PAYMENT_CURRENCY_CODE] ?? ''),
            'provider_request_key' => (string) ($row[PaymentAttempt::schema_fields_PROVIDER_REQUEST_KEY] ?? ''),
            'provider_reference' => $row[PaymentAttempt::schema_fields_PROVIDER_REFERENCE] ?? null,
            'provider_reference_guard' => $row[PaymentAttempt::schema_fields_PROVIDER_REFERENCE_GUARD] ?? null,
            'started_at' => strtotime((string) ($row[PaymentAttempt::schema_fields_STARTED_AT] ?? '')) ?: 0,
            'reservation_expires_at' => strtotime((string) ($row[PaymentAttempt::schema_fields_RESERVATION_EXPIRES_AT] ?? '')) ?: 0,
            'response_snapshot' => $this->decodeJson($row[PaymentAttempt::schema_fields_RESPONSE_SNAPSHOT] ?? null),
            'failure_reason_code' => $row[PaymentAttempt::schema_fields_FAILURE_REASON_CODE] ?? null,
            'reservation_uuid' => $request['reservation_uuid'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function outboxToArray(PaymentProviderCommandOutbox $outbox): array
    {
        return $this->outboxRowToArray($outbox->getData());
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function outboxRowToArray(array $row): array
    {
        return [
            'command_code' => (string) ($row[PaymentProviderCommandOutbox::schema_fields_COMMAND_CODE] ?? ''),
            'intent_code' => (string) ($row[PaymentProviderCommandOutbox::schema_fields_INTENT_CODE] ?? ''),
            'attempt_code' => (string) ($row[PaymentProviderCommandOutbox::schema_fields_ATTEMPT_CODE] ?? ''),
            'command_type' => (string) ($row[PaymentProviderCommandOutbox::schema_fields_COMMAND_TYPE] ?? ''),
            'provider_request_key' => (string) ($row[PaymentProviderCommandOutbox::schema_fields_PROVIDER_REQUEST_KEY] ?? ''),
            'status' => (string) ($row[PaymentProviderCommandOutbox::schema_fields_STATUS] ?? ''),
            'expected_attempt_version' => (int) ($row[PaymentProviderCommandOutbox::schema_fields_EXPECTED_ATTEMPT_VERSION] ?? 0),
            'payload' => $this->decodeJson($row[PaymentProviderCommandOutbox::schema_fields_PAYLOAD_JSON] ?? null),
            'response' => $this->decodeJson($row[PaymentProviderCommandOutbox::schema_fields_RESPONSE_JSON] ?? null),
            'error_code' => $row[PaymentProviderCommandOutbox::schema_fields_ERROR_CODE] ?? null,
            'attempt_count' => (int) ($row[PaymentProviderCommandOutbox::schema_fields_ATTEMPT_COUNT] ?? 0),
            'claim_token' => (string) ($row[PaymentProviderCommandOutbox::schema_fields_CLAIM_TOKEN] ?? ''),
            'created_at' => strtotime((string) ($row[PaymentProviderCommandOutbox::schema_fields_CREATED_AT] ?? '')) ?: 0,
            'processed_at' => strtotime((string) ($row[PaymentProviderCommandOutbox::schema_fields_PROCESSED_AT] ?? '')) ?: null,
        ];
    }

    /**
     * @return array{ok:bool,error_code:?string,intent:?array,attempt:?array,outbox:?array}
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

    private function snapshotVersion(PayableSnapshot $snapshot): string
    {
        return hash('sha256', $this->json([
            'amount_minor' => $snapshot->getAmountMinor(),
            'currency_code' => $snapshot->getCurrencyCode(),
            'items' => $snapshot->getItems(),
            'scope' => $snapshot->getArray('scope'),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function amountSnapshot(PayableSnapshot $snapshot, string $snapshotVersion): array
    {
        return [
            'amount_minor' => $snapshot->getAmountMinor(),
            'currency_code' => $snapshot->getCurrencyCode(),
            'precision' => $snapshot->getPrecision(),
            'items' => $snapshot->getItems(),
            'scope' => $snapshot->getArray('scope'),
            'amounts' => $snapshot->getArray('amounts'),
            'asset_allocations' => $snapshot->getArray('asset_allocations'),
            'snapshot_version' => $snapshotVersion,
            'payment_status' => 'unpaid',
            'attention' => null,
        ];
    }

    private function intentHasAssetAllocations(PaymentIntent $intent): bool
    {
        $snapshot = $this->decodeJson(
            $intent->getData(PaymentIntent::schema_fields_AMOUNT_SNAPSHOT),
        );

        return is_array($snapshot['asset_allocations'] ?? null)
            && $snapshot['asset_allocations'] !== [];
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function scopeCode(array $scope, string $currency): string
    {
        return implode('.', [
            (string) ((int) ($scope['website_id'] ?? 0)),
            (string) ((int) ($scope['store_id'] ?? 0)),
            strtoupper((string) ($scope['currency'] ?? $currency)),
        ]);
    }

    private function dateTime(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }

    private function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (\is_array($value)) {
            return $value;
        }
        if (!\is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function providerReferenceGuard(PaymentAttempt $attempt, ?string $providerReference): ?string
    {
        if ($providerReference === null) {
            return null;
        }

        return hash('sha256', implode('|', [
            strtolower(trim((string) $attempt->getData(PaymentAttempt::schema_fields_ENVIRONMENT))),
            strtolower(trim((string) $attempt->getData(PaymentAttempt::schema_fields_PROVIDER_CODE))),
            strtolower(trim((string) $attempt->getData(PaymentAttempt::schema_fields_MERCHANT_ACCOUNT))),
            $providerReference,
        ]));
    }

    private function minorToDecimal(int $amountMinor, int $precision): string
    {
        $precision = max(0, min(8, $precision));
        $factor = 10 ** $precision;
        if ($precision === 0) {
            return (string) $amountMinor;
        }
        $sign = $amountMinor < 0 ? '-' : '';
        $amountMinor = abs($amountMinor);

        return sprintf('%s%d.%0' . $precision . 'd', $sign, intdiv($amountMinor, $factor), $amountMinor % $factor);
    }

    /**
     * @template T of Model
     * @param class-string<T> $class
     * @return T
     */
    private function newModel(string $class): Model
    {
        /** @var T $model */
        $model = $this->objectManager->getInstance($class, [], false);

        return $model;
    }
}
