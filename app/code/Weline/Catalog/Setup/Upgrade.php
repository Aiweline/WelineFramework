<?php

declare(strict_types=1);

namespace Weline\Catalog\Setup;

use Weline\Catalog\Model\GoogleTaxonomy;
use Weline\Catalog\Service\GoogleTaxonomyService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\UpgradeInterface;

final class Upgrade implements UpgradeInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        $this->upgrade($setup, $context);
    }

    public function upgrade(Setup $setup, Context $context): void
    {
        $modelSetup = ObjectManager::make(ModelSetup::class);
        /** @var GoogleTaxonomy $model */
        $model = ObjectManager::getInstance(GoogleTaxonomy::class);
        $modelSetup->putModel($model);
        $model->upgrade($modelSetup, $context);
        $this->importTaxonomyReference();
    }

    private function importTaxonomyReference(): void
    {
        /** @var GoogleTaxonomyService $service */
        $service = ObjectManager::getInstance(GoogleTaxonomyService::class);
        try {
            $service->importOfficial();
        } catch (\Throwable) {
            $service->importSample();
        }
    }
}
