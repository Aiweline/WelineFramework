<?php

declare(strict_types=1);

namespace Weline\Inventory\Test\Unit\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Service\DatabaseTransactionRunner;
use Weline\Framework\Database\Transaction\TransactionCoordinator;
use Weline\Inventory\Model\InventoryLedger;
use Weline\Inventory\Model\Reservation;
use Weline\Inventory\Model\Warehouse;
use Weline\Inventory\Model\WarehouseQuota;
use Weline\Inventory\Model\WarehouseStoreAuthorization;
use Weline\Inventory\Service\InventoryConflictException;
use Weline\Inventory\Service\WarehouseAuthorizationService;
use Weline\Inventory\Service\WarehouseInventoryService;

final class WarehouseInventoryServiceDatabaseIntegrationTest extends TestCase
{
    public function testReservationAssignmentAndOriginalWarehouseReturnAreDurable(): void
    {
        [$path, $connection, $connector] = $this->database();
        try {
            $service = $this->service($connection);
            $reservationHash = hash('sha256', 'assign-reservation');
            $assigned = $service->assignReservationWarehouse(
                '00000000-0000-4000-8000-000000000701',
                0,
                20,
                100,
                'assign-reservation',
                $reservationHash,
            );
            $replayed = $this->service($connection)->assignReservationWarehouse(
                '00000000-0000-4000-8000-000000000701',
                0,
                20,
                100,
                'assign-reservation',
                $reservationHash,
            );
            self::assertFalse($assigned->replayed);
            self::assertTrue($replayed->replayed);
            self::assertSame(100, $this->scalar(
                $connector,
                'SELECT warehouse_id FROM weline_inventory_reservation',
                'warehouse_id',
            ));
            self::assertSame(1, $this->countLedger($connector, 'warehouse_assign'));

            try {
                $service->assignReservationWarehouse(
                    '00000000-0000-4000-8000-000000000701',
                    0,
                    20,
                    100,
                    'assign-reservation',
                    hash('sha256', 'assign-reservation-drift'),
                );
                self::fail('Assignment request hash drift must fail.');
            } catch (InventoryConflictException $exception) {
                self::assertSame(
                    WarehouseInventoryService::ERROR_REPLAY,
                    $exception->errorCode(),
                );
            }

            $returnHash = hash('sha256', 'warehouse-return');
            $service->returnCommittedToWarehouse(
                0,
                20,
                100,
                501,
                3,
                'warehouse-return',
                $returnHash,
            );
            $connector->query(
                'UPDATE weline_inventory_warehouse_store_authorization'
                    . ' SET enabled=0 WHERE website_id=0 AND store_id=20 AND warehouse_id=100'
            )->fetch();
            $this->service($connection)->returnCommittedToWarehouse(
                0,
                20,
                100,
                501,
                3,
                'warehouse-return',
                $returnHash,
            );
            self::assertSame(8, $this->scalar(
                $connector,
                'SELECT qty_minor FROM weline_inventory_warehouse_quota',
                'qty_minor',
            ));
            self::assertSame(1, $this->scalar(
                $connector,
                'SELECT quota_version FROM weline_inventory_warehouse_quota',
                'quota_version',
            ));
            self::assertSame(1, $this->countLedger($connector, 'refund_return'));
            self::assertSame(100, $this->scalar(
                $connector,
                "SELECT warehouse_id FROM weline_inventory_ledger"
                    . " WHERE event_type='refund_return'",
                'warehouse_id',
            ));

            try {
                $service->returnCommittedToWarehouse(
                    0,
                    20,
                    100,
                    501,
                    1,
                    'disabled-authorization-return',
                    hash('sha256', 'disabled-authorization-return'),
                );
                self::fail('A new return through a disabled authorization must fail closed.');
            } catch (InventoryConflictException $exception) {
                self::assertSame(
                    WarehouseInventoryService::ERROR_BLOCKED_AUTHORIZATION,
                    $exception->errorCode(),
                );
            }

            try {
                $service->returnCommittedToWarehouse(
                    0,
                    20,
                    999,
                    501,
                    1,
                    'unauthorized-return',
                    hash('sha256', 'unauthorized-return'),
                );
                self::fail('Unknown Warehouse authorization must fail closed.');
            } catch (InventoryConflictException $exception) {
                self::assertSame(
                    WarehouseInventoryService::ERROR_BLOCKED_AUTHORIZATION,
                    $exception->errorCode(),
                );
            }
            self::assertSame(8, $this->scalar(
                $connector,
                'SELECT qty_minor FROM weline_inventory_warehouse_quota',
                'qty_minor',
            ));
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
        $path = sys_get_temp_dir() . '/weline_p3a002_inventory_'
            . bin2hex(random_bytes(8)) . '.sqlite';
        $connection = ConnectionFactory::getInstance(new ConfigProvider([
            'type' => 'sqlite',
            'database' => '',
            'path' => $path,
            'persistent' => false,
        ]));
        $connector = $connection->getConnector();
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
            'CREATE TABLE weline_inventory_reservation ('
            . 'reservation_id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'reservation_uuid VARCHAR(36) NOT NULL UNIQUE, website_id INTEGER NOT NULL, '
            . 'store_id INTEGER NOT NULL, offer_id INTEGER NOT NULL, quantity_minor INTEGER NOT NULL, '
            . "state VARCHAR(32) NOT NULL DEFAULT 'reserved', idempotency_key VARCHAR(128) NOT NULL UNIQUE, "
            . 'request_hash VARCHAR(64) NOT NULL, warehouse_id INTEGER, '
            . 'lease_owner_attempt_code VARCHAR(64), lease_started_at DATETIME, '
            . 'queued_order INTEGER NOT NULL DEFAULT 0, lease_version INTEGER NOT NULL DEFAULT 0, '
            . 'lease_expires_at DATETIME, lease_max_expires_at DATETIME, '
            . 'created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)'
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
            "INSERT INTO weline_inventory_warehouse_store_authorization "
            . "(website_id,store_id,warehouse_id,store_mode_snapshot,is_default,enabled) "
            . "VALUES (0,20,100,'normal',0,1)"
        )->fetch();
        $connector->query(
            'INSERT INTO weline_inventory_warehouse_quota '
            . '(website_id,warehouse_id,offer_id,qty_minor,quota_version) '
            . 'VALUES (0,100,501,5,0)'
        )->fetch();
        $connector->query(
            'INSERT INTO weline_inventory_reservation '
            . '(reservation_uuid,website_id,store_id,offer_id,quantity_minor,state,'
            . 'idempotency_key,request_hash) VALUES '
            . "('00000000-0000-4000-8000-000000000701',0,20,501,2,'reserved',"
            . "'seed-reservation','" . hash('sha256', 'seed-reservation') . "')"
        )->fetch();

        return [$path, $connection, $connector];
    }

    private function service(ConnectionFactory $connection): WarehouseInventoryService
    {
        $model = static function (string $class) use ($connection): object {
            $instance = new $class();
            $instance->setConnection($connection);
            $instance->__init();

            return $instance;
        };
        $authorizations = new WarehouseAuthorizationService(
            warehouseFactory: static fn (): Warehouse => $model(Warehouse::class),
            authorizationFactory: static fn (): WarehouseStoreAuthorization
                => $model(WarehouseStoreAuthorization::class),
        );

        return new WarehouseInventoryService(
            authorizations: $authorizations,
            transactions: new DatabaseTransactionRunner(new TransactionCoordinator()),
            reservationFactory: static fn (): Reservation => $model(Reservation::class),
            quotaFactory: static fn (): WarehouseQuota => $model(WarehouseQuota::class),
            ledgerFactory: static fn (): InventoryLedger => $model(InventoryLedger::class),
        );
    }

    private function countLedger(ConnectorInterface $connector, string $eventType): int
    {
        return $this->scalar(
            $connector,
            "SELECT COUNT(*) AS total FROM weline_inventory_ledger"
                . " WHERE event_type='" . $eventType . "'",
            'total',
        );
    }

    private function scalar(
        ConnectorInterface $connector,
        string $sql,
        string $field,
    ): int {
        $rows = $connector->query($sql)->fetch();

        return (int)($rows[0][$field] ?? 0);
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
