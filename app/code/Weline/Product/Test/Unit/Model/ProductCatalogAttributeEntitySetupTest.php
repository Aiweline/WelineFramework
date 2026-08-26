<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Eav\Api\Attribute\EntityAttributeStoreInterface;
use Weline\Framework\Database\Connection\Api\Sql\Table\CreateInterface;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Product\Model\ProductCatalogAttributeEntity;

final class ProductCatalogAttributeEntitySetupTest extends TestCase
{
    public function testInstallDeclaresPostgresqlCompatiblePrimaryKeyColumn(): void
    {
        $table = $this->createMock(CreateInterface::class);
        $table->expects(self::once())
            ->method('addColumn')
            ->with(
                ProductCatalogAttributeEntity::schema_fields_ID,
                TableInterface::column_type_INTEGER,
                ProductCatalogAttributeEntity::eav_entity_id_field_length,
                'primary key auto_increment',
                '商品 ID',
            )
            ->willReturnSelf();
        $table->expects(self::once())->method('create');

        $setup = $this->createMock(ModelSetup::class);
        $setup->expects(self::once())
            ->method('tableExist')
            ->with(ProductCatalogAttributeEntity::schema_table)
            ->willReturn(false);
        $setup->expects(self::once())
            ->method('createTable')
            ->with(ProductCatalogAttributeEntity::schema_table)
            ->willReturn($table);

        $store = $this->createMock(EntityAttributeStoreInterface::class);
        $store->expects(self::once())->method('syncAttributeSequence');
        $store->expects(self::once())
            ->method('provisionValueTables')
            ->with(self::isInstanceOf(ProductCatalogAttributeEntity::class), $setup);

        $reflection = new ReflectionClass(ProductCatalogAttributeEntity::class);
        /** @var ProductCatalogAttributeEntity $entity */
        $entity = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('attributeStore')->setValue($entity, $store);
        /** @var Context $context */
        $context = (new ReflectionClass(Context::class))->newInstanceWithoutConstructor();

        $entity->install($setup, $context);
    }
}
