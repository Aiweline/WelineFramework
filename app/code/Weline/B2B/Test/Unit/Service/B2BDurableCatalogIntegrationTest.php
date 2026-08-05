<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\B2B\Model\CustomerGroup;
use Weline\B2B\Model\CustomerGroupMembershipRecord;
use Weline\B2B\Model\CustomerGroupRecord;
use Weline\B2B\Model\PriceList;
use Weline\B2B\Model\PriceListItemRecord;
use Weline\B2B\Model\PriceListRecord;
use Weline\B2B\Service\B2BPriceEngine;
use Weline\B2B\Service\B2BRolloutGate;
use Weline\B2B\Service\CustomerGroupStore;
use Weline\B2B\Service\PriceListStore;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Framework\Database\Service\DatabaseTransactionRunner;
use Weline\Framework\Database\Transaction\TransactionCoordinator;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;

/** TASK-P4C-001: durable scoped membership and immutable price-list revisions. */
final class B2BDurableCatalogIntegrationTest extends TestCase
{
    public function testFactsSurviveFreshStoresAndCannotBeForged(): void
    {
        self::assertContains('sqlite', PDO::getAvailableDrivers());
        foreach ([
            CustomerGroupRecord::class,
            CustomerGroupMembershipRecord::class,
            PriceListRecord::class,
            PriceListItemRecord::class,
        ] as $modelClass) {
            self::assertNotNull((new SchemaParser())->parse($modelClass));
        }

        $dbPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'weline_p4c001_b2b_' . bin2hex(random_bytes(8)) . '.sqlite';
        $connection = ConnectionFactory::getInstance(new ConfigProvider([
            'type' => 'sqlite',
            'database' => '',
            'path' => $dbPath,
            'persistent' => false,
        ]));
        $connector = $connection->getConnector();
        $this->createTables($connector);
        $transactions = new DatabaseTransactionRunner(new TransactionCoordinator());

        $groupFactory = $this->factory($connection, CustomerGroupRecord::class);
        $membershipFactory = $this->factory($connection, CustomerGroupMembershipRecord::class);
        $listFactory = $this->factory($connection, PriceListRecord::class);
        $itemFactory = $this->factory($connection, PriceListItemRecord::class);

        try {
            [$groups, $lists, $engine] = $this->runtime(
                $groupFactory,
                $membershipFactory,
                $listFactory,
                $itemFactory,
                $transactions,
            );
            $groups->put(new CustomerGroup('g-default', 0, 'dealer'));
            $groups->put(new CustomerGroup('g-second', 1, 'dealer'));
            $groups->assignCustomer('cust-durable', 'g-default');
            $groups->assignCustomer('cust-durable', 'g-second');
            $lists->put(new PriceList('pl-default', 'g-default', 0, 1, ['SKU-A' => 800]));
            $lists->put(new PriceList('pl-default', 'g-default', 0, 2, ['SKU-A' => 700]));
            $lists->put(new PriceList('pl-second', 'g-second', 1, 1, ['SKU-A' => 600]));

            self::assertSame(2, $groups->countGroups());
            self::assertSame(3, $lists->countRevisions());
            self::assertSame(700, $engine->resolve([
                'customer_id' => 'cust-durable',
                'website_id' => 0,
                'sku' => 'SKU-A',
                'retail_amount_minor' => 1000,
            ])['amount_minor']);

            [, , $fresh] = $this->runtime(
                $groupFactory,
                $membershipFactory,
                $listFactory,
                $itemFactory,
                $transactions,
            );
            $websiteZero = $fresh->resolve([
                'customer_id' => 'cust-durable',
                'website_id' => 0,
                'sku' => 'SKU-A',
                'retail_amount_minor' => 1000,
            ]);
            self::assertTrue($websiteZero['ok']);
            self::assertSame('g-default', $websiteZero['group_id']);
            self::assertSame('pl-default', $websiteZero['price_list_id']);
            self::assertSame(2, $websiteZero['version']);
            self::assertSame(700, $websiteZero['amount_minor']);

            $websiteOne = $fresh->resolve([
                'customer_id' => 'cust-durable',
                'website_id' => 1,
                'sku' => 'SKU-A',
                'retail_amount_minor' => 1000,
            ]);
            self::assertSame('g-second', $websiteOne['group_id']);
            self::assertSame(600, $websiteOne['amount_minor']);

            $forgedGroup = $fresh->resolve([
                'customer_id' => 'cust-retail',
                'group_id' => 'g-default',
                'website_id' => 0,
                'sku' => 'SKU-A',
                'retail_amount_minor' => 1000,
            ]);
            self::assertFalse($forgedGroup['ok']);
            self::assertSame(B2BPriceEngine::ERROR_GROUP_OVERRIDE, $forgedGroup['error']);

            $forgedList = $fresh->resolve([
                'customer_id' => 'cust-retail',
                'website_id' => 0,
                'sku' => 'SKU-A',
                'retail_amount_minor' => 1000,
                'claimed_price_list_id' => 'pl-default',
                'claimed_version' => 2,
            ]);
            self::assertFalse($forgedList['ok']);
            self::assertSame(B2BPriceEngine::ERROR_FORGED_PRICE_LIST, $forgedList['error']);
            self::assertSame(0, $fresh->orderCount());

            try {
                $lists->put(new PriceList('pl-default', 'g-default', 0, 2, ['SKU-A' => 1]));
                self::fail('expected immutable revision conflict');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('不可覆盖', $exception->getMessage());
            }
        } finally {
            $connector->close();
            $connection->close();
            if (is_file($dbPath)) {
                unlink($dbPath);
            }
        }
        self::assertFileDoesNotExist($dbPath);
    }

    /**
     * @param callable(): CustomerGroupRecord $groupFactory
     * @param callable(): CustomerGroupMembershipRecord $membershipFactory
     * @param callable(): PriceListRecord $listFactory
     * @param callable(): PriceListItemRecord $itemFactory
     * @return array{CustomerGroupStore,PriceListStore,B2BPriceEngine}
     */
    private function runtime(
        callable $groupFactory,
        callable $membershipFactory,
        callable $listFactory,
        callable $itemFactory,
        DatabaseTransactionRunner $transactions,
    ): array {
        $groups = new CustomerGroupStore($groupFactory, $membershipFactory);
        $lists = new PriceListStore($listFactory, $itemFactory, $transactions);
        $gate = B2BRolloutGate::forTestingConfiguration();
        $gate->setMode(
            B2BPriceEngine::CAPABILITY,
            CommerceRolloutGateInterface::MODE_SHADOW,
        );
        return [$groups, $lists, new B2BPriceEngine($groups, $lists, $gate)];
    }

    /**
     * @template T of Model
     * @param class-string<T> $modelClass
     * @return \Closure(): T
     */
    private function factory(ConnectionFactory $connection, string $modelClass): \Closure
    {
        return static function () use ($connection, $modelClass): Model {
            $model = new $modelClass();
            $model->setConnection($connection);
            $model->__init();
            return $model;
        };
    }

    private function createTables(ConnectorInterface $connector): void
    {
        $queries = [
            'CREATE TABLE weline_b2b_customer_group ('
                . 'group_row_id INTEGER PRIMARY KEY AUTOINCREMENT, group_id VARCHAR(64) NOT NULL UNIQUE, '
                . 'website_id INTEGER NOT NULL, code VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, '
                . 'group_version INTEGER NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, '
                . 'UNIQUE(website_id, code))',
            'CREATE TABLE weline_b2b_customer_group_membership ('
                . 'membership_row_id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id VARCHAR(64) NOT NULL, '
                . 'website_id INTEGER NOT NULL, group_id VARCHAR(64) NOT NULL, membership_version INTEGER NOT NULL, '
                . 'updated_at DATETIME NOT NULL, UNIQUE(customer_id, website_id))',
            'CREATE TABLE weline_b2b_price_list ('
                . 'price_list_row_id INTEGER PRIMARY KEY AUTOINCREMENT, list_id VARCHAR(64) NOT NULL, '
                . 'group_id VARCHAR(64) NOT NULL, website_id INTEGER NOT NULL, version INTEGER NOT NULL, '
                . 'channel_id VARCHAR(64) NULL, active INTEGER NOT NULL, created_at DATETIME NOT NULL, '
                . 'UNIQUE(list_id, version))',
            'CREATE TABLE weline_b2b_price_list_item ('
                . 'price_item_row_id INTEGER PRIMARY KEY AUTOINCREMENT, list_id VARCHAR(64) NOT NULL, '
                . 'list_version INTEGER NOT NULL, sku VARCHAR(128) NOT NULL, amount_minor INTEGER NOT NULL, '
                . 'UNIQUE(list_id, list_version, sku))',
        ];
        foreach ($queries as $query) {
            $connector->query($query)->fetch();
        }
    }
}
