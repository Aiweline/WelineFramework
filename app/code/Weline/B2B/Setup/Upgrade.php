<?php

declare(strict_types=1);

namespace Weline\B2B\Setup;

use Weline\B2B\Model\CustomerGroupRecord\LocalDescription;
use Weline\B2B\Service\CustomerGroupLocalSeedService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\UpgradeInterface;

/**
 * 2.6.47：客户组名称/等级说明 LocalDescription（LocalModel）。
 */
final class Upgrade implements UpgradeInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        foreach ([LocalDescription::class] as $modelClass) {
            $model = ObjectManager::getInstance($modelClass);
            $runner = ObjectManager::make(ModelSetup::class);
            $runner->putModel($model);
            $model->setup($runner, $context);
        }

        $this->seedCustomerGroupLocals();
    }

    private function seedCustomerGroupLocals(): void
    {
        try {
            /** @var CustomerGroupLocalSeedService $seed */
            $seed = ObjectManager::getInstance(CustomerGroupLocalSeedService::class);
            $seed->seedSourceLocalsAndEnqueue();
        } catch (\Throwable) {
            // Seed must not block module upgrade.
        }
    }
}
