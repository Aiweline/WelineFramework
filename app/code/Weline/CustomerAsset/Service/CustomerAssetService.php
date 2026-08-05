<?php

declare(strict_types=1);

namespace Weline\CustomerAsset\Service;

use Throwable;
use Weline\CustomerAsset\Api\CustomerAssetFacadeInterface;
use Weline\CustomerAsset\Model\AssetAccount;
use Weline\CustomerAsset\Model\AssetLedger;
use Weline\CustomerAsset\Model\AssetReservation;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;

/**
 * PostgreSQL-backed account, immutable ledger and reservation facade.
 *
 * The in-memory book is an explicit unit-test seam only. Production resolves
 * fresh ORM records and joins every balance/reservation/ledger mutation in one
 * framework transaction.
 */
final class CustomerAssetService implements CustomerAssetFacadeInterface
{
    public const CAPABILITY = 'customer_asset';

    public const ERROR_MODE_OFF = 'customer_asset_mode_off_blocks_tender';
    public const ERROR_INSUFFICIENT = 'customer_asset_insufficient_balance';
    public const ERROR_CAS = 'customer_asset_balance_cas_conflict';
    public const ERROR_DUPLICATE_EVENT = 'customer_asset_duplicate_event';
    public const ERROR_RESERVATION_NOT_FOUND = 'customer_asset_reservation_not_found';
    public const ERROR_INVALID_TRANSITION = 'customer_asset_reservation_invalid_transition';
    public const ERROR_NAMESPACE = 'customer_asset_invalid_namespace';
    public const ERROR_ACCOUNT_NOT_FOUND = 'customer_asset_account_not_found';
    public const ERROR_PERSISTENCE = 'customer_asset_persistence_failed';

    /** @var (\Closure(): AssetAccount)|null */
    private readonly ?\Closure $accountFactory;

    /** @var (\Closure(): AssetLedger)|null */
    private readonly ?\Closure $ledgerFactory;

    /** @var (\Closure(): AssetReservation)|null */
    private readonly ?\Closure $reservationFactory;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $memoryAccounts = null;

    /** @var array<string, string> */
    private array $memoryAccountIdentity = [];

    /** @var array<string, array<string, mixed>> */
    private array $memoryLedger = [];

    /** @var array<string, string> */
    private array $memoryLedgerEntry = [];

    /** @var array<string, array<string, mixed>> */
    private array $memoryReservations = [];

    public function __construct(
        private ?CommerceRolloutGateInterface $rolloutGate = null,
        private readonly ?DatabaseTransactionRunnerInterface $transactions = null,
        ?callable $accountFactory = null,
        ?callable $ledgerFactory = null,
        ?callable $reservationFactory = null,
        bool $useMemory = false,
    ) {
        $this->accountFactory = $accountFactory !== null
            ? \Closure::fromCallable($accountFactory)
            : null;
        $this->ledgerFactory = $ledgerFactory !== null
            ? \Closure::fromCallable($ledgerFactory)
            : null;
        $this->reservationFactory = $reservationFactory !== null
            ? \Closure::fromCallable($reservationFactory)
            : null;
        if ($useMemory) {
            $this->memoryAccounts = [];
        }
    }

    public static function forTesting(CommerceRolloutGateInterface $rollout): self
    {
        $rollout->setMode(self::CAPABILITY, CommerceRolloutGateInterface::MODE_OFF);

        return new self(rolloutGate: $rollout, useMemory: true);
    }

    public function rollout(): CommerceRolloutGateInterface
    {
        if (!$this->rolloutGate instanceof CommerceRolloutGateInterface) {
            $this->rolloutGate = CustomerAssetRolloutGate::forConnection(
                ConnectionFactory::getInstance(),
            );
        }

        return $this->rolloutGate;
    }

    /** @param list<string> $allowlist */
    public function enableAllowlist(array $allowlist = ['website:0']): void
    {
        $this->rollout()->setMode(
            self::CAPABILITY,
            CommerceRolloutGateInterface::MODE_ALLOWLIST,
            $allowlist,
        );
    }

    public function modeOff(): void
    {
        $this->rollout()->setMode(self::CAPABILITY, CommerceRolloutGateInterface::MODE_OFF);
    }

    public function credit(array $request): array
    {
        $identity = $this->normalizeIdentity($request);
        $amount = $this->positiveAmount((int) ($request['amount_minor'] ?? 0));
        $eventId = $this->requireEventId((string) ($request['event_id'] ?? ''));
        $this->assertMutable($identity['website_id']);
        $requestHash = $this->requestHash(
            AssetLedger::TYPE_CREDIT,
            $identity,
            $amount,
        );

        if ($this->isMemory()) {
            return $this->runMemoryAtomically(
                fn (): array => $this->creditOnce($identity, $amount, $eventId, $requestHash),
            );
        }

        $replay = $this->replayForIdentity(
            $eventId,
            $requestHash,
            AssetLedger::TYPE_CREDIT,
            $identity,
            $amount,
        );
        if ($replay !== null) {
            return $this->creditResponse($replay, true);
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                return $this->runDurable(
                    fn (): array => $this->creditOnce(
                        $identity,
                        $amount,
                        $eventId,
                        $requestHash,
                    ),
                );
            } catch (Throwable $exception) {
                $replay = $this->replayForIdentity(
                    $eventId,
                    $requestHash,
                    AssetLedger::TYPE_CREDIT,
                    $identity,
                    $amount,
                );
                if ($replay !== null) {
                    return $this->creditResponse($replay, true);
                }
                if ($attempt === 0 && $this->findAccountByIdentity($identity) !== null) {
                    continue;
                }
                $this->throwPersistence($exception);
            }
        }

        throw new \LogicException('unreachable');
    }

    public function reserve(array $request): array
    {
        $identity = $this->normalizeIdentity($request);
        $amount = $this->positiveAmount((int) ($request['amount_minor'] ?? 0));
        $eventId = $this->requireEventId((string) ($request['event_id'] ?? ''));
        $this->assertMutable($identity['website_id']);
        $requestHash = $this->requestHash(
            AssetLedger::TYPE_RESERVE,
            $identity,
            $amount,
        );

        $replay = $this->replayForIdentity(
            $eventId,
            $requestHash,
            AssetLedger::TYPE_RESERVE,
            $identity,
            $amount,
        );
        if ($replay !== null) {
            return $this->reserveResponse($replay, true);
        }

        try {
            if ($this->isMemory()) {
                return $this->runMemoryAtomically(
                    fn (): array => $this->reserveOnce(
                        $identity,
                        $amount,
                        $eventId,
                        $requestHash,
                    ),
                );
            }

            return $this->runDurable(
                fn (): array => $this->reserveOnce(
                    $identity,
                    $amount,
                    $eventId,
                    $requestHash,
                ),
            );
        } catch (Throwable $exception) {
            $replay = $this->replayForIdentity(
                $eventId,
                $requestHash,
                AssetLedger::TYPE_RESERVE,
                $identity,
                $amount,
            );
            if ($replay !== null) {
                return $this->reserveResponse($replay, true);
            }
            $this->throwPersistence($exception);
        }
    }

    public function release(string $reservationId, string $eventId): array
    {
        return $this->settleReservation(
            $reservationId,
            $eventId,
            AssetLedger::TYPE_RELEASE,
            AssetReservation::STATUS_RELEASED,
        );
    }

    public function commit(string $reservationId, string $eventId): array
    {
        return $this->settleReservation(
            $reservationId,
            $eventId,
            AssetLedger::TYPE_COMMIT,
            AssetReservation::STATUS_COMMITTED,
        );
    }

    public function returnCommitted(
        string $reservationId,
        int $amountMinor,
        string $eventId,
    ): array {
        $reservationId = $this->requireReservationId($reservationId);
        $amountMinor = $this->positiveAmount($amountMinor);
        $eventId = $this->requireEventId($eventId);
        $reservation = $this->findReservation($reservationId);
        if ($reservation === null) {
            throw new CustomerAssetConflictException(
                self::ERROR_RESERVATION_NOT_FOUND,
                __('CustomerAsset reservation 不存在：%{1}', [$reservationId]),
                ['reservation_id' => $reservationId],
            );
        }
        $identity = [
            'customer_id' => (string) $reservation['customer_id'],
            'website_id' => (int) $reservation['website_id'],
            'asset_code' => (string) $reservation['asset_code'],
            'namespace' => (string) $reservation['namespace'],
        ];
        $requestHash = $this->requestHash(
            AssetLedger::TYPE_RETURN,
            $identity,
            $amountMinor,
            $reservationId,
        );
        $replay = $this->findLedgerByEvent($eventId);
        if ($replay !== null) {
            $this->assertLedgerReplay(
                $replay,
                $requestHash,
                AssetLedger::TYPE_RETURN,
                $identity,
                $amountMinor,
                $reservationId,
            );
            return $this->settlementResponse($replay, true);
        }

        try {
            $callback = fn (): array => $this->returnCommittedOnce(
                $reservationId,
                $amountMinor,
                $eventId,
                $requestHash,
            );
            return $this->isMemory()
                ? $this->runMemoryAtomically($callback)
                : $this->runDurable($callback);
        } catch (Throwable $exception) {
            $replay = $this->findLedgerByEvent($eventId);
            if ($replay !== null) {
                $this->assertLedgerReplay(
                    $replay,
                    $requestHash,
                    AssetLedger::TYPE_RETURN,
                    $identity,
                    $amountMinor,
                    $reservationId,
                );
                return $this->settlementResponse($replay, true);
            }
            $this->throwPersistence($exception);
        }
    }

    public function getBalance(
        string|int $customerId,
        int $websiteId,
        string $assetCode,
        string $namespace = AssetAccount::NS_LIVE,
    ): array {
        $identity = $this->normalizeIdentity([
            'customer_id' => $customerId,
            'website_id' => $websiteId,
            'asset_code' => $assetCode,
            'namespace' => $namespace,
        ]);
        $account = $this->findAccountByIdentity($identity);
        if ($account === null) {
            return [
                'ok' => true,
                'exists' => false,
                'available_minor' => 0,
                'reserved_minor' => 0,
                'reservable_minor' => 0,
            ];
        }

        return [
            'ok' => true,
            'exists' => true,
            'account' => $account,
            'available_minor' => $account['available_minor'],
            'reserved_minor' => $account['reserved_minor'],
            'reservable_minor' => $account['reservable_minor'],
        ];
    }

    public function listLedger(
        string|int $customerId,
        int $websiteId,
        string $assetCode,
        string $namespace = AssetAccount::NS_LIVE,
        int $limit = 100,
    ): array {
        $limit = max(1, min(200, $limit));
        $identity = $this->normalizeIdentity([
            'customer_id' => $customerId,
            'website_id' => $websiteId,
            'asset_code' => $assetCode,
            'namespace' => $namespace,
        ]);
        $account = $this->findAccountByIdentity($identity);
        if ($account === null) {
            return [];
        }
        if ($this->isMemory()) {
            $rows = array_values(array_filter(
                $this->memoryLedger,
                static fn (array $row): bool => $row['account_id'] === $account['account_id'],
            ));
            usort(
                $rows,
                static fn (array $left, array $right): int => strcmp(
                    (string) $left['created_at'] . '|' . $left['entry_id'],
                    (string) $right['created_at'] . '|' . $right['entry_id'],
                ),
            );
            return array_slice($rows, -$limit);
        }

        $rows = $this->newLedger()->clear()
            ->where(AssetLedger::schema_fields_ACCOUNT_ID, $account['account_id'])
            ->order(AssetLedger::schema_fields_ID, 'desc')
            ->limit($limit)
            ->select()
            ->fetchArray();

        return array_reverse(array_map($this->normalizeLedger(...), $rows));
    }

    public function listAccounts(
        string|int $customerId,
        int $websiteId,
        string $namespace = AssetAccount::NS_LIVE,
        int $limit = 100,
    ): array {
        $customerId = trim((string) $customerId);
        $namespace = $this->normalizeNamespace($namespace);
        $limit = max(1, min(200, $limit));
        if ($customerId === '' || strlen($customerId) > 64) {
            throw new \InvalidArgumentException(__('CustomerAsset customer_id 非法'));
        }
        if ($websiteId < 0) {
            throw new \InvalidArgumentException(
                __('CustomerAsset website_id 不能为负数：%{1}', [$websiteId]),
            );
        }
        if ($this->isMemory()) {
            $rows = array_values(array_filter(
                $this->memoryAccounts ?? [],
                static fn (array $row): bool =>
                    (string) ($row['customer_id'] ?? '') === $customerId
                    && (int) ($row['website_id'] ?? -1) === $websiteId
                    && (string) ($row['namespace'] ?? '') === $namespace,
            ));
            usort(
                $rows,
                static fn (array $left, array $right): int => strcmp(
                    (string) ($left['asset_code'] ?? ''),
                    (string) ($right['asset_code'] ?? ''),
                ),
            );
            return array_slice($rows, 0, $limit);
        }

        $rows = $this->newAccount()->clear()
            ->where(AssetAccount::schema_fields_CUSTOMER_ID, $customerId)
            ->where(AssetAccount::schema_fields_WEBSITE_ID, $websiteId)
            ->where(AssetAccount::schema_fields_NAMESPACE, $namespace)
            ->order(AssetAccount::schema_fields_ASSET_CODE, 'asc')
            ->limit($limit)
            ->select()
            ->fetchArray();

        return array_map($this->normalizeAccount(...), $rows);
    }

    public function getReservation(string $reservationId): array
    {
        $row = $this->findReservation($this->requireReservationId($reservationId));
        if ($row === null) {
            throw new CustomerAssetConflictException(
                self::ERROR_RESERVATION_NOT_FOUND,
                __('CustomerAsset reservation 不存在：%{1}', [$reservationId]),
                ['reservation_id' => $reservationId],
            );
        }

        return $row;
    }

    /**
     * @param array{customer_id:string,website_id:int,asset_code:string,namespace:string} $identity
     * @return array<string, mixed>
     */
    private function creditOnce(
        array $identity,
        int $amount,
        string $eventId,
        string $requestHash,
    ): array {
        $existing = $this->replayForIdentity(
            $eventId,
            $requestHash,
            AssetLedger::TYPE_CREDIT,
            $identity,
            $amount,
        );
        if ($existing !== null) {
            return $this->creditResponse($existing, true);
        }

        $account = $this->findAccountByIdentity($identity)
            ?? $this->insertAccount($identity);
        $next = $this->mutateAccount(
            $account,
            (int) $account['available_minor'] + $amount,
            (int) $account['reserved_minor'],
        );
        $entry = $this->appendLedger(
            $next,
            $eventId,
            AssetLedger::TYPE_CREDIT,
            $amount,
            $requestHash,
        );

        return [
            'ok' => true,
            'idempotent' => false,
            'account' => $next,
            'entry' => $entry,
        ];
    }

    /**
     * @param array{customer_id:string,website_id:int,asset_code:string,namespace:string} $identity
     * @return array<string, mixed>
     */
    private function reserveOnce(
        array $identity,
        int $amount,
        string $eventId,
        string $requestHash,
    ): array {
        $existing = $this->replayForIdentity(
            $eventId,
            $requestHash,
            AssetLedger::TYPE_RESERVE,
            $identity,
            $amount,
        );
        if ($existing !== null) {
            return $this->reserveResponse($existing, true);
        }

        $account = $this->findAccountByIdentity($identity);
        if ($account === null) {
            throw new CustomerAssetConflictException(
                self::ERROR_ACCOUNT_NOT_FOUND,
                __('CustomerAsset account 不存在'),
                $identity,
            );
        }
        if ((int) $account['reservable_minor'] < $amount) {
            throw new CustomerAssetConflictException(
                self::ERROR_INSUFFICIENT,
                __('CustomerAsset 可预占余额不足'),
                [
                    'reservable_minor' => $account['reservable_minor'],
                    'requested_minor' => $amount,
                ],
            );
        }

        $next = $this->mutateAccount(
            $account,
            (int) $account['available_minor'],
            (int) $account['reserved_minor'] + $amount,
        );
        $reservation = $this->insertReservation(
            $next,
            $eventId,
            $requestHash,
            $amount,
        );
        $entry = $this->appendLedger(
            $next,
            $eventId,
            AssetLedger::TYPE_RESERVE,
            $amount,
            $requestHash,
            $reservation['reservation_id'],
        );

        return [
            'ok' => true,
            'idempotent' => false,
            'account' => $next,
            'reservation' => $reservation,
            'entry' => $entry,
        ];
    }

    /** @return array<string, mixed> */
    private function settleReservation(
        string $reservationId,
        string $eventId,
        string $eventType,
        string $terminalStatus,
    ): array {
        $reservationId = $this->requireReservationId($reservationId);
        $eventId = $this->requireEventId($eventId);
        $reservation = $this->findReservation($reservationId);
        if ($reservation === null) {
            throw new CustomerAssetConflictException(
                self::ERROR_RESERVATION_NOT_FOUND,
                __('CustomerAsset reservation 不存在：%{1}', [$reservationId]),
                ['reservation_id' => $reservationId],
            );
        }
        $requestHash = $this->requestHash(
            $eventType,
            [
                'customer_id' => $reservation['customer_id'],
                'website_id' => $reservation['website_id'],
                'asset_code' => $reservation['asset_code'],
                'namespace' => $reservation['namespace'],
            ],
            (int) $reservation['amount_minor'],
            $reservationId,
        );

        try {
            $callback = fn (): array => $this->settleOnce(
                $reservationId,
                $eventId,
                $eventType,
                $terminalStatus,
                $requestHash,
            );
            return $this->isMemory()
                ? $this->runMemoryAtomically($callback)
                : $this->runDurable($callback);
        } catch (Throwable $exception) {
            $ledger = $this->findLedgerByEvent($eventId);
            if ($ledger !== null) {
                $this->assertLedgerReplay(
                    $ledger,
                    $requestHash,
                    $eventType,
                    null,
                    (int) $reservation['amount_minor'],
                    $reservationId,
                );
                return $this->settlementResponse($ledger, true);
            }
            $this->throwPersistence($exception);
        }
    }

    /** @return array<string, mixed> */
    private function settleOnce(
        string $reservationId,
        string $eventId,
        string $eventType,
        string $terminalStatus,
        string $requestHash,
    ): array {
        $reservation = $this->findReservation($reservationId);
        if ($reservation === null) {
            throw new CustomerAssetConflictException(
                self::ERROR_RESERVATION_NOT_FOUND,
                __('CustomerAsset reservation 不存在：%{1}', [$reservationId]),
            );
        }
        if ($reservation['status'] === $terminalStatus) {
            if ($reservation['terminal_event_id'] !== $eventId
                || !hash_equals((string) $reservation['terminal_request_hash'], $requestHash)
            ) {
                throw new CustomerAssetConflictException(
                    self::ERROR_INVALID_TRANSITION,
                    __('CustomerAsset reservation 终态重放身份冲突'),
                    ['reservation_id' => $reservationId, 'status' => $reservation['status']],
                );
            }
            $ledger = $this->findLedgerByEvent($eventId);
            if ($ledger === null) {
                throw new CustomerAssetConflictException(
                    self::ERROR_PERSISTENCE,
                    __('CustomerAsset reservation 终态缺少 ledger'),
                );
            }
            $this->assertLedgerReplay(
                $ledger,
                $requestHash,
                $eventType,
                null,
                (int) $reservation['amount_minor'],
                $reservationId,
            );
            return $this->settlementResponse($ledger, true);
        }
        if ($reservation['status'] !== AssetReservation::STATUS_RESERVED) {
            throw new CustomerAssetConflictException(
                self::ERROR_INVALID_TRANSITION,
                __('CustomerAsset reservation 状态不能执行该动作：%{1}', [$reservation['status']]),
                ['reservation_id' => $reservationId, 'status' => $reservation['status']],
            );
        }

        $eventCollision = $this->findLedgerByEvent($eventId);
        if ($eventCollision !== null) {
            $this->assertLedgerReplay(
                $eventCollision,
                $requestHash,
                $eventType,
                null,
                (int) $reservation['amount_minor'],
                $reservationId,
            );
        }
        $account = $this->findAccountById((string) $reservation['account_id']);
        if ($account === null) {
            throw new CustomerAssetConflictException(
                self::ERROR_ACCOUNT_NOT_FOUND,
                __('CustomerAsset account 不存在'),
                ['account_id' => $reservation['account_id']],
            );
        }
        $amount = (int) $reservation['amount_minor'];
        if ((int) $account['reserved_minor'] < $amount
            || ($eventType === AssetLedger::TYPE_COMMIT
                && (int) $account['available_minor'] < $amount)
        ) {
            throw new CustomerAssetConflictException(
                self::ERROR_PERSISTENCE,
                __('CustomerAsset reservation 与账户余额不守恒'),
                ['reservation_id' => $reservationId],
            );
        }
        $nextAvailable = $eventType === AssetLedger::TYPE_COMMIT
            ? (int) $account['available_minor'] - $amount
            : (int) $account['available_minor'];
        $next = $this->mutateAccount(
            $account,
            $nextAvailable,
            (int) $account['reserved_minor'] - $amount,
        );
        $terminal = $this->transitionReservation(
            $reservation,
            $terminalStatus,
            $eventId,
            $requestHash,
        );
        $ledger = $this->appendLedger(
            $next,
            $eventId,
            $eventType,
            $amount,
            $requestHash,
            $reservationId,
        );

        return [
            'ok' => true,
            'idempotent' => false,
            'account' => $next,
            'reservation' => $terminal,
            'entry' => $ledger,
        ];
    }

    /** @return array<string, mixed> */
    private function returnCommittedOnce(
        string $reservationId,
        int $amountMinor,
        string $eventId,
        string $requestHash,
    ): array {
        $reservation = $this->findReservation($reservationId);
        if ($reservation === null) {
            throw new CustomerAssetConflictException(
                self::ERROR_RESERVATION_NOT_FOUND,
                __('CustomerAsset reservation 不存在：%{1}', [$reservationId]),
            );
        }
        $identity = [
            'customer_id' => (string) $reservation['customer_id'],
            'website_id' => (int) $reservation['website_id'],
            'asset_code' => (string) $reservation['asset_code'],
            'namespace' => (string) $reservation['namespace'],
        ];
        $eventCollision = $this->findLedgerByEvent($eventId);
        if ($eventCollision !== null) {
            $this->assertLedgerReplay(
                $eventCollision,
                $requestHash,
                AssetLedger::TYPE_RETURN,
                $identity,
                $amountMinor,
                $reservationId,
            );
            return $this->settlementResponse($eventCollision, true);
        }
        if ((string) $reservation['status'] !== AssetReservation::STATUS_COMMITTED) {
            throw new CustomerAssetConflictException(
                self::ERROR_INVALID_TRANSITION,
                __('CustomerAsset 仅已 commit reservation 可执行返还'),
                ['reservation_id' => $reservationId, 'status' => $reservation['status']],
            );
        }
        $returnedAmount = (int) ($reservation['returned_amount_minor'] ?? 0);
        if ($returnedAmount + $amountMinor > (int) $reservation['amount_minor']) {
            throw new CustomerAssetConflictException(
                self::ERROR_INVALID_TRANSITION,
                __('CustomerAsset 返还金额超过已 commit 金额'),
                [
                    'reservation_id' => $reservationId,
                    'committed_minor' => (int) $reservation['amount_minor'],
                    'returned_minor' => $returnedAmount,
                    'requested_minor' => $amountMinor,
                ],
            );
        }
        $account = $this->findAccountById((string) $reservation['account_id']);
        if ($account === null) {
            throw new CustomerAssetConflictException(
                self::ERROR_ACCOUNT_NOT_FOUND,
                __('CustomerAsset account 不存在'),
                ['account_id' => $reservation['account_id']],
            );
        }
        $next = $this->mutateAccount(
            $account,
            (int) $account['available_minor'] + $amountMinor,
            (int) $account['reserved_minor'],
        );
        $updatedReservation = $this->recordReservationReturn(
            $reservation,
            $returnedAmount + $amountMinor,
        );
        $ledger = $this->appendLedger(
            $next,
            $eventId,
            AssetLedger::TYPE_RETURN,
            $amountMinor,
            $requestHash,
            $reservationId,
        );

        return [
            'ok' => true,
            'idempotent' => false,
            'account' => $next,
            'reservation' => $updatedReservation,
            'entry' => $ledger,
        ];
    }

    /**
     * @param array{customer_id:string,website_id:int,asset_code:string,namespace:string} $identity
     * @return array<string, mixed>|null
     */
    private function replayForIdentity(
        string $eventId,
        string $requestHash,
        string $eventType,
        array $identity,
        int $amount,
    ): ?array {
        $ledger = $this->findLedgerByEvent($eventId);
        if ($ledger === null) {
            return null;
        }
        $this->assertLedgerReplay($ledger, $requestHash, $eventType, $identity, $amount);

        return $ledger;
    }

    /**
     * @param array<string, mixed> $ledger
     * @param array{customer_id:string,website_id:int,asset_code:string,namespace:string}|null $identity
     */
    private function assertLedgerReplay(
        array $ledger,
        string $requestHash,
        string $eventType,
        ?array $identity,
        int $amount,
        ?string $reservationId = null,
    ): void {
        $matches = hash_equals((string) ($ledger['request_hash'] ?? ''), $requestHash)
            && (string) ($ledger['event_type'] ?? '') === $eventType
            && (int) ($ledger['amount_minor'] ?? -1) === $amount;
        if ($identity !== null) {
            $matches = $matches
                && (string) ($ledger['customer_id'] ?? '') === $identity['customer_id']
                && (int) ($ledger['website_id'] ?? -1) === $identity['website_id']
                && (string) ($ledger['asset_code'] ?? '') === $identity['asset_code']
                && (string) ($ledger['namespace'] ?? '') === $identity['namespace'];
        }
        if ($reservationId !== null) {
            $matches = $matches
                && (string) ($ledger['reservation_id'] ?? '') === $reservationId;
        }
        if (!$matches) {
            throw new CustomerAssetConflictException(
                self::ERROR_DUPLICATE_EVENT,
                __('CustomerAsset event 已被不同请求占用：%{1}', [$ledger['event_id'] ?? '']),
                ['event_id' => $ledger['event_id'] ?? null],
            );
        }
    }

    /** @param array<string, mixed> $ledger @return array<string, mixed> */
    private function creditResponse(array $ledger, bool $idempotent): array
    {
        $account = $this->findAccountById((string) $ledger['account_id']);
        if ($account === null) {
            throw new CustomerAssetConflictException(self::ERROR_PERSISTENCE, __('CustomerAsset ledger 缺少账户'));
        }
        return [
            'ok' => true,
            'idempotent' => $idempotent,
            'account' => $account,
            'entry' => $ledger,
        ];
    }

    /** @param array<string, mixed> $ledger @return array<string, mixed> */
    private function reserveResponse(array $ledger, bool $idempotent): array
    {
        $reservationId = (string) ($ledger['reservation_id'] ?? '');
        $reservation = $this->findReservation($reservationId);
        $account = $this->findAccountById((string) $ledger['account_id']);
        if ($reservation === null || $account === null) {
            throw new CustomerAssetConflictException(
                self::ERROR_PERSISTENCE,
                __('CustomerAsset reserve replay 缺少账户或 reservation'),
            );
        }
        return [
            'ok' => true,
            'idempotent' => $idempotent,
            'account' => $account,
            'reservation' => $reservation,
            'entry' => $ledger,
        ];
    }

    /** @param array<string, mixed> $ledger @return array<string, mixed> */
    private function settlementResponse(array $ledger, bool $idempotent): array
    {
        $reservation = $this->findReservation((string) $ledger['reservation_id']);
        $account = $this->findAccountById((string) $ledger['account_id']);
        if ($reservation === null || $account === null) {
            throw new CustomerAssetConflictException(
                self::ERROR_PERSISTENCE,
                __('CustomerAsset settlement replay 缺少账户或 reservation'),
            );
        }
        return [
            'ok' => true,
            'idempotent' => $idempotent,
            'account' => $account,
            'reservation' => $reservation,
            'entry' => $ledger,
        ];
    }

    /**
     * @param array{customer_id:string,website_id:int,asset_code:string,namespace:string} $identity
     * @return array<string, mixed>
     */
    private function insertAccount(array $identity): array
    {
        $accountId = 'acct_' . substr(hash('sha256', implode('|', $identity)), 0, 40);
        $now = gmdate('Y-m-d H:i:s');
        $row = [
            'account_id' => $accountId,
            ...$identity,
            'available_minor' => 0,
            'reserved_minor' => 0,
            'reservable_minor' => 0,
            'version' => 0,
            'cas_token' => bin2hex(random_bytes(32)),
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if ($this->isMemory()) {
            $this->memoryAccounts[$accountId] = $row;
            $this->memoryAccountIdentity[$this->identityKey($identity)] = $accountId;
            return $row;
        }

        $this->newAccount()->clear()->setData([
            AssetAccount::schema_fields_ACCOUNT_ID => $row['account_id'],
            AssetAccount::schema_fields_CUSTOMER_ID => $row['customer_id'],
            AssetAccount::schema_fields_WEBSITE_ID => $row['website_id'],
            AssetAccount::schema_fields_ASSET_CODE => $row['asset_code'],
            AssetAccount::schema_fields_NAMESPACE => $row['namespace'],
            AssetAccount::schema_fields_AVAILABLE_MINOR => 0,
            AssetAccount::schema_fields_RESERVED_MINOR => 0,
            AssetAccount::schema_fields_VERSION => 0,
            AssetAccount::schema_fields_CAS_TOKEN => $row['cas_token'],
            AssetAccount::schema_fields_CREATED_AT => $now,
            AssetAccount::schema_fields_UPDATED_AT => $now,
        ])->save();

        return $this->findAccountById($accountId)
            ?? throw new CustomerAssetConflictException(self::ERROR_PERSISTENCE, __('CustomerAsset account 写后不可见'));
    }

    /** @param array<string, mixed> $account @return array<string, mixed> */
    private function mutateAccount(array $account, int $availableMinor, int $reservedMinor): array
    {
        if ($availableMinor < 0 || $reservedMinor < 0 || $reservedMinor > $availableMinor) {
            throw new CustomerAssetConflictException(
                self::ERROR_PERSISTENCE,
                __('CustomerAsset 账户余额不守恒'),
                ['account_id' => $account['account_id'] ?? null],
            );
        }
        $nextVersion = (int) $account['version'] + 1;
        $nextToken = bin2hex(random_bytes(32));
        $updatedAt = gmdate('Y-m-d H:i:s');
        if ($this->isMemory()) {
            $current = $this->memoryAccounts[$account['account_id']] ?? null;
            if ($current === null
                || (int) $current['version'] !== (int) $account['version']
                || !hash_equals((string) $current['cas_token'], (string) $account['cas_token'])
            ) {
                throw new CustomerAssetConflictException(self::ERROR_CAS, __('CustomerAsset account CAS 冲突'));
            }
            $current['available_minor'] = $availableMinor;
            $current['reserved_minor'] = $reservedMinor;
            $current['reservable_minor'] = $availableMinor - $reservedMinor;
            $current['version'] = $nextVersion;
            $current['cas_token'] = $nextToken;
            $current['updated_at'] = $updatedAt;
            $this->memoryAccounts[$account['account_id']] = $current;
            return $current;
        }

        $this->newAccount()->getQuery(false)
            ->where(AssetAccount::schema_fields_ACCOUNT_ID, $account['account_id'])
            ->where(AssetAccount::schema_fields_VERSION, $account['version'])
            ->where(AssetAccount::schema_fields_CAS_TOKEN, $account['cas_token'])
            ->update([
                AssetAccount::schema_fields_AVAILABLE_MINOR => $availableMinor,
                AssetAccount::schema_fields_RESERVED_MINOR => $reservedMinor,
                AssetAccount::schema_fields_VERSION => $nextVersion,
                AssetAccount::schema_fields_CAS_TOKEN => $nextToken,
                AssetAccount::schema_fields_UPDATED_AT => $updatedAt,
            ])
            ->fetch();
        $saved = $this->findAccountById((string) $account['account_id']);
        if ($saved === null || !hash_equals($nextToken, (string) $saved['cas_token'])) {
            throw new CustomerAssetConflictException(
                self::ERROR_CAS,
                __('CustomerAsset account CAS 冲突'),
                ['account_id' => $account['account_id']],
            );
        }

        return $saved;
    }

    /** @param array<string, mixed> $account @return array<string, mixed> */
    private function insertReservation(
        array $account,
        string $eventId,
        string $requestHash,
        int $amount,
    ): array {
        $reservationId = 'rsv_' . substr(hash('sha256', $eventId), 0, 40);
        $now = gmdate('Y-m-d H:i:s');
        $row = [
            'reservation_id' => $reservationId,
            'account_id' => $account['account_id'],
            'customer_id' => $account['customer_id'],
            'website_id' => $account['website_id'],
            'asset_code' => $account['asset_code'],
            'namespace' => $account['namespace'],
            'reserve_event_id' => $eventId,
            'reserve_request_hash' => $requestHash,
            'amount_minor' => $amount,
            'returned_amount_minor' => 0,
            'status' => AssetReservation::STATUS_RESERVED,
            'version' => 1,
            'cas_token' => bin2hex(random_bytes(32)),
            'terminal_event_id' => null,
            'terminal_request_hash' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'terminal_at' => null,
        ];
        if ($this->isMemory()) {
            $this->memoryReservations[$reservationId] = $row;
            return $row;
        }
        $this->newReservation()->clear()->setData([
            AssetReservation::schema_fields_RESERVATION_ID => $row['reservation_id'],
            AssetReservation::schema_fields_ACCOUNT_ID => $row['account_id'],
            AssetReservation::schema_fields_CUSTOMER_ID => $row['customer_id'],
            AssetReservation::schema_fields_WEBSITE_ID => $row['website_id'],
            AssetReservation::schema_fields_ASSET_CODE => $row['asset_code'],
            AssetReservation::schema_fields_NAMESPACE => $row['namespace'],
            AssetReservation::schema_fields_RESERVE_EVENT_ID => $row['reserve_event_id'],
            AssetReservation::schema_fields_RESERVE_REQUEST_HASH => $row['reserve_request_hash'],
            AssetReservation::schema_fields_AMOUNT_MINOR => $row['amount_minor'],
            AssetReservation::schema_fields_RETURNED_AMOUNT_MINOR => 0,
            AssetReservation::schema_fields_STATUS => $row['status'],
            AssetReservation::schema_fields_VERSION => $row['version'],
            AssetReservation::schema_fields_CAS_TOKEN => $row['cas_token'],
            AssetReservation::schema_fields_TERMINAL_EVENT_ID => null,
            AssetReservation::schema_fields_TERMINAL_REQUEST_HASH => null,
            AssetReservation::schema_fields_CREATED_AT => $now,
            AssetReservation::schema_fields_UPDATED_AT => $now,
            AssetReservation::schema_fields_TERMINAL_AT => null,
        ])->save();

        return $this->findReservation($reservationId)
            ?? throw new CustomerAssetConflictException(self::ERROR_PERSISTENCE, __('CustomerAsset reservation 写后不可见'));
    }

    /**
     * @param array<string, mixed> $reservation
     * @return array<string, mixed>
     */
    private function transitionReservation(
        array $reservation,
        string $status,
        string $eventId,
        string $requestHash,
    ): array {
        $nextVersion = (int) $reservation['version'] + 1;
        $nextToken = bin2hex(random_bytes(32));
        $now = gmdate('Y-m-d H:i:s');
        if ($this->isMemory()) {
            $current = $this->memoryReservations[$reservation['reservation_id']] ?? null;
            if ($current === null
                || (int) $current['version'] !== (int) $reservation['version']
                || !hash_equals((string) $current['cas_token'], (string) $reservation['cas_token'])
            ) {
                throw new CustomerAssetConflictException(self::ERROR_CAS, __('CustomerAsset reservation CAS 冲突'));
            }
            $current['status'] = $status;
            $current['version'] = $nextVersion;
            $current['cas_token'] = $nextToken;
            $current['terminal_event_id'] = $eventId;
            $current['terminal_request_hash'] = $requestHash;
            $current['updated_at'] = $now;
            $current['terminal_at'] = $now;
            $this->memoryReservations[$reservation['reservation_id']] = $current;
            return $current;
        }

        $this->newReservation()->getQuery(false)
            ->where(AssetReservation::schema_fields_RESERVATION_ID, $reservation['reservation_id'])
            ->where(AssetReservation::schema_fields_VERSION, $reservation['version'])
            ->where(AssetReservation::schema_fields_CAS_TOKEN, $reservation['cas_token'])
            ->where(AssetReservation::schema_fields_STATUS, AssetReservation::STATUS_RESERVED)
            ->update([
                AssetReservation::schema_fields_STATUS => $status,
                AssetReservation::schema_fields_VERSION => $nextVersion,
                AssetReservation::schema_fields_CAS_TOKEN => $nextToken,
                AssetReservation::schema_fields_TERMINAL_EVENT_ID => $eventId,
                AssetReservation::schema_fields_TERMINAL_REQUEST_HASH => $requestHash,
                AssetReservation::schema_fields_UPDATED_AT => $now,
                AssetReservation::schema_fields_TERMINAL_AT => $now,
            ])
            ->fetch();
        $saved = $this->findReservation((string) $reservation['reservation_id']);
        if ($saved === null || !hash_equals($nextToken, (string) $saved['cas_token'])) {
            throw new CustomerAssetConflictException(
                self::ERROR_CAS,
                __('CustomerAsset reservation CAS 冲突'),
                ['reservation_id' => $reservation['reservation_id']],
            );
        }

        return $saved;
    }

    /**
     * @param array<string, mixed> $reservation
     * @return array<string, mixed>
     */
    private function recordReservationReturn(
        array $reservation,
        int $returnedAmountMinor,
    ): array {
        $nextVersion = (int) $reservation['version'] + 1;
        $nextToken = bin2hex(random_bytes(32));
        $now = gmdate('Y-m-d H:i:s');
        if ($this->isMemory()) {
            $current = $this->memoryReservations[$reservation['reservation_id']] ?? null;
            if ($current === null
                || (int) $current['version'] !== (int) $reservation['version']
                || !hash_equals((string) $current['cas_token'], (string) $reservation['cas_token'])
            ) {
                throw new CustomerAssetConflictException(
                    self::ERROR_CAS,
                    __('CustomerAsset reservation CAS 冲突'),
                );
            }
            $current['returned_amount_minor'] = $returnedAmountMinor;
            $current['version'] = $nextVersion;
            $current['cas_token'] = $nextToken;
            $current['updated_at'] = $now;
            $this->memoryReservations[$reservation['reservation_id']] = $current;
            return $current;
        }

        $this->newReservation()->getQuery(false)
            ->where(
                AssetReservation::schema_fields_RESERVATION_ID,
                $reservation['reservation_id'],
            )
            ->where(AssetReservation::schema_fields_VERSION, $reservation['version'])
            ->where(AssetReservation::schema_fields_CAS_TOKEN, $reservation['cas_token'])
            ->where(
                AssetReservation::schema_fields_STATUS,
                AssetReservation::STATUS_COMMITTED,
            )
            ->update([
                AssetReservation::schema_fields_RETURNED_AMOUNT_MINOR => $returnedAmountMinor,
                AssetReservation::schema_fields_VERSION => $nextVersion,
                AssetReservation::schema_fields_CAS_TOKEN => $nextToken,
                AssetReservation::schema_fields_UPDATED_AT => $now,
            ])
            ->fetch();
        $saved = $this->findReservation((string) $reservation['reservation_id']);
        if ($saved === null || !hash_equals($nextToken, (string) $saved['cas_token'])) {
            throw new CustomerAssetConflictException(
                self::ERROR_CAS,
                __('CustomerAsset reservation CAS 冲突'),
                ['reservation_id' => $reservation['reservation_id']],
            );
        }

        return $saved;
    }

    /**
     * @param array<string, mixed> $account
     * @return array<string, mixed>
     */
    private function appendLedger(
        array $account,
        string $eventId,
        string $eventType,
        int $amount,
        string $requestHash,
        ?string $reservationId = null,
    ): array {
        $entryId = 'led_' . substr(hash('sha256', $eventId), 0, 40);
        $row = [
            'entry_id' => $entryId,
            'event_id' => $eventId,
            'account_id' => $account['account_id'],
            'customer_id' => $account['customer_id'],
            'website_id' => $account['website_id'],
            'asset_code' => $account['asset_code'],
            'namespace' => $account['namespace'],
            'event_type' => $eventType,
            'amount_minor' => $amount,
            'reservation_id' => $reservationId,
            'request_hash' => $requestHash,
            'balance_after_available' => $account['available_minor'],
            'balance_after_reserved' => $account['reserved_minor'],
            'account_version' => $account['version'],
            'meta' => [],
            'meta_json' => '{}',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];
        if ($this->isMemory()) {
            if (isset($this->memoryLedger[$eventId])) {
                throw new CustomerAssetConflictException(
                    self::ERROR_DUPLICATE_EVENT,
                    __('CustomerAsset event 已存在：%{1}', [$eventId]),
                );
            }
            $this->memoryLedger[$eventId] = $row;
            $this->memoryLedgerEntry[$entryId] = $eventId;
            return $row;
        }

        $this->newLedger()->clear()->setData([
            AssetLedger::schema_fields_ENTRY_ID => $row['entry_id'],
            AssetLedger::schema_fields_EVENT_ID => $row['event_id'],
            AssetLedger::schema_fields_ACCOUNT_ID => $row['account_id'],
            AssetLedger::schema_fields_CUSTOMER_ID => $row['customer_id'],
            AssetLedger::schema_fields_WEBSITE_ID => $row['website_id'],
            AssetLedger::schema_fields_ASSET_CODE => $row['asset_code'],
            AssetLedger::schema_fields_NAMESPACE => $row['namespace'],
            AssetLedger::schema_fields_EVENT_TYPE => $row['event_type'],
            AssetLedger::schema_fields_AMOUNT_MINOR => $row['amount_minor'],
            AssetLedger::schema_fields_RESERVATION_ID => $row['reservation_id'],
            AssetLedger::schema_fields_REQUEST_HASH => $row['request_hash'],
            AssetLedger::schema_fields_BALANCE_AVAILABLE => $row['balance_after_available'],
            AssetLedger::schema_fields_BALANCE_RESERVED => $row['balance_after_reserved'],
            AssetLedger::schema_fields_ACCOUNT_VERSION => $row['account_version'],
            AssetLedger::schema_fields_META_JSON => '{}',
            AssetLedger::schema_fields_CREATED_AT => $row['created_at'],
        ])->save();

        return $this->findLedgerByEvent($eventId)
            ?? throw new CustomerAssetConflictException(self::ERROR_PERSISTENCE, __('CustomerAsset ledger 写后不可见'));
    }

    /**
     * @param array{customer_id:string,website_id:int,asset_code:string,namespace:string} $identity
     * @return array<string, mixed>|null
     */
    private function findAccountByIdentity(array $identity): ?array
    {
        if ($this->isMemory()) {
            $id = $this->memoryAccountIdentity[$this->identityKey($identity)] ?? null;
            return $id !== null ? ($this->memoryAccounts[$id] ?? null) : null;
        }
        $model = $this->newAccount();
        $model->clear()
            ->where(AssetAccount::schema_fields_CUSTOMER_ID, $identity['customer_id'])
            ->where(AssetAccount::schema_fields_WEBSITE_ID, $identity['website_id'])
            ->where(AssetAccount::schema_fields_ASSET_CODE, $identity['asset_code'])
            ->where(AssetAccount::schema_fields_NAMESPACE, $identity['namespace'])
            ->find()
            ->fetch();

        return $model->getId() ? $this->normalizeAccount($model->getData()) : null;
    }

    /** @return array<string, mixed>|null */
    private function findAccountById(string $accountId): ?array
    {
        if ($this->isMemory()) {
            return $this->memoryAccounts[$accountId] ?? null;
        }
        $model = $this->newAccount();
        $model->clear()
            ->where(AssetAccount::schema_fields_ACCOUNT_ID, $accountId)
            ->find()
            ->fetch();

        return $model->getId() ? $this->normalizeAccount($model->getData()) : null;
    }

    /** @return array<string, mixed>|null */
    private function findLedgerByEvent(string $eventId): ?array
    {
        if ($this->isMemory()) {
            return $this->memoryLedger[$eventId] ?? null;
        }
        $model = $this->newLedger();
        $model->clear()
            ->where(AssetLedger::schema_fields_EVENT_ID, $eventId)
            ->find()
            ->fetch();

        return $model->getId() ? $this->normalizeLedger($model->getData()) : null;
    }

    /** @return array<string, mixed>|null */
    private function findReservation(string $reservationId): ?array
    {
        if ($this->isMemory()) {
            return $this->memoryReservations[$reservationId] ?? null;
        }
        $model = $this->newReservation();
        $model->clear()
            ->where(AssetReservation::schema_fields_RESERVATION_ID, $reservationId)
            ->find()
            ->fetch();

        return $model->getId() ? $this->normalizeReservation($model->getData()) : null;
    }

    /**
     * @param array<string, mixed> $request
     * @return array{customer_id:string,website_id:int,asset_code:string,namespace:string}
     */
    private function normalizeIdentity(array $request): array
    {
        $customerId = trim((string) ($request['customer_id'] ?? ''));
        $websiteId = (int) ($request['website_id'] ?? -1);
        $assetCode = strtolower(trim((string) ($request['asset_code'] ?? '')));
        $namespace = $this->normalizeNamespace(
            (string) ($request['namespace'] ?? AssetAccount::NS_LIVE),
        );
        if ($customerId === '' || strlen($customerId) > 64) {
            throw new \InvalidArgumentException(__('CustomerAsset customer_id 非法'));
        }
        if ($websiteId < 0) {
            throw new \InvalidArgumentException(__('CustomerAsset website_id 不能为负数：%{1}', [$websiteId]));
        }
        if ($assetCode === '' || strlen($assetCode) > 64) {
            throw new \InvalidArgumentException(__('CustomerAsset asset_code 非法'));
        }

        return [
            'customer_id' => $customerId,
            'website_id' => $websiteId,
            'asset_code' => $assetCode,
            'namespace' => $namespace,
        ];
    }

    private function normalizeNamespace(string $namespace): string
    {
        $namespace = strtolower(trim($namespace));
        if (!in_array($namespace, [AssetAccount::NS_LIVE, AssetAccount::NS_SANDBOX], true)) {
            throw new CustomerAssetConflictException(
                self::ERROR_NAMESPACE,
                __('CustomerAsset namespace 非法：%{1}', [$namespace]),
                ['namespace' => $namespace],
            );
        }

        return $namespace;
    }

    private function positiveAmount(int $amount): int
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException(__('CustomerAsset amount_minor 必须大于 0'));
        }

        return $amount;
    }

    private function requireEventId(string $eventId): string
    {
        $eventId = trim($eventId);
        if ($eventId === '' || strlen($eventId) > 128) {
            throw new \InvalidArgumentException(__('CustomerAsset event_id 非法'));
        }

        return $eventId;
    }

    private function requireReservationId(string $reservationId): string
    {
        $reservationId = trim($reservationId);
        if ($reservationId === '' || strlen($reservationId) > 64) {
            throw new \InvalidArgumentException(__('CustomerAsset reservation_id 非法'));
        }

        return $reservationId;
    }

    /**
     * @param array{customer_id:string,website_id:int,asset_code:string,namespace:string} $identity
     */
    private function requestHash(
        string $eventType,
        array $identity,
        int $amount,
        ?string $reservationId = null,
    ): string {
        return hash('sha256', json_encode([
            'event_type' => $eventType,
            'customer_id' => $identity['customer_id'],
            'website_id' => $identity['website_id'],
            'asset_code' => $identity['asset_code'],
            'namespace' => $identity['namespace'],
            'amount_minor' => $amount,
            'reservation_id' => $reservationId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array{customer_id:string,website_id:int,asset_code:string,namespace:string} $identity
     */
    private function identityKey(array $identity): string
    {
        return implode('|', [
            $identity['namespace'],
            (string) $identity['website_id'],
            $identity['customer_id'],
            $identity['asset_code'],
        ]);
    }

    private function assertMutable(int $websiteId): void
    {
        $mode = $this->rollout()->mode(self::CAPABILITY);
        if (!in_array($mode, [
            CommerceRolloutGateInterface::MODE_ALLOWLIST,
            CommerceRolloutGateInterface::MODE_ON,
        ], true)) {
            throw new CustomerAssetConflictException(
                self::ERROR_MODE_OFF,
                __('CustomerAsset mode off/shadow，禁止新资产 tender'),
                ['capability' => self::CAPABILITY, 'website_id' => $websiteId],
            );
        }
        $this->rollout()->assertMutable(self::CAPABILITY, 'website:' . $websiteId);
    }

    private function isMemory(): bool
    {
        return $this->memoryAccounts !== null;
    }

    /** @template T @param callable(): T $callback @return T */
    private function runMemoryAtomically(callable $callback): mixed
    {
        $snapshot = [
            $this->memoryAccounts,
            $this->memoryAccountIdentity,
            $this->memoryLedger,
            $this->memoryLedgerEntry,
            $this->memoryReservations,
        ];
        try {
            return $callback();
        } catch (Throwable $exception) {
            [
                $this->memoryAccounts,
                $this->memoryAccountIdentity,
                $this->memoryLedger,
                $this->memoryLedgerEntry,
                $this->memoryReservations,
            ] = $snapshot;
            throw $exception;
        }
    }

    /** @template T @param callable(): T $callback @return T */
    private function runDurable(callable $callback): mixed
    {
        return $this->transactionRunner()->run(
            $this->newAccount()->getConnection(),
            $callback,
        );
    }

    private function transactionRunner(): DatabaseTransactionRunnerInterface
    {
        $runner = $this->transactions
            ?? ObjectManager::getInstance(DatabaseTransactionRunnerInterface::class);
        if (!$runner instanceof DatabaseTransactionRunnerInterface) {
            throw new \LogicException('DatabaseTransactionRunnerInterface is unavailable');
        }

        return $runner;
    }

    private function newAccount(): AssetAccount
    {
        return $this->accountFactory !== null
            ? ($this->accountFactory)()
            : ObjectManager::create(AssetAccount::class, [], false);
    }

    private function newLedger(): AssetLedger
    {
        return $this->ledgerFactory !== null
            ? ($this->ledgerFactory)()
            : ObjectManager::create(AssetLedger::class, [], false);
    }

    private function newReservation(): AssetReservation
    {
        return $this->reservationFactory !== null
            ? ($this->reservationFactory)()
            : ObjectManager::create(AssetReservation::class, [], false);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalizeAccount(array $row): array
    {
        $row['website_id'] = (int) ($row['website_id'] ?? 0);
        $row['available_minor'] = (int) ($row['available_minor'] ?? 0);
        $row['reserved_minor'] = (int) ($row['reserved_minor'] ?? 0);
        $row['reservable_minor'] = $row['available_minor'] - $row['reserved_minor'];
        $row['version'] = (int) ($row['version'] ?? 0);
        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalizeLedger(array $row): array
    {
        $row['website_id'] = (int) ($row['website_id'] ?? 0);
        $row['amount_minor'] = (int) ($row['amount_minor'] ?? 0);
        $row['balance_after_available'] = (int) ($row['balance_after_available'] ?? 0);
        $row['balance_after_reserved'] = (int) ($row['balance_after_reserved'] ?? 0);
        $row['account_version'] = (int) ($row['account_version'] ?? 0);
        $decoded = json_decode((string) ($row['meta_json'] ?? '{}'), true);
        $row['meta'] = is_array($decoded) ? $decoded : [];
        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalizeReservation(array $row): array
    {
        $row['website_id'] = (int) ($row['website_id'] ?? 0);
        $row['amount_minor'] = (int) ($row['amount_minor'] ?? 0);
        $row['returned_amount_minor'] = (int) ($row['returned_amount_minor'] ?? 0);
        $row['version'] = (int) ($row['version'] ?? 0);
        return $row;
    }

    private function throwPersistence(Throwable $exception): never
    {
        if ($exception instanceof CustomerAssetConflictException
            || $exception instanceof \InvalidArgumentException
        ) {
            throw $exception;
        }
        throw new CustomerAssetConflictException(
            self::ERROR_PERSISTENCE,
            __('CustomerAsset 持久化失败'),
            [],
            0,
            $exception,
        );
    }
}
