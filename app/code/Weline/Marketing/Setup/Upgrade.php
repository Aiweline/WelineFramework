<?php

declare(strict_types=1);

namespace Weline\Marketing\Setup;

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

/** Ensure rule local-description table exists; unify coupon widget codes. */
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
     * Soft：Theme 布局表未就绪时不阻断 upgrade（Theme 为 optional）。
     * 走 Model/Query 连接器拼方言，禁止手写跨库 SQL。
     */
    private function remapLegacyCouponWidgetCodes(): void
    {
        try {
            /** @var ThemeLayout $layoutModel */
            $layoutModel = ObjectManager::getInstance(ThemeLayout::class);
            $layoutModel->reset()
                ->where(ThemeLayout::schema_fields_WIDGET_MODULE, 'Weline_Marketing')
                ->where(
                    ThemeLayout::schema_fields_WIDGET_CODE,
                    ['cart-coupon', 'mini-cart-coupon'],
                    'IN',
                )
                ->update([
                    ThemeLayout::schema_fields_WIDGET_CODE => 'checkout-coupon',
                ])
                ->fetch();
        } catch (\Throwable $e) {
            w_log_warning('Marketing remapLegacyCouponWidgetCodes: ' . $e->getMessage(), [], 'marketing');
        }
    }

}
