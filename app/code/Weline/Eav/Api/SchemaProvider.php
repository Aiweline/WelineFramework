<?php

declare(strict_types=1);

namespace Weline\Eav\Api;

use Weline\Eav\Schema\SchemaRegistry;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Stage\EavSchemaProviderInterface;

final class SchemaProvider implements EavSchemaProviderInterface
{
    public function ownerModuleName(): string
    {
        return 'Weline_Eav';
    }

    public function createTables(ModelSetup $setup): array
    {
        $registry = ObjectManager::getInstance(SchemaRegistry::class);
        $registry->registerClasses($registry::getDefaultSchemas());
        $tables = [];
        foreach ($registry->getSortedSchemas() as $schema) {
            $registry->createTable($setup, $schema);
            $tables[] = $schema->getTableName();
        }
        return $tables;
    }
}
