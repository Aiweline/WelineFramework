<?php

declare(strict_types=1);

namespace Weline\Inventory\Test\Unit\Service;

\defined('APP_PATH') || \define('APP_PATH', BP . 'app' . DS);
\defined('APP_ETC_PATH') || \define('APP_ETC_PATH', APP_PATH . 'etc' . DS);
\defined('APP_CODE_PATH') || \define('APP_CODE_PATH', APP_PATH . 'code' . DS);
\defined('VENDOR_PATH') || \define('VENDOR_PATH', BP . 'vendor' . DS);
\defined('PUB') || \define('PUB', BP . 'pub' . DS);
\defined('DEBUG') || \define('DEBUG', false);
\defined('CLI') || \define('CLI', true);
\defined('SANDBOX') || \define('SANDBOX', false);
\defined('DEV') || \define('DEV', true);
\defined('PROD') || \define('PROD', false);
$frameworkFunctions = APP_CODE_PATH . 'Weline/Framework/Common/functions.php';
if (\is_file($frameworkFunctions)) {
    require_once $frameworkFunctions;
}

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Service\DatabaseTransactionRunner;
use Weline\Framework\Database\Transaction\TransactionCoordinator;
use Weline\Inventory\Api\InventoryCatalogMaintenanceInterface;
use Weline\Inventory\Model\InventoryLedger;
use Weline\Inventory\Model\InventoryStock;
use Weline\Inventory\Model\Reservation;
use Weline\Inventory\Service\InventoryService;

final class InventoryCatalogPurgeTest extends TestCase
{
    public function testOnlyActiveReferencesBlockAndImmutableLedgerIsPreserved(): void
    {
        self::assertContains('sqlite', PDO::getAvailableDrivers());
        $missing = [];
        if (!interface_exists(InventoryCatalogMaintenanceInterface::class)) {
            $missing[] = InventoryCatalogMaintenanceInterface::class;
        }
        foreach (['previewCatalogPurge', 'purgeCatalogOffers'] as $method) {
            if (!method_exists(InventoryService::class, $method)) {
                $missing[] = InventoryService::class . '::' . $method;
            }
        }
        self::assertSame([], $missing, 'Missing inventory catalog-maintenance contract.');

        $dbPath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'weline_inventory_catalog_purge_'
            . bin2hex(random_bytes(8))
            . '.sqlite';
        $connectionFactory = ConnectionFactory::getInstance(new ConfigProvider([
            'type' => 'sqlite',
            'database' => '',
            'path' => $dbPath,
            'persistent' => false,
        ]));
        $connector = $connectionFactory->getConnector();
        $this->createTables($connector);
        $this->insertFixtures($connector);
        $service = new InventoryService(
            stockFactory: $this->modelFactory($connectionFactory, InventoryStock::class),
            ledgerFactory: $this->modelFactory($connectionFactory, InventoryLedger::class),
            reservationFactory: $this->modelFactory($connectionFactory, Reservation::class),
            connectionFactory: $connectionFactory,
            transactions: new DatabaseTransactionRunner(new TransactionCoordinator()),
        );

        try {
            self::assertInstanceOf(InventoryCatalogMaintenanceInterface::class, $service);
            self::assertSame([
                'stock_items' => 0,
                'reservations' => 0,
                'ledger_events' => 0,
                'protected_references' => [],
                'preserved_audit_references' => [],
            ], $service->previewCatalogPurge(0, []));
            self::assertSame([
                'stock_items' => 0,
                'reservations' => 0,
                'ledger_events' => 0,
            ], $service->purgeCatalogOffers(0, []));

            $before = $this->counts($connector);
            $preview = $service->previewCatalogPurge(0, [9001, 9001, -1, 0]);
            self::assertSame(1, $preview['stock_items']);
            self::assertSame(1, $preview['reservations']);
            self::assertSame(1, $preview['ledger_events']);
            self::assertSame(
                ['ORDER-1'],
                array_column($preview['protected_references'], 'reference_id'),
            );
            self::assertSame(
                ['AUDIT-1'],
                array_column($preview['preserved_audit_references'], 'reference_id'),
            );
            self::assertSame($before, $this->counts($connector), 'Preview must never write.');

            try {
                $service->purgeCatalogOffers(0, [9001]);
                self::fail('Active order references must block inventory purge.');
            } catch (\RuntimeException $exception) {
                self::assertSame('hanfu_cleanup_protected_reference', $exception->getMessage());
            }
            self::assertSame($before, $this->counts($connector), 'Blocked apply must be zero-write.');

            $auditBefore = $this->offerRows($connector, InventoryLedger::schema_table, 9001);
            $connector->query(
                "UPDATE " . Reservation::schema_table
                . " SET state = 'released' WHERE offer_id = 9001"
            )->fetch();

            $ready = $service->previewCatalogPurge(0, [9001]);
            self::assertSame(1, $ready['stock_items']);
            self::assertSame(1, $ready['reservations']);
            self::assertSame(1, $ready['ledger_events']);
            self::assertSame([], $ready['protected_references']);
            self::assertSame(
                ['AUDIT-1'],
                array_column($ready['preserved_audit_references'], 'reference_id'),
            );
            self::assertSame([
                'stock_items' => 1,
                'reservations' => 1,
                'ledger_events' => 0,
            ], $service->purgeCatalogOffers(0, [9001, 9001, -1, 0]));

            self::assertSame(0, $this->countOfferRows($connector, InventoryStock::schema_table, 9001));
            self::assertSame(0, $this->countOfferRows($connector, Reservation::schema_table, 9001));
            self::assertSame(
                $auditBefore,
                $this->offerRows($connector, InventoryLedger::schema_table, 9001),
                'Immutable audit rows must remain byte-for-byte equivalent after purge.',
            );
            self::assertSame(1, $this->countOfferRows($connector, InventoryStock::schema_table, 9002));
            self::assertSame(1, $this->countOfferRows($connector, Reservation::schema_table, 9002));

            $this->expectException(\InvalidArgumentException::class);
            $service->previewCatalogPurge(-1, [9002]);
        } finally {
            $connector->close();
            $connectionFactory->close();
            if (is_file($dbPath)) {
                unlink($dbPath);
            }
        }
    }

    public function testMemoryPurgeKeepsAuditHistoryAndAllowsRecreation(): void
    {
        $service = InventoryService::forTesting();
        $service->setOnHand(
            0,
            0,
            9010,
            1,
            'catalog-set-9010',
            hash('sha256', 'catalog-set-9010'),
        );
        $auditBefore = $service->listLedgerEvents(0, 0, 9010);
        self::assertCount(1, $auditBefore);

        $preview = $service->previewCatalogPurge(0, [9010]);
        self::assertSame([], $preview['protected_references']);
        self::assertCount(1, $preview['preserved_audit_references']);
        self::assertSame([
            'stock_items' => 1,
            'reservations' => 0,
            'ledger_events' => 0,
        ], $service->purgeCatalogOffers(0, [9010]));

        self::assertSame($auditBefore, $service->listLedgerEvents(0, 0, 9010));
        $after = $service->previewCatalogPurge(0, [9010]);
        self::assertSame(0, $after['stock_items']);
        self::assertSame(1, $after['ledger_events']);
        self::assertSame([], $after['protected_references']);
        self::assertCount(1, $after['preserved_audit_references']);

        $service->ensureStock(0, 0, 9010);
        self::assertSame(1, $service->previewCatalogPurge(0, [9010])['stock_items']);
        self::assertSame($auditBefore, $service->listLedgerEvents(0, 0, 9010));
    }

    /** @param class-string<Model> $class @return \Closure(): Model */
    private function modelFactory(ConnectionFactory $connectionFactory, string $class): \Closure
    {
        return static function () use ($connectionFactory, $class): Model {
            $model = new $class();
            $model->setConnection($connectionFactory);
            $model->__init();
            return $model;
        };
    }

    private function createTables(ConnectorInterface $connector): void
    {
        $connector->query(
            'CREATE TABLE ' . InventoryStock::schema_table . ' ('
            . 'stock_id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'website_id INTEGER NOT NULL, store_id INTEGER NOT NULL, offer_id INTEGER NOT NULL)'
        )->fetch();
        $connector->query(
            'CREATE TABLE ' . Reservation::schema_table . ' ('
            . 'reservation_id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'reservation_uuid VARCHAR(64) NOT NULL, website_id INTEGER NOT NULL, '
            . 'store_id INTEGER NOT NULL, offer_id INTEGER NOT NULL, '
            . 'state VARCHAR(32) NOT NULL, idempotency_key VARCHAR(128) NOT NULL)'
        )->fetch();
        $connector->query(
            'CREATE TABLE ' . InventoryLedger::schema_table . ' ('
            . 'ledger_id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'event_uuid VARCHAR(64) NOT NULL, event_type VARCHAR(64) NOT NULL, '
            . 'website_id INTEGER NOT NULL, store_id INTEGER NOT NULL, offer_id INTEGER NOT NULL, '
            . 'reservation_uuid VARCHAR(64) NULL, idempotency_key VARCHAR(128) NOT NULL)'
        )->fetch();
    }

    private function insertFixtures(ConnectorInterface $connector): void
    {
        foreach ([9001, 9002] as $offerId) {
            $connector->query(
                'INSERT INTO ' . InventoryStock::schema_table
                . " (website_id, store_id, offer_id) VALUES (0, 0, {$offerId})"
            )->fetch();
        }
        $connector->query(
            'INSERT INTO ' . Reservation::schema_table
            . " (reservation_uuid, website_id, store_id, offer_id, state, idempotency_key) VALUES "
            . "('ORDER-1', 0, 0, 9001, 'committed', 'order-1'), "
            . "('KEEP-2', 0, 0, 9002, 'released', 'keep-2')"
        )->fetch();
        $connector->query(
            'INSERT INTO ' . InventoryLedger::schema_table
            . " (event_uuid, event_type, website_id, store_id, offer_id, reservation_uuid, idempotency_key) "
            . "VALUES ('AUDIT-1', 'commit', 0, 0, 9001, 'ORDER-1', 'audit-1')"
        )->fetch();
    }

    /** @return array{stock:int,reservation:int,ledger:int} */
    private function counts(ConnectorInterface $connector): array
    {
        return [
            'stock' => $this->countRows($connector, InventoryStock::schema_table),
            'reservation' => $this->countRows($connector, Reservation::schema_table),
            'ledger' => $this->countRows($connector, InventoryLedger::schema_table),
        ];
    }

    private function countRows(ConnectorInterface $connector, string $table): int
    {
        $rows = $connector->query('SELECT COUNT(*) AS total FROM ' . $table)->fetch();
        return (int)($rows[0]['total'] ?? 0);
    }

    private function countOfferRows(
        ConnectorInterface $connector,
        string $table,
        int $offerId,
    ): int {
        $rows = $connector->query(
            'SELECT COUNT(*) AS total FROM ' . $table . ' WHERE offer_id = ' . $offerId
        )->fetch();
        return (int)($rows[0]['total'] ?? 0);
    }

    /** @return list<array<string,mixed>> */
    private function offerRows(ConnectorInterface $connector, string $table, int $offerId): array
    {
        $rows = $connector->query(
            'SELECT * FROM ' . $table . ' WHERE offer_id = ' . $offerId . ' ORDER BY 1 ASC'
        )->fetch();
        return is_array($rows) ? array_values($rows) : [];
    }
}
