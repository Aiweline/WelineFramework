<?php

declare(strict_types=1);

namespace Weline\Product\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\Product\Model\CategoryAttributeEntity;
use Weline\Product\Model\ProductCatalogAttributeEntity;
use Weline\Product\Service\ProductCategoryEavBootstrap;

final class Upgrade implements UpgradeInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        $this->upgrade($setup, $context);
    }

    public function upgrade(Setup $setup, Context $context): void
    {
        $modelSetup = ObjectManager::make(ModelSetup::class);

        /** @var ProductCatalogAttributeEntity $productEntity */
        $productEntity = ObjectManager::getInstance(ProductCatalogAttributeEntity::class);
        $modelSetup->putModel($productEntity);
        $productEntity->upgrade($modelSetup, $context);

        /** @var CategoryAttributeEntity $categoryEntity */
        $categoryEntity = ObjectManager::getInstance(CategoryAttributeEntity::class);
        $modelSetup->putModel($categoryEntity);
        $categoryEntity->upgrade($modelSetup, $context);

        ObjectManager::getInstance(ProductCategoryEavBootstrap::class)->ensureCategorySchema();
    }
}
