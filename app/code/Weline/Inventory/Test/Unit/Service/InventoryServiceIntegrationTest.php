<?php

declare(strict_types=1);

namespace Weline\Inventory\Test\Unit\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Framework\Database\Service\DatabaseTransactionRunner;
use Weline\Framework\Database\Transaction\TransactionCoordinator;
use Weline\Inventory\Model\InventoryLedger;
use Weline\Inventory\Model\InventoryStock;
use Weline\Inventory\Model\Reservation;
use Weline\Inventory\Service\ControllableClock;
use Weline\Inventory\Service\InventoryConflictException;
use Weline\Inventory\Service\InventoryService;
use Weline\Inventory\Service\LeaseCoordinator;
use Weline\Inventory\Service\ReservationService;

final class InventoryServiceIntegrationTest extends TestCase
{
    public function testMemoryTransactionRestoresAllCommandsOnFailure(): void
    {
        $service = InventoryService::forTesting();
        $service->setOnHand(0, 1, 1001, 3, 'before', hash('sha256', 'before'));

        try {
            $service->transactional(function () use ($service): void {
                $service->setOnHand(0, 1, 1001, 9, 'inside-a', hash('sha256', 'inside-a'));
                $service->setOnHand(0, 1, 1002, 7, 'inside-b', hash('sha256', 'inside-b'));
                throw new \RuntimeException('force rollback');
            });
            self::fail('transaction must rethrow the failure');
        } catch (\RuntimeException $exception) {
            self::assertSame('force rollback', $exception->getMessage());
        }

        self::assertSame(3, $service->getAvailability(0, 1, 1001)->onHandMinor);
        self::assertSame(0, $service->getAvailability(0, 1, 1002)->onHandMinor);
        self::assertCount(1, $service->listLedgerEvents(0, 1, 1001));
        self::assertSame([], $service->listLedgerEvents(0, 1, 1002));
    }

    public function testRealSqliteLedgerStrategyReplayAndStoreIsolation(): void
    {
        self::assertContains('sqlite', PDO::getAvailableDrivers());
        $schema = (new SchemaParser())->parse(InventoryLedger::class);
        self::assertNotNull($schema);
        self::assertSame(
            [
                'strategy',
                'oversell_allowance',
                'preorder_allowance',
            ],
            array_values(array_intersect(
                array_map(static fn ($column): string => $column->name, $schema->columns),
                ['strategy', 'oversell_allowance', 'preorder_allowance'],
            )),
        );
        self::assertContains(
            'uk_inv_ledger_idem',
            array_map(static fn ($index): string => $index->name, $schema->indexes),
        );

        [$path, $connection, $connector] = $this->database(includeLedger: true);
        $service = $this->service($connection);
        $this->assertRequestedDatabase($connection);
        $otherRequest = $this->service($connection);

        try {
            $setHash = hash('sha256', 'sqlite-set');
            $first = $service->setOnHand(
                0,
                1,
                1001,
                1,
                'sqlite-set',
                $setHash,
                'strict',
            );
            $replayed = $otherRequest->setOnHand(
                0,
                1,
                1001,
                1,
                'sqlite-set',
                $setHash,
                'strict',
            );
            self::assertSame($first->stockVersion, $replayed->stockVersion);

            $reserved = $service->reserve(
                0,
                1,
                1001,
                1,
                'sqlite-reserve',
                hash('sha256', 'sqlite-reserve'),
            );
            $reserveReplay = $otherRequest->reserve(
                0,
                1,
                1001,
                1,
                'sqlite-reserve',
                hash('sha256', 'sqlite-reserve'),
            );
            self::assertTrue($reserveReplay->replayed);
            self::assertSame($reserved->reservationUuid, $reserveReplay->reservationUuid);

            try {
                $otherRequest->reserve(
                    0,
                    1,
                    1001,
                    1,
                    'sqlite-reserve-loser',
                    hash('sha256', 'sqlite-reserve-loser'),
                );
                self::fail('Strict stock=1 must accept only one reservation.');
            } catch (InventoryConflictException $exception) {
                self::assertSame('inventory_insufficient', $exception->errorCode());
            }

            $service->setOnHand(
                0,
                2,
                1001,
                2,
                'sqlite-store-two',
                hash('sha256', 'sqlite-store-two'),
                'preorder',
                0,
                3,
            );
            self::assertSame(0, $service->getAvailability(0, 1, 1001)->availableMinor);
            self::assertSame(5, $service->getAvailability(0, 2, 1001)->availableMinor);

            $stockRows = $connector->query(
                'SELECT store_id, on_hand_minor, reserved_minor, stock_version'
                . ' FROM weline_inventory_stock ORDER BY store_id'
            )->fetch();
            self::assertCount(2, $stockRows);
            self::assertSame(1, (int)$stockRows[0]['reserved_minor']);
            self::assertGreaterThanOrEqual(0, (int)$stockRows[0]['on_hand_minor']);
            self::assertSame(0, (int)$stockRows[1]['reserved_minor']);

            $ledgerRows = $connector->query(
                'SELECT event_type, strategy, oversell_allowance, preorder_allowance'
                . ' FROM weline_inventory_ledger ORDER BY ledger_id'
            )->fetch();
            self::assertCount(3, $ledgerRows);
            self::assertSame('strict', (string)$ledgerRows[0]['strategy']);
            self::assertSame('reserve', (string)$ledgerRows[1]['event_type']);
            self::assertSame('preorder', (string)$ledgerRows[2]['strategy']);
            self::assertSame(3, (int)$ledgerRows[2]['preorder_allowance']);

            $listedEvents = $otherRequest->listLedgerEvents(0, 1, 1001);
            self::assertSame(
                ['stock_set', 'reserve'],
                array_column($listedEvents, 'event_type'),
            );
            self::assertLessThan(
                (int)$listedEvents[1]['ledger_id'],
                (int)$listedEvents[0]['ledger_id'],
            );

            $reservationRows = $connector->query(
                'SELECT reservation_uuid, quantity_minor FROM weline_inventory_reservation'
            )->fetch();
            self::assertCount(1, $reservationRows);
            self::assertSame($reserved->reservationUuid, (string)$reservationRows[0]['reservation_uuid']);
        } finally {
            $this->cleanup($path, $connection, $connector);
        }
        self::assertFileDoesNotExist($path);
    }

    public function testRealSqliteRollsBackProjectionWhenLedgerWriteFails(): void
    {
        [$path, $connection, $connector] = $this->database(
            includeLedger: true,
            rejectLedgerWrites: true,
        );
        $service = $this->service($connection);
        $connector->query(
            "INSERT INTO weline_inventory_stock "
            . "(website_id,store_id,offer_id,strategy,on_hand_minor,reserved_minor,"
            . "oversell_allowance,preorder_allowance,stock_version) "
            . "VALUES (0,1,2001,'strict',1,0,0,0,0)"
        )->fetch();

        try {
            try {
                $service->reserve(
                    0,
                    1,
                    2001,
                    1,
                    'rollback-reserve',
                    hash('sha256', 'rollback-reserve'),
                );
                self::fail('Missing ledger table must fail the transaction.');
            } catch (InventoryConflictException $exception) {
                self::assertSame('inventory_ledger_unique_conflict', $exception->errorCode());
            }

            $stock = $connector->query(
                'SELECT reserved_minor, stock_version FROM weline_inventory_stock'
                . ' WHERE website_id=0 AND store_id=1 AND offer_id=2001'
            )->fetch();
            self::assertSame(0, (int)$stock[0]['reserved_minor']);
            self::assertSame(0, (int)$stock[0]['stock_version']);
            $reservations = $connector->query(
                'SELECT COUNT(*) AS total FROM weline_inventory_reservation'
            )->fetch();
            self::assertSame(0, (int)$reservations[0]['total']);
        } finally {
            $this->cleanup($path, $connection, $connector);
        }
        self::assertFileDoesNotExist($path);
    }

    public function testRealSqliteLeaseReplayQueueAndExpiryCas(): void
    {
        [$path, $connection, $connector] = $this->database(includeLedger: true);
        $clock = new ControllableClock(new \DateTimeImmutable(
            '2026-07-24 10:00:00',
            new \DateTimeZone('UTC'),
        ));
        $inventory = $this->service($connection);
        $service = new ReservationService(
            $inventory,
            new LeaseCoordinator($inventory, $clock),
        );

        try {
            $inventory->setOnHand(
                0,
                3,
                3001,
                3,
                'durable-lease-stock',
                hash('sha256', 'durable-lease-stock'),
            );
            $queued = $service->reserve(
                0,
                3,
                3001,
                1,
                'durable-queued',
                hash('sha256', 'durable-queued'),
                'attempt-queued',
                queuedOrder: true,
            );
            self::assertSame(1, $queued['lease']['lease_version']);
            self::assertTrue($queued['lease']['queued_order']);

            $persisted = $connector->query(
                'SELECT lease_owner_attempt_code, lease_started_at, queued_order,'
                . ' lease_version, lease_expires_at, lease_max_expires_at'
                . ' FROM weline_inventory_reservation'
                . " WHERE idempotency_key='durable-queued'"
            )->fetch();
            self::assertCount(1, $persisted);
            self::assertSame('attempt-queued', (string)$persisted[0]['lease_owner_attempt_code']);
            self::assertSame(1, (int)$persisted[0]['queued_order']);
            self::assertSame('2026-07-24 10:00:00', (string)$persisted[0]['lease_started_at']);

            $otherInventory = $this->service($connection);
            $otherService = new ReservationService(
                $otherInventory,
                new LeaseCoordinator($otherInventory, $clock),
            );
            $replayed = $otherService->reserve(
                0,
                3,
                3001,
                1,
                'durable-queued',
                hash('sha256', 'durable-queued'),
                'attempt-queued',
                queuedOrder: true,
            );
            self::assertTrue($replayed['reservation']->replayed);
            self::assertTrue($replayed['lease']['queued_order']);

            try {
                $otherService->renew(
                    $queued['reservation']->reservationUuid,
                    'attempt-queued',
                    1,
                );
                self::fail('Persisted queued reservation must not renew.');
            } catch (InventoryConflictException $exception) {
                self::assertSame('inventory_lease_queue_no_renew', $exception->errorCode());
            }
            try {
                $otherService->reserve(
                    0,
                    3,
                    3001,
                    1,
                    'durable-queued',
                    hash('sha256', 'durable-queued'),
                    'attempt-drift',
                    queuedOrder: true,
                );
                self::fail('Lease owner drift must fail replay.');
            } catch (InventoryConflictException $exception) {
                self::assertSame('inventory_lease_payload_conflict', $exception->errorCode());
            }

            $normal = $service->reserve(
                0,
                3,
                3001,
                1,
                'durable-normal',
                hash('sha256', 'durable-normal'),
                'attempt-normal',
            );
            $normalUuid = $normal['reservation']->reservationUuid;
            $renewed = $service->renew($normalUuid, 'attempt-normal', 1);
            self::assertSame(2, $renewed['lease_version']);
            try {
                $otherService->renew($normalUuid, 'attempt-normal', 1);
                self::fail('Only one durable lease CAS may win.');
            } catch (InventoryConflictException $exception) {
                self::assertSame('inventory_lease_version_conflict', $exception->errorCode());
            }

            self::assertTrue($inventory->patchReservation(
                $normalUuid,
                [
                    'lease_version' => 3,
                    'lease_expires_at' => '2026-07-24 09:59:00',
                ],
                expectedLeaseVersion: 2,
                expectedState: Reservation::STATE_RESERVED,
            ));
            $cutoff = '2026-07-24 10:00:00';
            $expiredRows = $inventory->listExpiredReservations($cutoff);
            self::assertCount(1, $expiredRows);
            self::assertSame(3, (int)$expiredRows[0]['lease_version']);

            self::assertTrue($otherInventory->patchReservation(
                $normalUuid,
                [
                    'lease_version' => 4,
                    'lease_expires_at' => '2026-07-24 10:30:00',
                ],
                expectedLeaseVersion: 3,
                expectedState: Reservation::STATE_RESERVED,
            ));
            self::assertFalse($inventory->expire($normalUuid, 3, $cutoff));
            self::assertSame(
                Reservation::STATE_RESERVED,
                $inventory->getReservation($normalUuid)['state'],
            );
            self::assertSame(2, $inventory->getAvailability(0, 3, 3001)->reservedMinor);

            $cron = new \Weline\Inventory\Cron\ReservationExpiry($inventory);
            $cron->setClock($clock);
            self::assertSame(
                'expired=0;skipped=0;errors=0;scanned=0',
                $cron->execute(),
            );
        } finally {
            $this->cleanup($path, $connection, $connector);
        }
        self::assertFileDoesNotExist($path);
    }

    public function testRealSqliteRollsBackCommitAndReleaseWhenLedgerFails(): void
    {
        [$path, $connection, $connector] = $this->database(
            includeLedger: true,
            rejectLedgerWrites: true,
        );
        $service = $this->service($connection);
        $uuid = '00000000-0000-4000-8000-000000004001';
        $connector->query(
            "INSERT INTO weline_inventory_stock "
            . "(website_id,store_id,offer_id,strategy,on_hand_minor,reserved_minor,"
            . "oversell_allowance,preorder_allowance,stock_version) "
            . "VALUES (0,4,4001,'strict',2,1,0,0,0)"
        )->fetch();
        $connector->query(
            'INSERT INTO weline_inventory_reservation '
            . '(reservation_uuid,website_id,store_id,offer_id,quantity_minor,state,'
            . 'idempotency_key,request_hash,lease_owner_attempt_code,lease_started_at,'
            . 'queued_order,lease_version,lease_expires_at,lease_max_expires_at) '
            . "VALUES ('$uuid',0,4,4001,1,'reserved','seed-rollback',"
            . "'" . hash('sha256', 'seed-rollback') . "','attempt-rollback',"
            . "'2026-07-24 10:00:00',0,1,'2026-07-24 10:30:00','2026-07-24 12:00:00')"
        )->fetch();

        try {
            try {
                $service->commit(
                    $uuid,
                    'commit-rollback',
                    hash('sha256', 'commit-rollback'),
                );
                self::fail('Commit ledger failure must roll back projection and state.');
            } catch (InventoryConflictException $exception) {
                self::assertSame('inventory_ledger_unique_conflict', $exception->errorCode());
            }
            $this->assertSeededReservationUnchanged($connector, $uuid);

            try {
                $service->release($uuid);
                self::fail('Release ledger failure must roll back projection and state.');
            } catch (InventoryConflictException $exception) {
                self::assertSame('inventory_ledger_unique_conflict', $exception->errorCode());
            }
            $this->assertSeededReservationUnchanged($connector, $uuid);
        } finally {
            $this->cleanup($path, $connection, $connector);
        }
        self::assertFileDoesNotExist($path);
    }

    private function assertSeededReservationUnchanged(
        ConnectorInterface $connector,
        string $uuid,
    ): void {
        $stock = $connector->query(
            'SELECT on_hand_minor, reserved_minor, stock_version'
            . ' FROM weline_inventory_stock WHERE offer_id=4001'
        )->fetch();
        self::assertSame(2, (int)$stock[0]['on_hand_minor']);
        self::assertSame(1, (int)$stock[0]['reserved_minor']);
        self::assertSame(0, (int)$stock[0]['stock_version']);
        $reservation = $connector->query(
            'SELECT state FROM weline_inventory_reservation'
            . " WHERE reservation_uuid='$uuid'"
        )->fetch();
        self::assertSame(Reservation::STATE_RESERVED, (string)$reservation[0]['state']);
        $ledger = $connector->query(
            'SELECT COUNT(*) AS total FROM weline_inventory_ledger'
        )->fetch();
        self::assertSame(0, (int)$ledger[0]['total']);
    }

    private function assertRequestedDatabase(ConnectionFactory $connection): void
    {
        $requested = trim((string)getenv('WELINE_INVENTORY_TEST_PGSQL_DATABASE'));
        if ($requested === '') {
            return;
        }

        self::assertSame(
            $requested,
            $connection->getConfigProvider()->getDatabase(),
            'PostgreSQL acceptance must connect to the requested isolated database.',
        );
    }

    /**
     * @return array{0:string,1:ConnectionFactory,2:ConnectorInterface}
     */
    private function database(bool $includeLedger, bool $rejectLedgerWrites = false): array
    {
        $pgsqlDatabase = trim((string)getenv('WELINE_INVENTORY_TEST_PGSQL_DATABASE'));
        $pgsql = $pgsqlDatabase !== '';
        if ($pgsql) {
            self::assertContains('pgsql', PDO::getAvailableDrivers());
            $path = '';
            $connection = ConnectionFactory::getInstance(new ConfigProvider([
                'type' => 'pgsql',
                'hostname' => getenv('WELINE_INVENTORY_TEST_PGSQL_HOST') ?: '127.0.0.1',
                'hostport' => getenv('WELINE_INVENTORY_TEST_PGSQL_PORT') ?: '5432',
                'database' => $pgsqlDatabase,
                'username' => getenv('WELINE_INVENTORY_TEST_PGSQL_USERNAME') ?: 'weline',
                'password' => getenv('WELINE_INVENTORY_TEST_PGSQL_PASSWORD') ?: 'weline',
                'charset' => 'utf8',
                'persistent' => false,
            ]));
        } else {
            self::assertContains('sqlite', PDO::getAvailableDrivers());
            $path = sys_get_temp_dir()
                . DIRECTORY_SEPARATOR
                . 'weline_p2b001_inventory_'
                . bin2hex(random_bytes(8))
                . '.sqlite';
            $connection = ConnectionFactory::getInstance(new ConfigProvider([
                'type' => 'sqlite',
                'database' => '',
                'path' => $path,
                'persistent' => false,
            ]));
        }
        $connector = $connection->getConnector();
        if ($pgsql) {
            $this->dropPostgresqlTables($connector);
        }
        $primaryKey = $pgsql
            ? 'INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY'
            : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $dateType = $pgsql ? 'TIMESTAMP' : 'DATETIME';

        $connector->query(
            'CREATE TABLE weline_inventory_stock ('
            . 'stock_id ' . $primaryKey . ', '
            . 'website_id INTEGER NOT NULL, store_id INTEGER NOT NULL, offer_id INTEGER NOT NULL, '
            . "strategy VARCHAR(32) NOT NULL DEFAULT 'strict', "
            . 'on_hand_minor INTEGER NOT NULL DEFAULT 0, reserved_minor INTEGER NOT NULL DEFAULT 0, '
            . 'oversell_allowance INTEGER NOT NULL DEFAULT 0, '
            . 'preorder_allowance INTEGER NOT NULL DEFAULT 0, '
            . 'stock_version INTEGER NOT NULL DEFAULT 0, '
            . 'created_at ' . $dateType . ' NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'updated_at ' . $dateType . ' NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'UNIQUE(website_id,store_id,offer_id)'
            . ')'
        )->fetch();
        if ($includeLedger) {
            $connector->query(
                'CREATE TABLE weline_inventory_ledger ('
                . 'ledger_id ' . $primaryKey . ', '
                . 'event_uuid VARCHAR(36) NOT NULL UNIQUE, event_type VARCHAR(32) NOT NULL, '
                . 'website_id INTEGER NOT NULL, store_id INTEGER NOT NULL, offer_id INTEGER NOT NULL, '
                . 'qty_delta_minor INTEGER NOT NULL'
                . ($rejectLedgerWrites ? ' CHECK(qty_delta_minor = 0), ' : ', ')
                . "strategy VARCHAR(32) NOT NULL DEFAULT 'strict', "
                . 'oversell_allowance INTEGER NOT NULL DEFAULT 0, '
                . 'preorder_allowance INTEGER NOT NULL DEFAULT 0, '
                . 'reservation_uuid VARCHAR(36), idempotency_key VARCHAR(128) NOT NULL, '
                . 'request_hash VARCHAR(64) NOT NULL, '
                . 'created_at ' . $dateType . ' NOT NULL DEFAULT CURRENT_TIMESTAMP, '
                . 'UNIQUE(idempotency_key,event_type)'
                . ')'
            )->fetch();
        }
        $connector->query(
            'CREATE TABLE weline_inventory_reservation ('
            . 'reservation_id ' . $primaryKey . ', '
            . 'reservation_uuid VARCHAR(36) NOT NULL UNIQUE, '
            . 'website_id INTEGER NOT NULL, store_id INTEGER NOT NULL, offer_id INTEGER NOT NULL, '
            . 'quantity_minor INTEGER NOT NULL, '
            . "state VARCHAR(32) NOT NULL DEFAULT 'reserved', "
            . 'idempotency_key VARCHAR(128) NOT NULL UNIQUE, request_hash VARCHAR(64) NOT NULL, '
            . 'warehouse_id INTEGER, lease_owner_attempt_code VARCHAR(64), '
            . 'lease_started_at ' . $dateType . ', queued_order INTEGER NOT NULL DEFAULT 0, '
            . 'lease_version INTEGER NOT NULL DEFAULT 0, lease_expires_at ' . $dateType . ', '
            . 'lease_max_expires_at ' . $dateType . ', '
            . 'created_at ' . $dateType . ' NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'updated_at ' . $dateType . ' NOT NULL DEFAULT CURRENT_TIMESTAMP'
            . ')'
        )->fetch();
        return [$path, $connection, $connector];
    }

    private function service(ConnectionFactory $connection): InventoryService
    {
        $model = static function (string $class) use ($connection) {
            $instance = new $class();
            $instance->setConnection($connection);
            $instance->__init();
            return $instance;
        };
        return new InventoryService(
            stockFactory: static fn (): InventoryStock => $model(InventoryStock::class),
            ledgerFactory: static fn (): InventoryLedger => $model(InventoryLedger::class),
            reservationFactory: static fn (): Reservation => $model(Reservation::class),
            connectionFactory: $connection,
            transactions: new DatabaseTransactionRunner(new TransactionCoordinator()),
        );
    }

    private function cleanup(
        string $path,
        ConnectionFactory $connection,
        ConnectorInterface $connector,
    ): void {
        if ($path === '') {
            $this->dropPostgresqlTables($connector);
        }
        $connector->close();
        $connection->close();
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function dropPostgresqlTables(ConnectorInterface $connector): void
    {
        $connector->query(
            'DROP TABLE IF EXISTS weline_inventory_reservation CASCADE',
        )->fetch();
        $connector->query(
            'DROP TABLE IF EXISTS weline_inventory_ledger CASCADE',
        )->fetch();
        $connector->query(
            'DROP TABLE IF EXISTS weline_inventory_stock CASCADE',
        )->fetch();
    }
}
