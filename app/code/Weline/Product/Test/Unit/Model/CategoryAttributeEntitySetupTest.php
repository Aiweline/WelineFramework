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
use Weline\Product\Model\CategoryAttributeEntity;

final class CategoryAttributeEntitySetupTest extends TestCase
{
    public function testInstallDeclaresCategoryAnchorTable(): void
    {
        $table = $this->createMock(CreateInterface::class);
        $table->expects(self::once())
            ->method('addColumn')
            ->with(
                CategoryAttributeEntity::schema_fields_ID,
                TableInterface::column_type_INTEGER,
                CategoryAttributeEntity::eav_entity_id_field_length,
                'primary key auto_increment',
                '分类 ID',
            )
            ->willReturnSelf();
        $table->expects(self::once())->method('create');

        $setup = $this->createMock(ModelSetup::class);
        $setup->expects(self::once())
            ->method('tableExist')
            ->with(CategoryAttributeEntity::schema_table)
            ->willReturn(false);
        $setup->expects(self::once())
            ->method('createTable')
            ->with(CategoryAttributeEntity::schema_table)
            ->willReturn($table);

        $store = $this->createMock(EntityAttributeStoreInterface::class);
        $store->expects(self::once())->method('syncAttributeSequence');
        $store->expects(self::once())
            ->method('provisionValueTables')
            ->with(self::isInstanceOf(CategoryAttributeEntity::class), $setup);

        $reflection = new ReflectionClass(CategoryAttributeEntity::class);
        /** @var CategoryAttributeEntity $entity */
        $entity = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('attributeStore')->setValue($entity, $store);
        /** @var Context $context */
        $context = (new ReflectionClass(Context::class))->newInstanceWithoutConstructor();

        $entity->install($setup, $context);
    }

    public function testEntityCodeIsCategory(): void
    {
        self::assertSame('category', CategoryAttributeEntity::entity_code);
    }
}
