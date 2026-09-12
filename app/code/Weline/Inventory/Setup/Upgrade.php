<?php

declare(strict_types=1);

namespace Weline\Inventory\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\Inventory\Model\WarehouseCodeLabel;
use Weline\Inventory\Model\WarehouseCodeLabel\LocalDescription as WarehouseCodeLabelLocal;
use Weline\Inventory\Model\WarehouseStoreAuthorization;
use Weline\Inventory\Service\WarehouseAuthorizationService;
use Weline\Inventory\Service\WarehouseCodeLabelAdminService;

/**
 * 2.5.14：默认站/店种子仓挂载 + 授权树；码别名字典种子保持。
 */
final class Upgrade implements UpgradeInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        foreach ([
            WarehouseCodeLabel::class,
            WarehouseCodeLabelLocal::class,
            WarehouseStoreAuthorization::class,
        ] as $modelClass) {
            $model = ObjectManager::getInstance($modelClass);
            $runner = ObjectManager::make(ModelSetup::class);
            $runner->putModel($model);
            $model->setup($runner, $context);
        }

        try {
            /** @var WarehouseCodeLabelAdminService $admin */
            $admin = ObjectManager::getInstance(WarehouseCodeLabelAdminService::class);
            $admin->seedDefaults();
        } catch (\Throwable) {
            // Seed must not block module upgrade.
        }

        try {
            /** @var WarehouseAuthorizationService $authorizations */
            $authorizations = ObjectManager::getInstance(WarehouseAuthorizationService::class);
            $authorizations->ensureDefaultStoreWarehouseMount();
        } catch (\Throwable) {
            // Default-site warehouse mount must not block module upgrade.
        }
    }
}
