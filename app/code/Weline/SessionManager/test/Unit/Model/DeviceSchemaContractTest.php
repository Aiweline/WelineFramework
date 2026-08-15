<?php

declare(strict_types=1);

namespace Weline\SessionManager\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Adapter\Mysql\Table\Create as MysqlCreate;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Table\Create as PgsqlCreate;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Framework\Database\Schema\TableSchema;
use Weline\SessionManager\Model\AuthenticatedDevice;
use Weline\SessionManager\Model\RememberedDeviceCredential;

final class DeviceSchemaContractTest extends TestCase
{
    public function testDeviceSchemaUsesPublicIdsAndDigestsWithPortableUniqueBindings(): void
    {
        $schema = (new SchemaParser())->parse(AuthenticatedDevice::class);

        self::assertNotNull($schema);
        self::assertSame('weline_authenticated_device', AuthenticatedDevice::schema_table);
        self::assertStringEndsWith(
            AuthenticatedDevice::schema_table,
            strtolower(str_replace(['"', '`'], '', $schema->tableName)),
        );
        $columns = [];
        foreach ($schema->columns as $column) {
            $columns[$column->name] = $column;
        }
        self::assertArrayHasKey('public_id', $columns);
        self::assertArrayHasKey('session_digest', $columns);
        self::assertArrayNotHasKey('session_id', $columns);
        self::assertSame(43, $columns['public_id']->length);
        self::assertSame(64, $columns['session_digest']->length);

        $indexes = [];
        foreach ($schema->indexes as $index) {
            $indexes[$index->name] = $index;
        }
        self::assertSame('UNIQUE', $indexes['uk_authenticated_device_public_id']->type);
        self::assertSame(['auth_area', 'session_digest'], $indexes['uk_authenticated_device_session']->columns);
        self::assertSame('UNIQUE', $indexes['uk_authenticated_device_session']->type);
    }

    public function testRememberCredentialSchemaAllowsOnlyOneHashedCredentialPerDevice(): void
    {
        $schema = (new SchemaParser())->parse(RememberedDeviceCredential::class);

        self::assertNotNull($schema);
        $columns = [];
        foreach ($schema->columns as $column) {
            $columns[$column->name] = $column;
        }
        self::assertArrayHasKey('token_digest', $columns);
        self::assertArrayNotHasKey('token', $columns);
        self::assertSame(64, $columns['token_digest']->length);

        $indexes = [];
        foreach ($schema->indexes as $index) {
            $indexes[$index->name] = $index;
        }
        self::assertSame(['device_id'], $indexes['uk_remembered_device']->columns);
        self::assertSame('UNIQUE', $indexes['uk_remembered_device']->type);
        self::assertSame(['token_digest'], $indexes['uk_remembered_device_token']->columns);
        self::assertSame('UNIQUE', $indexes['uk_remembered_device_token']->type);
    }

    public function testMysqlAndPostgresqlBuildersAcceptBothDeviceSchemasAndUniqueIndexes(): void
    {
        $parser = new SchemaParser();
        $schemas = [
            $parser->parse(AuthenticatedDevice::class),
            $parser->parse(RememberedDeviceCredential::class),
        ];
        foreach ([new MysqlCreate(), new PgsqlCreate()] as $builder) {
            $compiledSchemas = [];
            foreach ($schemas as $schema) {
                self::assertInstanceOf(TableSchema::class, $schema);
                $compiledSchemas[] = $this->compileWithDialectBuilder($builder, $schema);
            }
            $compiled = strtoupper(implode('', $compiledSchemas));
            self::assertStringContainsString('SESSION_DIGEST', $compiled);
            self::assertStringContainsString('TOKEN_DIGEST', $compiled);
            self::assertStringContainsString('VARCHAR(64)', $compiled);
            self::assertStringContainsString('UNIQUE', $compiled);

            $deviceDefinition = json_decode($compiledSchemas[0], true, flags: JSON_THROW_ON_ERROR);
            $credentialDefinition = json_decode($compiledSchemas[1], true, flags: JSON_THROW_ON_ERROR);
            self::assertTrue($this->hasUniqueIndex($deviceDefinition[1] ?? [], ['AUTH_AREA', 'SESSION_DIGEST']));
            self::assertTrue($this->hasUniqueIndex($credentialDefinition[1] ?? [], ['DEVICE_ID']));
            self::assertTrue($this->hasUniqueIndex($credentialDefinition[1] ?? [], ['TOKEN_DIGEST']));
            if ($builder instanceof PgsqlCreate) {
                self::assertStringContainsString('SERIAL', $compiled);
            } else {
                self::assertStringContainsString('AUTO_INCREMENT', $compiled);
            }
        }
    }

    private function compileWithDialectBuilder(MysqlCreate|PgsqlCreate $builder, TableSchema $schema): string
    {
        $builder->reset();
        $reflection = new \ReflectionObject($builder);
        $parent = $reflection->getParentClass();
        self::assertNotFalse($parent);
        $table = $parent->getProperty('table');
        $table->setAccessible(true);
        $table->setValue($builder, $schema->tableName);

        foreach ($schema->columns as $column) {
            $options = [];
            if ($column->primaryKey) {
                $options[] = 'PRIMARY KEY';
            }
            if ($column->autoIncrement) {
                $options[] = 'AUTO_INCREMENT';
            }
            if (!$column->nullable && !$column->primaryKey) {
                $options[] = 'NOT NULL';
            }
            if ($column->default !== null) {
                $options[] = strtoupper((string)$column->default) === 'CURRENT_TIMESTAMP'
                    ? 'DEFAULT CURRENT_TIMESTAMP'
                    : "DEFAULT '" . str_replace("'", "''", (string)$column->default) . "'";
            }
            $builder->addColumn(
                $column->name,
                $column->type,
                $column->length,
                implode(' ', $options),
                $column->comment,
            );
        }
        foreach ($schema->indexes as $index) {
            $builder->addIndex(
                $index->type,
                $index->name,
                $index->columns,
                $index->comment,
                $index->method,
            );
        }

        $fields = $parent->getProperty('fields');
        $fields->setAccessible(true);
        $indexes = $parent->getProperty('indexes');
        $indexes->setAccessible(true);
        return json_encode(
            [$fields->getValue($builder), $indexes->getValue($builder)],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );
    }

    /** @param list<string> $indexes @param list<string> $columns */
    private function hasUniqueIndex(array $indexes, array $columns): bool
    {
        foreach ($indexes as $index) {
            $index = strtoupper($index);
            if (!str_contains($index, 'UNIQUE')) {
                continue;
            }
            $matches = true;
            foreach ($columns as $column) {
                if (!str_contains($index, $column)) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                return true;
            }
        }
        return false;
    }
}
