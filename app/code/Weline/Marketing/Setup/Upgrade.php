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
use Weline\Smtp\Service\MailTemplateSeeder;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\DefaultLayoutSeeder;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\WidgetDefaultInjectionService;

/** Ensure rule local-description table exists; unify coupon widget codes; seed coupon slots. */
class Upgrade implements UpgradeInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        /** @var LocalDescription $local */
        $local = ObjectManager::getInstance(LocalDescription::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($local);
        $local->setup($modelSetup, $context);

        $this->remapLegacyCouponWidgetCodes();
        $this->seedCouponSlots();
        $this->migrateScheduleWindowsToUtc();
        $this->syncMailTemplatesToGlobalScope();
    }

    /**
     * 将 Marketing 渠道默认邮件模板写入抽象默认站层（GLOBAL），供各站继承。
     * Soft：Smtp 表未就绪时不阻断 upgrade。
     */
    private function syncMailTemplatesToGlobalScope(): void
    {
        try {
            /** @var MailTemplateSeeder $seeder */
            $seeder = ObjectManager::getInstance(MailTemplateSeeder::class);
            $seeder->syncAll(SystemConfig::SCOPE_GLOBAL);
        } catch (\Throwable $e) {
            w_log_warning('Marketing MailTemplateSeeder syncAll: ' . $e->getMessage(), [], 'marketing');
        }
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

    /**
     * cart-coupon / mini-cart-coupon → checkout-coupon（同一部件三处注入）。
     */
    private function remapLegacyCouponWidgetCodes(): void
    {
        try {
            /** @var ThemeLayout $layoutModel */
            $layoutModel = ObjectManager::getInstance(ThemeLayout::class);
            foreach (['cart-coupon', 'mini-cart-coupon'] as $legacyCode) {
                $rows = $layoutModel->reset()
                    ->where(ThemeLayout::schema_fields_WIDGET_MODULE, 'Weline_Marketing')
                    ->where(ThemeLayout::schema_fields_WIDGET_CODE, $legacyCode)
                    ->select()
                    ->fetchArray();
                if (!is_array($rows) || $rows === []) {
                    continue;
                }
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $id = (int)($row[ThemeLayout::schema_fields_ID] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }
                    /** @var ThemeLayout $rowModel */
                    $rowModel = ObjectManager::getInstance(ThemeLayout::class);
                    $rowModel->load($id);
                    if ($rowModel->getLayoutId() !== $id) {
                        continue;
                    }
                    $rowModel->setWidgetCode('checkout-coupon');
                    $config = $rowModel->getWidgetConfig();
                    if ($legacyCode === 'mini-cart-coupon') {
                        $config['compact'] = true;
                        if (!isset($config['title']) || trim((string)$config['title']) === '') {
                            $config['title'] = '优惠券';
                        }
                    }
                    $rowModel->setWidgetConfig($config);
                    $rowModel->save();
                }
            }
        } catch (\Throwable $e) {
            throw new Exception(__(
                '优惠券部件 code 迁移失败：%{1}',
                [$e->getMessage()]
            ), 0, $e);
        }
    }

    private function seedCouponSlots(): void
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

            $targets = [
                ['layout' => 'cart', 'slot' => 'cart-summary-discount', 'reason' => 'cart coupon slot default injection publish'],
                ['layout' => 'checkout', 'slot' => 'checkout-summary-discount', 'reason' => 'checkout coupon slot default injection publish'],
                ['layout' => 'mini-cart', 'slot' => 'footer-extras', 'reason' => 'mini-cart coupon slot default injection publish'],
            ];

            foreach ($themes as $themeRow) {
                if (!is_array($themeRow)) {
                    continue;
                }
                $themeId = (int)($themeRow[WelineTheme::schema_fields_ID] ?? 0);
                if ($themeId <= 0) {
                    continue;
                }

                foreach ($targets as $target) {
                    $seeder->seedDefaultLayout($themeId, $target['layout'], false);

                    foreach ([ThemeLayout::STATUS_DRAFT, ThemeLayout::STATUS_PUBLISHED] as $status) {
                        $injectionService->initSlotDefaultInjections(
                            $themeId,
                            $target['layout'],
                            $identity,
                            $target['slot'],
                            PreviewContextService::AREA_FRONTEND,
                            $status,
                        );
                    }

                    /** @var \Weline\Theme\Service\ThemeLayoutService $layoutService */
                    $layoutService = ObjectManager::getInstance(\Weline\Theme\Service\ThemeLayoutService::class);
                    $layoutService->publishLayout(
                        $themeId,
                        $target['layout'],
                        $identity,
                        true,
                        [
                            'reason' => $target['reason'],
                            'actor' => 'system:Weline_Marketing:Upgrade',
                        ],
                    );
                }
            }
        } catch (\Throwable $e) {
            throw new Exception(__(
                '优惠券槽默认部件迁移失败：%{1}',
                [$e->getMessage()]
            ), 0, $e);
        }
    }
}
