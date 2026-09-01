<?php

declare(strict_types=1);

namespace Weline\Promotion\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\InstallInterface;
use Weline\Promotion\Model\PromotionActivityTheme;
use Weline\Promotion\Model\PromotionActivityThemeLocal;
use Weline\Promotion\Model\PromotionCampaignRun;
use Weline\Promotion\Service\PromotionActivityThemeService;

class Install implements InstallInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        $modelSetup = ObjectManager::make(ModelSetup::class);

        foreach ([
            PromotionCampaignRun::class,
            PromotionActivityTheme::class,
            PromotionActivityThemeLocal::class,
        ] as $modelClass) {
            /** @var object $model */
            $model = ObjectManager::getInstance($modelClass);
            $modelSetup->putModel($model);
            $model->setup($modelSetup, $context);
        }

        ObjectManager::getInstance(PromotionActivityThemeService::class)->ensureDefaultThemes();
    }
}
