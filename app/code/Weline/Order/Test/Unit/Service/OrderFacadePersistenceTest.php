<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Order\Api\Data\CreateCheckoutGroupCommand;
use Weline\Order\Model\CheckoutGroup;
use Weline\Order\Model\DisplayNumberRegistry;
use Weline\Order\Model\FulfillmentUnit;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderItem;
use Weline\Order\Service\DisplayNumberAllocator;
use Weline\Order\Service\DisplayNumberLookup;
use Weline\Order\Service\OrderFacade;
use Weline\Order\Service\OrderFacadeConflictException;
use Weline\Order\Service\OrderFacadeStoreInterface;
use Weline\Order\Service\OrmOrderFacadeStore;

/**
 * TEST-P2D-01/02 database persistence and idempotency-race evidence.
 */
final class OrderFacadePersistenceTest extends TestCase
{
    public function testRealSqlitePersistsOneGroupAndReplaysAcrossFacadeInstances(): void
    {
        [$path, $connection, $connector, $store, $allocator] = $this->database();
        try {
            $firstFacade = $this->facade($store, $allocator);
            $command = $this->command('sqlite-idem', hash('sha256', 'sqlite-idem'));
            $created = $firstFacade->create($command);

            $secondFacade = $this->facade($store, $allocator);
            $replayed = $secondFacade->create($command);
            self::assertFalse($created->replayed);
            self::assertTrue($replayed->replayed);
            self::assertSame($created->checkoutGroupUuid, $replayed->checkoutGroupUuid);
            self::assertSame($created->orderUuids, $replayed->orderUuids);
            self::assertCount(2, $created->orderUuids);
            self::assertSame(350, $created->totals['grand_total_minor']);

            $read = $secondFacade->get($created->orderUuids[0]);
            self::assertSame($created->checkoutGroupUuid, $read->checkoutGroupUuid);
            self::assertSame(OrderFacade::STATUS_PENDING, $read->status);
            self::assertSame(0, $read->websiteId);
            self::assertSame(1, $read->storeId);
            self::assertSame('SZ', $read->shipping['address']['city'] ?? null);
            self::assertSame('Road 1', $read->shipping['address']['address1'] ?? null);

            self::assertSame(1, $this->tableCount($connector, 'weline_checkout_group'));
            self::assertSame(2, $this->tableCount($connector, 'weline_order'));
            self::assertSame(2, $this->tableCount($connector, 'weline_order_item'));
            self::assertSame(2, $this->tableCount($connector, 'weline_fulfillment_unit'));
            self::assertSame(2, $this->tableCount($connector, 'weline_display_number_registry'));
            $group = $secondFacade->getGroup($created->checkoutGroupUuid);
            self::assertCount(1, $group['orders'][0]['fulfillment_units']);
            self::assertCount(1, $group['orders'][1]['fulfillment_units']);
            self::assertSame(1, $group['orders'][0]['fulfillment_units'][0]['qty_minor']);
            self::assertSame(2, $group['orders'][1]['fulfillment_units'][0]['qty_minor']);
            self::assertSame(50, $group['orders'][0]['snapshots']['shipping']['amount_minor']);
            self::assertSame(0, $group['orders'][1]['snapshots']['shipping']['amount_minor']);
            self::assertSame('stub_zero', $group['orders'][0]['snapshots']['tax']['mode']);

            try {
                $secondFacade->create($this->command('sqlite-idem', hash('sha256', 'different')));
                self::fail('different hash must conflict');
            } catch (OrderFacadeConflictException $exception) {
                self::assertSame(OrderFacade::ERROR_HASH_CONFLICT, $exception->errorCode());
            }
            self::assertSame(1, $this->tableCount($connector, 'weline_checkout_group'));
        } finally {
            $this->cleanup($path, $connection, $connector);
        }
    }

    public function testPersistFailureRollsBackGroupOrdersItemsFulfillmentAndDisplayNumbers(): void
    {
        [$path, $connection, $connector, $store, $allocator] = $this->database(rejectSecondQty: true);
        try {
            $facade = $this->facade($store, $allocator);
            try {
                $facade->create($this->command('sqlite-rollback', hash('sha256', 'sqlite-rollback')));
                self::fail('item constraint must fail the group transaction');
            } catch (OrderFacadeConflictException $exception) {
                self::assertSame(OrderFacade::ERROR_COMMIT_FAILED, $exception->errorCode());
            }

            self::assertSame(0, $this->tableCount($connector, 'weline_checkout_group'));
            self::assertSame(0, $this->tableCount($connector, 'weline_order'));
            self::assertSame(0, $this->tableCount($connector, 'weline_order_item'));
            self::assertSame(0, $this->tableCount($connector, 'weline_fulfillment_unit'));
            self::assertSame(0, $this->tableCount($connector, 'weline_display_number_registry'));
        } finally {
            $this->cleanup($path, $connection, $connector);
        }
    }

    public function testUniqueKeyRaceConvergesToReplayOrStableHashConflict(): void
    {
        foreach ([false, true] as $differentHash) {
            $winnerHash = $differentHash
                ? hash('sha256', 'winner')
                : hash('sha256', 'race');
            $store = new class($winnerHash) implements OrderFacadeStoreInterface {
                private int $reads = 0;
                /** @var array<string, mixed>|null */
                private ?array $winner = null;

                public function __construct(private readonly string $winnerHash)
                {
                }

                public function findGroupByIdempotencyKey(string $idempotencyKey): ?array
                {
                    $this->reads++;
                    return $this->reads === 1 ? null : $this->winner;
                }

                public function findGroup(string $checkoutGroupUuid): ?array
                {
                    return $this->winner;
                }

                public function findOrder(string $orderUuid): ?array
                {
                    return null;
                }

                public function persist(array $group): void
                {
                    $group['checkout_group_uuid'] = 'winner-group';
                    $group['order_uuids'] = ['winner-order'];
                    $group['request_hash'] = $this->winnerHash;
                    $group['orders'][0]['order_uuid'] = 'winner-order';
                    $group['orders'] = [$group['orders'][0]];
                    $group['shipping_charge_owner_order_uuid'] = 'winner-order';
                    $this->winner = $group;
                    throw new \RuntimeException('simulated unique idempotency race');
                }
            };
            $allocator = DisplayNumberAllocator::forTesting();
            $facade = $this->facade($store, $allocator);
            try {
                $result = $facade->create(new CreateCheckoutGroupCommand(
                    idempotencyKey: 'race',
                    requestHash: hash('sha256', 'race'),
                    websiteId: 0,
                    storeId: 1,
                    lines: [['name' => 'A', 'qty_minor' => 1, 'unit_price_minor' => 100]],
                    shippingAmountMinor: 10,
                ));
                self::assertFalse($differentHash);
                self::assertTrue($result->replayed);
                self::assertSame('winner-group', $result->checkoutGroupUuid);
            } catch (OrderFacadeConflictException $exception) {
                self::assertTrue(
                    $differentHash,
                    $exception->errorCode() . ': ' . $exception->getMessage(),
                );
                self::assertSame(OrderFacade::ERROR_HASH_CONFLICT, $exception->errorCode());
            }
            self::assertSame([], $allocator->all(), 'loser display-number claim must be compensated');
        }
    }

    private function facade(
        OrderFacadeStoreInterface $store,
        DisplayNumberAllocator $allocator,
    ): OrderFacade {
        return new OrderFacade(
            displayNumbers: $allocator,
            displayLookup: new DisplayNumberLookup($allocator),
            dbStore: $store,
        );
    }

    private function command(string $key, string $hash): CreateCheckoutGroupCommand
    {
        return new CreateCheckoutGroupCommand(
            idempotencyKey: $key,
            requestHash: $hash,
            websiteId: 0,
            storeId: 1,
            currency: 'CNY',
            lines: [
                ['name' => 'A', 'qty_minor' => 1, 'unit_price_minor' => 100, 'split_key' => 'a'],
                ['name' => 'B', 'qty_minor' => 2, 'unit_price_minor' => 100, 'split_key' => 'b'],
            ],
            shippingMethod: 'flat',
            shippingAmountMinor: 50,
            shippingAddress: [
                'name' => 'Buyer',
                'phone' => '13800138000',
                'country_code' => 'CN',
                'province' => 'GD',
                'city' => 'SZ',
                'address1' => 'Road 1',
                'postal_code' => '518000',
            ],
        );
    }

    /**
     * @return array{string,ConnectionFactory,ConnectorInterface,OrmOrderFacadeStore,DisplayNumberAllocator}
     */
    private function database(bool $rejectSecondQty = false): array
    {
        self::assertContains('sqlite', PDO::getAvailableDrivers());
        $path = sys_get_temp_dir() . '/weline_order_facade_' . bin2hex(random_bytes(8)) . '.sqlite';
        $connection = ConnectionFactory::getInstance(new ConfigProvider([
            'type' => 'sqlite',
            'database' => '',
            'path' => $path,
            'persistent' => false,
        ]));
        $connector = $connection->getConnector();
        $this->createTables($connector, $rejectSecondQty);

        $model = static function (string $class) use ($connection): object {
            $instance = new $class();
            $instance->setConnection($connection);
            $instance->__init();
            return $instance;
        };
        /** @var CheckoutGroup $group */
        $group = $model(CheckoutGroup::class);
        /** @var Order $order */
        $order = $model(Order::class);
        /** @var OrderItem $item */
        $item = $model(OrderItem::class);
        /** @var FulfillmentUnit $fulfillmentUnit */
        $fulfillmentUnit = $model(FulfillmentUnit::class);
        /** @var DisplayNumberRegistry $registry */
        $registry = $model(DisplayNumberRegistry::class);
        $store = new OrmOrderFacadeStore($group, $order, $item, $fulfillmentUnit);
        $next = 1000;
        $allocator = new DisplayNumberAllocator(
            useMemory: false,
            randomInt: static function () use (&$next): int {
                return $next++;
            },
            registryModel: $registry,
        );

        return [$path, $connection, $connector, $store, $allocator];
    }

    private function createTables(ConnectorInterface $connector, bool $rejectSecondQty): void
    {
        $connector->query(
            'CREATE TABLE weline_checkout_group ('
            . 'checkout_group_id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'checkout_group_uuid VARCHAR(36) NOT NULL UNIQUE, '
            . 'website_id INTEGER NOT NULL, store_id INTEGER NOT NULL, currency VARCHAR(10) NOT NULL, '
            . 'status VARCHAR(32) NOT NULL, idempotency_key VARCHAR(128) NOT NULL UNIQUE, '
            . 'request_hash VARCHAR(64) NOT NULL, shipping_owner_order_uuid VARCHAR(36), '
            . 'grand_total_minor INTEGER NOT NULL, money_snapshot_json TEXT, scope_snapshot_json TEXT, '
            . 'shipping_snapshot_json TEXT, tax_snapshot_json TEXT)'
        )->fetch();
        $connector->query(
            'CREATE TABLE weline_order ('
            . 'order_id INTEGER PRIMARY KEY AUTOINCREMENT, order_number VARCHAR(64) NOT NULL UNIQUE, '
            . 'order_uuid VARCHAR(36) UNIQUE, checkout_group_uuid VARCHAR(36), '
            . 'website_id INTEGER, store_id INTEGER, customer_id INTEGER, '
            . 'status VARCHAR(50) NOT NULL, state VARCHAR(50) NOT NULL, currency VARCHAR(10) NOT NULL, '
            . 'subtotal DECIMAL(20,2) NOT NULL, shipping_amount DECIMAL(20,2) NOT NULL, '
            . 'tax_amount DECIMAL(20,2) NOT NULL, grand_total DECIMAL(20,2) NOT NULL, '
            . 'source_module VARCHAR(100) NOT NULL, shipping_method VARCHAR(100), shipping_address TEXT, '
            . 'money_snapshot_json TEXT, catalog_snapshot_json TEXT, scope_snapshot_json TEXT, '
            . 'tax_snapshot_json TEXT, shipping_snapshot_json TEXT, '
            . 'is_shipping_charge_owner INTEGER NOT NULL DEFAULT 0, split_key VARCHAR(64), '
            . 'state_version INTEGER NOT NULL DEFAULT 0)'
        )->fetch();
        $qtyCheck = $rejectSecondQty ? ' CHECK(qty_minor < 2)' : '';
        $connector->query(
            'CREATE TABLE weline_order_item ('
            . 'item_id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL, product_id INTEGER, '
            . 'product_sku VARCHAR(100), product_name VARCHAR(255) NOT NULL, qty_ordered DECIMAL(20,2), '
            . 'price DECIMAL(20,2), row_total DECIMAL(20,2), tax_amount DECIMAL(20,2), item_uuid VARCHAR(36), '
            . 'order_uuid VARCHAR(36), offer_id INTEGER, qty_minor INTEGER NOT NULL' . $qtyCheck . ', '
            . 'unit_price_minor INTEGER NOT NULL, catalog_line_snapshot_json TEXT, tax_snapshot_json TEXT)'
        )->fetch();
        $connector->query(
            'CREATE TABLE weline_fulfillment_unit ('
            . 'fulfillment_unit_id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'fulfillment_unit_uuid VARCHAR(36) NOT NULL UNIQUE, '
            . 'order_uuid VARCHAR(36) NOT NULL, checkout_group_uuid VARCHAR(36), '
            . 'status VARCHAR(32) NOT NULL, warehouse_id INTEGER, '
            . 'warehouse_source VARCHAR(24), allocations_json TEXT, qty_minor INTEGER NOT NULL, '
            . 'fulfilled_qty_minor INTEGER NOT NULL DEFAULT 0, fulfillment_version INTEGER NOT NULL DEFAULT 0)'
        )->fetch();
        $connector->query(
            'CREATE TABLE weline_display_number_registry ('
            . 'registry_id INTEGER PRIMARY KEY AUTOINCREMENT, website_id INTEGER NOT NULL, '
            . 'store_id INTEGER NOT NULL, number_kind VARCHAR(32) NOT NULL, '
            . 'display_number VARCHAR(32) NOT NULL, entity_uuid VARCHAR(36) NOT NULL, '
            . 'UNIQUE(website_id,store_id,number_kind,display_number))'
        )->fetch();
    }

    private function tableCount(ConnectorInterface $connector, string $table): int
    {
        $rows = $connector->query('SELECT COUNT(*) AS total FROM ' . $table)->fetch();
        return (int)($rows[0]['total'] ?? 0);
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
