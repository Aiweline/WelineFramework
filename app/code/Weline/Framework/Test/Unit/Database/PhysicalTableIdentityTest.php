<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Api\PhysicalTableIdentity;
use Weline\Framework\Database\Connection\Api\PhysicalTableMetadataInterface;
use Weline\Framework\Database\Connection\Api\Sql\PhysicalTableQueryInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\Connection\Adapter\Mysql\Connector as MysqlConnector;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Connector as PgsqlConnector;
use Weline\Framework\Database\Connection\Adapter\Sqlite\Connector as SqliteConnector;

final class PhysicalTableIdentityTest extends TestCase
{
    public function testIdentityKeepsValidatedSegmentsAndCanonicalName(): void
    {
        $identity = new PhysicalTableIdentity('tenant_42', 'unit_probe');

        self::assertSame('tenant_42', $identity->namespace());
        self::assertSame('unit_probe', $identity->table());
        self::assertSame('tenant_42.unit_probe', $identity->canonical());
    }

    /** @dataProvider invalidIdentityProvider */
    public function testIdentityRejectsInvalidSegments(string $namespace, string $table): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PhysicalTableIdentity($namespace, $table);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidIdentityProvider(): iterable
    {
        yield 'empty namespace' => ['', 'unit_probe'];
        yield 'empty table' => ['public', ''];
        yield 'qualified namespace' => ['tenant.extra', 'unit_probe'];
        yield 'qualified table' => ['tenant', 'unit.probe'];
        yield 'quoted namespace' => ['"tenant"', 'unit_probe'];
        yield 'statement payload' => ['tenant;drop', 'unit_probe'];
        yield 'leading digit' => ['42tenant', 'unit_probe'];
    }

    public function testOfficialPgsqlAdapterUsesExactPhysicalIdentityWithoutPrefixing(): void
    {
        $factory = ObjectManager::getInstance(ConnectionFactory::class);
        $connector = $factory->getConnector();
        self::assertSame('pgsql', strtolower($connector->getConfigProvider()->getDbType()));
        self::assertInstanceOf(PhysicalTableMetadataInterface::class, $connector);
        self::assertInstanceOf(PhysicalTableQueryInterface::class, $connector->getQuery());

        $schema = 'weline_physical_' . bin2hex(random_bytes(5));
        $identity = new PhysicalTableIdentity($schema, 'unit_probe');
        $prefixed = new PhysicalTableIdentity($schema, 'w_unit_probe');
        $metadata = $connector;
        $quotedSchema = $connector->quoteIdentifier($schema);
        $connector->query("CREATE SCHEMA {$quotedSchema}")->fetch();

        try {
            $connector->query(
                'CREATE TABLE ' . $metadata->quotePhysicalTable($identity)
                . ' (id INTEGER PRIMARY KEY, marker TEXT NOT NULL)',
            )->fetch();
            $connector->query(
                'CREATE TABLE ' . $metadata->quotePhysicalTable($prefixed)
                . ' (id INTEGER PRIMARY KEY, marker TEXT NOT NULL)',
            )->fetch();
            $connector->query(
                'INSERT INTO ' . $metadata->quotePhysicalTable($identity) . " VALUES (1, 'exact')",
            )->fetch();
            $connector->query(
                'INSERT INTO ' . $metadata->quotePhysicalTable($prefixed) . " VALUES (1, 'sentinel')",
            )->fetch();

            $rows = $connector->getQuery()->clearQuery()
                ->tablePhysical($identity)
                ->fields(['marker'])
                ->select()
                ->fetch();

            self::assertSame('exact', $rows[0]['marker'] ?? null);
            self::assertTrue($metadata->physicalTableExists($identity));
            self::assertTrue($metadata->physicalTableExists($prefixed));
            self::assertSame(
                $connector->quoteIdentifier($schema) . '.' . $connector->quoteIdentifier('unit_probe'),
                $metadata->quotePhysicalTable($identity),
            );
            self::assertStringContainsString('CREATE TABLE', $metadata->getPhysicalCreateTableSql($identity));
            self::assertContains('marker', array_column($metadata->getPhysicalTableColumns($identity), 'name'));

            $metadata->dropPhysicalTableIfExists($identity);
            self::assertFalse($metadata->physicalTableExists($identity));
            self::assertTrue($metadata->physicalTableExists($prefixed));
        } finally {
            $connector->query("DROP SCHEMA {$quotedSchema} CASCADE")->fetch();
        }
    }

    public function testAllOfficialAdaptersPublishOptionalPhysicalMetadataCapability(): void
    {
        self::assertTrue(is_subclass_of(PgsqlConnector::class, PhysicalTableMetadataInterface::class));
        self::assertTrue(is_subclass_of(MysqlConnector::class, PhysicalTableMetadataInterface::class));
        self::assertTrue(is_subclass_of(SqliteConnector::class, PhysicalTableMetadataInterface::class));
    }
}
