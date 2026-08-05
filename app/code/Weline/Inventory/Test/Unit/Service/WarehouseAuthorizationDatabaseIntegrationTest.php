<?php

declare(strict_types=1);

namespace Weline\Inventory\Test\Unit\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Inventory\Model\Warehouse;
use Weline\Inventory\Model\WarehouseStoreAuthorization;
use Weline\Inventory\Service\DefaultLogicalWarehouseResolver;
use Weline\Inventory\Service\WarehouseAuthorizationService;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

/** Real SQLite coverage for TASK-P3A-001 / TEST-P3A-04. */
final class WarehouseAuthorizationDatabaseIntegrationTest extends TestCase
{
    public function testSchemaDeclaresDurableUniqueGuards(): void
    {
        $warehouse = (new SchemaParser())->parse(Warehouse::class);
        $authorization = (new SchemaParser())->parse(WarehouseStoreAuthorization::class);
        self::assertNotNull($warehouse);
        self::assertNotNull($authorization);
        self::assertContains(
            Warehouse::schema_fields_WAREHOUSE_TYPE,
            array_map(static fn ($column): string => $column->name, $warehouse->columns),
        );
        self::assertContains(
            'uk_inv_wh_default',
            array_map(static fn ($index): string => $index->name, $warehouse->indexes),
        );
        self::assertContains(
            'uk_inv_wh_store_auth',
            array_map(static fn ($index): string => $index->name, $authorization->indexes),
        );
        self::assertContains(
            'uk_inv_wh_store_default',
            array_map(static fn ($index): string => $index->name, $authorization->indexes),
        );
    }

    public function testTrustedModePersistenceReplayConflictAndFreshResolver(): void
    {
        self::assertContains('sqlite', PDO::getAvailableDrivers());
        [$path, $connection, $connector] = $this->database();
        $catalog = new WarehouseStoreCatalogStub([
            new StoreSummary(20, 0, 'test-store', 'Test Store', 'test', false, true, 'active', null),
            new StoreSummary(21, 0, 'dev-store', 'Dev Store', 'dev', false, true, 'active', null),
            new StoreSummary(22, 1, 'other-store', 'Other Store', 'normal', false, true, 'active', null),
            new StoreSummary(23, 0, 'disabled', 'Disabled Store', 'test', false, false, 'active', null),
            new StoreSummary(24, 0, 'unknown', 'Unknown Store', 'preview', false, true, 'active', null),
        ]);
        $model = static function (string $class) use ($connection) {
            $instance = new $class();
            $instance->setConnection($connection);
            $instance->__init();
            return $instance;
        };
        $warehouseFactory = static fn (): Warehouse => $model(Warehouse::class);
        $authorizationFactory = static fn (): WarehouseStoreAuthorization => $model(
            WarehouseStoreAuthorization::class,
        );
        $service = static fn (): WarehouseAuthorizationService => new WarehouseAuthorizationService(
            stores: $catalog,
            warehouseFactory: $warehouseFactory,
            authorizationFactory: $authorizationFactory,
        );

        try {
            $normal = $this->createWarehouse(
                $warehouseFactory,
                0,
                'NORMAL-PHYSICAL',
                Warehouse::MODE_NORMAL,
                false,
            );
            $default = $this->createWarehouse(
                $warehouseFactory,
                0,
                'TEST-DEFAULT',
                Warehouse::MODE_TEST,
                true,
            );
            $otherDefault = $this->createWarehouse(
                $warehouseFactory,
                0,
                'TEST-OTHER',
                Warehouse::MODE_TEST,
                false,
                true,
            );
            $otherWebsite = $this->createWarehouse(
                $warehouseFactory,
                1,
                'OTHER-WEBSITE',
                Warehouse::MODE_NORMAL,
                false,
            );

            $forged = $service()->assertBindAllowed([
                'website_id' => 0,
                'store_id' => 20,
                'store_mode' => 'normal',
                'warehouse_id' => $normal,
            ]);
            self::assertSame(WarehouseAuthorizationService::ERROR_MODE_MISMATCH, $forged['error']);
            self::assertSame(0, $this->authorizationCount($connector));

            $crossWebsite = $service()->assertBindAllowed([
                'website_id' => 0,
                'store_id' => 20,
                'warehouse_id' => $otherWebsite,
            ]);
            $disabled = $service()->assertBindAllowed([
                'website_id' => 0,
                'store_id' => 23,
                'warehouse_id' => $default,
            ]);
            $unknown = $service()->assertBindAllowed([
                'website_id' => 0,
                'store_id' => 24,
                'warehouse_id' => $default,
            ]);
            self::assertSame(
                WarehouseAuthorizationService::ERROR_WEBSITE_MISMATCH,
                $crossWebsite['error'],
            );
            self::assertSame(
                WarehouseAuthorizationService::ERROR_STORE_INACTIVE,
                $disabled['error'],
            );
            self::assertSame(
                WarehouseAuthorizationService::ERROR_STORE_MODE_INVALID,
                $unknown['error'],
            );
            self::assertSame(0, $this->authorizationCount($connector));

            $accepted = $service()->assertBindAllowed([
                'website_id' => 0,
                'store_id' => 20,
                'store_mode' => 'normal',
                'warehouse_id' => $default,
                'is_default' => true,
            ]);
            $replayed = $service()->assertBindAllowed([
                'website_id' => 0,
                'store_id' => 20,
                'store_mode' => 'normal',
                'warehouse_id' => $default,
                'is_default' => true,
            ]);
            self::assertTrue($accepted['ok']);
            self::assertTrue($replayed['ok']);
            self::assertSame(1, $this->authorizationCount($connector));

            $resolver = new DefaultLogicalWarehouseResolver(
                stores: $catalog,
                warehouseFactory: $warehouseFactory,
                authorizationFactory: $authorizationFactory,
            );
            self::assertSame($default, (int) $resolver->resolve(0, 20, 'normal')['warehouse_id']);

            $conflict = $service()->assertBindAllowed([
                'website_id' => 0,
                'store_id' => 20,
                'warehouse_id' => $otherDefault,
                'is_default' => true,
            ]);
            self::assertSame(
                WarehouseAuthorizationService::ERROR_DEFAULT_CONFLICT,
                $conflict['error'],
            );
            self::assertSame(1, $this->authorizationCount($connector));
            self::assertSame(
                $default,
                (int) (new DefaultLogicalWarehouseResolver(
                    stores: $catalog,
                    warehouseFactory: $warehouseFactory,
                    authorizationFactory: $authorizationFactory,
                ))->resolve(0, 20)['warehouse_id'],
            );

            $devAccepted = $service()->assertBindAllowed([
                'website_id' => 0,
                'store_id' => 21,
                'warehouse_id' => $default,
            ]);
            self::assertTrue($devAccepted['ok']);
            self::assertSame(2, $this->authorizationCount($connector));
        } finally {
            $connector->close();
            $connection->close();
            if (is_file($path)) {
                unlink($path);
            }
        }
        self::assertFileDoesNotExist($path);
    }

    /** @return array{0:string,1:ConnectionFactory,2:ConnectorInterface} */
    private function database(): array
    {
        $path = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'weline_p3a001_'
            . bin2hex(random_bytes(8))
            . '.sqlite';
        $connection = ConnectionFactory::getInstance(new ConfigProvider([
            'type' => 'sqlite',
            'database' => '',
            'path' => $path,
            'persistent' => false,
        ]));
        $connector = $connection->getConnector();
        $connector->query(
            'CREATE TABLE weline_inventory_warehouse ('
            . 'warehouse_id INTEGER PRIMARY KEY AUTOINCREMENT, website_id INTEGER NOT NULL, '
            . 'warehouse_code VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL DEFAULT \'\', '
            . 'mode VARCHAR(16) NOT NULL DEFAULT \'normal\', '
            . 'warehouse_type VARCHAR(16) NOT NULL DEFAULT \'physical\', '
            . 'is_default_logical INTEGER NOT NULL DEFAULT 0, '
            . 'default_logical_guard VARCHAR(16), enabled INTEGER NOT NULL DEFAULT 1, '
            . 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'UNIQUE(website_id,warehouse_code), '
            . 'UNIQUE(website_id,mode,default_logical_guard))'
        )->fetch();
        $connector->query(
            'CREATE TABLE weline_inventory_warehouse_store_authorization ('
            . 'authorization_id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'website_id INTEGER NOT NULL, store_id INTEGER NOT NULL, '
            . 'warehouse_id INTEGER NOT NULL, store_mode_snapshot VARCHAR(16) NOT NULL, '
            . 'is_default INTEGER NOT NULL DEFAULT 0, default_guard VARCHAR(16), '
            . 'enabled INTEGER NOT NULL DEFAULT 1, writer_enabled INTEGER NOT NULL DEFAULT 0, '
            . 'authorization_version INTEGER NOT NULL DEFAULT 0, '
            . 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'UNIQUE(website_id,store_id,warehouse_id), '
            . 'UNIQUE(website_id,store_id,default_guard))'
        )->fetch();
        return [$path, $connection, $connector];
    }

    /** @param callable(): Warehouse $factory */
    private function createWarehouse(
        callable $factory,
        int $websiteId,
        string $code,
        string $mode,
        bool $isDefault,
        bool $logical = false,
    ): int {
        $warehouse = $factory();
        $warehouse->setData([
            Warehouse::schema_fields_WEBSITE_ID => $websiteId,
            Warehouse::schema_fields_WAREHOUSE_CODE => $code,
            Warehouse::schema_fields_NAME => $code,
            Warehouse::schema_fields_MODE => $mode,
            Warehouse::schema_fields_WAREHOUSE_TYPE => ($isDefault || $logical)
                ? Warehouse::TYPE_LOGICAL
                : Warehouse::TYPE_PHYSICAL,
            Warehouse::schema_fields_IS_DEFAULT_LOGICAL => $isDefault ? 1 : 0,
            Warehouse::schema_fields_ENABLED => 1,
        ])->save();
        return (int) $warehouse->getId();
    }

    private function authorizationCount(ConnectorInterface $connector): int
    {
        $rows = $connector->query(
            'SELECT COUNT(*) AS total FROM weline_inventory_warehouse_store_authorization',
        )->fetch();
        return (int) $rows[0]['total'];
    }
}

/** @internal test double for the public Websites catalog contract. */
final class WarehouseStoreCatalogStub implements StoreCatalogInterface
{
    /** @var array<int, StoreSummary> */
    private array $stores = [];

    /** @param list<StoreSummary> $stores */
    public function __construct(array $stores)
    {
        foreach ($stores as $store) {
            $this->stores[$store->id] = $store;
        }
    }

    public function byWebsite(int $websiteId): array
    {
        return array_values(array_filter(
            $this->stores,
            static fn (StoreSummary $store): bool => $store->websiteId === $websiteId,
        ));
    }

    public function byCode(int $websiteId, string $storeCode): ?StoreSummary
    {
        foreach ($this->byWebsite($websiteId) as $store) {
            if ($store->code === $storeCode) {
                return $store;
            }
        }
        return null;
    }

    public function byId(int $storeId): ?StoreSummary
    {
        return $this->stores[$storeId] ?? null;
    }

    public function defaultStore(int $websiteId): ?StoreSummary
    {
        foreach ($this->byWebsite($websiteId) as $store) {
            if ($store->isDefault) {
                return $store;
            }
        }
        return null;
    }

    public function all(): array
    {
        return array_values($this->stores);
    }
}
