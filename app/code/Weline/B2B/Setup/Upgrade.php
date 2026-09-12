<?php

declare(strict_types=1);

namespace Weline\B2B\Setup;

use Weline\B2B\Model\CustomerGroupRecord\LocalDescription;
use Weline\B2B\Service\CustomerGroupLocalSeedService;
use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\DefaultLayoutSeeder;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\ThemeLayoutService;
use Weline\Theme\Service\WidgetDefaultInjectionService;

/**
 * 2.6.47：客户组名称/等级说明 LocalDescription（LocalModel）。
 * 2.6.62：播种迷你车/购物车批发信用 default_injections（须在 B2B widget 注册后执行）。
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
        $this->seedWholesaleCreditSurfaces();
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

    private function seedWholesaleCreditSurfaces(): void
    {
        try {
            /** @var WidgetDefaultInjectionService $injectionService */
            $injectionService = ObjectManager::getInstance(WidgetDefaultInjectionService::class);
            /** @var DefaultLayoutSeeder $seeder */
            $seeder = ObjectManager::getInstance(DefaultLayoutSeeder::class);
            /** @var WelineTheme $themeModel */
            $themeModel = ObjectManager::getInstance(WelineTheme::class);
            /** @var ThemeLayoutService $layoutService */
            $layoutService = ObjectManager::getInstance(ThemeLayoutService::class);
            $themes = $themeModel->reset()->select()->fetchArray();
            if (!is_array($themes)) {
                return;
            }
            $identity = [
                'layout_option' => 'default',
                'scope' => 'default.__store__.__channel__',
                'locale_code' => '',
                'target_type' => 'global',
                'target_id' => 0,
            ];
            $jobs = [
                ['mini-cart', 'footer-extras'],
                ['cart', 'cart-summary-credit'],
            ];
            foreach ($themes as $themeRow) {
                if (!is_array($themeRow)) {
                    continue;
                }
                $themeId = (int)($themeRow[WelineTheme::schema_fields_ID] ?? 0);
                if ($themeId <= 0) {
                    continue;
                }
                foreach ($jobs as [$layoutType, $slotId]) {
                    $seeder->seedDefaultLayout($themeId, $layoutType, false);
                    foreach ([ThemeLayout::STATUS_DRAFT, ThemeLayout::STATUS_PUBLISHED] as $status) {
                        $injectionService->initSlotDefaultInjections(
                            $themeId,
                            $layoutType,
                            $identity,
                            $slotId,
                            PreviewContextService::AREA_FRONTEND,
                            $status,
                        );
                    }
                    $layoutService->publishLayout(
                        $themeId,
                        $layoutType,
                        $identity,
                        true,
                        [
                            'reason' => 'b2b wholesale credit surface default injection',
                            'actor' => 'system:Weline_B2B:Upgrade',
                        ],
                    );
                }
            }
        } catch (\Throwable $e) {
            throw new Exception(__(
                'B2B 批发信用迷你车/购物车槽默认注入失败：%{1}',
                [$e->getMessage()]
            ), 0, $e);
        }
    }
}
