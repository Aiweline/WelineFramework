<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

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
use Weline\Framework\Database\Service\DatabaseTransactionRunner;
use Weline\Framework\Database\Transaction\TransactionCoordinator;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;

/**
 * Canonical PostgreSQL acceptance for durable quote consumption + snapshot.
 *
 * The test creates a dedicated PostgreSQL database, runs through fresh ORM
 * runtimes, then drops the database in finally. Shared tables are untouched.
 */
final class B2BPostgresqlQuoteSnapshotIntegrationTest extends TestCase
{
    public function testCanonicalPgsqlFreshRuntimeIsAtomicAndDurable(): void
    {
        $env = require APP_ETC_PATH . 'env.php';
        $config = $env['db']['master'] ?? $env['sandbox_db']['master'] ?? null;
        self::assertIsArray($config);
        self::assertSame('pgsql', $config['type'] ?? null);
        $config['persistent'] = false;

        $baseConnection = ConnectionFactory::getInstance(new ConfigProvider($config));
        $baseConnector = $baseConnection->getConnector();
        $database = 'weline_p4c002_' . bin2hex(random_bytes(6));
        $baseConnector->query('CREATE DATABASE "' . $database . '"')->fetch();

        $testConfig = $config;
        $testConfig['database'] = $database;
        $connection = ConnectionFactory::getInstance(new ConfigProvider($testConfig));
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
        $now = 1_700_200_000;
        $clock = static function () use (&$now): int {
            return $now;
        };

        try {
            [$groups, $lists, $checkout] = $this->runtime(
                $factories,
                $transactions,
                $clock,
            );
            $groups->put(new CustomerGroup('g-pgsql-submit', 0, 'pgsql-submit'));
            $groups->assignCustomer('cust-pgsql-submit', 'g-pgsql-submit');
            $lists->put(new PriceList(
                'pl-pgsql-submit',
                'g-pgsql-submit',
                0,
                1,
                ['SKU-A' => 640],
            ));
            $quote = $checkout->issueQuote([
                'customer_id' => 'cust-pgsql-submit',
                'website_id' => 0,
                'sku' => 'SKU-A',
                'retail_amount_minor' => 1000,
            ]);
            self::assertTrue($quote['ok']);
            $tokenId = (string)$quote['token']['token_id'];

            [, , $fresh] = $this->runtime($factories, $transactions, $clock);
            $accepted = $fresh->submit(
                $tokenId,
                'cust-pgsql-submit',
                0,
                'order-pgsql-submit',
            );
            self::assertTrue($accepted['ok']);
            self::assertSame(640, $accepted['snapshot']['amount_minor']);

            [, , $reader] = $this->runtime($factories, $transactions, $clock);
            self::assertSame(
                B2BQuoteToken::STATUS_CONSUMED,
                $reader->quotes()->get($tokenId)?->status(),
            );
            $snapshot = $reader->readSnapshot(
                'order-pgsql-submit',
                'cust-pgsql-submit',
                0,
            );
            self::assertNotNull($snapshot);
            self::assertSame(640, $snapshot['amount_minor']);
            self::assertSame(1, $snapshot['version']);
            self::assertSame(1, $reader->acceptedOrderCount());

            $replay = $reader->submit(
                $tokenId,
                'cust-pgsql-submit',
                0,
                'order-pgsql-replay',
            );
            self::assertFalse($replay['ok']);
            self::assertSame(B2BCheckoutRecheckService::ERROR_QUOTE_NOT_OPEN, $replay['error']);
            self::assertSame(1, $reader->acceptedOrderCount());
        } finally {
            $connector->close();
            $connection->close();
            $baseConnector->query('DROP DATABASE IF EXISTS "' . $database . '" WITH (FORCE)')->fetch();
            $baseConnector->close();
            $baseConnection->close();
        }
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
        return [
            $groups,
            $lists,
            new B2BCheckoutRecheckService(
                $engine,
                new B2BQuoteTokenStore($factories['quote'], $transactions),
                new B2BOrderSnapshotStore($factories['snapshot']),
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
            'CREATE TABLE w_weline_b2b_customer_group ('
                . 'group_row_id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, '
                . 'group_id VARCHAR(64) NOT NULL UNIQUE, website_id INTEGER NOT NULL, '
                . 'code VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, group_version BIGINT NOT NULL, '
                . 'created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL, UNIQUE(website_id, code))',
            'CREATE TABLE w_weline_b2b_customer_group_membership ('
                . 'membership_row_id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, '
                . 'customer_id VARCHAR(64) NOT NULL, website_id INTEGER NOT NULL, group_id VARCHAR(64) NOT NULL, '
                . 'membership_version BIGINT NOT NULL, updated_at TIMESTAMP NOT NULL, '
                . 'UNIQUE(customer_id, website_id))',
            'CREATE TABLE w_weline_b2b_price_list ('
                . 'price_list_row_id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, '
                . 'list_id VARCHAR(64) NOT NULL, group_id VARCHAR(64) NOT NULL, website_id INTEGER NOT NULL, '
                . 'version BIGINT NOT NULL, channel_id VARCHAR(64) NULL, active SMALLINT NOT NULL, '
                . 'created_at TIMESTAMP NOT NULL, UNIQUE(list_id, version))',
            'CREATE TABLE w_weline_b2b_price_list_item ('
                . 'price_item_row_id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, '
                . 'list_id VARCHAR(64) NOT NULL, list_version BIGINT NOT NULL, sku VARCHAR(128) NOT NULL, '
                . 'amount_minor BIGINT NOT NULL, UNIQUE(list_id, list_version, sku))',
            'CREATE TABLE w_weline_b2b_quote_token ('
                . 'quote_token_row_id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, '
                . 'token_id VARCHAR(64) NOT NULL UNIQUE, customer_id VARCHAR(64) NOT NULL, '
                . 'website_id INTEGER NOT NULL, sku VARCHAR(128) NOT NULL, retail_amount_minor BIGINT NOT NULL, '
                . 'amount_minor BIGINT NOT NULL, source VARCHAR(32) NOT NULL, group_id VARCHAR(64) NULL, '
                . 'price_list_id VARCHAR(64) NULL, list_version BIGINT NULL, channel_id VARCHAR(64) NULL, '
                . 'rule_stack_json TEXT NOT NULL, fingerprint CHAR(64) NOT NULL, issued_at_epoch BIGINT NOT NULL, '
                . 'expires_at_epoch BIGINT NOT NULL, status VARCHAR(16) NOT NULL, consumed_order_ref VARCHAR(64) NULL, '
                . 'consumed_at_epoch BIGINT NULL, created_at TIMESTAMP NOT NULL)',
            'CREATE TABLE w_weline_b2b_order_price_snapshot ('
                . 'snapshot_row_id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, '
                . 'order_ref VARCHAR(64) NOT NULL UNIQUE, token_id VARCHAR(64) NOT NULL UNIQUE, '
                . 'customer_id VARCHAR(64) NOT NULL, website_id INTEGER NOT NULL, sku VARCHAR(128) NOT NULL, '
                . 'retail_amount_minor BIGINT NOT NULL, amount_minor BIGINT NOT NULL, source VARCHAR(32) NOT NULL, '
                . 'group_id VARCHAR(64) NULL, price_list_id VARCHAR(64) NULL, list_version BIGINT NULL, '
                . 'channel_id VARCHAR(64) NULL, rule_stack_json TEXT NOT NULL, payload_hash CHAR(64) NOT NULL, '
                . 'created_at_epoch BIGINT NOT NULL, created_at TIMESTAMP NOT NULL)',
        ];
        foreach ($queries as $query) {
            $connector->query($query)->fetch();
        }
    }
}
