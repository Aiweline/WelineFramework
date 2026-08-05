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
use Weline\Inventory\Api\Data\WarehouseAssignment;
use Weline\Inventory\Api\DefaultWarehouseResolverInterface;
use Weline\Order\Model\FulfillmentProgressLedger;
use Weline\Order\Model\FulfillmentUnit;
use Weline\Order\Model\Order;
use Weline\Order\Service\OriginalWarehouseLocator;
use Weline\Order\Service\WarehouseFulfillmentConflictException;
use Weline\Order\Service\WarehouseFulfillmentService;

/** TEST-P3A-02 and TEST-P3A-05: stale-worker CAS cannot over-ship and old facts survive mode-off. */
final class WarehouseFulfillmentServiceDatabaseIntegrationTest extends TestCase
{
    public function testModeOffDefaultAndStaleWorkerPartialShipAreDurable(): void
    {
        [$path, $connection, $connector] = $this->database();
        try {
            $firstWorker = $this->service($connection);
            $secondWorker = $this->service($connection);

            $new = $firstWorker->assignDefaultWarehouseForOrder('order-new');
            self::assertCount(1, $new);
            self::assertSame(100, $new[0]['warehouse_id']);
            self::assertSame(
                WarehouseFulfillmentService::SOURCE_LEGACY_DEFAULT,
                $new[0]['warehouse_source'],
            );

            $old = $firstWorker->assignDefaultWarehouseForOrder('order-old');
            self::assertCount(1, $old);
            self::assertSame(55, $old[0]['warehouse_id']);
            self::assertSame(
                WarehouseFulfillmentService::SOURCE_WAREHOUSE,
                $old[0]['warehouse_source'],
            );

            $aHash = hash('sha256', 'worker-a');
            $a = $firstWorker->partialShip('unit-old', 6, 0, 'worker-a', $aHash);
            self::assertSame(6, $a['fulfilled_qty_minor']);
            self::assertSame(FulfillmentUnit::STATUS_PARTIAL, $a['status']);

            try {
                $secondWorker->partialShip(
                    'unit-old',
                    5,
                    0,
                    'worker-stale',
                    hash('sha256', 'worker-stale'),
                );
                self::fail('The stale worker must lose the CAS.');
            } catch (WarehouseFulfillmentConflictException $exception) {
                self::assertSame(
                    WarehouseFulfillmentService::ERROR_CAS,
                    $exception->errorCode(),
                );
            }

            $bHash = hash('sha256', 'worker-b');
            $b = $secondWorker->partialShip('unit-old', 4, 1, 'worker-b', $bHash);
            $replayed = $firstWorker->partialShip(
                'unit-old',
                4,
                1,
                'worker-b',
                $bHash,
            );
            self::assertSame(10, $b['fulfilled_qty_minor']);
            self::assertSame(FulfillmentUnit::STATUS_SHIPPED, $b['status']);
            self::assertTrue($replayed['replayed']);

            try {
                $firstWorker->partialShip(
                    'unit-old',
                    1,
                    2,
                    'worker-over',
                    hash('sha256', 'worker-over'),
                );
                self::fail('Fulfilled quantity must remain bounded.');
            } catch (WarehouseFulfillmentConflictException $exception) {
                self::assertSame(
                    WarehouseFulfillmentService::ERROR_OVER_FULFILL,
                    $exception->errorCode(),
                );
            }

            self::assertSame(10, $this->scalar(
                $connector,
                "SELECT fulfilled_qty_minor FROM weline_fulfillment_unit"
                    . " WHERE fulfillment_unit_uuid='unit-old'",
                'fulfilled_qty_minor',
            ));
            self::assertSame(2, $this->scalar(
                $connector,
                'SELECT COUNT(*) AS total FROM weline_fulfillment_progress_ledger',
                'total',
            ));
            self::assertSame(10, $this->scalar(
                $connector,
                'SELECT SUM(qty_minor) AS total FROM weline_fulfillment_progress_ledger',
                'total',
            ));

            $locator = new OriginalWarehouseLocator(
                unitFactory: fn (): FulfillmentUnit => $this->model(
                    FulfillmentUnit::class,
                    $connection,
                ),
            );
            self::assertSame(
                [
                    'warehouse_id' => 55,
                    'warehouse_source' => WarehouseFulfillmentService::SOURCE_WAREHOUSE,
                ],
                $locator->forOffer('order-old', 701),
            );

            $connector->query(
                'INSERT INTO weline_fulfillment_unit '
                . '(fulfillment_unit_uuid,order_uuid,status,warehouse_id,warehouse_source,'
                . 'allocations_json,qty_minor,fulfilled_qty_minor,fulfillment_version) VALUES '
                . "('unit-ambiguous','order-old','pending',66,'warehouse',"
                . '\'[{"offer_id":701,"qty_minor":1}]\',1,0,0)'
            )->fetch();
            try {
                $locator->forOffer('order-old', 701);
                self::fail('Ambiguous original Warehouse must fail closed.');
            } catch (WarehouseFulfillmentConflictException $exception) {
                self::assertSame(
                    OriginalWarehouseLocator::ERROR_BLOCKED_AUTHORIZATION,
                    $exception->errorCode(),
                );
            }
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
        $path = sys_get_temp_dir() . '/weline_p3a002_order_'
            . bin2hex(random_bytes(8)) . '.sqlite';
        $connection = ConnectionFactory::getInstance(new ConfigProvider([
            'type' => 'sqlite',
            'database' => '',
            'path' => $path,
            'persistent' => false,
        ]));
        $connector = $connection->getConnector();
        $connector->query(
            'CREATE TABLE weline_order ('
            . 'order_id INTEGER PRIMARY KEY AUTOINCREMENT, order_uuid VARCHAR(36) NOT NULL UNIQUE, '
            . 'website_id INTEGER NOT NULL, store_id INTEGER NOT NULL)'
        )->fetch();
        $connector->query(
            'CREATE TABLE weline_fulfillment_unit ('
            . 'fulfillment_unit_id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'fulfillment_unit_uuid VARCHAR(36) NOT NULL UNIQUE, '
            . 'order_uuid VARCHAR(36) NOT NULL, checkout_group_uuid VARCHAR(36), '
            . "status VARCHAR(32) NOT NULL DEFAULT 'pending', warehouse_id INTEGER, "
            . 'warehouse_source VARCHAR(24), allocations_json TEXT, qty_minor INTEGER NOT NULL, '
            . 'fulfilled_qty_minor INTEGER NOT NULL DEFAULT 0, '
            . 'fulfillment_version INTEGER NOT NULL DEFAULT 0, '
            . 'created_at DATETIME DEFAULT CURRENT_TIMESTAMP, '
            . 'updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)'
        )->fetch();
        $connector->query(
            'CREATE TABLE weline_fulfillment_progress_ledger ('
            . 'progress_id INTEGER PRIMARY KEY AUTOINCREMENT, event_uuid VARCHAR(36) NOT NULL UNIQUE, '
            . 'event_type VARCHAR(32) NOT NULL, fulfillment_unit_uuid VARCHAR(36) NOT NULL, '
            . 'order_uuid VARCHAR(36) NOT NULL, warehouse_id INTEGER NOT NULL, qty_minor INTEGER NOT NULL, '
            . 'expected_version INTEGER NOT NULL, new_version INTEGER NOT NULL, '
            . 'idempotency_key VARCHAR(128) NOT NULL, request_hash VARCHAR(64) NOT NULL, '
            . 'created_at DATETIME DEFAULT CURRENT_TIMESTAMP, '
            . 'UNIQUE(idempotency_key,event_type))'
        )->fetch();
        $connector->query(
            "INSERT INTO weline_order (order_uuid,website_id,store_id) VALUES "
            . "('order-new',0,20),('order-old',0,20)"
        )->fetch();
        $connector->query(
            'INSERT INTO weline_fulfillment_unit '
            . '(fulfillment_unit_uuid,order_uuid,status,warehouse_id,warehouse_source,'
            . 'allocations_json,qty_minor,fulfilled_qty_minor,fulfillment_version) VALUES '
            . "('unit-new','order-new','pending',NULL,NULL,"
            . '\'[{"offer_id":700,"qty_minor":2}]\',2,0,0),'
            . "('unit-old','order-old','pending',55,'warehouse',"
            . '\'[{"offer_id":701,"qty_minor":10}]\',10,0,0)'
        )->fetch();

        return [$path, $connection, $connector];
    }

    private function service(ConnectionFactory $connection): WarehouseFulfillmentService
    {
        $defaults = new class implements DefaultWarehouseResolverInterface {
            public function resolveDefault(int $websiteId, int $storeId): WarehouseAssignment
            {
                return new WarehouseAssignment(100, $websiteId, 'DEFAULT', 'normal', 'logical');
            }
        };

        return new WarehouseFulfillmentService(
            defaults: $defaults,
            transactions: new DatabaseTransactionRunner(new TransactionCoordinator()),
            orderFactory: fn (): Order => $this->model(Order::class, $connection),
            unitFactory: fn (): FulfillmentUnit => $this->model(
                FulfillmentUnit::class,
                $connection,
            ),
            ledgerFactory: fn (): FulfillmentProgressLedger => $this->model(
                FulfillmentProgressLedger::class,
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
