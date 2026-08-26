<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Connector;
use Weline\Framework\Database\Connection\Adapter\Pgsql\PgsqlIndexName;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Table\Create;
use Weline\Framework\Database\DbManager\ConfigProvider;

final class PgsqlCreateTableTest extends TestCase
{
    public function testBooleanDefaultsUsePostgreSqlLiteralsForAddAndModify(): void
    {
        $connector = new Connector(new ConfigProvider([
            'type' => 'pgsql',
            'database' => 'unit',
            'prefix' => '',
        ]));
        $column = [
            'name' => 'is_anonymous',
            'type' => 'boolean',
            'nullable' => false,
            'default' => 0,
            'autoIncrement' => false,
            'primaryKey' => false,
            'comment' => '',
        ];

        $add = $connector->buildAlterAddColumnSql('review_product', $column);
        self::assertStringContainsString('DEFAULT false', $add);
        self::assertStringNotContainsString('DEFAULT 0', $add);

        $modify = $connector->buildAlterModifyColumnSql('review_product', $column, $column);
        self::assertStringContainsString('SET "is_anonymous" = false', $modify);
        self::assertStringContainsString('SET DEFAULT false', $modify);
        self::assertStringNotContainsString('SET DEFAULT 0', $modify);
    }

    public function testAutoIncrementWithoutPrimaryKeyDoesNotAddInlinePrimaryKey(): void
    {
        $create = new Create();

        $create->addColumn('id', 'int', 11, 'AUTO_INCREMENT', '');

        self::assertStringNotContainsString('PRIMARY KEY', $this->columnDefinition($create, 'id'));
        self::assertStringContainsString('"id" SERIAL', $this->columnDefinition($create, 'id'));
    }

    public function testAutoIncrementWithExplicitPrimaryKeyKeepsInlinePrimaryKey(): void
    {
        $create = new Create();

        $create->addColumn('id', 'int', 11, 'PRIMARY KEY AUTO_INCREMENT', '');

        self::assertStringContainsString('PRIMARY KEY', $this->columnDefinition($create, 'id'));
        self::assertStringContainsString('"id" SERIAL', $this->columnDefinition($create, 'id'));
    }

    public function testCreateUsesCanonicalPhysicalIndexNameForLongQualifiedTable(): void
    {
        $create = new Create();
        $table = '"product_copy_test_990914b864e24efd"."product_ws_0_product"';
        $this->setInheritedProperty($create, 'table', $table);

        $create->addIndex('UNIQUE', 'uk_global_product_uuid', ['global_product_uuid']);

        $expected = PgsqlIndexName::canonicalPhysical($table, 'uk_global_product_uuid');
        self::assertStringContainsString('"' . $expected . '"', $this->firstIndexSql($create));
        self::assertLessThanOrEqual(PgsqlIndexName::MAX_IDENTIFIER_BYTES, strlen($expected));
    }

    private function columnDefinition(Create $create, string $column): string
    {
        $ref = new \ReflectionClass($create);
        $property = $ref->getParentClass()->getProperty('fields');
        $property->setAccessible(true);

        $fields = $property->getValue($create);

        return (string) ($fields[$column]['definition'] ?? '');
    }

    private function firstIndexSql(Create $create): string
    {
        $indexes = $this->inheritedProperty($create, 'indexes');

        return (string)($indexes[0] ?? '');
    }

    private function setInheritedProperty(Create $create, string $name, mixed $value): void
    {
        $reflection = new \ReflectionClass($create);
        $property = $reflection->getParentClass()->getProperty($name);
        $property->setValue($create, $value);
    }

    /** @return array<mixed> */
    private function inheritedProperty(Create $create, string $name): array
    {
        $reflection = new \ReflectionClass($create);
        $property = $reflection->getParentClass()->getProperty($name);

        return (array)$property->getValue($create);
    }
}
