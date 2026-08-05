<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\B2B\Model\B2BOrderPriceSnapshotRecord;
use Weline\B2B\Model\B2BQuoteToken;
use Weline\B2B\Model\B2BQuoteTokenRecord;
use Weline\B2B\Model\CustomerGroup;
use Weline\B2B\Model\CustomerGroupMembershipRecord;
use Weline\B2B\Model\CustomerGroupRecord;
use Weline\B2B\Model\PriceList;
use Weline\B2B\Model\PriceListItemRecord;
use Weline\B2B\Model\PriceListRecord;
use Weline\B2B\Service\B2BAclGuard;
use Weline\B2B\Service\B2BCheckoutRecheckService;
use Weline\B2B\Service\B2BOrderSnapshotStore;
use Weline\B2B\Service\B2BPriceEngine;
use Weline\B2B\Service\B2BQuoteTokenStore;
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

/**
 * Isolated SQLite portability regression only.
 *
 * PostgreSQL remains the canonical TASK-P4C-002 acceptance database.
 */
final class B2BDurableQuoteSnapshotIntegrationTest extends TestCase
{
    public function testQuoteAndSnapshotSurviveFreshRuntimeAndRemainWriteOnce(): void
    {
        self::assertContains('sqlite', PDO::getAvailableDrivers());
        foreach ([B2BQuoteTokenRecord::class, B2BOrderPriceSnapshotRecord::class] as $modelClass) {
            self::assertNotNull((new SchemaParser())->parse($modelClass));
        }

        $dbPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'weline_p4c002_b2b_' . bin2hex(random_bytes(8)) . '.sqlite';
        $connection = ConnectionFactory::getInstance(new ConfigProvider([
            'type' => 'sqlite',
            'database' => '',
            'path' => $dbPath,
            'persistent' => false,
        ]));
        $connector = $connection->getConnector();
        $this->createTables($connector);
        $transactions = new DatabaseTransactionRunner(new TransactionCoordinator());

        $factories = [
            'group' => $this->factory($connection, CustomerGroupRecord::class),
            'membership' => $this->factory($connection, CustomerGroupMembershipRecord::class),
            'list' => $this->factory($connection, PriceListRecord::class),
            'item' => $this->factory($connection, PriceListItemRecord::class),
            'quote' => $this->factory($connection, B2BQuoteTokenRecord::class),
            'snapshot' => $this->factory($connection, B2BOrderPriceSnapshotRecord::class),
        ];
        $now = 1_700_100_000;
        $clock = static function () use (&$now): int {
            return $now;
        };

        try {
            [$groups, $lists, $checkout] = $this->runtime(
                $factories,
                $transactions,
                $clock,
            );
            $groups->put(new CustomerGroup('g-durable-submit', 0, 'durable-submit'));
            $groups->assignCustomer('cust-durable-submit', 'g-durable-submit');
            $lists->put(new PriceList(
                'pl-durable-submit',
                'g-durable-submit',
                0,
                1,
                ['SKU-A' => 800],
            ));

            $quote = $checkout->issueQuote([
                'customer_id' => 'cust-durable-submit',
                'website_id' => 0,
                'sku' => 'SKU-A',
                'retail_amount_minor' => 1000,
            ]);
            self::assertTrue($quote['ok']);
            self::assertFalse($checkout->quotes()->isMemory());
            self::assertFalse($checkout->snapshots()->isMemory());
            self::assertSame(1, $checkout->quotes()->count());
            self::assertSame(0, $checkout->acceptedOrderCount());
            $tokenId = (string)$quote['token']['token_id'];

            [, , $freshCheckout] = $this->runtime($factories, $transactions, $clock);
            $accepted = $freshCheckout->submit(
                $tokenId,
                'cust-durable-submit',
                0,
                'order-durable-submit',
            );
            self::assertTrue($accepted['ok']);
            self::assertSame(800, $accepted['snapshot']['amount_minor']);
            self::assertSame(1, $freshCheckout->acceptedOrderCount());

            [$freshGroups, $freshLists, $reader] = $this->runtime(
                $factories,
                $transactions,
                $clock,
            );
            $storedToken = $reader->quotes()->get($tokenId);
            self::assertNotNull($storedToken);
            self::assertSame(B2BQuoteToken::STATUS_CONSUMED, $storedToken->status());
            self::assertSame('order-durable-submit', $storedToken->consumedOrderRef());
            $frozen = $reader->readSnapshot(
                'order-durable-submit',
                'cust-durable-submit',
                0,
            );
            self::assertNotNull($frozen);
            $frozenHash = (string)$frozen['hash'];

            $freshLists->put(new PriceList(
                'pl-durable-submit',
                'g-durable-submit',
                0,
                2,
                ['SKU-A' => 1],
            ));
            $again = $reader->readSnapshot(
                'order-durable-submit',
                'cust-durable-submit',
                0,
            );
            self::assertSame(800, $again['amount_minor']);
            self::assertSame(1, $again['version']);
            self::assertSame($frozenHash, $again['hash']);

            $replay = $reader->submit(
                $tokenId,
                'cust-durable-submit',
                0,
                'order-durable-replay',
            );
            self::assertFalse($replay['ok']);
            self::assertSame(B2BCheckoutRecheckService::ERROR_QUOTE_NOT_OPEN, $replay['error']);
            self::assertSame(1, $reader->acceptedOrderCount());

            $second = $reader->issueQuote([
                'customer_id' => 'cust-durable-submit',
                'website_id' => 0,
                'sku' => 'SKU-A',
                'retail_amount_minor' => 1000,
            ]);
            $now += 61;
            [, , $expiredRuntime] = $this->runtime($factories, $transactions, $clock);
            $expired = $expiredRuntime->submit(
                (string)$second['token']['token_id'],
                'cust-durable-submit',
                0,
                'order-durable-expired',
            );
            self::assertFalse($expired['ok']);
            self::assertSame(B2BCheckoutRecheckService::ERROR_QUOTE_EXPIRED, $expired['error']);
            self::assertSame(1, $expiredRuntime->acceptedOrderCount());

            self::assertNotNull($freshGroups->groupForCustomer('cust-durable-submit', 0));
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
     * @param array<string,callable():Model> $factories
     * @return array{CustomerGroupStore,PriceListStore,B2BCheckoutRecheckService}
     */
    private function runtime(
        array $factories,
        DatabaseTransactionRunner $transactions,
        callable $clock,
    ): array {
        $groups = new CustomerGroupStore($factories['group'], $factories['membership']);
        $lists = new PriceListStore($factories['list'], $factories['item'], $transactions);
        $gate = B2BRolloutGate::forTestingConfiguration();
        $gate->setMode(B2BPriceEngine::CAPABILITY, CommerceRolloutGateInterface::MODE_SHADOW);
        $engine = new B2BPriceEngine($groups, $lists, $gate);
        $quotes = new B2BQuoteTokenStore($factories['quote'], $transactions);
        $snapshots = new B2BOrderSnapshotStore($factories['snapshot']);
        return [
            $groups,
            $lists,
            new B2BCheckoutRecheckService(
                $engine,
                $quotes,
                $snapshots,
                new B2BAclGuard($groups),
                $clock,
                60,
            ),
        ];
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
            'CREATE TABLE weline_b2b_quote_token ('
                . 'quote_token_row_id INTEGER PRIMARY KEY AUTOINCREMENT, token_id VARCHAR(64) NOT NULL UNIQUE, '
                . 'customer_id VARCHAR(64) NOT NULL, website_id INTEGER NOT NULL, sku VARCHAR(128) NOT NULL, '
                . 'retail_amount_minor INTEGER NOT NULL, amount_minor INTEGER NOT NULL, source VARCHAR(32) NOT NULL, '
                . 'group_id VARCHAR(64) NULL, price_list_id VARCHAR(64) NULL, list_version INTEGER NULL, '
                . 'channel_id VARCHAR(64) NULL, rule_stack_json TEXT NOT NULL, fingerprint CHAR(64) NOT NULL, '
                . 'issued_at_epoch INTEGER NOT NULL, expires_at_epoch INTEGER NOT NULL, status VARCHAR(16) NOT NULL, '
                . 'consumed_order_ref VARCHAR(64) NULL, consumed_at_epoch INTEGER NULL, created_at DATETIME NOT NULL)',
            'CREATE TABLE weline_b2b_order_price_snapshot ('
                . 'snapshot_row_id INTEGER PRIMARY KEY AUTOINCREMENT, order_ref VARCHAR(64) NOT NULL UNIQUE, '
                . 'token_id VARCHAR(64) NOT NULL UNIQUE, customer_id VARCHAR(64) NOT NULL, website_id INTEGER NOT NULL, '
                . 'sku VARCHAR(128) NOT NULL, retail_amount_minor INTEGER NOT NULL, amount_minor INTEGER NOT NULL, '
                . 'source VARCHAR(32) NOT NULL, group_id VARCHAR(64) NULL, price_list_id VARCHAR(64) NULL, '
                . 'list_version INTEGER NULL, channel_id VARCHAR(64) NULL, rule_stack_json TEXT NOT NULL, '
                . 'payload_hash CHAR(64) NOT NULL, created_at_epoch INTEGER NOT NULL, created_at DATETIME NOT NULL)',
        ];
        foreach ($queries as $query) {
            $connector->query($query)->fetch();
        }
    }
}
