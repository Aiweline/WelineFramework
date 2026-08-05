<?php

declare(strict_types=1);

namespace Weline\Inventory\Service;

use Throwable;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Api\Data\AvailabilityResult;
use Weline\Inventory\Api\Data\ReservationResult;
use Weline\Inventory\Api\InventoryCapabilityInterface;
use Weline\Inventory\Api\InventoryRefundCapabilityInterface;
use Weline\Inventory\Api\InventoryReservationCommitCapabilityInterface;
use Weline\Inventory\Model\InventoryLedger;
use Weline\Inventory\Model\InventoryStock;
use Weline\Inventory\Model\Reservation;

/**
 * Store logical inventory: availability + basic reserve/release + immutable ledger.
 *
 * P2B-001/002：策略、ledger、reserve/release/commit/expire；lease 编排见 ReservationService。
 */
final class InventoryService implements
    InventoryCapabilityInterface,
    InventoryRefundCapabilityInterface,
    InventoryReservationCommitCapabilityInterface
{
    private readonly InventoryAvailabilityCalculator $calculator;

    /** @var (\Closure(): InventoryStock)|null */
    private readonly mixed $stockFactory;
    /** @var (\Closure(): InventoryLedger)|null */
    private readonly mixed $ledgerFactory;
    /** @var (\Closure(): Reservation)|null */
    private readonly mixed $reservationFactory;
    private readonly ?ConnectionFactory $connectionFactory;
    private readonly ?DatabaseTransactionRunnerInterface $transactions;

    /**
     * In-memory book for unit tests: key => stock row array + reservations + ledger.
     *
     * @var array<string, array{
     *   stock: array<string,mixed>,
     *   reservations: array<string, array<string,mixed>>,
     *   by_idem: array<string, string>,
     *   ledger: list<array<string,mixed>>
     * }>|null
     */
    private ?array $memory = null;

    /**
     * @param (\Closure(): InventoryStock)|null $stockFactory
     * @param (\Closure(): InventoryLedger)|null $ledgerFactory
     * @param (\Closure(): Reservation)|null $reservationFactory
     */
    public function __construct(
        ?InventoryAvailabilityCalculator $calculator = null,
        ?callable $stockFactory = null,
        ?callable $ledgerFactory = null,
        ?callable $reservationFactory = null,
        bool $useMemory = false,
        ?ConnectionFactory $connectionFactory = null,
        ?DatabaseTransactionRunnerInterface $transactions = null,
    ) {
        $this->calculator = $calculator ?? new InventoryAvailabilityCalculator();
        $this->stockFactory = $stockFactory;
        $this->ledgerFactory = $ledgerFactory;
        $this->reservationFactory = $reservationFactory;
        $this->connectionFactory = $connectionFactory;
        $this->transactions = $transactions;
        if ($useMemory) {
            $this->memory = [];
        }
    }

    public static function forTesting(): self
    {
        return new self(useMemory: true);
    }

    /**
     * Execute a group of inventory commands as one atomic unit.
     *
     * Memory mode restores the complete test book on failure. Durable mode
     * joins the framework transaction context for the configured connection.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transactional(callable $callback): mixed
    {
        return $this->runAtomically($callback);
    }

    public function ensureStock(
        int $websiteId,
        int $storeId,
        int $offerId,
        string $strategy = self::STRATEGY_STRICT,
        int $onHandMinor = 0,
        int $oversellAllowance = 0,
        int $preorderAllowance = 0,
    ): void {
        $this->assertScope($websiteId, $storeId, $offerId);
        [$strategy, $oversellAllowance, $preorderAllowance] = $this->assertStockConfiguration(
            $strategy,
            $onHandMinor,
            $oversellAllowance,
            $preorderAllowance,
        );
        if ($this->memory !== null) {
            $key = $this->stockKey($websiteId, $storeId, $offerId);
            if (!isset($this->memory[$key])) {
                $this->memory[$key] = [
                    'stock' => [
                        'website_id' => $websiteId,
                        'store_id' => $storeId,
                        'offer_id' => $offerId,
                        'strategy' => $strategy,
                        'on_hand_minor' => $onHandMinor,
                        'reserved_minor' => 0,
                        'oversell_allowance' => $oversellAllowance,
                        'preorder_allowance' => $preorderAllowance,
                        'stock_version' => 0,
                    ],
                    'reservations' => [],
                    'by_idem' => [],
                    'ledger' => [],
                ];
            }
            return;
        }

        $stock = $this->loadStockModel($websiteId, $storeId, $offerId);
        if ($stock->getId()) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        try {
            $stock->clear()->setData([
                InventoryStock::schema_fields_WEBSITE_ID => $websiteId,
                InventoryStock::schema_fields_STORE_ID => $storeId,
                InventoryStock::schema_fields_OFFER_ID => $offerId,
                InventoryStock::schema_fields_STRATEGY => $strategy,
                InventoryStock::schema_fields_ON_HAND_MINOR => $onHandMinor,
                InventoryStock::schema_fields_RESERVED_MINOR => 0,
                InventoryStock::schema_fields_OVERSELL_ALLOWANCE => $oversellAllowance,
                InventoryStock::schema_fields_PREORDER_ALLOWANCE => $preorderAllowance,
                InventoryStock::schema_fields_STOCK_VERSION => 0,
                InventoryStock::schema_fields_CREATED_AT => $now,
                InventoryStock::schema_fields_UPDATED_AT => $now,
            ])->save();
        } catch (Throwable $exception) {
            if (!$this->loadStockModel($websiteId, $storeId, $offerId)->getId()) {
                throw $exception;
            }
        }
    }

    public function setOnHand(
        int $websiteId,
        int $storeId,
        int $offerId,
        int $onHandMinor,
        string $idempotencyKey,
        string $requestHash,
        ?string $strategy = null,
        ?int $oversellAllowance = null,
        ?int $preorderAllowance = null,
    ): AvailabilityResult {
        $this->assertScope($websiteId, $storeId, $offerId);
        if ($onHandMinor < 0) {
            throw new \InvalidArgumentException(__('on_hand 不能为负'));
        }
        [$idempotencyKey, $requestHash] = $this->normalizeCommandIdentity(
            $idempotencyKey,
            $requestHash,
        );
        if ($strategy !== null) {
            $strategy = $this->calculator->assertValidStrategy($strategy);
        }
        if (($oversellAllowance !== null && $oversellAllowance < 0)
            || ($preorderAllowance !== null && $preorderAllowance < 0)
        ) {
            throw new \InvalidArgumentException(__('allowance 不能为负'));
        }

        $existing = $this->findLedgerEventByKey($idempotencyKey, InventoryLedger::TYPE_STOCK_SET);
        if ($existing !== null) {
            $this->assertLedgerReplay(
                $existing,
                $websiteId,
                $storeId,
                $offerId,
                InventoryLedger::TYPE_STOCK_SET,
                $requestHash,
            );
            return $this->getAvailability($websiteId, $storeId, $offerId);
        }

        try {
            return $this->runAtomically(function () use (
                $websiteId,
                $storeId,
                $offerId,
                $onHandMinor,
                $idempotencyKey,
                $requestHash,
                $strategy,
                $oversellAllowance,
                $preorderAllowance,
            ): AvailabilityResult {
                $existing = $this->findLedgerEventByKey(
                    $idempotencyKey,
                    InventoryLedger::TYPE_STOCK_SET,
                );
                if ($existing !== null) {
                    $this->assertLedgerReplay(
                        $existing,
                        $websiteId,
                        $storeId,
                        $offerId,
                        InventoryLedger::TYPE_STOCK_SET,
                        $requestHash,
                    );
                    return $this->getAvailability($websiteId, $storeId, $offerId);
                }

                $this->ensureStock($websiteId, $storeId, $offerId);
                for ($attempt = 0; $attempt < 8; $attempt++) {
                    $row = $this->stockSnapshot($websiteId, $storeId, $offerId);
                    $nextStrategy = $strategy ?? (string)$row['strategy'];
                    $nextOversell = $oversellAllowance ?? (int)$row['oversell_allowance'];
                    $nextPreorder = $preorderAllowance ?? (int)$row['preorder_allowance'];
                    $this->assertStockConfiguration(
                        $nextStrategy,
                        $onHandMinor,
                        $nextOversell,
                        $nextPreorder,
                    );
                    $delta = $onHandMinor - (int)$row['on_hand_minor'];
                    $updated = $this->mutateStock(
                        $websiteId,
                        $storeId,
                        $offerId,
                        (int)$row['stock_version'],
                        static function (array $stock) use (
                            $onHandMinor,
                            $nextStrategy,
                            $nextOversell,
                            $nextPreorder,
                        ): array {
                            $stock['on_hand_minor'] = $onHandMinor;
                            $stock['strategy'] = $nextStrategy;
                            $stock['oversell_allowance'] = $nextOversell;
                            $stock['preorder_allowance'] = $nextPreorder;
                            $stock['stock_version'] = (int)$stock['stock_version'] + 1;
                            return $stock;
                        },
                    );
                    if (!$updated) {
                        continue;
                    }
                    if (!$this->appendLedger(
                        $websiteId,
                        $storeId,
                        $offerId,
                        InventoryLedger::TYPE_STOCK_SET,
                        $delta,
                        null,
                        $idempotencyKey,
                        $requestHash,
                        $nextStrategy,
                        $nextOversell,
                        $nextPreorder,
                    )) {
                        throw new InventoryConflictException(
                            'inventory_ledger_replay_race',
                            __('Ledger 重放竞争需要回滚'),
                            ['idempotency_key' => $idempotencyKey],
                        );
                    }
                    return $this->getAvailability($websiteId, $storeId, $offerId);
                }
                throw new InventoryConflictException(
                    'inventory_stock_cas_exhausted',
                    __('库存设置 CAS 重试耗尽'),
                    ['website_id' => $websiteId, 'store_id' => $storeId, 'offer_id' => $offerId],
                );
            });
        } catch (Throwable $exception) {
            $replayed = $this->findLedgerEventByKey(
                $idempotencyKey,
                InventoryLedger::TYPE_STOCK_SET,
            );
            if ($replayed === null) {
                throw $exception;
            }
            $this->assertLedgerReplay(
                $replayed,
                $websiteId,
                $storeId,
                $offerId,
                InventoryLedger::TYPE_STOCK_SET,
                $requestHash,
            );
            return $this->getAvailability($websiteId, $storeId, $offerId);
        }
    }

    public function getAvailability(int $websiteId, int $storeId, int $offerId): AvailabilityResult
    {
        $this->ensureStock($websiteId, $storeId, $offerId);
        $row = $this->stockSnapshot($websiteId, $storeId, $offerId);
        $available = $this->calculator->availableMinor(
            (string)$row['strategy'],
            (int)$row['on_hand_minor'],
            (int)$row['reserved_minor'],
            (int)$row['oversell_allowance'],
            (int)$row['preorder_allowance'],
        );
        return new AvailabilityResult(
            websiteId: $websiteId,
            storeId: $storeId,
            offerId: $offerId,
            strategy: (string)$row['strategy'],
            onHandMinor: (int)$row['on_hand_minor'],
            reservedMinor: (int)$row['reserved_minor'],
            availableMinor: $available === PHP_INT_MAX ? PHP_INT_MAX : $available,
            sellable: $available > 0,
            stockVersion: (int)$row['stock_version'],
        );
    }

    public function reserve(
        int $websiteId,
        int $storeId,
        int $offerId,
        int $quantityMinor,
        string $idempotencyKey,
        string $requestHash,
        array $initialLease = [],
    ): ReservationResult {
        $this->assertScope($websiteId, $storeId, $offerId);
        $this->calculator->assertPositiveMinor($quantityMinor);
        [$idempotencyKey, $requestHash] = $this->normalizeCommandIdentity(
            $idempotencyKey,
            $requestHash,
        );
        $initialLease = $this->normalizeInitialLease($initialLease);

        $existing = $this->findReservationByIdempotency($idempotencyKey);
        if ($existing !== null) {
            $this->assertInitialLeaseReplay($existing, $initialLease);
            return $this->reservationReplayResult(
                $existing,
                $websiteId,
                $storeId,
                $offerId,
                $quantityMinor,
                $idempotencyKey,
                $requestHash,
            );
        }

        try {
            return $this->runAtomically(function () use (
                $websiteId,
                $storeId,
                $offerId,
                $quantityMinor,
                $idempotencyKey,
                $requestHash,
                $initialLease,
            ): ReservationResult {
                $existing = $this->findReservationByIdempotency($idempotencyKey);
                if ($existing !== null) {
                    $this->assertInitialLeaseReplay($existing, $initialLease);
                    return $this->reservationReplayResult(
                        $existing,
                        $websiteId,
                        $storeId,
                        $offerId,
                        $quantityMinor,
                        $idempotencyKey,
                        $requestHash,
                    );
                }

                $this->ensureStock($websiteId, $storeId, $offerId);

                for ($attempt = 0; $attempt < 8; $attempt++) {
                    $row = $this->stockSnapshot($websiteId, $storeId, $offerId);
                    if (!$this->calculator->canReserve(
                        (string)$row['strategy'],
                        (int)$row['on_hand_minor'],
                        (int)$row['reserved_minor'],
                        $quantityMinor,
                        (int)$row['oversell_allowance'],
                        (int)$row['preorder_allowance'],
                    )) {
                        throw new InventoryConflictException(
                            'inventory_insufficient',
                            __('可售库存不足：offer=%{1} need=%{2}', [$offerId, $quantityMinor]),
                            [
                                'website_id' => $websiteId,
                                'store_id' => $storeId,
                                'offer_id' => $offerId,
                                'quantity_minor' => $quantityMinor,
                                'available_minor' => $this->calculator->availableMinor(
                                    (string)$row['strategy'],
                                    (int)$row['on_hand_minor'],
                                    (int)$row['reserved_minor'],
                                    (int)$row['oversell_allowance'],
                                    (int)$row['preorder_allowance'],
                                ),
                            ],
                        );
                    }
                    if ($quantityMinor > PHP_INT_MAX - (int)$row['reserved_minor']) {
                        throw new InventoryConflictException(
                            'inventory_quantity_overflow',
                            __('库存预占 minor 累加溢出'),
                            ['offer_id' => $offerId, 'quantity_minor' => $quantityMinor],
                        );
                    }

                    $version = (int)$row['stock_version'];
                    $uuid = $this->newUuid();
                    $ok = $this->mutateStock(
                        $websiteId,
                        $storeId,
                        $offerId,
                        $version,
                        static function (array $stock) use ($quantityMinor): array {
                            $stock['reserved_minor'] = (int)$stock['reserved_minor'] + $quantityMinor;
                            $stock['stock_version'] = (int)$stock['stock_version'] + 1;
                            return $stock;
                        },
                    );
                    if (!$ok) {
                        continue;
                    }

                    if (!$this->appendLedger(
                        $websiteId,
                        $storeId,
                        $offerId,
                        InventoryLedger::TYPE_RESERVE,
                        $quantityMinor,
                        $uuid,
                        $idempotencyKey,
                        $requestHash,
                        (string)$row['strategy'],
                        (int)$row['oversell_allowance'],
                        (int)$row['preorder_allowance'],
                    )) {
                        throw new InventoryConflictException(
                            'inventory_ledger_replay_race',
                            __('Ledger 重放竞争需要回滚'),
                            ['idempotency_key' => $idempotencyKey],
                        );
                    }
                    $this->insertReservation(array_merge([
                        'reservation_uuid' => $uuid,
                        'website_id' => $websiteId,
                        'store_id' => $storeId,
                        'offer_id' => $offerId,
                        'quantity_minor' => $quantityMinor,
                        'state' => Reservation::STATE_RESERVED,
                        'idempotency_key' => $idempotencyKey,
                        'request_hash' => $requestHash,
                        'lease_version' => 0,
                        'queued_order' => 0,
                    ], $initialLease));

                    return new ReservationResult(
                        reservationUuid: $uuid,
                        state: Reservation::STATE_RESERVED,
                        quantityMinor: $quantityMinor,
                        idempotencyKey: $idempotencyKey,
                        requestHash: $requestHash,
                    );
                }

                throw new InventoryConflictException(
                    'inventory_reserve_cas_exhausted',
                    __('库存预占 CAS 重试耗尽'),
                    ['offer_id' => $offerId],
                );
            });
        } catch (Throwable $exception) {
            $replayed = $this->findReservationByIdempotency($idempotencyKey);
            if ($replayed === null) {
                throw $exception;
            }
            $this->assertInitialLeaseReplay($replayed, $initialLease);
            return $this->reservationReplayResult(
                $replayed,
                $websiteId,
                $storeId,
                $offerId,
                $quantityMinor,
                $idempotencyKey,
                $requestHash,
            );
        }
    }

    public function release(string $reservationUuid): void
    {
        $this->transitionReleaseOrExpire($reservationUuid, Reservation::STATE_RELEASED, InventoryLedger::TYPE_RELEASE);
    }

    /**
     * Commit reserved stock：on_hand 与 reserved 同时扣减；幂等。
     */
    public function commit(string $reservationUuid, string $idempotencyKey, string $requestHash): void
    {
        $reservationUuid = $this->normalizeReservationUuid($reservationUuid);
        [$idempotencyKey, $requestHash] = $this->normalizeCommandIdentity(
            $idempotencyKey,
            $requestHash,
        );
        $row = $this->findReservationByUuid($reservationUuid);
        if ($row === null) {
            throw new \InvalidArgumentException(__('Reservation 不存在：%{1}', [$reservationUuid]));
        }

        $existingCommit = $this->findLedgerEventByKey(
            $idempotencyKey,
            InventoryLedger::TYPE_COMMIT,
        );
        if ($existingCommit !== null) {
            $this->assertTransitionReplay(
                $existingCommit,
                $row,
                InventoryLedger::TYPE_COMMIT,
                $requestHash,
            );
            if ((string)$row['state'] !== Reservation::STATE_COMMITTED) {
                throw new InventoryConflictException(
                    'inventory_transition_invariant_violation',
                    __('Commit ledger 已存在但 Reservation 状态不一致'),
                    ['reservation_uuid' => $reservationUuid, 'state' => $row['state']],
                );
            }
            return;
        }

        try {
            $this->runAtomically(function () use (
                $reservationUuid,
                $idempotencyKey,
                $requestHash,
            ): void {
                $row = $this->findReservationByUuid($reservationUuid);
                if ($row === null) {
                    throw new \InvalidArgumentException(__('Reservation 不存在：%{1}', [$reservationUuid]));
                }
                $existing = $this->findLedgerEventByKey(
                    $idempotencyKey,
                    InventoryLedger::TYPE_COMMIT,
                );
                if ($existing !== null) {
                    $this->assertTransitionReplay(
                        $existing,
                        $row,
                        InventoryLedger::TYPE_COMMIT,
                        $requestHash,
                    );
                    if ((string)$row['state'] !== Reservation::STATE_COMMITTED) {
                        throw new InventoryConflictException(
                            'inventory_transition_invariant_violation',
                            __('Commit ledger 已存在但 Reservation 状态不一致'),
                            ['reservation_uuid' => $reservationUuid, 'state' => $row['state']],
                        );
                    }
                    return;
                }
                if ((string)$row['state'] === Reservation::STATE_COMMITTED) {
                    throw new InventoryConflictException(
                        'inventory_transition_invariant_violation',
                        __('Reservation 已 committed 但缺少 commit ledger'),
                        ['reservation_uuid' => $reservationUuid],
                    );
                }
                if ((string)$row['state'] !== Reservation::STATE_RESERVED) {
                    throw new InventoryConflictException(
                        'inventory_commit_invalid_state',
                        __('只能 commit reserved 状态：%{1}', [$row['state']]),
                        ['reservation_uuid' => $reservationUuid, 'state' => $row['state']],
                    );
                }

                $websiteId = (int)$row['website_id'];
                $storeId = (int)$row['store_id'];
                $offerId = (int)$row['offer_id'];
                $qty = (int)$row['quantity_minor'];
                if ($qty <= 0) {
                    throw new InventoryConflictException(
                        'inventory_reservation_quantity_invariant',
                        __('Reservation quantity_minor 必须为正'),
                        ['reservation_uuid' => $reservationUuid],
                    );
                }

                for ($attempt = 0; $attempt < 8; $attempt++) {
                    $stock = $this->stockSnapshot($websiteId, $storeId, $offerId);
                    if ((int)$stock['reserved_minor'] < $qty) {
                        throw new InventoryConflictException(
                            'inventory_reserved_projection_invariant',
                            __('reserved_minor 小于待 commit 数量'),
                            ['reservation_uuid' => $reservationUuid],
                        );
                    }
                    $ok = $this->mutateStock(
                        $websiteId,
                        $storeId,
                        $offerId,
                        (int)$stock['stock_version'],
                        static function (array $current) use ($qty): array {
                            $current['on_hand_minor'] = max(
                                0,
                                (int)$current['on_hand_minor'] - $qty,
                            );
                            $current['reserved_minor'] = (int)$current['reserved_minor'] - $qty;
                            $current['stock_version'] = (int)$current['stock_version'] + 1;
                            return $current;
                        },
                    );
                    if (!$ok) {
                        continue;
                    }
                    if (!$this->appendLedger(
                        $websiteId,
                        $storeId,
                        $offerId,
                        InventoryLedger::TYPE_COMMIT,
                        -$qty,
                        $reservationUuid,
                        $idempotencyKey,
                        $requestHash,
                    )) {
                        throw new InventoryConflictException(
                            'inventory_ledger_replay_race',
                            __('Commit ledger 重放竞争需要回滚'),
                            ['idempotency_key' => $idempotencyKey],
                        );
                    }
                    if (!$this->updateReservationState(
                        $reservationUuid,
                        Reservation::STATE_COMMITTED,
                        Reservation::STATE_RESERVED,
                    )) {
                        throw new InventoryConflictException(
                            'inventory_reservation_state_conflict',
                            __('Commit Reservation 状态 CAS 失败'),
                            ['reservation_uuid' => $reservationUuid],
                        );
                    }
                    return;
                }
                throw new InventoryConflictException(
                    'inventory_commit_cas_exhausted',
                    __('库存 commit CAS 重试耗尽'),
                    ['reservation_uuid' => $reservationUuid],
                );
            });
        } catch (Throwable $exception) {
            $replayed = $this->findLedgerEventByKey(
                $idempotencyKey,
                InventoryLedger::TYPE_COMMIT,
            );
            $current = $this->findReservationByUuid($reservationUuid);
            if ($replayed === null || $current === null
                || (string)$current['state'] !== Reservation::STATE_COMMITTED
            ) {
                throw $exception;
            }
            $this->assertTransitionReplay(
                $replayed,
                $current,
                InventoryLedger::TYPE_COMMIT,
                $requestHash,
            );
        }
    }

    /**
     * Return an already committed, unshipped quantity to on-hand stock.
     *
     * The immutable (idempotency_key, refund_return) ledger fence makes retries
     * safe. The stock CAS and ledger append share one transaction.
     */
    public function returnCommitted(
        int $websiteId,
        int $storeId,
        int $offerId,
        int $quantityMinor,
        string $idempotencyKey,
        string $requestHash,
    ): void {
        $this->assertScope($websiteId, $storeId, $offerId);
        if ($quantityMinor <= 0) {
            throw new \InvalidArgumentException(__('退款回库数量必须为正数'));
        }
        [$idempotencyKey, $requestHash] = $this->normalizeCommandIdentity(
            $idempotencyKey,
            $requestHash,
        );
        $existing = $this->findLedgerEventByKey(
            $idempotencyKey,
            InventoryLedger::TYPE_REFUND_RETURN,
        );
        if ($existing !== null) {
            $this->assertLedgerReplay(
                $existing,
                $websiteId,
                $storeId,
                $offerId,
                InventoryLedger::TYPE_REFUND_RETURN,
                $requestHash,
            );
            if ((int)($existing['qty_delta_minor'] ?? 0) !== $quantityMinor) {
                throw new InventoryConflictException(
                    'inventory_refund_return_quantity_conflict',
                    __('退款回库重放数量与原请求不一致'),
                    ['idempotency_key' => $idempotencyKey],
                );
            }
            return;
        }

        try {
            $this->runAtomically(function () use (
                $websiteId,
                $storeId,
                $offerId,
                $quantityMinor,
                $idempotencyKey,
                $requestHash,
            ): void {
                $existing = $this->findLedgerEventByKey(
                    $idempotencyKey,
                    InventoryLedger::TYPE_REFUND_RETURN,
                );
                if ($existing !== null) {
                    $this->assertLedgerReplay(
                        $existing,
                        $websiteId,
                        $storeId,
                        $offerId,
                        InventoryLedger::TYPE_REFUND_RETURN,
                        $requestHash,
                    );
                    if ((int)($existing['qty_delta_minor'] ?? 0) !== $quantityMinor) {
                        throw new InventoryConflictException(
                            'inventory_refund_return_quantity_conflict',
                            __('退款回库重放数量与原请求不一致'),
                            ['idempotency_key' => $idempotencyKey],
                        );
                    }
                    return;
                }

                $this->ensureStock($websiteId, $storeId, $offerId);
                for ($attempt = 0; $attempt < 8; $attempt++) {
                    $stock = $this->stockSnapshot($websiteId, $storeId, $offerId);
                    $updated = $this->mutateStock(
                        $websiteId,
                        $storeId,
                        $offerId,
                        (int)$stock['stock_version'],
                        static function (array $current) use ($quantityMinor): array {
                            $current['on_hand_minor'] = (int)$current['on_hand_minor'] + $quantityMinor;
                            $current['stock_version'] = (int)$current['stock_version'] + 1;
                            return $current;
                        },
                    );
                    if (!$updated) {
                        continue;
                    }
                    if (!$this->appendLedger(
                        $websiteId,
                        $storeId,
                        $offerId,
                        InventoryLedger::TYPE_REFUND_RETURN,
                        $quantityMinor,
                        null,
                        $idempotencyKey,
                        $requestHash,
                    )) {
                        throw new InventoryConflictException(
                            'inventory_ledger_replay_race',
                            __('退款回库 ledger 重放竞争需要回滚'),
                            ['idempotency_key' => $idempotencyKey],
                        );
                    }
                    return;
                }
                throw new InventoryConflictException(
                    'inventory_refund_return_cas_exhausted',
                    __('退款回库库存 CAS 重试耗尽'),
                    ['website_id' => $websiteId, 'store_id' => $storeId, 'offer_id' => $offerId],
                );
            });
        } catch (Throwable $exception) {
            $replayed = $this->findLedgerEventByKey(
                $idempotencyKey,
                InventoryLedger::TYPE_REFUND_RETURN,
            );
            if ($replayed === null) {
                throw $exception;
            }
            $this->assertLedgerReplay(
                $replayed,
                $websiteId,
                $storeId,
                $offerId,
                InventoryLedger::TYPE_REFUND_RETURN,
                $requestHash,
            );
            if ((int)($replayed['qty_delta_minor'] ?? 0) !== $quantityMinor) {
                throw $exception;
            }
        }
    }

    public function expire(
        string $reservationUuid,
        ?int $expectedLeaseVersion = null,
        ?string $leaseExpiresAtMax = null,
    ): bool {
        return $this->transitionReleaseOrExpire(
            $reservationUuid,
            Reservation::STATE_EXPIRED,
            InventoryLedger::TYPE_EXPIRE,
            $expectedLeaseVersion,
            $leaseExpiresAtMax,
        );
    }

    /** @return array<string, mixed>|null */
    public function getReservation(string $reservationUuid): ?array
    {
        return $this->findReservationByUuid(trim($reservationUuid));
    }

    /** @return array<string, mixed>|null */
    public function getReservationByIdempotencyKey(string $key): ?array
    {
        return $this->findReservationByIdempotency(trim($key));
    }

    /**
     * @param array<string, mixed> $fields
     * @return bool false when lease_version CAS lost
     */
    public function patchReservation(
        string $uuid,
        array $fields,
        ?int $expectedLeaseVersion = null,
        ?string $expectedState = null,
    ): bool {
        $uuid = $this->normalizeReservationUuid($uuid);
        $map = [
            'state' => Reservation::schema_fields_STATE,
            'lease_owner_attempt_code' => Reservation::schema_fields_LEASE_OWNER_ATTEMPT_CODE,
            'lease_started_at' => Reservation::schema_fields_LEASE_STARTED_AT,
            'queued_order' => Reservation::schema_fields_QUEUED_ORDER,
            'lease_version' => Reservation::schema_fields_LEASE_VERSION,
            'lease_expires_at' => Reservation::schema_fields_LEASE_EXPIRES_AT,
            'lease_max_expires_at' => Reservation::schema_fields_LEASE_MAX_EXPIRES_AT,
        ];
        $unknown = array_values(array_diff(array_keys($fields), array_keys($map)));
        if ($fields === [] || $unknown !== []) {
            throw new \InvalidArgumentException(__(
                'Reservation patch 字段为空或不受支持：%{1}',
                [implode(',', $unknown)],
            ));
        }
        if ($this->memory !== null) {
            foreach ($this->memory as &$bucket) {
                if (!isset($bucket['reservations'][$uuid])) {
                    continue;
                }
                $row = &$bucket['reservations'][$uuid];
                if ($expectedLeaseVersion !== null
                    && (int)($row['lease_version'] ?? 0) !== $expectedLeaseVersion
                ) {
                    return false;
                }
                if ($expectedState !== null && (string)$row['state'] !== $expectedState) {
                    return false;
                }
                foreach ($fields as $k => $v) {
                    $row[$k] = $v;
                }
                return true;
            }
            return false;
        }

        $model = $this->newReservation();
        $model->clear()
            ->where(Reservation::schema_fields_RESERVATION_UUID, $uuid)
            ->find()
            ->fetch();
        if (!$model->getId()) {
            return false;
        }
        if ($expectedLeaseVersion !== null
            && (int)$model->getData(Reservation::schema_fields_LEASE_VERSION) !== $expectedLeaseVersion
        ) {
            return false;
        }
        if ($expectedState !== null
            && (string)$model->getData(Reservation::schema_fields_STATE) !== $expectedState
        ) {
            return false;
        }
        $update = [Reservation::schema_fields_UPDATED_AT => date('Y-m-d H:i:s')];
        foreach ($map as $k => $col) {
            if (array_key_exists($k, $fields)) {
                $update[$col] = $fields[$k];
            }
        }
        $q = $model->clear()->where(Reservation::schema_fields_RESERVATION_UUID, $uuid);
        if ($expectedLeaseVersion !== null) {
            $q->where(Reservation::schema_fields_LEASE_VERSION, $expectedLeaseVersion);
        }
        if ($expectedState !== null) {
            $q->where(Reservation::schema_fields_STATE, $expectedState);
        }
        $q->update($update)->fetch();
        $updated = $q->getQueryData();
        if ($updated === false || $updated === null || $updated === 0) {
            return false;
        }
        $re = $this->findReservationByUuid($uuid);
        if ($re === null) {
            return false;
        }
        foreach ($fields as $key => $expected) {
            if (!$this->reservationFieldMatches($key, $expected, $re[$key] ?? null)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listExpiredReservations(string $nowSqlDatetime, int $limit = 500): array
    {
        $nowSqlDatetime = $this->normalizeSqlDatetimeUtc($nowSqlDatetime);
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException(__('expiry batch limit 必须在 1..1000'));
        }
        if ($this->memory !== null) {
            $out = [];
            foreach ($this->memory as $bucket) {
                foreach ($bucket['reservations'] as $row) {
                    if ((string)$row['state'] !== Reservation::STATE_RESERVED) {
                        continue;
                    }
                    $exp = (string)($row['lease_expires_at'] ?? '');
                    if ($exp !== '' && $exp <= $nowSqlDatetime) {
                        $out[] = $row;
                    }
                }
            }
            usort($out, static function (array $left, array $right): int {
                $byExpiry = strcmp(
                    (string)($left['lease_expires_at'] ?? ''),
                    (string)($right['lease_expires_at'] ?? ''),
                );
                return $byExpiry !== 0
                    ? $byExpiry
                    : strcmp(
                        (string)($left['reservation_uuid'] ?? ''),
                        (string)($right['reservation_uuid'] ?? ''),
                    );
            });
            return array_slice($out, 0, $limit);
        }
        $model = $this->newReservation();
        $rows = $model->clear()
            ->where(Reservation::schema_fields_STATE, Reservation::STATE_RESERVED)
            ->where(Reservation::schema_fields_LEASE_EXPIRES_AT, $nowSqlDatetime, '<=')
            ->order(Reservation::schema_fields_LEASE_EXPIRES_AT, 'ASC')
            ->order(Reservation::schema_fields_ID, 'ASC')
            ->limit($limit)
            ->select()
            ->fetchArray();
        return is_array($rows) ? $rows : [];
    }

    /** @return list<array<string, mixed>> */
    public function listLedgerEvents(int $websiteId, int $storeId, int $offerId): array
    {
        if ($this->memory !== null) {
            $key = $this->stockKey($websiteId, $storeId, $offerId);
            return $this->memory[$key]['ledger'] ?? [];
        }
        $model = $this->newLedger();
        $rows = $model->clear()
            ->where(InventoryLedger::schema_fields_WEBSITE_ID, $websiteId)
            ->where(InventoryLedger::schema_fields_STORE_ID, $storeId)
            ->where(InventoryLedger::schema_fields_OFFER_ID, $offerId)
            ->order(InventoryLedger::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();
        return is_array($rows) ? $rows : [];
    }

    private function transitionReleaseOrExpire(
        string $reservationUuid,
        string $toState,
        string $ledgerType,
        ?int $expectedLeaseVersion = null,
        ?string $leaseExpiresAtMax = null,
    ): bool {
        $reservationUuid = $this->normalizeReservationUuid($reservationUuid);
        if ($leaseExpiresAtMax !== null) {
            $leaseExpiresAtMax = $this->normalizeSqlDatetimeUtc($leaseExpiresAtMax);
        }
        $key = strtolower($toState) . ':' . $reservationUuid;
        $hash = hash('sha256', $key);

        try {
            return $this->runAtomically(function () use (
                $reservationUuid,
                $toState,
                $ledgerType,
                $expectedLeaseVersion,
                $leaseExpiresAtMax,
                $key,
                $hash,
            ): bool {
                $row = $this->findReservationByUuid($reservationUuid);
                if ($row === null) {
                    throw new \InvalidArgumentException(__('Reservation 不存在：%{1}', [$reservationUuid]));
                }
                if ((string)$row['state'] !== Reservation::STATE_RESERVED) {
                    if ((string)$row['state'] === $toState) {
                        $existing = $this->findLedgerEventByKey($key, $ledgerType);
                        if ($existing === null) {
                            throw new InventoryConflictException(
                                'inventory_transition_invariant_violation',
                                __('Reservation 终态缺少对应 immutable ledger'),
                                ['reservation_uuid' => $reservationUuid, 'state' => $toState],
                            );
                        }
                        $this->assertTransitionReplay($existing, $row, $ledgerType, $hash);
                    }
                    return false;
                }
                if ($expectedLeaseVersion !== null
                    && (int)($row['lease_version'] ?? 0) !== $expectedLeaseVersion
                ) {
                    return false;
                }
                if ($leaseExpiresAtMax !== null
                    && ((string)($row['lease_expires_at'] ?? '') === ''
                        || (string)$row['lease_expires_at'] > $leaseExpiresAtMax)
                ) {
                    return false;
                }

                $websiteId = (int)$row['website_id'];
                $storeId = (int)$row['store_id'];
                $offerId = (int)$row['offer_id'];
                $qty = (int)$row['quantity_minor'];
                if ($qty <= 0) {
                    throw new InventoryConflictException(
                        'inventory_reservation_quantity_invariant',
                        __('Reservation quantity_minor 必须为正'),
                        ['reservation_uuid' => $reservationUuid],
                    );
                }

                for ($attempt = 0; $attempt < 8; $attempt++) {
                    $stock = $this->stockSnapshot($websiteId, $storeId, $offerId);
                    if ((int)$stock['reserved_minor'] < $qty) {
                        throw new InventoryConflictException(
                            'inventory_reserved_projection_invariant',
                            __('reserved_minor 小于待释放数量'),
                            ['reservation_uuid' => $reservationUuid],
                        );
                    }
                    $ok = $this->mutateStock(
                        $websiteId,
                        $storeId,
                        $offerId,
                        (int)$stock['stock_version'],
                        static function (array $current) use ($qty): array {
                            $current['reserved_minor'] = (int)$current['reserved_minor'] - $qty;
                            $current['stock_version'] = (int)$current['stock_version'] + 1;
                            return $current;
                        },
                    );
                    if (!$ok) {
                        continue;
                    }
                    if (!$this->appendLedger(
                        $websiteId,
                        $storeId,
                        $offerId,
                        $ledgerType,
                        -$qty,
                        $reservationUuid,
                        $key,
                        $hash,
                    )) {
                        throw new InventoryConflictException(
                            'inventory_ledger_replay_race',
                            __('释放/过期 ledger 重放竞争需要回滚'),
                            ['reservation_uuid' => $reservationUuid],
                        );
                    }
                    if (!$this->updateReservationState(
                        $reservationUuid,
                        $toState,
                        Reservation::STATE_RESERVED,
                        $expectedLeaseVersion,
                        $leaseExpiresAtMax,
                    )) {
                        throw new InventoryConflictException(
                            'inventory_reservation_state_conflict',
                            __('释放/过期 Reservation 状态 CAS 失败'),
                            ['reservation_uuid' => $reservationUuid],
                        );
                    }
                    return true;
                }
                throw new InventoryConflictException(
                    'inventory_release_cas_exhausted',
                    __('库存释放/过期 CAS 重试耗尽'),
                    ['reservation_uuid' => $reservationUuid],
                );
            });
        } catch (Throwable $exception) {
            $current = $this->findReservationByUuid($reservationUuid);
            if ($current !== null && (string)$current['state'] === $toState) {
                $existing = $this->findLedgerEventByKey($key, $ledgerType);
                if ($existing === null) {
                    throw $exception;
                }
                $this->assertTransitionReplay($existing, $current, $ledgerType, $hash);
                return false;
            }
            if ($current !== null && ((string)$current['state'] !== Reservation::STATE_RESERVED
                || ($expectedLeaseVersion !== null
                    && (int)($current['lease_version'] ?? 0) !== $expectedLeaseVersion)
                || ($leaseExpiresAtMax !== null
                    && (string)($current['lease_expires_at'] ?? '') > $leaseExpiresAtMax)
            )) {
                return false;
            }
            throw $exception;
        }
    }

    private function assertScope(int $websiteId, int $storeId, int $offerId): void
    {
        if ($websiteId < 0 || $storeId < 0 || $offerId <= 0) {
            throw new \InvalidArgumentException(__(
                'website_id/store_id 须 >=0 且 offer_id>0：%{1}/%{2}/%{3}',
                [$websiteId, $storeId, $offerId],
            ));
        }
    }

    private function stockKey(int $websiteId, int $storeId, int $offerId): string
    {
        return $websiteId . ':' . $storeId . ':' . $offerId;
    }

    /** @return array<string, mixed> */
    private function stockSnapshot(int $websiteId, int $storeId, int $offerId): array
    {
        if ($this->memory !== null) {
            $key = $this->stockKey($websiteId, $storeId, $offerId);
            return $this->memory[$key]['stock'];
        }
        $stock = $this->loadStockModel($websiteId, $storeId, $offerId);
        if (!$stock->getId()) {
            throw new \RuntimeException(__('库存行不存在'));
        }
        return $stock->getData();
    }

    /**
     * @param callable(array<string,mixed>): array<string,mixed> $mutator
     */
    private function mutateStock(int $websiteId, int $storeId, int $offerId, int $expectedVersion, callable $mutator): bool
    {
        if ($this->memory !== null) {
            $key = $this->stockKey($websiteId, $storeId, $offerId);
            $current = $this->memory[$key]['stock'];
            if ((int)$current['stock_version'] !== $expectedVersion) {
                return false;
            }
            $this->memory[$key]['stock'] = $mutator($current);
            return true;
        }

        $stock = $this->loadStockModel($websiteId, $storeId, $offerId);
        $stockId = (int)$stock->getId();
        if ($stockId <= 0 || (int)$stock->getData(InventoryStock::schema_fields_STOCK_VERSION) !== $expectedVersion) {
            return false;
        }
        $data = $stock->getData();
        $next = $mutator($data);
        $stock->clear()
            ->where(InventoryStock::schema_fields_ID, $stockId)
            ->where(InventoryStock::schema_fields_STOCK_VERSION, $expectedVersion)
            ->update([
                InventoryStock::schema_fields_ON_HAND_MINOR => $next['on_hand_minor'],
                InventoryStock::schema_fields_RESERVED_MINOR => $next['reserved_minor'],
                InventoryStock::schema_fields_STRATEGY => $next['strategy'],
                InventoryStock::schema_fields_OVERSELL_ALLOWANCE => $next['oversell_allowance'],
                InventoryStock::schema_fields_PREORDER_ALLOWANCE => $next['preorder_allowance'],
                InventoryStock::schema_fields_STOCK_VERSION => $next['stock_version'],
                InventoryStock::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
            ])
            ->fetch();
        $updated = $stock->getQueryData();
        if ($updated === false || $updated === null || $updated === 0) {
            return false;
        }
        $reloaded = $this->loadStockModel($websiteId, $storeId, $offerId);
        return (int)$reloaded->getData(InventoryStock::schema_fields_STOCK_VERSION) === (int)$next['stock_version']
            && (int)$reloaded->getData(InventoryStock::schema_fields_ON_HAND_MINOR) === (int)$next['on_hand_minor']
            && (int)$reloaded->getData(InventoryStock::schema_fields_RESERVED_MINOR) === (int)$next['reserved_minor']
            && (string)$reloaded->getData(InventoryStock::schema_fields_STRATEGY) === (string)$next['strategy'];
    }

    private function appendLedger(
        int $websiteId,
        int $storeId,
        int $offerId,
        string $eventType,
        int $qtyDelta,
        ?string $reservationUuid,
        string $idempotencyKey,
        string $requestHash,
        ?string $strategy = null,
        ?int $oversellAllowance = null,
        ?int $preorderAllowance = null,
    ): bool {
        $stock = $this->stockSnapshot($websiteId, $storeId, $offerId);
        $strategy ??= (string)$stock['strategy'];
        $oversellAllowance ??= (int)$stock['oversell_allowance'];
        $preorderAllowance ??= (int)$stock['preorder_allowance'];
        $this->assertStockConfiguration(
            $strategy,
            (int)$stock['on_hand_minor'],
            $oversellAllowance,
            $preorderAllowance,
        );
        $existing = $this->findLedgerEventByKey($idempotencyKey, $eventType);
        if ($existing !== null) {
            $this->assertLedgerReplay(
                $existing,
                $websiteId,
                $storeId,
                $offerId,
                $eventType,
                $requestHash,
            );
            return false;
        }

        if ($this->memory !== null) {
            $key = $this->stockKey($websiteId, $storeId, $offerId);
            $this->memory[$key]['ledger'][] = [
                'event_uuid' => $this->newUuid(),
                'event_type' => $eventType,
                'website_id' => $websiteId,
                'store_id' => $storeId,
                'offer_id' => $offerId,
                'qty_delta_minor' => $qtyDelta,
                'strategy' => $strategy,
                'oversell_allowance' => $oversellAllowance,
                'preorder_allowance' => $preorderAllowance,
                'reservation_uuid' => $reservationUuid,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
            ];
            return true;
        }

        try {
            $ledger = $this->newLedger();
            $ledger->clear()->setData([
                InventoryLedger::schema_fields_EVENT_UUID => $this->newUuid(),
                InventoryLedger::schema_fields_EVENT_TYPE => $eventType,
                InventoryLedger::schema_fields_WEBSITE_ID => $websiteId,
                InventoryLedger::schema_fields_STORE_ID => $storeId,
                InventoryLedger::schema_fields_OFFER_ID => $offerId,
                InventoryLedger::schema_fields_QTY_DELTA_MINOR => $qtyDelta,
                InventoryLedger::schema_fields_STRATEGY => $strategy,
                InventoryLedger::schema_fields_OVERSELL_ALLOWANCE => $oversellAllowance,
                InventoryLedger::schema_fields_PREORDER_ALLOWANCE => $preorderAllowance,
                InventoryLedger::schema_fields_RESERVATION_UUID => $reservationUuid,
                InventoryLedger::schema_fields_IDEMPOTENCY_KEY => $idempotencyKey,
                InventoryLedger::schema_fields_REQUEST_HASH => $requestHash,
                InventoryLedger::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
            ])->save();
        } catch (\Throwable $e) {
            throw new InventoryConflictException(
                'inventory_ledger_unique_conflict',
                __('Ledger 唯一约束冲突：%{1}', [$e->getMessage()]),
                ['idempotency_key' => $idempotencyKey, 'event_type' => $eventType],
                $e,
            );
        }
        return true;
    }

    /** @return array{0:string,1:string} */
    private function normalizeCommandIdentity(string $idempotencyKey, string $requestHash): array
    {
        $idempotencyKey = trim($idempotencyKey);
        $requestHash = trim($requestHash);
        if ($idempotencyKey === '' || $requestHash === '') {
            throw new \InvalidArgumentException(__('idempotency_key/request_hash 不能为空'));
        }
        if (strlen($idempotencyKey) > 128 || strlen($requestHash) > 64) {
            throw new \InvalidArgumentException(__('idempotency_key/request_hash 超出 128/64 字符限制'));
        }
        return [$idempotencyKey, $requestHash];
    }

    private function normalizeReservationUuid(string $reservationUuid): string
    {
        $reservationUuid = trim($reservationUuid);
        if ($reservationUuid === '' || strlen($reservationUuid) > 36) {
            throw new \InvalidArgumentException(__('reservation_uuid 不能为空且最多 36 字符'));
        }
        return $reservationUuid;
    }

    /** @param array<string, mixed> $fields */
    private function normalizeInitialLease(array $fields): array
    {
        if ($fields === []) {
            return [];
        }
        $required = [
            'lease_owner_attempt_code',
            'lease_started_at',
            'queued_order',
            'lease_version',
            'lease_expires_at',
            'lease_max_expires_at',
        ];
        $keys = array_keys($fields);
        sort($keys);
        $expectedKeys = $required;
        sort($expectedKeys);
        if ($keys !== $expectedKeys) {
            throw new \InvalidArgumentException(__('Initial lease 字段必须完整且不得扩展'));
        }
        $owner = trim((string)$fields['lease_owner_attempt_code']);
        if ($owner === '' || strlen($owner) > 64) {
            throw new \InvalidArgumentException(__('lease_owner_attempt_code 不能为空且最多 64 字符'));
        }
        $version = (int)$fields['lease_version'];
        $queued = (int)$fields['queued_order'];
        if ($version !== 1 || !in_array($queued, [0, 1], true)) {
            throw new \InvalidArgumentException(__('Initial lease version/queued_order 无效'));
        }
        $started = $this->normalizeSqlDatetimeUtc((string)$fields['lease_started_at']);
        $expires = $this->normalizeSqlDatetimeUtc((string)$fields['lease_expires_at']);
        $max = $this->normalizeSqlDatetimeUtc((string)$fields['lease_max_expires_at']);
        $utc = new \DateTimeZone('UTC');
        $startedAt = new \DateTimeImmutable($started, $utc);
        $expiresAt = new \DateTimeImmutable($expires, $utc);
        $maxAt = new \DateTimeImmutable($max, $utc);
        if ($maxAt != $startedAt->modify('+2 hours')
            || $expiresAt <= $startedAt
            || $expiresAt > $maxAt
        ) {
            throw new \InvalidArgumentException(__('Initial lease 时间不满足 2h 硬上限'));
        }
        return [
            'lease_owner_attempt_code' => $owner,
            'lease_started_at' => $started,
            'queued_order' => $queued,
            'lease_version' => $version,
            'lease_expires_at' => $expires,
            'lease_max_expires_at' => $max,
        ];
    }

    /** @param array<string, mixed> $existing */
    private function assertInitialLeaseReplay(array $existing, array $initialLease): void
    {
        if ($initialLease === []) {
            return;
        }
        if ((int)($existing['lease_version'] ?? 0) < 1
            || (string)($existing['lease_owner_attempt_code'] ?? '')
                !== (string)$initialLease['lease_owner_attempt_code']
            || (int)($existing['queued_order'] ?? 0) !== (int)$initialLease['queued_order']
        ) {
            throw new InventoryConflictException(
                'inventory_lease_payload_conflict',
                __('Reservation 重放 lease owner/queued 负载冲突'),
                ['reservation_uuid' => (string)($existing['reservation_uuid'] ?? '')],
            );
        }
    }

    private function normalizeSqlDatetimeUtc(string $value): string
    {
        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $value,
            new \DateTimeZone('UTC'),
        );
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || $date->format('Y-m-d H:i:s') !== $value
            || (is_array($errors)
                && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))
        ) {
            throw new \InvalidArgumentException(__('UTC datetime 格式无效：%{1}', [$value]));
        }
        return $value;
    }

    private function reservationFieldMatches(string $key, mixed $expected, mixed $actual): bool
    {
        if (in_array($key, ['queued_order', 'lease_version'], true)) {
            return (int)$actual === (int)$expected;
        }
        if ($expected === null) {
            return $actual === null || $actual === '';
        }
        return (string)$actual === (string)$expected;
    }

    /** @return array{0:string,1:int,2:int} */
    private function assertStockConfiguration(
        string $strategy,
        int $onHandMinor,
        int $oversellAllowance,
        int $preorderAllowance,
    ): array {
        $strategy = $this->calculator->assertValidStrategy($strategy);
        if ($onHandMinor < 0) {
            throw new \InvalidArgumentException(__('on_hand 不能为负'));
        }
        if ($oversellAllowance < 0 || $preorderAllowance < 0) {
            throw new \InvalidArgumentException(__('allowance 不能为负'));
        }
        $this->calculator->availableMinor(
            $strategy,
            $onHandMinor,
            0,
            $oversellAllowance,
            $preorderAllowance,
        );
        return [$strategy, $oversellAllowance, $preorderAllowance];
    }

    /** @param array<string, mixed> $existing */
    private function assertLedgerReplay(
        array $existing,
        int $websiteId,
        int $storeId,
        int $offerId,
        string $eventType,
        string $requestHash,
    ): void {
        if ((int)($existing['website_id'] ?? -1) !== $websiteId
            || (int)($existing['store_id'] ?? -1) !== $storeId
            || (int)($existing['offer_id'] ?? -1) !== $offerId
            || (string)($existing['event_type'] ?? '') !== $eventType
            || (string)($existing['request_hash'] ?? '') !== $requestHash
        ) {
            throw new InventoryConflictException(
                'inventory_ledger_replay_conflict',
                __('Ledger idempotency payload 冲突'),
                ['idempotency_key' => $existing['idempotency_key'] ?? ''],
            );
        }
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $reservation
     */
    private function assertTransitionReplay(
        array $existing,
        array $reservation,
        string $eventType,
        string $requestHash,
    ): void {
        if ((string)($existing['request_hash'] ?? '') !== $requestHash) {
            throw new InventoryConflictException(
                'inventory_request_hash_conflict',
                __('库存 transition idempotency hash 冲突'),
                ['idempotency_key' => (string)($existing['idempotency_key'] ?? '')],
            );
        }
        $this->assertLedgerReplay(
            $existing,
            (int)$reservation['website_id'],
            (int)$reservation['store_id'],
            (int)$reservation['offer_id'],
            $eventType,
            $requestHash,
        );
        if ((string)($existing['reservation_uuid'] ?? '')
                !== (string)$reservation['reservation_uuid']
            || (int)($existing['qty_delta_minor'] ?? 0)
                !== -(int)$reservation['quantity_minor']
        ) {
            throw new InventoryConflictException(
                'inventory_request_payload_conflict',
                __('库存 transition 重放 reservation/quantity 负载冲突'),
                ['reservation_uuid' => (string)$reservation['reservation_uuid']],
            );
        }
    }

    /** @param array<string, mixed> $existing */
    private function reservationReplayResult(
        array $existing,
        int $websiteId,
        int $storeId,
        int $offerId,
        int $quantityMinor,
        string $idempotencyKey,
        string $requestHash,
    ): ReservationResult {
        if ((string)$existing['request_hash'] !== $requestHash) {
            throw new InventoryConflictException(
                'inventory_request_hash_conflict',
                __('库存预占 idempotency hash 冲突：key=%{1}', [$idempotencyKey]),
                ['idempotency_key' => $idempotencyKey],
            );
        }
        if ((int)$existing['website_id'] !== $websiteId
            || (int)$existing['store_id'] !== $storeId
            || (int)$existing['offer_id'] !== $offerId
            || (int)$existing['quantity_minor'] !== $quantityMinor
        ) {
            throw new InventoryConflictException(
                'inventory_request_payload_conflict',
                __('库存预占 idempotency payload 冲突：key=%{1}', [$idempotencyKey]),
                ['idempotency_key' => $idempotencyKey],
            );
        }
        return new ReservationResult(
            reservationUuid: (string)$existing['reservation_uuid'],
            state: (string)$existing['state'],
            quantityMinor: (int)$existing['quantity_minor'],
            idempotencyKey: $idempotencyKey,
            requestHash: $requestHash,
            replayed: true,
        );
    }

    /** @return array<string, mixed>|null */
    private function findLedgerEventByKey(string $idempotencyKey, string $eventType): ?array
    {
        if ($this->memory !== null) {
            foreach ($this->memory as $bucket) {
                foreach ($bucket['ledger'] as $event) {
                    if ((string)$event['idempotency_key'] === $idempotencyKey
                        && (string)$event['event_type'] === $eventType
                    ) {
                        return $event;
                    }
                }
            }
            return null;
        }
        $model = $this->newLedger();
        $model->clear()
            ->where(InventoryLedger::schema_fields_IDEMPOTENCY_KEY, $idempotencyKey)
            ->where(InventoryLedger::schema_fields_EVENT_TYPE, $eventType)
            ->find()
            ->fetch();
        return $model->getId() ? $model->getData() : null;
    }

    private function runAtomically(callable $callback): mixed
    {
        if ($this->memory !== null) {
            $snapshot = $this->memory;
            try {
                return $callback();
            } catch (Throwable $exception) {
                $this->memory = $snapshot;
                throw $exception;
            }
        }
        $connection = $this->connectionFactory ?? ConnectionFactory::getInstance();
        $transactions = $this->transactions
            ?? ObjectManager::getInstance(DatabaseTransactionRunnerInterface::class);
        return $transactions->run($connection, $callback);
    }

    /** @param array<string, mixed> $data */
    private function insertReservation(array $data): void
    {
        if ($this->memory !== null) {
            $key = $this->stockKey((int)$data['website_id'], (int)$data['store_id'], (int)$data['offer_id']);
            $uuid = (string)$data['reservation_uuid'];
            $this->memory[$key]['reservations'][$uuid] = $data;
            $this->memory[$key]['by_idem'][(string)$data['idempotency_key']] = $uuid;
            return;
        }
        $model = $this->newReservation();
        $now = date('Y-m-d H:i:s');
        $model->clear()->setData([
            Reservation::schema_fields_RESERVATION_UUID => $data['reservation_uuid'],
            Reservation::schema_fields_WEBSITE_ID => $data['website_id'],
            Reservation::schema_fields_STORE_ID => $data['store_id'],
            Reservation::schema_fields_OFFER_ID => $data['offer_id'],
            Reservation::schema_fields_QUANTITY_MINOR => $data['quantity_minor'],
            Reservation::schema_fields_STATE => $data['state'],
            Reservation::schema_fields_IDEMPOTENCY_KEY => $data['idempotency_key'],
            Reservation::schema_fields_REQUEST_HASH => $data['request_hash'],
            Reservation::schema_fields_LEASE_OWNER_ATTEMPT_CODE => $data['lease_owner_attempt_code'] ?? null,
            Reservation::schema_fields_LEASE_STARTED_AT => $data['lease_started_at'] ?? null,
            Reservation::schema_fields_QUEUED_ORDER => $data['queued_order'] ?? 0,
            Reservation::schema_fields_LEASE_VERSION => $data['lease_version'] ?? 0,
            Reservation::schema_fields_LEASE_EXPIRES_AT => $data['lease_expires_at'] ?? null,
            Reservation::schema_fields_LEASE_MAX_EXPIRES_AT => $data['lease_max_expires_at'] ?? null,
            Reservation::schema_fields_CREATED_AT => $now,
            Reservation::schema_fields_UPDATED_AT => $now,
        ])->save();
    }

    private function updateReservationState(
        string $uuid,
        string $state,
        ?string $expectedState = null,
        ?int $expectedLeaseVersion = null,
        ?string $leaseExpiresAtMax = null,
    ): bool {
        if ($this->memory !== null) {
            foreach ($this->memory as &$bucket) {
                if (isset($bucket['reservations'][$uuid])) {
                    $row = &$bucket['reservations'][$uuid];
                    if ($expectedState !== null && (string)$row['state'] !== $expectedState) {
                        return false;
                    }
                    if ($expectedLeaseVersion !== null
                        && (int)($row['lease_version'] ?? 0) !== $expectedLeaseVersion
                    ) {
                        return false;
                    }
                    if ($leaseExpiresAtMax !== null
                        && ((string)($row['lease_expires_at'] ?? '') === ''
                            || (string)$row['lease_expires_at'] > $leaseExpiresAtMax)
                    ) {
                        return false;
                    }
                    $bucket['reservations'][$uuid]['state'] = $state;
                    return true;
                }
            }
            return false;
        }
        $model = $this->newReservation();
        $query = $model->clear()
            ->where(Reservation::schema_fields_RESERVATION_UUID, $uuid);
        if ($expectedState !== null) {
            $query->where(Reservation::schema_fields_STATE, $expectedState);
        }
        if ($expectedLeaseVersion !== null) {
            $query->where(Reservation::schema_fields_LEASE_VERSION, $expectedLeaseVersion);
        }
        if ($leaseExpiresAtMax !== null) {
            $query->where(
                Reservation::schema_fields_LEASE_EXPIRES_AT,
                $leaseExpiresAtMax,
                '<=',
            );
        }
        $query->update([
                Reservation::schema_fields_STATE => $state,
                Reservation::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
            ])
            ->fetch();
        $updated = $query->getQueryData();
        if ($updated === false || $updated === null || $updated === 0) {
            return false;
        }
        $reloaded = $this->findReservationByUuid($uuid);
        return $reloaded !== null && (string)$reloaded['state'] === $state;
    }

    /** @return array<string, mixed>|null */
    private function findReservationByIdempotency(string $key): ?array
    {
        if ($this->memory !== null) {
            foreach ($this->memory as $bucket) {
                $uuid = $bucket['by_idem'][$key] ?? null;
                if ($uuid !== null) {
                    return $bucket['reservations'][$uuid] ?? null;
                }
            }
            return null;
        }
        $model = $this->newReservation();
        $model->clear()
            ->where(Reservation::schema_fields_IDEMPOTENCY_KEY, $key)
            ->find()
            ->fetch();
        return $model->getId() ? $model->getData() : null;
    }

    /** @return array<string, mixed>|null */
    private function findReservationByUuid(string $uuid): ?array
    {
        if ($this->memory !== null) {
            foreach ($this->memory as $bucket) {
                if (isset($bucket['reservations'][$uuid])) {
                    return $bucket['reservations'][$uuid];
                }
            }
            return null;
        }
        $model = $this->newReservation();
        $model->clear()
            ->where(Reservation::schema_fields_RESERVATION_UUID, $uuid)
            ->find()
            ->fetch();
        return $model->getId() ? $model->getData() : null;
    }

    private function loadStockModel(int $websiteId, int $storeId, int $offerId): InventoryStock
    {
        $stock = $this->newStock();
        $stock->clear()
            ->where(InventoryStock::schema_fields_WEBSITE_ID, $websiteId)
            ->where(InventoryStock::schema_fields_STORE_ID, $storeId)
            ->where(InventoryStock::schema_fields_OFFER_ID, $offerId)
            ->find()
            ->fetch();
        return $stock;
    }

    private function newStock(): InventoryStock
    {
        if ($this->stockFactory !== null) {
            return ($this->stockFactory)();
        }
        return ObjectManager::create(InventoryStock::class, [], false);
    }

    private function newLedger(): InventoryLedger
    {
        if ($this->ledgerFactory !== null) {
            return ($this->ledgerFactory)();
        }
        return ObjectManager::create(InventoryLedger::class, [], false);
    }

    private function newReservation(): Reservation
    {
        if ($this->reservationFactory !== null) {
            return ($this->reservationFactory)();
        }
        return ObjectManager::create(Reservation::class, [], false);
    }

    private function newUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
