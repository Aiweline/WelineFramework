<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Weline\Eav\Schema\SchemaInterface;
use Weline\Eav\Schema\AbstractSchema;
use Weline\Eav\Schema\EavEntitySchema;
use Weline\Eav\Schema\EavAttributeTypeSchema;
use Weline\Eav\Schema\EavAttributeSetSchema;
use Weline\Eav\Schema\EavAttributeGroupSchema;
use Weline\Eav\Schema\EavAttributeSchema;
use Weline\Eav\Schema\EavAttributeOptionSchema;

/**
 * @covers \Weline\Eav\Schema\SchemaInterface
 * @covers \Weline\Eav\Schema\AbstractSchema
 */
class SchemaInterfaceTest extends TestCase
{
    /**
     * @var SchemaInterface[]
     */
    private array $schemas = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->schemas = [
            'entity' => new EavEntitySchema(),
            'type' => new EavAttributeTypeSchema(),
            'set' => new EavAttributeSetSchema(),
            'group' => new EavAttributeGroupSchema(),
            'attribute' => new EavAttributeSchema(),
            'option' => new EavAttributeOptionSchema(),
        ];
    }

    /**
     * Test that all Schema classes implement SchemaInterface
     */
    public function testAllSchemasImplementInterface(): void
    {
        foreach ($this->schemas as $name => $schema) {
            $this->assertInstanceOf(
                SchemaInterface::class,
                $schema,
                "{$name} schema should implement SchemaInterface"
            );
        }
    }

    /**
     * Test that all Schema classes extend AbstractSchema
     */
    public function testAllSchemasExtendAbstract(): void
    {
        foreach ($this->schemas as $name => $schema) {
            $this->assertInstanceOf(
                AbstractSchema::class,
                $schema,
                "{$name} schema should extend AbstractSchema"
            );
        }
    }

    /**
     * Test getTableName returns non-empty string
     */
    public function testGetTableNameReturnsNonEmptyString(): void
    {
        foreach ($this->schemas as $name => $schema) {
            $tableName = $schema->getTableName();
            $this->assertIsString($tableName, "{$name}: getTableName should return string");
            $this->assertNotEmpty($tableName, "{$name}: getTableName should not be empty");
        }
    }

    /**
     * Test getTableComment returns string
     */
    public function testGetTableCommentReturnsString(): void
    {
        foreach ($this->schemas as $name => $schema) {
            $comment = $schema->getTableComment();
            $this->assertIsString($comment, "{$name}: getTableComment should return string");
        }
    }

    /**
     * Test getColumns returns array with proper structure
     */
    public function testGetColumnsReturnsProperStructure(): void
    {
        foreach ($this->schemas as $name => $schema) {
            $columns = $schema->getColumns();
            $this->assertIsArray($columns, "{$name}: getColumns should return array");
            $this->assertNotEmpty($columns, "{$name}: getColumns should not be empty");

            foreach ($columns as $columnName => $columnDef) {
                $this->assertIsString($columnName, "{$name}: column name should be string");
                $this->assertIsArray($columnDef, "{$name}: column definition should be array");
                $this->assertArrayHasKey('type', $columnDef, "{$name}.{$columnName}: column should have 'type'");
                $this->assertArrayHasKey('length', $columnDef, "{$name}.{$columnName}: column should have 'length'");
                $this->assertArrayHasKey('options', $columnDef, "{$name}.{$columnName}: column should have 'options'");
                $this->assertArrayHasKey('comment', $columnDef, "{$name}.{$columnName}: column should have 'comment'");
            }
        }
    }

    /**
     * Test getIndexes returns array
     */
    public function testGetIndexesReturnsArray(): void
    {
        foreach ($this->schemas as $name => $schema) {
            $indexes = $schema->getIndexes();
            $this->assertIsArray($indexes, "{$name}: getIndexes should return array");
        }
    }

    /**
     * Test getForeignKeys returns array
     */
    public function testGetForeignKeysReturnsArray(): void
    {
        foreach ($this->schemas as $name => $schema) {
            $foreignKeys = $schema->getForeignKeys();
            $this->assertIsArray($foreignKeys, "{$name}: getForeignKeys should return array");
        }
    }

    /**
     * Test getInitialData returns array
     */
    public function testGetInitialDataReturnsArray(): void
    {
        foreach ($this->schemas as $name => $schema) {
            $initialData = $schema->getInitialData();
            $this->assertIsArray($initialData, "{$name}: getInitialData should return array");
        }
    }

    /**
     * Test getDependencies returns array of class strings
     */
    public function testGetDependenciesReturnsArray(): void
    {
        foreach ($this->schemas as $name => $schema) {
            $dependencies = $schema->getDependencies();
            $this->assertIsArray($dependencies, "{$name}: getDependencies should return array");
            
            foreach ($dependencies as $dep) {
                $this->assertIsString($dep, "{$name}: dependency should be class string");
                $this->assertTrue(
                    is_subclass_of($dep, SchemaInterface::class),
                    "{$name}: dependency {$dep} should implement SchemaInterface"
                );
            }
        }
    }

    /**
     * Test getUniqueKey returns string or array
     */
    public function testGetUniqueKeyReturnsStringOrArray(): void
    {
        foreach ($this->schemas as $name => $schema) {
            $uniqueKey = $schema->getUniqueKey();
            $this->assertTrue(
                is_string($uniqueKey) || is_array($uniqueKey),
                "{$name}: getUniqueKey should return string or array"
            );
        }
    }
}
