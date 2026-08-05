<?php

declare(strict_types=1);

namespace Weline\Inventory\Service;

use Throwable;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Api\Data\ReservationResult;
use Weline\Inventory\Api\WarehouseInventoryCapabilityInterface;
use Weline\Inventory\Model\InventoryLedger;
use Weline\Inventory\Model\Reservation;
use Weline\Inventory\Model\WarehouseQuota;

/**
 * Durable Warehouse reservation mapping and original-Warehouse stock return.
 */
final class WarehouseInventoryService implements WarehouseInventoryCapabilityInterface
{
    public const ERROR_BLOCKED_AUTHORIZATION = 'BLOCKED_AUTHORIZATION';
    public const ERROR_RESERVATION_NOT_FOUND = 'inventory_warehouse_reservation_not_found';
    public const ERROR_RESERVATION_SCOPE = 'inventory_warehouse_reservation_scope_conflict';
    public const ERROR_RESERVATION_CONFLICT = 'inventory_warehouse_reservation_conflict';
    public const ERROR_QUOTA_NOT_FOUND = 'inventory_warehouse_quota_not_found';
    public const ERROR_QUOTA_CAS = 'inventory_warehouse_quota_cas_exhausted';
    public const ERROR_REPLAY = 'inventory_warehouse_idempotency_conflict';

    /** @var (\Closure(): Reservation)|null */
    private readonly ?\Closure $reservationFactory;
    /** @var (\Closure(): WarehouseQuota)|null */
    private readonly ?\Closure $quotaFactory;
    /** @var (\Closure(): InventoryLedger)|null */
    private readonly ?\Closure $ledgerFactory;

    /**
     * @param (callable(): Reservation)|null $reservationFactory
     * @param (callable(): WarehouseQuota)|null $quotaFactory
     * @param (callable(): InventoryLedger)|null $ledgerFactory
     */
    public function __construct(
        private readonly ?WarehouseAuthorizationService $authorizations = null,
        private readonly ?DatabaseTransactionRunnerInterface $transactions = null,
        ?callable $reservationFactory = null,
        ?callable $quotaFactory = null,
        ?callable $ledgerFactory = null,
    ) {
        $this->reservationFactory = $reservationFactory !== null
            ? \Closure::fromCallable($reservationFactory)
            : null;
        $this->quotaFactory = $quotaFactory !== null
            ? \Closure::fromCallable($quotaFactory)
            : null;
        $this->ledgerFactory = $ledgerFactory !== null
            ? \Closure::fromCallable($ledgerFactory)
            : null;
    }

    public function assignReservationWarehouse(
        string $reservationUuid,
        int $websiteId,
        int $storeId,
        int $warehouseId,
        string $idempotencyKey,
        string $requestHash,
    ): ReservationResult {
        $reservationUuid = $this->normalizeUuid($reservationUuid);
        [$idempotencyKey, $requestHash] = $this->normalizeIdentity(
            $idempotencyKey,
            $requestHash,
        );
        $replayed = $this->findLedger(
            $idempotencyKey,
            InventoryLedger::TYPE_WAREHOUSE_ASSIGN,
        );
        if ($replayed !== null) {
            $this->assertReplay(
                $replayed,
                $websiteId,
                $storeId,
                $warehouseId,
                (int) ($replayed[InventoryLedger::schema_fields_OFFER_ID] ?? 0),
                0,
                $reservationUuid,
                $requestHash,
            );

            return $this->reservationResult(
                $this->loadReservation($reservationUuid),
                $idempotencyKey,
                $requestHash,
                true,
                $warehouseId,
            );
        }
        $this->assertAuthorized($websiteId, $storeId, $warehouseId);

        $connection = $this->newReservation()->getConnection();
        try {
            return $this->transactionRunner()->run(
                $connection,
                function () use (
                    $reservationUuid,
                    $websiteId,
                    $storeId,
                    $warehouseId,
                    $idempotencyKey,
                    $requestHash,
                ): ReservationResult {
                    $reservation = $this->loadReservationForUpdate($reservationUuid);
                    if (!$reservation->getId()) {
                        throw new InventoryConflictException(
                            self::ERROR_RESERVATION_NOT_FOUND,
                            __('库存 Reservation 不存在'),
                        );
                    }
                    if ((int) $reservation->getData(Reservation::schema_fields_WEBSITE_ID)
                            !== $websiteId
                        || (int) $reservation->getData(Reservation::schema_fields_STORE_ID)
                            !== $storeId
                    ) {
                        throw new InventoryConflictException(
                            self::ERROR_RESERVATION_SCOPE,
                            __('Reservation 与 Warehouse Scope 不一致'),
                        );
                    }
                    $currentWarehouse = (int) $reservation->getData(
                        Reservation::schema_fields_WAREHOUSE_ID,
                    );
                    if ($currentWarehouse > 0 && $currentWarehouse !== $warehouseId) {
                        throw new InventoryConflictException(
                            self::ERROR_RESERVATION_CONFLICT,
                            __('Reservation 已绑定不同 Warehouse'),
                        );
                    }
                    $offerId = (int) $reservation->getData(
                        Reservation::schema_fields_OFFER_ID,
                    );
                    if ($currentWarehouse === 0) {
                        $reservation->setData(
                            Reservation::schema_fields_WAREHOUSE_ID,
                            $warehouseId,
                        )->setData(
                            Reservation::schema_fields_UPDATED_AT,
                            date('Y-m-d H:i:s'),
                        )->save();
                    }
                    $this->appendLedger(
                        $websiteId,
                        $storeId,
                        $warehouseId,
                        $offerId,
                        InventoryLedger::TYPE_WAREHOUSE_ASSIGN,
                        0,
                        $reservationUuid,
                        $idempotencyKey,
                        $requestHash,
                    );

                    return $this->reservationResult(
                        $reservation,
                        $idempotencyKey,
                        $requestHash,
                        false,
                        $warehouseId,
                    );
                },
            );
        } catch (Throwable $exception) {
            $ledger = $this->findLedger(
                $idempotencyKey,
                InventoryLedger::TYPE_WAREHOUSE_ASSIGN,
            );
            if ($ledger === null) {
                throw $exception;
            }
            $this->assertReplay(
                $ledger,
                $websiteId,
                $storeId,
                $warehouseId,
                (int) ($ledger[InventoryLedger::schema_fields_OFFER_ID] ?? 0),
                0,
                $reservationUuid,
                $requestHash,
            );

            return $this->reservationResult(
                $this->loadReservation($reservationUuid),
                $idempotencyKey,
                $requestHash,
                true,
                $warehouseId,
            );
        }
    }

    public function returnCommittedToWarehouse(
        int $websiteId,
        int $storeId,
        int $warehouseId,
        int $offerId,
        int $quantityMinor,
        string $idempotencyKey,
        string $requestHash,
    ): void {
        if ($websiteId < 0 || $storeId < 0 || $warehouseId <= 0 || $offerId <= 0) {
            throw new \InvalidArgumentException(__('Warehouse 退款回库 Scope 无效'));
        }
        if ($quantityMinor <= 0) {
            throw new \InvalidArgumentException(__('Warehouse 退款回库数量必须为正数'));
        }
        [$idempotencyKey, $requestHash] = $this->normalizeIdentity(
            $idempotencyKey,
            $requestHash,
        );
        $existing = $this->findLedger(
            $idempotencyKey,
            InventoryLedger::TYPE_REFUND_RETURN,
        );
        if ($existing !== null) {
            $this->assertReplay(
                $existing,
                $websiteId,
                $storeId,
                $warehouseId,
                $offerId,
                $quantityMinor,
                null,
                $requestHash,
            );
            return;
        }
        $this->assertAuthorized($websiteId, $storeId, $warehouseId);

        $connection = $this->newQuota()->getConnection();
        try {
            $this->transactionRunner()->run(
                $connection,
                function () use (
                    $websiteId,
                    $storeId,
                    $warehouseId,
                    $offerId,
                    $quantityMinor,
                    $idempotencyKey,
                    $requestHash,
                ): void {
                    $existing = $this->findLedger(
                        $idempotencyKey,
                        InventoryLedger::TYPE_REFUND_RETURN,
                    );
                    if ($existing !== null) {
                        $this->assertReplay(
                            $existing,
                            $websiteId,
                            $storeId,
                            $warehouseId,
                            $offerId,
                            $quantityMinor,
                            null,
                            $requestHash,
                        );
                        return;
                    }

                    for ($attempt = 0; $attempt < 8; $attempt++) {
                        $quota = $this->loadQuota($websiteId, $warehouseId, $offerId);
                        if (!$quota->getId()) {
                            throw new InventoryConflictException(
                                self::ERROR_QUOTA_NOT_FOUND,
                                __('原 Warehouse/Offer 库存入口不存在'),
                                [
                                    'website_id' => $websiteId,
                                    'warehouse_id' => $warehouseId,
                                    'offer_id' => $offerId,
                                ],
                            );
                        }
                        $version = (int) $quota->getData(
                            WarehouseQuota::schema_fields_QUOTA_VERSION,
                        );
                        $nextQty = (int) $quota->getData(
                            WarehouseQuota::schema_fields_QTY_MINOR,
                        ) + $quantityMinor;
                        $writer = $this->newQuota()
                            ->where(WarehouseQuota::schema_fields_ID, (int) $quota->getId())
                            ->where(WarehouseQuota::schema_fields_QUOTA_VERSION, $version);
                        $writer->update([
                            WarehouseQuota::schema_fields_QTY_MINOR => $nextQty,
                            WarehouseQuota::schema_fields_QUOTA_VERSION => $version + 1,
                            WarehouseQuota::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
                        ])->fetch();
                        $updated = $writer->getQueryData();
                        if ($updated === false || $updated === null || $updated === 0) {
                            continue;
                        }
                        $this->appendLedger(
                            $websiteId,
                            $storeId,
                            $warehouseId,
                            $offerId,
                            InventoryLedger::TYPE_REFUND_RETURN,
                            $quantityMinor,
                            null,
                            $idempotencyKey,
                            $requestHash,
                        );
                        return;
                    }
                    throw new InventoryConflictException(
                        self::ERROR_QUOTA_CAS,
                        __('WarehouseQuota CAS 重试耗尽'),
                    );
                },
            );
        } catch (Throwable $exception) {
            $replayed = $this->findLedger(
                $idempotencyKey,
                InventoryLedger::TYPE_REFUND_RETURN,
            );
            if ($replayed === null) {
                throw $exception;
            }
            $this->assertReplay(
                $replayed,
                $websiteId,
                $storeId,
                $warehouseId,
                $offerId,
                $quantityMinor,
                null,
                $requestHash,
            );
        }
    }

    private function assertAuthorized(int $websiteId, int $storeId, int $warehouseId): void
    {
        $authorized = ($this->authorizations
            ?? ObjectManager::getInstance(WarehouseAuthorizationService::class))
            ->isAuthorized($websiteId, $storeId, $warehouseId);
        if (!$authorized) {
            throw new InventoryConflictException(
                self::ERROR_BLOCKED_AUTHORIZATION,
                __('Store 无原 Warehouse 库存操作入口'),
                [
                    'website_id' => $websiteId,
                    'store_id' => $storeId,
                    'warehouse_id' => $warehouseId,
                ],
            );
        }
    }

    private function loadReservationForUpdate(string $reservationUuid): Reservation
    {
        $model = $this->newReservation()
            ->where(Reservation::schema_fields_RESERVATION_UUID, $reservationUuid);
        if (!$this->isSqlite($model)) {
            $model->additional('FOR UPDATE');
        }
        $model->find()->fetch();

        return $model;
    }

    private function loadReservation(string $reservationUuid): Reservation
    {
        return $this->newReservation()
            ->where(Reservation::schema_fields_RESERVATION_UUID, $reservationUuid)
            ->find()
            ->fetch();
    }

    private function loadQuota(int $websiteId, int $warehouseId, int $offerId): WarehouseQuota
    {
        return $this->newQuota()
            ->where(WarehouseQuota::schema_fields_WEBSITE_ID, $websiteId)
            ->where(WarehouseQuota::schema_fields_WAREHOUSE_ID, $warehouseId)
            ->where(WarehouseQuota::schema_fields_OFFER_ID, $offerId)
            ->find()
            ->fetch();
    }

    /** @return array<string,mixed>|null */
    private function findLedger(string $idempotencyKey, string $eventType): ?array
    {
        $ledger = $this->newLedger()
            ->where(InventoryLedger::schema_fields_IDEMPOTENCY_KEY, $idempotencyKey)
            ->where(InventoryLedger::schema_fields_EVENT_TYPE, $eventType)
            ->find()
            ->fetch();

        return $ledger->getId() ? $ledger->getData() : null;
    }

    private function appendLedger(
        int $websiteId,
        int $storeId,
        int $warehouseId,
        int $offerId,
        string $eventType,
        int $quantityMinor,
        ?string $reservationUuid,
        string $idempotencyKey,
        string $requestHash,
    ): void {
        if ($this->findLedger($idempotencyKey, $eventType) !== null) {
            throw new InventoryConflictException(
                self::ERROR_REPLAY,
                __('Warehouse inventory idempotency 已占用'),
            );
        }
        try {
            $this->newLedger()->setData([
                InventoryLedger::schema_fields_EVENT_UUID => $this->newUuid(),
                InventoryLedger::schema_fields_EVENT_TYPE => $eventType,
                InventoryLedger::schema_fields_WEBSITE_ID => $websiteId,
                InventoryLedger::schema_fields_STORE_ID => $storeId,
                InventoryLedger::schema_fields_OFFER_ID => $offerId,
                InventoryLedger::schema_fields_WAREHOUSE_ID => $warehouseId,
                InventoryLedger::schema_fields_QTY_DELTA_MINOR => $quantityMinor,
                InventoryLedger::schema_fields_STRATEGY => 'strict',
                InventoryLedger::schema_fields_OVERSELL_ALLOWANCE => 0,
                InventoryLedger::schema_fields_PREORDER_ALLOWANCE => 0,
                InventoryLedger::schema_fields_RESERVATION_UUID => $reservationUuid,
                InventoryLedger::schema_fields_IDEMPOTENCY_KEY => $idempotencyKey,
                InventoryLedger::schema_fields_REQUEST_HASH => $requestHash,
                InventoryLedger::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
            ])->save();
        } catch (Throwable $exception) {
            throw new InventoryConflictException(
                self::ERROR_REPLAY,
                __('Warehouse inventory ledger 唯一约束冲突'),
                ['idempotency_key' => $idempotencyKey, 'event_type' => $eventType],
                $exception,
            );
        }
    }

    /** @param array<string,mixed> $ledger */
    private function assertReplay(
        array $ledger,
        int $websiteId,
        int $storeId,
        int $warehouseId,
        int $offerId,
        int $quantityMinor,
        ?string $reservationUuid,
        string $requestHash,
    ): void {
        if ((int) ($ledger[InventoryLedger::schema_fields_WEBSITE_ID] ?? -1) !== $websiteId
            || (int) ($ledger[InventoryLedger::schema_fields_STORE_ID] ?? -1) !== $storeId
            || (int) ($ledger[InventoryLedger::schema_fields_WAREHOUSE_ID] ?? 0) !== $warehouseId
            || (int) ($ledger[InventoryLedger::schema_fields_OFFER_ID] ?? 0) !== $offerId
            || (int) ($ledger[InventoryLedger::schema_fields_QTY_DELTA_MINOR] ?? 0)
                !== $quantityMinor
            || (string) ($ledger[InventoryLedger::schema_fields_RESERVATION_UUID] ?? '')
                !== (string) $reservationUuid
            || !hash_equals(
                (string) ($ledger[InventoryLedger::schema_fields_REQUEST_HASH] ?? ''),
                $requestHash,
            )
        ) {
            throw new InventoryConflictException(
                self::ERROR_REPLAY,
                __('Warehouse inventory idempotency payload 冲突'),
            );
        }
    }

    private function reservationResult(
        Reservation $reservation,
        string $idempotencyKey,
        string $requestHash,
        bool $replayed,
        int $warehouseId,
    ): ReservationResult {
        if (!$reservation->getId()) {
            throw new InventoryConflictException(
                self::ERROR_RESERVATION_NOT_FOUND,
                __('库存 Reservation 不存在'),
            );
        }
        if ((int) $reservation->getData(Reservation::schema_fields_WAREHOUSE_ID)
            !== $warehouseId
        ) {
            throw new InventoryConflictException(
                self::ERROR_RESERVATION_CONFLICT,
                __('Reservation Warehouse 持久化结果不一致'),
            );
        }

        return new ReservationResult(
            reservationUuid: (string) $reservation->getData(
                Reservation::schema_fields_RESERVATION_UUID,
            ),
            state: (string) $reservation->getData(Reservation::schema_fields_STATE),
            quantityMinor: (int) $reservation->getData(
                Reservation::schema_fields_QUANTITY_MINOR,
            ),
            idempotencyKey: $idempotencyKey,
            requestHash: $requestHash,
            replayed: $replayed,
        );
    }

    /** @return array{0:string,1:string} */
    private function normalizeIdentity(string $idempotencyKey, string $requestHash): array
    {
        $idempotencyKey = trim($idempotencyKey);
        $requestHash = trim($requestHash);
        if ($idempotencyKey === '' || $requestHash === ''
            || strlen($idempotencyKey) > 128 || strlen($requestHash) > 64
        ) {
            throw new \InvalidArgumentException(__('Warehouse idempotency identity 无效'));
        }

        return [$idempotencyKey, $requestHash];
    }

    private function normalizeUuid(string $reservationUuid): string
    {
        $reservationUuid = trim($reservationUuid);
        if ($reservationUuid === '' || strlen($reservationUuid) > 36) {
            throw new \InvalidArgumentException(__('reservation_uuid 无效'));
        }

        return $reservationUuid;
    }

    private function transactionRunner(): DatabaseTransactionRunnerInterface
    {
        return $this->transactions
            ?? ObjectManager::getInstance(DatabaseTransactionRunnerInterface::class);
    }

    private function newReservation(): Reservation
    {
        return $this->reservationFactory !== null
            ? ($this->reservationFactory)()
            : ObjectManager::create(Reservation::class, [], false);
    }

    private function newQuota(): WarehouseQuota
    {
        return $this->quotaFactory !== null
            ? ($this->quotaFactory)()
            : ObjectManager::create(WarehouseQuota::class, [], false);
    }

    private function newLedger(): InventoryLedger
    {
        return $this->ledgerFactory !== null
            ? ($this->ledgerFactory)()
            : ObjectManager::create(InventoryLedger::class, [], false);
    }

    private function isSqlite(Model $model): bool
    {
        return strtolower((string) $model->getConnection()
            ->getConnector()
            ->getConfigProvider()
            ->getDbType()) === 'sqlite';
    }

    private function newUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
