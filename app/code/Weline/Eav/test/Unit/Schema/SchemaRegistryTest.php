<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Weline\Eav\Schema\SchemaRegistry;
use Weline\Eav\Schema\SchemaInterface;
use Weline\Eav\Schema\EavEntitySchema;
use Weline\Eav\Schema\EavAttributeTypeSchema;
use Weline\Eav\Schema\EavAttributeSetSchema;
use Weline\Eav\Schema\EavAttributeGroupSchema;
use Weline\Eav\Schema\EavAttributeSchema;
use Weline\Eav\Schema\EavAttributeOptionSchema;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\Sql\Table\AlterInterface;
use Weline\Framework\Database\Connection\Adapter\Sqlite\Connector;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * @covers \Weline\Eav\Schema\SchemaRegistry
 */
class SchemaRegistryTest extends TestCase
{
    private SchemaRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new SchemaRegistry();
    }

    /**
     * Test register method
     */
    public function testRegister(): void
    {
        $schema = new EavEntitySchema();
        $result = $this->registry->register($schema);
        
        // Should return self for chaining
        $this->assertSame($this->registry, $result);
        
        // Should be retrievable
        $all = $this->registry->getAll();
        $this->assertArrayHasKey($schema->getTableName(), $all);
        $this->assertSame($schema, $all[$schema->getTableName()]);
    }

    /**
     * Test registerClasses method
     */
    public function testRegisterClasses(): void
    {
        $classes = [
            EavEntitySchema::class,
            EavAttributeTypeSchema::class,
        ];
        
        $result = $this->registry->registerClasses($classes);
        
        // Should return self for chaining
        $this->assertSame($this->registry, $result);
        
        // Should have registered all schemas
        $all = $this->registry->getAll();
        $this->assertCount(2, $all);
    }

    /**
     * Test getDefaultSchemas returns all EAV schemas
     */
    public function testGetDefaultSchemas(): void
    {
        $defaults = SchemaRegistry::getDefaultSchemas();
        
        $this->assertIsArray($defaults);
        $this->assertCount(6, $defaults, 'Should have 6 default schemas');
        
        // Verify all are valid schema classes
        foreach ($defaults as $schemaClass) {
            $this->assertTrue(
                is_subclass_of($schemaClass, SchemaInterface::class),
                "{$schemaClass} should implement SchemaInterface"
            );
        }
        
        // Verify expected schemas are present
        $this->assertContains(EavEntitySchema::class, $defaults);
        $this->assertContains(EavAttributeTypeSchema::class, $defaults);
        $this->assertContains(EavAttributeSetSchema::class, $defaults);
        $this->assertContains(EavAttributeGroupSchema::class, $defaults);
        $this->assertContains(EavAttributeSchema::class, $defaults);
        $this->assertContains(EavAttributeOptionSchema::class, $defaults);
    }

    /**
     * Test getSortedSchemas returns schemas in dependency order
     */
    public function testGetSortedSchemasDependencyOrder(): void
    {
        $this->registry->registerClasses(SchemaRegistry::getDefaultSchemas());
        
        $sorted = $this->registry->getSortedSchemas();
        
        $this->assertNotEmpty($sorted);
        
        // Get table names in sorted order
        $sortedNames = array_map(fn($s) => $s->getTableName(), $sorted);
        
        // Entity should come before Set, Group, Attribute
        $entityPos = array_search('eav_entity', $sortedNames);
        $setPos = array_search('eav_attribute_set', $sortedNames);
        $groupPos = array_search('eav_attribute_group', $sortedNames);
        $attributePos = array_search('eav_attribute', $sortedNames);
        
        $this->assertLessThan($setPos, $entityPos, 'Entity should come before Set');
        $this->assertLessThan($groupPos, $entityPos, 'Entity should come before Group');
        $this->assertLessThan($attributePos, $entityPos, 'Entity should come before Attribute');
        
        // Type should come before Attribute
        $typePos = array_search('eav_attribute_type', $sortedNames);
        $this->assertLessThan($attributePos, $typePos, 'Type should come before Attribute');
    }

    /**
     * Test getAll returns all registered schemas
     */
    public function testGetAll(): void
    {
        $this->assertEmpty($this->registry->getAll());
        
        $this->registry->register(new EavEntitySchema());
        $this->registry->register(new EavAttributeTypeSchema());
        
        $all = $this->registry->getAll();
        $this->assertCount(2, $all);
    }

    public function testTableExistsUsesSqliteNativeLookup(): void
    {
        $dbPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline-eav-schema-registry-' . uniqid('', true) . '.sqlite';

        try {
            $connector = new Connector(new ConfigProvider([
                'type' => 'sqlite',
                'path' => $dbPath,
                'database' => '',
                'prefix' => '',
            ]));
            $connector->create();
            $connector->query('CREATE TABLE eav_attribute_type (code varchar(255) unique not null)')->fetch();

            $method = new \ReflectionMethod(SchemaRegistry::class, 'tableExists');
            $method->setAccessible(true);

            $this->assertTrue($method->invoke($this->registry, $connector, 'eav_attribute_type'));
            $this->assertFalse($method->invoke($this->registry, $connector, 'missing_eav_attribute_type'));
        } finally {
            if (isset($connector)) {
                $connector->close();
            }
            if (is_file($dbPath)) {
                unlink($dbPath);
            }
        }
    }

    public function testCreateTableSeedsInitialDataWhenTableAlreadyExists(): void
    {
        $dbPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline-eav-schema-registry-seed-' . uniqid('', true) . '.sqlite';

        try {
            $connector = new Connector(new ConfigProvider([
                'type' => 'sqlite',
                'path' => $dbPath,
                'database' => '',
                'prefix' => '',
            ]));
            $connector->create();
            $this->createExistingAttributeTypeTable($connector);

            $model = $this->getMockBuilder(AbstractModel::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getConnection'])
                ->getMockForAbstractClass();
            $model->method('getConnection')->willReturn($connector);

            $setup = $this->getMockBuilder(ModelSetup::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getModel'])
                ->getMock();
            $setup->method('getModel')->willReturn($model);

            $this->registry->createTable($setup, new EavAttributeTypeSchema());

            $rows = $connector
                ->query("SELECT code FROM eav_attribute_type WHERE code IN ('input_bool', 'select_yes_no', 'select_option') ORDER BY code")
                ->fetch();

            $this->assertSame(['input_bool', 'select_option', 'select_yes_no'], array_column($rows, 'code'));
        } finally {
            if (isset($connector)) {
                $connector->close();
            }
            if (is_file($dbPath)) {
                unlink($dbPath);
            }
        }
    }

    public function testCreateTableAddsMissingColumnsBeforeIndexesAndSeed(): void
    {
        $alter = $this->getMockBuilder(AlterInterface::class)->getMock();
        $alter->expects($this->once())
            ->method('forTable')
            ->with('m_eav_attribute_type', EavAttributeTypeSchema::FIELD_ID)
            ->willReturnSelf();
        $alter->expects($this->atLeastOnce())
            ->method('addColumn')
            ->willReturnSelf();
        $alter->expects($this->once())
            ->method('alter')
            ->willReturn(true);

        $connector = $this->getMockBuilder(ConnectorInterface::class)->getMock();
        $connector->method('getTableColumns')->with('m_eav_attribute_type')->willReturn([
            ['name' => EavAttributeTypeSchema::FIELD_ID],
        ]);
        $connector->method('alterTable')->willReturn($alter);
        $connector->method('getConfigProvider')->willReturn(new ConfigProvider([
            'type' => 'pgsql',
            'path' => '',
            'database' => '',
            'prefix' => 'm_',
        ]));

        $method = new \ReflectionMethod(SchemaRegistry::class, 'ensureColumns');
        $method->setAccessible(true);
        $method->invoke($this->registry, $connector, 'm_eav_attribute_type', new EavAttributeTypeSchema());
    }

    private function createExistingAttributeTypeTable(Connector $connector): void
    {
        $connector->query(
            'CREATE TABLE eav_attribute_type (
                type_id INTEGER PRIMARY KEY AUTOINCREMENT,
                code varchar(255) UNIQUE NOT NULL,
                name varchar(255) NOT NULL,
                element varchar(60) DEFAULT \'input\',
                model_class varchar(255) DEFAULT \'\',
                model_class_data TEXT,
                default_value TEXT,
                is_swatch INTEGER DEFAULT 0,
                swatch_image INTEGER DEFAULT 0,
                swatch_color INTEGER DEFAULT 0,
                swatch_text INTEGER DEFAULT 0,
                frontend_attrs varchar(255) NOT NULL,
                required INTEGER DEFAULT 0,
                field_type varchar(60) NOT NULL,
                field_length INTEGER NOT NULL
            )'
        )->fetch();
    }
}
