<?php

declare(strict_types=1);

namespace Weline\Promotion\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\Promotion\Model\PromotionActivityTheme;
use Weline\Promotion\Model\PromotionActivityThemeLocal;
use Weline\Promotion\Model\PromotionActivityThemeProduct;
use Weline\Promotion\Service\PromotionActivityThemeService;

class Upgrade implements UpgradeInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        $modelSetup = ObjectManager::make(ModelSetup::class);
        foreach ([PromotionActivityTheme::class, PromotionActivityThemeLocal::class, PromotionActivityThemeProduct::class] as $modelClass) {
            $model = ObjectManager::getInstance($modelClass);
            $modelSetup->putModel($model);
        }

        ObjectManager::getInstance(PromotionActivityThemeService::class)->ensureDefaultThemes();
    }
}
