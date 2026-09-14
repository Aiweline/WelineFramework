<?php

declare(strict_types=1);

namespace Weline\Marketing\Setup;

use Weline\Framework\App\Exception;
use Weline\Framework\DateTime\ScheduleWindowUtcMigrator;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\Marketing\Model\Rule\LocalDescription;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\DefaultLayoutSeeder;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\WidgetDefaultInjectionService;

/** Ensure rule local-description table exists for list joins; seed cart coupon slot. */
class Upgrade implements UpgradeInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        /** @var LocalDescription $local */
        $local = ObjectManager::getInstance(LocalDescription::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($local);
        $local->setup($modelSetup, $context);

        $this->seedCartCouponSlot();
        $this->migrateScheduleWindowsToUtc();
    }

    private function migrateScheduleWindowsToUtc(): void
    {
        try {
            /** @var ScheduleWindowUtcMigrator $migrator */
            $migrator = ObjectManager::getInstance(ScheduleWindowUtcMigrator::class);
            $migrator->migrate(false);
        } catch (\Throwable) {
            // Non-blocking: operator can re-run Marketing/scripts/migrate-schedule-windows-to-utc.php
        }
    }

    private function seedCartCouponSlot(): void
    {
        try {
            /** @var WidgetDefaultInjectionService $injectionService */
            $injectionService = ObjectManager::getInstance(WidgetDefaultInjectionService::class);
            /** @var DefaultLayoutSeeder $seeder */
            $seeder = ObjectManager::getInstance(DefaultLayoutSeeder::class);
            /** @var WelineTheme $themeModel */
            $themeModel = ObjectManager::getInstance(WelineTheme::class);
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

            foreach ($themes as $themeRow) {
                if (!is_array($themeRow)) {
                    continue;
                }
                $themeId = (int)($themeRow[WelineTheme::schema_fields_ID] ?? 0);
                if ($themeId <= 0) {
                    continue;
                }

                $seeder->seedDefaultLayout($themeId, 'cart', false);

                foreach ([ThemeLayout::STATUS_DRAFT, ThemeLayout::STATUS_PUBLISHED] as $status) {
                    $injectionService->initSlotDefaultInjections(
                        $themeId,
                        'cart',
                        $identity,
                        'cart-summary-discount',
                        PreviewContextService::AREA_FRONTEND,
                        $status,
                    );
                }

                /** @var \Weline\Theme\Service\ThemeLayoutService $layoutService */
                $layoutService = ObjectManager::getInstance(\Weline\Theme\Service\ThemeLayoutService::class);
                $layoutService->publishLayout(
                    $themeId,
                    'cart',
                    $identity,
                    true,
                    [
                        'reason' => 'cart-coupon slot default injection publish',
                        'actor' => 'system:Weline_Marketing:Upgrade',
                    ],
                );
            }
        } catch (\Throwable $e) {
            throw new Exception(__(
                '购物车优惠券槽默认部件迁移失败：%{1}',
                [$e->getMessage()]
            ), 0, $e);
        }
    }
}
