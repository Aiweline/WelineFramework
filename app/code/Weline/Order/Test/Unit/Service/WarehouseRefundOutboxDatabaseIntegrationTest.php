<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Service\DatabaseTransactionRunner;
use Weline\Framework\Database\Transaction\TransactionCoordinator;
use Weline\Inventory\Api\Data\ReservationResult;
use Weline\Inventory\Api\InventoryRefundCapabilityInterface;
use Weline\Inventory\Api\WarehouseInventoryCapabilityInterface;
use Weline\Inventory\Model\InventoryLedger;
use Weline\Inventory\Model\Reservation;
use Weline\Inventory\Model\Warehouse;
use Weline\Inventory\Model\WarehouseQuota;
use Weline\Inventory\Model\WarehouseStoreAuthorization;
use Weline\Inventory\Service\WarehouseAuthorizationService;
use Weline\Inventory\Service\WarehouseInventoryService;
use Weline\Order\Model\RefundCase;
use Weline\Order\Model\RefundOutbox;
use Weline\Order\Service\OrderRefundCoordinator;
use Weline\Order\Service\WarehouseFulfillmentService;

/** TEST-P3A-03: cash success survives a warehouse-return failure and stock is restored once on retry. */
final class WarehouseRefundOutboxDatabaseIntegrationTest extends TestCase
{
    public function testCashSuccessSurvivesWarehouseFailureAndStockRetriesOnce(): void
    {
        [$path, $connection, $connector] = $this->database();
        try {
            $warehouse = $this->warehouseService($connection);
            $flaky = new class($warehouse) implements WarehouseInventoryCapabilityInterface {
                private bool $failFirstReturn = true;

                public function __construct(
                    private readonly WarehouseInventoryCapabilityInterface $next,
                ) {
                }

                public function assignReservationWarehouse(
                    string $reservationUuid,
                    int $websiteId,
                    int $storeId,
                    int $warehouseId,
                    string $idempotencyKey,
                    string $requestHash,
                ): ReservationResult {
                    return $this->next->assignReservationWarehouse(
                        $reservationUuid,
                        $websiteId,
                        $storeId,
                        $warehouseId,
                        $idempotencyKey,
                        $requestHash,
                    );
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
                    if ($this->failFirstReturn) {
                        $this->failFirstReturn = false;
                        throw new \RuntimeException('stock_step_failed');
                    }
                    $this->next->returnCommittedToWarehouse(
                        $websiteId,
                        $storeId,
                        $warehouseId,
                        $offerId,
                        $quantityMinor,
                        $idempotencyKey,
                        $requestHash,
                    );
                }
            };
            $legacy = new class implements InventoryRefundCapabilityInterface {
                public function returnCommitted(
                    int $websiteId,
                    int $storeId,
                    int $offerId,
                    int $quantityMinor,
                    string $idempotencyKey,
                    string $requestHash,
                ): void {
                    throw new \LogicException('legacy_inventory_path_must_not_run');
                }
            };
            $coordinator = new OrderRefundCoordinator(
                transactions: new TransactionCoordinator(),
                inventoryRefunds: $legacy,
                warehouseInventory: $flaky,
                modelFactory: fn (string $class) => $this->model($class, $connection),
            );

            $failed = $coordinator->processOneOutbox('outbox-restock');
            self::assertFalse($failed['ok']);
            self::assertSame('stock_step_failed', $failed['error_code']);
            self::assertSame(RefundOutbox::STATUS_PENDING, $this->text(
                $connector,
                "SELECT status FROM weline_order_refund_outbox WHERE outbox_code='outbox-restock'",
                'status',
            ));
            self::assertSame(RefundCase::STATUS_SUCCEEDED, $this->text(
                $connector,
                "SELECT status FROM weline_order_refund_case WHERE refund_case_uuid='refund-case-1'",
                'status',
            ));
            self::assertSame(5, $this->scalar(
                $connector,
                'SELECT qty_minor FROM weline_inventory_warehouse_quota',
                'qty_minor',
            ));

            $succeeded = $coordinator->processOneOutbox('outbox-restock');
            $replayed = $coordinator->processOneOutbox('outbox-restock');
            self::assertTrue($succeeded['ok']);
            self::assertTrue($replayed['ok']);
            self::assertTrue($replayed['replayed']);
            self::assertSame(8, $this->scalar(
                $connector,
                'SELECT qty_minor FROM weline_inventory_warehouse_quota',
                'qty_minor',
            ));
            self::assertSame(1, $this->scalar(
                $connector,
                "SELECT COUNT(*) AS total FROM weline_inventory_ledger"
                    . " WHERE event_type='refund_return'",
                'total',
            ));
            self::assertSame(2, $this->scalar(
                $connector,
                "SELECT attempt_count FROM weline_order_refund_outbox"
                    . " WHERE outbox_code='outbox-restock'",
                'attempt_count',
            ));
            self::assertSame(RefundOutbox::STATUS_DONE, $this->text(
                $connector,
                "SELECT status FROM weline_order_refund_outbox WHERE outbox_code='outbox-restock'",
                'status',
            ));
            self::assertSame(RefundCase::STATUS_SUCCEEDED, $this->text(
                $connector,
                "SELECT status FROM weline_order_refund_case WHERE refund_case_uuid='refund-case-1'",
                'status',
            ));
            $steps = json_decode($this->text(
                $connector,
                "SELECT steps_json FROM weline_order_refund_case"
                    . " WHERE refund_case_uuid='refund-case-1'",
                'steps_json',
            ), true, 32, JSON_THROW_ON_ERROR);
            self::assertSame(
                RefundOutbox::STATUS_DONE,
                $steps['refund:refund-case-1:inventory:restock:v1']['status'] ?? null,
            );
        } finally {
            $this->cleanup($path, $connection, $connector);
        }
    }

    /**
     * @return array{0:string,1:ConnectionFactory,2:ConnectorInterface}
     */
    private function database(): array
    {
        self::assertContains('sqlite', PDO::getAvailableDrivers());
        $path = sys_get_temp_dir() . '/weline_p3a002_refund_'
            . bin2hex(random_bytes(8)) . '.sqlite';
        $connection = ConnectionFactory::getInstance(new ConfigProvider([
            'type' => 'sqlite',
            'database' => '',
            'path' => $path,
            'persistent' => false,
        ]));
        $connector = $connection->getConnector();
        $connector->query(
            'CREATE TABLE weline_order_refund_case ('
            . 'refund_case_id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'refund_case_uuid VARCHAR(36) NOT NULL UNIQUE, order_uuid VARCHAR(36) NOT NULL, '
            . 'payment_refund_code VARCHAR(96), idempotency_key VARCHAR(128), request_hash VARCHAR(64), '
            . 'amount_minor INTEGER NOT NULL DEFAULT 0, currency VARCHAR(3) NOT NULL, items_json TEXT, '
            . 'shipping_refund_minor INTEGER NOT NULL DEFAULT 0, status VARCHAR(32) NOT NULL, '
            . 'customer_view VARCHAR(24) NOT NULL, version INTEGER NOT NULL DEFAULT 0, '
            . 'reason VARCHAR(255), steps_json TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, '
            . 'updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)'
        )->fetch();
        $connector->query(
            'CREATE TABLE weline_order_refund_outbox ('
            . 'outbox_id INTEGER PRIMARY KEY AUTOINCREMENT, outbox_code VARCHAR(96) NOT NULL UNIQUE, '
            . 'effect_key VARCHAR(191) NOT NULL UNIQUE, refund_case_uuid VARCHAR(36) NOT NULL, '
            . 'refund_code VARCHAR(96) NOT NULL, operation VARCHAR(48) NOT NULL, '
            . 'provider_request_key VARCHAR(160), status VARCHAR(32) NOT NULL, payload_json TEXT, '
            . 'result_json TEXT, error_code VARCHAR(96), attempt_count INTEGER NOT NULL DEFAULT 0, '
            . "claim_token VARCHAR(64) NOT NULL DEFAULT '', claimed_at DATETIME, "
            . 'created_at DATETIME DEFAULT CURRENT_TIMESTAMP, processed_at DATETIME)'
        )->fetch();
        $connector->query(
            'CREATE TABLE weline_inventory_warehouse_store_authorization ('
            . 'authorization_id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'website_id INTEGER NOT NULL, store_id INTEGER NOT NULL, warehouse_id INTEGER NOT NULL, '
            . 'store_mode_snapshot VARCHAR(16) NOT NULL, is_default INTEGER NOT NULL DEFAULT 0, '
            . 'default_guard VARCHAR(16), enabled INTEGER NOT NULL DEFAULT 1, '
            . 'authorization_version INTEGER NOT NULL DEFAULT 0, '
            . 'created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, '
            . 'UNIQUE(website_id,store_id,warehouse_id))'
        )->fetch();
        $connector->query(
            'CREATE TABLE weline_inventory_warehouse_quota ('
            . 'quota_id INTEGER PRIMARY KEY AUTOINCREMENT, website_id INTEGER NOT NULL, '
            . 'warehouse_id INTEGER NOT NULL, pool_id INTEGER, offer_id INTEGER NOT NULL, '
            . 'qty_minor INTEGER NOT NULL DEFAULT 0, quota_version INTEGER NOT NULL DEFAULT 0, '
            . 'created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, '
            . 'UNIQUE(website_id,warehouse_id,offer_id))'
        )->fetch();
        $connector->query(
            'CREATE TABLE weline_inventory_ledger ('
            . 'ledger_id INTEGER PRIMARY KEY AUTOINCREMENT, event_uuid VARCHAR(36) NOT NULL UNIQUE, '
            . 'event_type VARCHAR(32) NOT NULL, website_id INTEGER NOT NULL, store_id INTEGER NOT NULL, '
            . 'offer_id INTEGER NOT NULL, warehouse_id INTEGER, qty_delta_minor INTEGER NOT NULL, '
            . "strategy VARCHAR(32) NOT NULL DEFAULT 'strict', "
            . 'oversell_allowance INTEGER NOT NULL DEFAULT 0, '
            . 'preorder_allowance INTEGER NOT NULL DEFAULT 0, reservation_uuid VARCHAR(36), '
            . 'idempotency_key VARCHAR(128) NOT NULL, request_hash VARCHAR(64) NOT NULL, '
            . 'created_at DATETIME DEFAULT CURRENT_TIMESTAMP, '
            . 'UNIQUE(idempotency_key,event_type))'
        )->fetch();
        $connector->query(
            'CREATE TABLE weline_inventory_reservation ('
            . 'reservation_id INTEGER PRIMARY KEY AUTOINCREMENT, reservation_uuid VARCHAR(36) UNIQUE, '
            . 'website_id INTEGER, store_id INTEGER, offer_id INTEGER, quantity_minor INTEGER, '
            . 'state VARCHAR(32), idempotency_key VARCHAR(128), request_hash VARCHAR(64), '
            . 'warehouse_id INTEGER, created_at DATETIME, updated_at DATETIME)'
        )->fetch();
        $connector->query(
            "INSERT INTO weline_order_refund_case "
            . "(refund_case_uuid,order_uuid,payment_refund_code,amount_minor,currency,status,"
            . "customer_view,steps_json) VALUES "
            . "('refund-case-1','order-1','payment-refund-1',300,'CNY','succeeded','succeeded',"
            . '\'{"refund:refund-case-1:inventory:restock:v1":{"status":"pending"}}\')'
        )->fetch();
        $payload = json_encode([
            'items' => [[
                'item_uuid' => 'item-1',
                'website_id' => 0,
                'store_id' => 20,
                'warehouse_id' => 100,
                'warehouse_source' => WarehouseFulfillmentService::SOURCE_WAREHOUSE,
                'offer_id' => 501,
                'qty_minor' => 3,
                'restock' => true,
            ]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $connector->query(
            'INSERT INTO weline_order_refund_outbox '
            . '(outbox_code,effect_key,refund_case_uuid,refund_code,operation,status,payload_json) '
            . "VALUES ('outbox-restock','refund:refund-case-1:inventory:restock:v1',"
            . "'refund-case-1','payment-refund-1','inventory_restock','pending',"
            . $connector->quote($payload) . ')'
        )->fetch();
        $connector->query(
            "INSERT INTO weline_inventory_warehouse_store_authorization "
            . "(website_id,store_id,warehouse_id,store_mode_snapshot,enabled) "
            . "VALUES (0,20,100,'normal',1)"
        )->fetch();
        $connector->query(
            'INSERT INTO weline_inventory_warehouse_quota '
            . '(website_id,warehouse_id,offer_id,qty_minor,quota_version) '
            . 'VALUES (0,100,501,5,0)'
        )->fetch();

        return [$path, $connection, $connector];
    }

    private function warehouseService(
        ConnectionFactory $connection,
    ): WarehouseInventoryService {
        $authorizations = new WarehouseAuthorizationService(
            warehouseFactory: fn (): Warehouse => $this->model(Warehouse::class, $connection),
            authorizationFactory: fn (): WarehouseStoreAuthorization => $this->model(
                WarehouseStoreAuthorization::class,
                $connection,
            ),
        );

        return new WarehouseInventoryService(
            authorizations: $authorizations,
            transactions: new DatabaseTransactionRunner(new TransactionCoordinator()),
            reservationFactory: fn (): Reservation => $this->model(
                Reservation::class,
                $connection,
            ),
            quotaFactory: fn (): WarehouseQuota => $this->model(
                WarehouseQuota::class,
                $connection,
            ),
            ledgerFactory: fn (): InventoryLedger => $this->model(
                InventoryLedger::class,
                $connection,
            ),
        );
    }

    /** @template T of object @param class-string<T> $class @return T */
    private function model(string $class, ConnectionFactory $connection): object
    {
        $instance = new $class();
        $instance->setConnection($connection);
        $instance->__init();

        return $instance;
    }

    private function scalar(
        ConnectorInterface $connector,
        string $sql,
        string $field,
    ): int {
        $rows = $connector->query($sql)->fetch();

        return (int)($rows[0][$field] ?? 0);
    }

    private function text(
        ConnectorInterface $connector,
        string $sql,
        string $field,
    ): string {
        $rows = $connector->query($sql)->fetch();

        return (string)($rows[0][$field] ?? '');
    }

    private function cleanup(
        string $path,
        ConnectionFactory $connection,
        ConnectorInterface $connector,
    ): void {
        $connector->close();
        $connection->close();
        if (is_file($path)) {
            unlink($path);
        }
    }
}
