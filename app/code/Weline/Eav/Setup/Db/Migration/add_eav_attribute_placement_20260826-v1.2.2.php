<?php

declare(strict_types=1);

namespace Weline\Eav\Setup\Db\Migration;

use Weline\Eav\Model\EavAttribute\Placement;
use Weline\Eav\Schema\EavAttributePlacementSchema;
use Weline\Eav\Schema\SchemaRegistry;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Migration\AbstractMigration;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 属性跨属性集「归属」：不移动主记录，仅在目标属性集建立 placement。
 */
class AddEavAttributePlacement20260826V122 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '新增 eav_attribute_placement，支持属性跨属性集归属。';
    }

    public function getVersion(): string
    {
        return '1.2.2';
    }

    public function getDate(): string
    {
        return '2026-08-26';
    }

    /**
     * @return array<int, string>
     */
    public function getAffectedTables(): array
    {
        return [Placement::schema_table];
    }

    public function install(): bool
    {
        /** @var Placement $placement */
        $placement = ObjectManager::getInstance(Placement::class);
        /** @var ModelSetup $setup */
        $setup = ObjectManager::getInstance(ModelSetup::class);
        $setup->putModel($placement);

        /** @var SchemaRegistry $registry */
        $registry = ObjectManager::getInstance(SchemaRegistry::class);
        $registry->register(ObjectManager::getInstance(EavAttributePlacementSchema::class));
        $registry->createTable($setup, ObjectManager::getInstance(EavAttributePlacementSchema::class));

        return true;
    }

    public function uninstall(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnection();
        $table = ObjectManager::getInstance(Placement::class)->getTable();
        if (method_exists($connection, 'getTableColumns') && $connection->getTableColumns($table) === []) {
            return true;
        }
        $connection->dropTable($table);

        return true;
    }
}
