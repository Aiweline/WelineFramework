<?php

declare(strict_types=1);

namespace Weline\Marketing\Setup;

use Weline\Framework\DateTime\ScheduleWindowUtcMigrator;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\Marketing\Model\Audience\AudienceSegment;
use Weline\Marketing\Model\Lifecycle\LifecycleCampaign;
use Weline\Marketing\Model\Rule\LocalDescription;
use Weline\Marketing\Model\Winback\WinbackCampaign;
use Weline\Marketing\Service\MarketingWebsiteScope;
use Weline\Smtp\Service\MailTemplateSeeder;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\Marketing\Service\LegacyCouponWidgetCodeRemapper;

/** Ensure rule local-description table exists; unify coupon widget codes. */
class Upgrade implements UpgradeInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        /** @var LocalDescription $local */
        $local = ObjectManager::getInstance(LocalDescription::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($local);

        $this->ensureAudienceSegmentWebsiteColumn($modelSetup, $context);
        $this->migrateLegacyWebsiteIdZeroMeansAll();
        $this->remapLegacyCouponWidgetCodes();
        $this->migrateScheduleWindowsToUtc();
        $this->syncMailTemplatesToGlobalScope();
    }

    private function ensureAudienceSegmentWebsiteColumn(ModelSetup $modelSetup, Context $context): void
    {
        try {
            /** @var AudienceSegment $segment */
            $segment = ObjectManager::getInstance(AudienceSegment::class);
            $modelSetup->putModel($segment);
        } catch (\Throwable $e) {
            w_log_warning('Marketing AudienceSegment website_id setup: ' . $e->getMessage(), [], 'marketing');
        }
    }

    /**
     * 历史文案「website_id=0 表示全部」→ MarketingWebsiteScope::ENTITY_ALL (-1)。
     */
    private function migrateLegacyWebsiteIdZeroMeansAll(): void
    {
        foreach ([
            WinbackCampaign::class => WinbackCampaign::schema_fields_WEBSITE_ID,
            LifecycleCampaign::class => LifecycleCampaign::schema_fields_WEBSITE_ID,
        ] as $modelClass => $field) {
            try {
                /** @var \Weline\Framework\Database\Model $model */
                $model = ObjectManager::getInstance($modelClass);
                $model->reset()
                    ->where($field, 0)
                    ->update([$field => MarketingWebsiteScope::ENTITY_ALL])
                    ->fetch();
            } catch (\Throwable $e) {
                w_log_warning('Marketing migrate website_id 0→-1 (' . $modelClass . '): ' . $e->getMessage(), [], 'marketing');
            }
        }
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
     * Soft：Theme 表/载荷未就绪时不阻断 upgrade（Theme 为 optional）。
     * 清洗面：theme_layout（若仍存在）+ default_injection + chrome/patch/release JSON。
     */
    private function remapLegacyCouponWidgetCodes(): void
    {
        try {
            /** @var LegacyCouponWidgetCodeRemapper $remapper */
            $remapper = ObjectManager::getInstance(LegacyCouponWidgetCodeRemapper::class);
            $stats = $remapper->run();
            w_log_info('Marketing remapLegacyCouponWidgetCodes: ' . \json_encode($stats, \JSON_UNESCAPED_UNICODE), [], 'marketing');
        } catch (\Throwable $e) {
            w_log_warning('Marketing remapLegacyCouponWidgetCodes: ' . $e->getMessage(), [], 'marketing');
        }
    }

}
