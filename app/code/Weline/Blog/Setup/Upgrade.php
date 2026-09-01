<?php

declare(strict_types=1);

namespace Weline\Blog\Setup;

use Weline\Blog\Model\BlogCategoryAttributeEntity;
use Weline\Blog\Service\BlogCategoryEavBootstrap;
use Weline\Blog\Service\BlogCategoryLocaleSyncService;
use Weline\Blog\Service\BlogNewsCategoryBootstrap;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\UpgradeInterface;

final class Upgrade implements UpgradeInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        $modelSetup = ObjectManager::make(ModelSetup::class);

        /** @var BlogCategoryAttributeEntity $entity */
        $entity = ObjectManager::getInstance(BlogCategoryAttributeEntity::class);
        $modelSetup->putModel($entity);
        $entity->upgrade($modelSetup, $context);

        ObjectManager::getInstance(BlogCategoryEavBootstrap::class)->ensureCategorySchema();
        ObjectManager::getInstance(BlogCategoryLocaleSyncService::class)->syncExistingCategories();
        ObjectManager::getInstance(BlogNewsCategoryBootstrap::class)->ensure(0);
    }
}
