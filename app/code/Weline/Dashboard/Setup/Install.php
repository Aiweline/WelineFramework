<?php

declare(strict_types=1);

namespace Weline\Dashboard\Setup;

use Weline\Dashboard\Model\DashboardView;
use Weline\Dashboard\Service\DashboardViewService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\InstallInterface;

class Install implements InstallInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        /** @var DashboardView $dashboardView */
        $dashboardView = ObjectManager::getInstance(DashboardView::class);
        /** @var ModelSetup $modelSetup */
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($dashboardView);
        $dashboardView->install($modelSetup, $context);

        /** @var DashboardViewService $dashboardViewService */
        $dashboardViewService = ObjectManager::getInstance(DashboardViewService::class);
        foreach ($dashboardViewService->listWebsites() as $website) {
            $view = $dashboardViewService->ensureDefaultView((int)($website['website_id'] ?? 0));
            if ($view) {
                $dashboardViewService->ensureLayoutInitialized($view);
            }
        }
    }
}
