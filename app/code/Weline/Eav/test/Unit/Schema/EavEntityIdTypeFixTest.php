<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Weline\Eav\Model\EavAttribute\Group;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Eav\Schema\EavEntitySchema;
use Weline\Eav\Schema\EavAttributeGroupSchema;
use Weline\Eav\Schema\EavAttributeOptionSchema;
use Weline\Eav\Schema\EavAttributeSetSchema;
use Weline\Eav\Schema\EavAttributeSchema;
use Weline\Framework\Database\Api\Db\TableInterface;

/**
 * Test that eav_entity_id field is correctly defined as INTEGER
 * This is a regression test for the PostgreSQL type mismatch bug
 * 
 * @covers \Weline\Eav\Schema\EavEntitySchema
 * @covers \Weline\Eav\Schema\EavAttributeGroupSchema
 * @covers \Weline\Eav\Schema\EavAttributeOptionSchema
 */
class EavEntityIdTypeFixTest extends TestCase
{
    /**
     * Test EavEntity primary key is INTEGER
     */
    public function testEavEntityIdIsInteger(): void
    {
        $schema = new EavEntitySchema();
        $columns = $schema->getColumns();
        
        $this->assertArrayHasKey('eav_entity_id', $columns);
        $this->assertEquals(
            TableInterface::column_type_INTEGER,
            $columns['eav_entity_id']['type'],
            'EavEntity.eav_entity_id should be INTEGER type'
        );
    }

    /**
     * Test EavAttributeGroup.eav_entity_id is INTEGER (not VARCHAR)
     * This was the original bug - it was defined as VARCHAR(255)
     */
    public function testGroupEavEntityIdIsInteger(): void
    {
        $schema = new EavAttributeGroupSchema();
        $columns = $schema->getColumns();
        
        $this->assertArrayHasKey('eav_entity_id', $columns);
        $this->assertEquals(
            TableInterface::column_type_INTEGER,
            $columns['eav_entity_id']['type'],
            'EavAttributeGroup.eav_entity_id should be INTEGER type (was VARCHAR - bug fix)'
        );
    }

    /**
     * Test EavAttributeOption.eav_entity_id is INTEGER (not VARCHAR)
     * This was the original bug - it was defined as VARCHAR(255)
     */
    public function testOptionEavEntityIdIsInteger(): void
    {
        $schema = new EavAttributeOptionSchema();
        $columns = $schema->getColumns();
        
        $this->assertArrayHasKey('eav_entity_id', $columns);
        $this->assertEquals(
            TableInterface::column_type_INTEGER,
            $columns['eav_entity_id']['type'],
            'EavAttributeOption.eav_entity_id should be INTEGER type (was VARCHAR - bug fix)'
        );
    }

    /**
     * Test EavAttributeSet.eav_entity_id is INTEGER
     */
    public function testSetEavEntityIdIsInteger(): void
    {
        $schema = new EavAttributeSetSchema();
        $columns = $schema->getColumns();
        
        $this->assertArrayHasKey('eav_entity_id', $columns);
        $this->assertEquals(
            TableInterface::column_type_INTEGER,
            $columns['eav_entity_id']['type'],
            'EavAttributeSet.eav_entity_id should be INTEGER type'
        );
    }

    /**
     * Test EavAttribute.eav_entity_id is INTEGER
     */
    public function testAttributeEavEntityIdIsInteger(): void
    {
        $schema = new EavAttributeSchema();
        $columns = $schema->getColumns();
        
        $this->assertArrayHasKey('eav_entity_id', $columns);
        $this->assertEquals(
            TableInterface::column_type_INTEGER,
            $columns['eav_entity_id']['type'],
            'EavAttribute.eav_entity_id should be INTEGER type'
        );
    }

    /**
     * Test all schemas with eav_entity_id have consistent types
     * This ensures the foreign key relationship will work in PostgreSQL
     */
    public function testAllEavEntityIdTypesConsistent(): void
    {
        $schemas = [
            'EavEntity' => new EavEntitySchema(),
            'EavAttributeSet' => new EavAttributeSetSchema(),
            'EavAttributeGroup' => new EavAttributeGroupSchema(),
            'EavAttribute' => new EavAttributeSchema(),
            'EavAttributeOption' => new EavAttributeOptionSchema(),
        ];

        $expectedType = TableInterface::column_type_INTEGER;
        
        foreach ($schemas as $name => $schema) {
            $columns = $schema->getColumns();
            $this->assertArrayHasKey(
                'eav_entity_id',
                $columns,
                "{$name} should have eav_entity_id column"
            );
            $this->assertEquals(
                $expectedType,
                $columns['eav_entity_id']['type'],
                "{$name}.eav_entity_id should be INTEGER for PostgreSQL compatibility"
            );
        }
    }

    public function testAttributeSetConflictKeysMatchSchemaUniqueKey(): void
    {
        $schema = new EavAttributeSetSchema();
        $model = new Set();
        $model->__init();

        $this->assertSame('set_id', $model->getPrimaryKey());
        $this->assertSame($schema->getUniqueKey(), $model->getUnitPrimaryKeys());
    }

    public function testAttributeGroupConflictKeysMatchSchemaUniqueKey(): void
    {
        $schema = new EavAttributeGroupSchema();
        $model = new Group();
        $model->__init();

        $this->assertSame('group_id', $model->getPrimaryKey());
        $this->assertSame($schema->getUniqueKey(), $model->getUnitPrimaryKeys());
    }
}
