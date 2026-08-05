<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Throwable;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Api\DefaultWarehouseResolverInterface;
use Weline\Order\Model\FulfillmentProgressLedger;
use Weline\Order\Model\FulfillmentUnit;
use Weline\Order\Model\Order;

/**
 * Order-owned default Warehouse assignment and partial-ship CAS.
 */
final class WarehouseFulfillmentService
{
    public const SOURCE_LEGACY_DEFAULT = 'legacy_default';
    public const SOURCE_WAREHOUSE = 'warehouse';

    public const ERROR_ORDER_NOT_FOUND = 'order_warehouse_order_not_found';
    public const ERROR_UNIT_NOT_FOUND = 'order_fulfillment_unit_not_found';
    public const ERROR_WAREHOUSE_MISSING = 'order_fulfillment_warehouse_missing';
    public const ERROR_CAS = 'order_fulfillment_cas_conflict';
    public const ERROR_OVER_FULFILL = 'order_fulfillment_quantity_exceeded';
    public const ERROR_IDEMPOTENCY = 'order_fulfillment_idempotency_conflict';

    /** @var (\Closure(): Order)|null */
    private readonly ?\Closure $orderFactory;
    /** @var (\Closure(): FulfillmentUnit)|null */
    private readonly ?\Closure $unitFactory;
    /** @var (\Closure(): FulfillmentProgressLedger)|null */
    private readonly ?\Closure $ledgerFactory;

    /**
     * @param (callable(): Order)|null $orderFactory
     * @param (callable(): FulfillmentUnit)|null $unitFactory
     * @param (callable(): FulfillmentProgressLedger)|null $ledgerFactory
     */
    public function __construct(
        private readonly ?DefaultWarehouseResolverInterface $defaults = null,
        private readonly ?DatabaseTransactionRunnerInterface $transactions = null,
        ?callable $orderFactory = null,
        ?callable $unitFactory = null,
        ?callable $ledgerFactory = null,
    ) {
        $this->orderFactory = $orderFactory !== null
            ? \Closure::fromCallable($orderFactory)
            : null;
        $this->unitFactory = $unitFactory !== null
            ? \Closure::fromCallable($unitFactory)
            : null;
        $this->ledgerFactory = $ledgerFactory !== null
            ? \Closure::fromCallable($ledgerFactory)
            : null;
    }

    /**
     * Mode-off compatibility assignment: fill only null Warehouse IDs.
     *
     * @return list<array<string,mixed>>
     */
    public function assignDefaultWarehouseForOrder(string $orderUuid): array
    {
        $orderUuid = $this->normalizeUuid($orderUuid, 'order_uuid');
        $snapshot = $this->loadOrder($orderUuid);
        if (!$snapshot->getId()) {
            throw new WarehouseFulfillmentConflictException(
                self::ERROR_ORDER_NOT_FOUND,
                __('Order 不存在'),
            );
        }
        $assignment = $this->defaultResolver()->resolveDefault(
            (int) $snapshot->getData(Order::schema_fields_WEBSITE_ID),
            (int) $snapshot->getData(Order::schema_fields_STORE_ID),
        );

        return $this->transactionRunner()->run(
            $snapshot->getConnection(),
            function () use ($orderUuid, $assignment): array {
                $order = $this->loadOrderForUpdate($orderUuid);
                if (!$order->getId()) {
                    throw new WarehouseFulfillmentConflictException(
                        self::ERROR_ORDER_NOT_FOUND,
                        __('Order 不存在'),
                    );
                }
                $rows = $this->newUnit()
                    ->where(FulfillmentUnit::schema_fields_ORDER_UUID, $orderUuid)
                    ->order(FulfillmentUnit::schema_fields_ID, 'ASC')
                    ->select()
                    ->fetch();
                $result = [];
                foreach ($rows->getItems() as $row) {
                    if (!$row instanceof FulfillmentUnit) {
                        continue;
                    }
                    $unit = $this->loadUnitForUpdate(
                        (string) $row->getData(
                            FulfillmentUnit::schema_fields_FULFILLMENT_UNIT_UUID,
                        ),
                    );
                    $warehouseId = (int) $unit->getData(
                        FulfillmentUnit::schema_fields_WAREHOUSE_ID,
                    );
                    if ($warehouseId === 0) {
                        $unit->setData(
                            FulfillmentUnit::schema_fields_WAREHOUSE_ID,
                            $assignment->warehouseId,
                        )->setData(
                            FulfillmentUnit::schema_fields_WAREHOUSE_SOURCE,
                            $assignment->writerEnabled
                                ? self::SOURCE_WAREHOUSE
                                : self::SOURCE_LEGACY_DEFAULT,
                        )->setData(
                            FulfillmentUnit::schema_fields_UPDATED_AT,
                            date('Y-m-d H:i:s'),
                        )->save();
                    }
                    $result[] = $this->unitResult($unit);
                }

                return $result;
            },
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function partialShip(
        string $unitUuid,
        int $shipQuantityMinor,
        int $expectedVersion,
        string $idempotencyKey,
        string $requestHash,
    ): array {
        $unitUuid = $this->normalizeUuid($unitUuid, 'fulfillment_unit_uuid');
        if ($shipQuantityMinor <= 0 || $expectedVersion < 0) {
            throw new WarehouseFulfillmentConflictException(
                self::ERROR_OVER_FULFILL,
                __('发货数量或版本无效'),
            );
        }
        [$idempotencyKey, $requestHash] = $this->normalizeIdentity(
            $idempotencyKey,
            $requestHash,
        );

        $existing = $this->findLedger($idempotencyKey);
        if ($existing !== null) {
            $this->assertReplay(
                $existing,
                $unitUuid,
                $shipQuantityMinor,
                $expectedVersion,
                $requestHash,
            );

            return $this->unitResult($this->loadUnit($unitUuid), true);
        }

        $connection = $this->newUnit()->getConnection();
        try {
            return $this->transactionRunner()->run(
                $connection,
                function () use (
                    $unitUuid,
                    $shipQuantityMinor,
                    $expectedVersion,
                    $idempotencyKey,
                    $requestHash,
                ): array {
                    $existing = $this->findLedger($idempotencyKey);
                    if ($existing !== null) {
                        $this->assertReplay(
                            $existing,
                            $unitUuid,
                            $shipQuantityMinor,
                            $expectedVersion,
                            $requestHash,
                        );

                        return $this->unitResult($this->loadUnit($unitUuid), true);
                    }
                    $unit = $this->loadUnitForUpdate($unitUuid);
                    if (!$unit->getId()) {
                        throw new WarehouseFulfillmentConflictException(
                            self::ERROR_UNIT_NOT_FOUND,
                            __('FulfillmentUnit 不存在'),
                        );
                    }
                    $warehouseId = (int) $unit->getData(
                        FulfillmentUnit::schema_fields_WAREHOUSE_ID,
                    );
                    if ($warehouseId <= 0) {
                        throw new WarehouseFulfillmentConflictException(
                            self::ERROR_WAREHOUSE_MISSING,
                            __('FulfillmentUnit 缺少 Warehouse'),
                        );
                    }
                    $actualVersion = (int) $unit->getData(
                        FulfillmentUnit::schema_fields_FULFILLMENT_VERSION,
                    );
                    if ($actualVersion !== $expectedVersion) {
                        throw new WarehouseFulfillmentConflictException(
                            self::ERROR_CAS,
                            __('履约 CAS 版本冲突'),
                            ['expected' => $expectedVersion, 'actual' => $actualVersion],
                        );
                    }
                    $fulfilled = (int) $unit->getData(
                        FulfillmentUnit::schema_fields_FULFILLED_QTY_MINOR,
                    );
                    $total = (int) $unit->getData(FulfillmentUnit::schema_fields_QTY_MINOR);
                    $next = $fulfilled + $shipQuantityMinor;
                    if ($next > $total) {
                        throw new WarehouseFulfillmentConflictException(
                            self::ERROR_OVER_FULFILL,
                            __('累计发货数量超过可发数量'),
                            [
                                'qty_minor' => $total,
                                'fulfilled_qty_minor' => $fulfilled,
                                'request_qty_minor' => $shipQuantityMinor,
                            ],
                        );
                    }
                    $status = $next === $total
                        ? FulfillmentUnit::STATUS_SHIPPED
                        : FulfillmentUnit::STATUS_PARTIAL;
                    $writer = $this->newUnit()
                        ->where(FulfillmentUnit::schema_fields_ID, (int) $unit->getId())
                        ->where(
                            FulfillmentUnit::schema_fields_FULFILLMENT_VERSION,
                            $expectedVersion,
                        );
                    $writer->update([
                        FulfillmentUnit::schema_fields_FULFILLED_QTY_MINOR => $next,
                        FulfillmentUnit::schema_fields_FULFILLMENT_VERSION
                            => $expectedVersion + 1,
                        FulfillmentUnit::schema_fields_STATUS => $status,
                        FulfillmentUnit::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
                    ])->fetch();
                    $updated = $writer->getQueryData();
                    if ($updated === false || $updated === null || $updated === 0) {
                        throw new WarehouseFulfillmentConflictException(
                            self::ERROR_CAS,
                            __('履约 CAS 写入冲突'),
                        );
                    }
                    $this->appendLedger(
                        $unit,
                        $shipQuantityMinor,
                        $expectedVersion,
                        $idempotencyKey,
                        $requestHash,
                    );

                    return $this->unitResult($this->loadUnit($unitUuid));
                },
            );
        } catch (Throwable $exception) {
            $ledger = $this->findLedger($idempotencyKey);
            if ($ledger === null) {
                throw $exception;
            }
            $this->assertReplay(
                $ledger,
                $unitUuid,
                $shipQuantityMinor,
                $expectedVersion,
                $requestHash,
            );

            return $this->unitResult($this->loadUnit($unitUuid), true);
        }
    }

    /** @return array<string,mixed> */
    public function getUnit(string $unitUuid): array
    {
        $unit = $this->loadUnit($this->normalizeUuid($unitUuid, 'fulfillment_unit_uuid'));
        if (!$unit->getId()) {
            throw new WarehouseFulfillmentConflictException(
                self::ERROR_UNIT_NOT_FOUND,
                __('FulfillmentUnit 不存在'),
            );
        }

        return $this->unitResult($unit);
    }

    private function appendLedger(
        FulfillmentUnit $unit,
        int $shipQuantityMinor,
        int $expectedVersion,
        string $idempotencyKey,
        string $requestHash,
    ): void {
        try {
            $this->newLedger()->setData([
                FulfillmentProgressLedger::schema_fields_EVENT_UUID => $this->newUuid(),
                FulfillmentProgressLedger::schema_fields_EVENT_TYPE
                    => FulfillmentProgressLedger::TYPE_PARTIAL_SHIP,
                FulfillmentProgressLedger::schema_fields_UNIT_UUID => (string) $unit->getData(
                    FulfillmentUnit::schema_fields_FULFILLMENT_UNIT_UUID,
                ),
                FulfillmentProgressLedger::schema_fields_ORDER_UUID => (string) $unit->getData(
                    FulfillmentUnit::schema_fields_ORDER_UUID,
                ),
                FulfillmentProgressLedger::schema_fields_WAREHOUSE_ID => (int) $unit->getData(
                    FulfillmentUnit::schema_fields_WAREHOUSE_ID,
                ),
                FulfillmentProgressLedger::schema_fields_QTY_MINOR => $shipQuantityMinor,
                FulfillmentProgressLedger::schema_fields_EXPECTED_VERSION => $expectedVersion,
                FulfillmentProgressLedger::schema_fields_NEW_VERSION => $expectedVersion + 1,
                FulfillmentProgressLedger::schema_fields_IDEMPOTENCY_KEY => $idempotencyKey,
                FulfillmentProgressLedger::schema_fields_REQUEST_HASH => $requestHash,
                FulfillmentProgressLedger::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
            ])->save();
        } catch (Throwable $exception) {
            throw new WarehouseFulfillmentConflictException(
                self::ERROR_IDEMPOTENCY,
                __('履约进度 ledger 唯一约束冲突'),
                ['idempotency_key' => $idempotencyKey],
                $exception,
            );
        }
    }

    /** @return array<string,mixed>|null */
    private function findLedger(string $idempotencyKey): ?array
    {
        $ledger = $this->newLedger()
            ->where(
                FulfillmentProgressLedger::schema_fields_IDEMPOTENCY_KEY,
                $idempotencyKey,
            )
            ->where(
                FulfillmentProgressLedger::schema_fields_EVENT_TYPE,
                FulfillmentProgressLedger::TYPE_PARTIAL_SHIP,
            )
            ->find()
            ->fetch();

        return $ledger->getId() ? $ledger->getData() : null;
    }

    /** @param array<string,mixed> $ledger */
    private function assertReplay(
        array $ledger,
        string $unitUuid,
        int $shipQuantityMinor,
        int $expectedVersion,
        string $requestHash,
    ): void {
        if ((string) ($ledger[FulfillmentProgressLedger::schema_fields_UNIT_UUID] ?? '')
                !== $unitUuid
            || (int) ($ledger[FulfillmentProgressLedger::schema_fields_QTY_MINOR] ?? 0)
                !== $shipQuantityMinor
            || (int) (
                $ledger[FulfillmentProgressLedger::schema_fields_EXPECTED_VERSION] ?? -1
            ) !== $expectedVersion
            || !hash_equals(
                (string) ($ledger[FulfillmentProgressLedger::schema_fields_REQUEST_HASH] ?? ''),
                $requestHash,
            )
        ) {
            throw new WarehouseFulfillmentConflictException(
                self::ERROR_IDEMPOTENCY,
                __('履约进度 idempotency payload 冲突'),
            );
        }
    }

    private function loadOrder(string $orderUuid): Order
    {
        return $this->newOrder()
            ->where(Order::schema_fields_ORDER_UUID, $orderUuid)
            ->find()
            ->fetch();
    }

    private function loadOrderForUpdate(string $orderUuid): Order
    {
        $order = $this->newOrder()->where(Order::schema_fields_ORDER_UUID, $orderUuid);
        if (!$this->isSqlite($order)) {
            $order->additional('FOR UPDATE');
        }
        $order->find()->fetch();

        return $order;
    }

    private function loadUnit(string $unitUuid): FulfillmentUnit
    {
        return $this->newUnit()
            ->where(FulfillmentUnit::schema_fields_FULFILLMENT_UNIT_UUID, $unitUuid)
            ->find()
            ->fetch();
    }

    private function loadUnitForUpdate(string $unitUuid): FulfillmentUnit
    {
        $unit = $this->newUnit()
            ->where(FulfillmentUnit::schema_fields_FULFILLMENT_UNIT_UUID, $unitUuid);
        if (!$this->isSqlite($unit)) {
            $unit->additional('FOR UPDATE');
        }
        $unit->find()->fetch();

        return $unit;
    }

    /** @return array<string,mixed> */
    private function unitResult(FulfillmentUnit $unit, bool $replayed = false): array
    {
        if (!$unit->getId()) {
            throw new WarehouseFulfillmentConflictException(
                self::ERROR_UNIT_NOT_FOUND,
                __('FulfillmentUnit 不存在'),
            );
        }

        return [
            'fulfillment_unit_uuid' => (string) $unit->getData(
                FulfillmentUnit::schema_fields_FULFILLMENT_UNIT_UUID,
            ),
            'order_uuid' => (string) $unit->getData(
                FulfillmentUnit::schema_fields_ORDER_UUID,
            ),
            'warehouse_id' => (int) $unit->getData(
                FulfillmentUnit::schema_fields_WAREHOUSE_ID,
            ),
            'warehouse_source' => (string) (
                $unit->getData(FulfillmentUnit::schema_fields_WAREHOUSE_SOURCE)
                ?: self::SOURCE_WAREHOUSE
            ),
            'qty_minor' => (int) $unit->getData(FulfillmentUnit::schema_fields_QTY_MINOR),
            'fulfilled_qty_minor' => (int) $unit->getData(
                FulfillmentUnit::schema_fields_FULFILLED_QTY_MINOR,
            ),
            'fulfillment_version' => (int) $unit->getData(
                FulfillmentUnit::schema_fields_FULFILLMENT_VERSION,
            ),
            'status' => (string) $unit->getData(FulfillmentUnit::schema_fields_STATUS),
            'replayed' => $replayed,
        ];
    }

    private function defaultResolver(): DefaultWarehouseResolverInterface
    {
        return $this->defaults
            ?? ObjectManager::getInstance(DefaultWarehouseResolverInterface::class);
    }

    private function transactionRunner(): DatabaseTransactionRunnerInterface
    {
        return $this->transactions
            ?? ObjectManager::getInstance(DatabaseTransactionRunnerInterface::class);
    }

    private function newOrder(): Order
    {
        return $this->orderFactory !== null
            ? ($this->orderFactory)()
            : ObjectManager::create(Order::class, [], false);
    }

    private function newUnit(): FulfillmentUnit
    {
        return $this->unitFactory !== null
            ? ($this->unitFactory)()
            : ObjectManager::create(FulfillmentUnit::class, [], false);
    }

    private function newLedger(): FulfillmentProgressLedger
    {
        return $this->ledgerFactory !== null
            ? ($this->ledgerFactory)()
            : ObjectManager::create(FulfillmentProgressLedger::class, [], false);
    }

    private function isSqlite(Model $model): bool
    {
        return strtolower((string) $model->getConnection()
            ->getConnector()
            ->getConfigProvider()
            ->getDbType()) === 'sqlite';
    }

    /** @return array{0:string,1:string} */
    private function normalizeIdentity(string $idempotencyKey, string $requestHash): array
    {
        $idempotencyKey = trim($idempotencyKey);
        $requestHash = trim($requestHash);
        if ($idempotencyKey === '' || $requestHash === ''
            || strlen($idempotencyKey) > 128 || strlen($requestHash) > 64
        ) {
            throw new \InvalidArgumentException(__('履约 idempotency identity 无效'));
        }

        return [$idempotencyKey, $requestHash];
    }

    private function normalizeUuid(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 36) {
            throw new \InvalidArgumentException(__('%{1} 无效', [$field]));
        }

        return $value;
    }

    private function newUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
