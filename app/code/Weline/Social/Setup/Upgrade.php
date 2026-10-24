<?php

declare(strict_types=1);

namespace Weline\Social\Setup;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Db\Setup as DbSetup;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\Social\Model\SocialAutopilotPlan;
use Weline\Social\Model\SocialCreativeDraft;
use Weline\Social\Model\SocialExtensionDevice;
use Weline\Social\Model\SocialExtensionJob;
use Weline\Social\Model\SocialExtensionStep;
use Weline\Social\Model\SocialMediaAsset;
use Weline\Social\Model\SocialPublishTarget;

/**
 * Social 模块迁移（幂等）：5 张新表 + publish_target/creative_draft 各 3 列 + autopilot_plan 媒体 5 列。
 */
class Upgrade implements UpgradeInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        $this->ensureMediaAssetTable();
        $this->ensureAutopilotPlanTable();
        $this->ensureAutopilotPlanMediaColumns();
        $this->ensureExtensionDeviceTable();
        $this->ensureExtensionDeviceWebsiteColumn();
        $this->ensureExtensionJobTable();
        $this->ensureExtensionStepTable();
        $this->ensurePublishTargetColumns();
        $this->ensureCreativeDraftColumns();
    }

    private function ensureMediaAssetTable(): void
    {
        /** @var SocialMediaAsset $model */
        $model = ObjectManager::getInstance(SocialMediaAsset::class);
        $db = $this->dbSetup();
        $table = $model->getTable();
        if ($db->tableExist($table)) {
            return;
        }

        $db->createTable($table, 'Social media asset')
            ->addColumn(SocialMediaAsset::schema_fields_ID, 'int', null, 'auto_increment primary key', 'Asset ID')
            ->addColumn(SocialMediaAsset::schema_fields_WEBSITE_ID, 'int', null, 'NOT NULL DEFAULT 0', 'Website ID')
            ->addColumn(SocialMediaAsset::schema_fields_KIND, 'varchar', 16, "NOT NULL DEFAULT 'image'", 'Asset kind')
            ->addColumn(SocialMediaAsset::schema_fields_PATH, 'varchar', 512, "NOT NULL DEFAULT ''", 'Storage path')
            ->addColumn(SocialMediaAsset::schema_fields_MIME, 'varchar', 100, "NULL DEFAULT NULL", 'MIME type')
            ->addColumn(SocialMediaAsset::schema_fields_WIDTH, 'int', null, 'NULL DEFAULT 0', 'Width px')
            ->addColumn(SocialMediaAsset::schema_fields_HEIGHT, 'int', null, 'NULL DEFAULT 0', 'Height px')
            ->addColumn(SocialMediaAsset::schema_fields_DURATION_MS, 'int', null, 'NULL DEFAULT 0', 'Duration ms')
            ->addColumn(SocialMediaAsset::schema_fields_CHECKSUM, 'varchar', 64, "NOT NULL DEFAULT ''", 'Content checksum')
            ->addColumn(SocialMediaAsset::schema_fields_SOURCE, 'varchar', 32, "NOT NULL DEFAULT 'site'", 'Asset source')
            ->addColumn(SocialMediaAsset::schema_fields_SOURCE_URL, 'varchar', 512, "NULL DEFAULT NULL", 'Source URL')
            ->addColumn(SocialMediaAsset::schema_fields_LICENSE, 'varchar', 64, "NULL DEFAULT NULL", 'License code')
            ->addColumn(SocialMediaAsset::schema_fields_ATTRIBUTION, 'varchar', 255, "NULL DEFAULT NULL", 'Attribution text')
            ->addColumn(SocialMediaAsset::schema_fields_LICENSE_CONFIRMED_AT, 'datetime', null, 'NULL DEFAULT NULL', 'License confirmed at')
            ->addColumn(SocialMediaAsset::schema_fields_CREATED_AT, 'datetime', null, 'NULL DEFAULT NULL', 'Created at')
            ->addIndex('UNIQUE', 'uniq_social_asset_checksum', [SocialMediaAsset::schema_fields_WEBSITE_ID, SocialMediaAsset::schema_fields_CHECKSUM], '同站 checksum 唯一')
            ->create(false);
    }

    private function ensureAutopilotPlanTable(): void
    {
        /** @var SocialAutopilotPlan $model */
        $model = ObjectManager::getInstance(SocialAutopilotPlan::class);
        $db = $this->dbSetup();
        $table = $model->getTable();
        if ($db->tableExist($table)) {
            return;
        }

        $db->createTable($table, 'Social autopilot plan')
            ->addColumn(SocialAutopilotPlan::schema_fields_ID, 'int', null, 'auto_increment primary key', 'Plan ID')
            ->addColumn(SocialAutopilotPlan::schema_fields_WEBSITE_ID, 'int', null, 'NOT NULL DEFAULT 0', 'Website ID')
            ->addColumn(SocialAutopilotPlan::schema_fields_ENABLED, 'int', null, 'NOT NULL DEFAULT 0', 'Enabled')
            ->addColumn(SocialAutopilotPlan::schema_fields_TIMEZONE, 'varchar', 64, "NOT NULL DEFAULT 'Asia/Shanghai'", 'Timezone')
            ->addColumn(SocialAutopilotPlan::schema_fields_SLOTS_JSON, 'text', null, 'NULL', 'Daily slots JSON')
            ->addColumn(SocialAutopilotPlan::schema_fields_MIX_JSON, 'text', null, 'NULL', 'Weekly content mix JSON')
            ->addColumn(SocialAutopilotPlan::schema_fields_TOPIC_RATIO_JSON, 'text', null, 'NULL', 'Topic source ratio JSON')
            ->addColumn(SocialAutopilotPlan::schema_fields_AUTO_PLATFORM_CODES_JSON, 'text', null, 'NULL', 'Auto publish platform codes JSON')
            ->addColumn(SocialAutopilotPlan::schema_fields_REVIEW_VIDEO, 'int', null, 'NOT NULL DEFAULT 1', 'Review video before publish')
            ->addColumn(SocialAutopilotPlan::schema_fields_QUOTA_JSON, 'text', null, 'NULL', 'Per platform quota JSON')
            ->addColumn(SocialAutopilotPlan::schema_fields_MODE, 'varchar', 16, "NOT NULL DEFAULT 'auto'", 'Publish mode')
            ->addColumn(SocialAutopilotPlan::schema_fields_MAX_DAILY, 'int', null, 'NOT NULL DEFAULT 6', 'Max posts per day')
            ->addColumn(SocialAutopilotPlan::schema_fields_DAILY_COUNT, 'int', null, 'NOT NULL DEFAULT 1', 'Daily generation count')
            ->addColumn(SocialAutopilotPlan::schema_fields_CONTENT_TYPES_JSON, 'text', null, "NULL DEFAULT '[\"text\"]'", 'Content types JSON')
            ->addColumn(SocialAutopilotPlan::schema_fields_IMAGE_MODEL_CODE, 'varchar', 191, "NOT NULL DEFAULT ''", 'Image model code')
            ->addColumn(SocialAutopilotPlan::schema_fields_VIDEO_MODEL_CODE, 'varchar', 191, "NOT NULL DEFAULT ''", 'Video model code')
            ->addColumn(SocialAutopilotPlan::schema_fields_VIDEO_SOURCE, 'varchar', 24, "NOT NULL DEFAULT 'image_slideshow'", 'Video source')
            ->addColumn(SocialAutopilotPlan::schema_fields_CREATED_AT, 'datetime', null, 'NULL DEFAULT NULL', 'Created at')
            ->addColumn(SocialAutopilotPlan::schema_fields_UPDATED_AT, 'datetime', null, 'NULL DEFAULT NULL', 'Updated at')
            ->addIndex('UNIQUE', 'uniq_social_autopilot_website', SocialAutopilotPlan::schema_fields_WEBSITE_ID, '每站一份计划')
            ->create(false);
    }

    private function ensureAutopilotPlanMediaColumns(): void
    {
        /** @var SocialAutopilotPlan $model */
        $model = ObjectManager::getInstance(SocialAutopilotPlan::class);
        $this->addColumns($model, [
            SocialAutopilotPlan::schema_fields_DAILY_COUNT => [
                'after' => SocialAutopilotPlan::schema_fields_MAX_DAILY,
                'type' => 'int',
                'length' => null,
                'options' => 'NOT NULL DEFAULT 1',
                'comment' => 'Daily generation count',
            ],
            SocialAutopilotPlan::schema_fields_CONTENT_TYPES_JSON => [
                'after' => SocialAutopilotPlan::schema_fields_DAILY_COUNT,
                'type' => 'text',
                'length' => null,
                'options' => "NULL DEFAULT '[\"text\"]'",
                'comment' => 'Content types JSON',
            ],
            SocialAutopilotPlan::schema_fields_IMAGE_MODEL_CODE => [
                'after' => SocialAutopilotPlan::schema_fields_CONTENT_TYPES_JSON,
                'type' => 'varchar',
                'length' => 191,
                'options' => "NOT NULL DEFAULT ''",
                'comment' => 'Image model code',
            ],
            SocialAutopilotPlan::schema_fields_VIDEO_MODEL_CODE => [
                'after' => SocialAutopilotPlan::schema_fields_IMAGE_MODEL_CODE,
                'type' => 'varchar',
                'length' => 191,
                'options' => "NOT NULL DEFAULT ''",
                'comment' => 'Video model code',
            ],
            SocialAutopilotPlan::schema_fields_VIDEO_SOURCE => [
                'after' => SocialAutopilotPlan::schema_fields_VIDEO_MODEL_CODE,
                'type' => 'varchar',
                'length' => 24,
                'options' => "NOT NULL DEFAULT 'image_slideshow'",
                'comment' => 'Video source',
            ],
        ]);
    }

    private function ensureExtensionDeviceTable(): void
    {
        /** @var SocialExtensionDevice $model */
        $model = ObjectManager::getInstance(SocialExtensionDevice::class);
        $db = $this->dbSetup();
        $table = $model->getTable();
        if ($db->tableExist($table)) {
            return;
        }

        $db->createTable($table, 'Social extension device')
            ->addColumn(SocialExtensionDevice::schema_fields_ID, 'int', null, 'auto_increment primary key', 'Device ID')
            ->addColumn('website_id', 'int', null, 'NOT NULL DEFAULT 0', 'Website ID')
            ->addColumn(SocialExtensionDevice::schema_fields_USER_ID, 'int', null, 'NOT NULL DEFAULT 0', 'User ID')
            ->addColumn(SocialExtensionDevice::schema_fields_NAME, 'varchar', 100, "NOT NULL DEFAULT ''", 'Device name')
            ->addColumn(SocialExtensionDevice::schema_fields_FP_HASH, 'varchar', 64, "NOT NULL DEFAULT ''", 'Fingerprint hash')
            ->addColumn(SocialExtensionDevice::schema_fields_TOKEN_HASH, 'varchar', 64, "NOT NULL DEFAULT ''", 'Token hash')
            ->addColumn(SocialExtensionDevice::schema_fields_VERSION, 'varchar', 32, "NOT NULL DEFAULT ''", 'Extension version')
            ->addColumn(SocialExtensionDevice::schema_fields_STATUS, 'varchar', 20, "NOT NULL DEFAULT 'active'", 'Status')
            ->addColumn(SocialExtensionDevice::schema_fields_LAST_SEEN_AT, 'datetime', null, 'NULL DEFAULT NULL', 'Last heartbeat')
            ->addColumn(SocialExtensionDevice::schema_fields_CREATED_AT, 'datetime', null, 'NULL DEFAULT NULL', 'Created at')
            ->addIndex('UNIQUE', 'uniq_social_ext_device', [SocialExtensionDevice::schema_fields_USER_ID, SocialExtensionDevice::schema_fields_FP_HASH], '设备唯一')
            ->addIndex('INDEX', 'idx_social_ext_device_website', 'website_id', '站点设备')
            ->create(false);
    }

    private function ensureExtensionDeviceWebsiteColumn(): void
    {
        /** @var SocialExtensionDevice $model */
        $model = ObjectManager::getInstance(SocialExtensionDevice::class);
        $this->addColumns($model, [
            'website_id' => [
                'after' => SocialExtensionDevice::schema_fields_USER_ID,
                'type' => 'int',
                'length' => null,
                'options' => 'NOT NULL DEFAULT 0',
                'comment' => 'Website ID',
            ],
        ]);
    }

    private function ensureExtensionJobTable(): void
    {
        /** @var SocialExtensionJob $model */
        $model = ObjectManager::getInstance(SocialExtensionJob::class);
        $db = $this->dbSetup();
        $table = $model->getTable();
        if ($db->tableExist($table)) {
            return;
        }

        $db->createTable($table, 'Social extension publish job')
            ->addColumn(SocialExtensionJob::schema_fields_ID, 'int', null, 'auto_increment primary key', 'Job ID')
            ->addColumn(SocialExtensionJob::schema_fields_TARGET_ID, 'int', null, 'NOT NULL DEFAULT 0', 'Publish target ID')
            ->addColumn(SocialExtensionJob::schema_fields_ACCOUNT_ID, 'int', null, 'NOT NULL DEFAULT 0', 'Account ID')
            ->addColumn(SocialExtensionJob::schema_fields_WEBSITE_ID, 'int', null, 'NOT NULL DEFAULT 0', 'Website ID')
            ->addColumn(SocialExtensionJob::schema_fields_PLATFORM_CODE, 'varchar', 64, "NOT NULL DEFAULT ''", 'Platform code')
            ->addColumn(SocialExtensionJob::schema_fields_CONTENT_KIND, 'varchar', 16, "NOT NULL DEFAULT 'text'", 'Content kind')
            ->addColumn(SocialExtensionJob::schema_fields_RECIPE_VERSION, 'varchar', 16, "NOT NULL DEFAULT ''", 'Recipe version')
            ->addColumn(SocialExtensionJob::schema_fields_PAYLOAD_JSON, 'text', null, 'NULL', 'Job payload JSON')
            ->addColumn(SocialExtensionJob::schema_fields_STATUS, 'varchar', 20, "NOT NULL DEFAULT 'pending'", 'Job status')
            ->addColumn(SocialExtensionJob::schema_fields_ATTEMPTS, 'int', null, 'NOT NULL DEFAULT 0', 'Attempts')
            ->addColumn(SocialExtensionJob::schema_fields_CLAIM_TOKEN, 'varchar', 64, 'NULL DEFAULT NULL', 'Claim token')
            ->addColumn(SocialExtensionJob::schema_fields_CLAIM_EXPIRES_AT, 'datetime', null, 'NULL DEFAULT NULL', 'Claim expires at')
            ->addColumn(SocialExtensionJob::schema_fields_CLAIMED_BY, 'varchar', 64, 'NULL DEFAULT NULL', 'Claimed by device')
            ->addColumn(SocialExtensionJob::schema_fields_RESULT_JSON, 'text', null, 'NULL', 'Result JSON')
            ->addColumn(SocialExtensionJob::schema_fields_ERROR_CODE, 'varchar', 64, 'NULL DEFAULT NULL', 'Error code')
            ->addColumn(SocialExtensionJob::schema_fields_CREATED_AT, 'datetime', null, 'NULL DEFAULT NULL', 'Created at')
            ->addColumn(SocialExtensionJob::schema_fields_UPDATED_AT, 'datetime', null, 'NULL DEFAULT NULL', 'Updated at')
            ->addIndex('INDEX', 'idx_social_ext_job_status', SocialExtensionJob::schema_fields_STATUS, '任务状态')
            ->addIndex('INDEX', 'idx_social_ext_job_account', [SocialExtensionJob::schema_fields_ACCOUNT_ID, SocialExtensionJob::schema_fields_STATUS], '账号任务')
            ->create(false);
    }

    private function ensureExtensionStepTable(): void
    {
        /** @var SocialExtensionStep $model */
        $model = ObjectManager::getInstance(SocialExtensionStep::class);
        $db = $this->dbSetup();
        $table = $model->getTable();
        if ($db->tableExist($table)) {
            return;
        }

        $db->createTable($table, 'Social extension step')
            ->addColumn(SocialExtensionStep::schema_fields_ID, 'int', null, 'auto_increment primary key', 'Step ID')
            ->addColumn(SocialExtensionStep::schema_fields_JOB_ID, 'int', null, 'NOT NULL DEFAULT 0', 'Job ID')
            ->addColumn(SocialExtensionStep::schema_fields_STEP_NO, 'int', null, 'NOT NULL DEFAULT 0', 'Step number')
            ->addColumn(SocialExtensionStep::schema_fields_ACTION, 'varchar', 32, "NOT NULL DEFAULT ''", 'Action')
            ->addColumn(SocialExtensionStep::schema_fields_SNAPSHOT_HASH, 'varchar', 64, "NOT NULL DEFAULT ''", 'Snapshot hash')
            ->addColumn(SocialExtensionStep::schema_fields_DECISION_JSON, 'text', null, 'NULL', 'Decision JSON')
            ->addColumn(SocialExtensionStep::schema_fields_RESULT, 'varchar', 20, "NOT NULL DEFAULT ''", 'Step result')
            ->addColumn(SocialExtensionStep::schema_fields_DURATION_MS, 'int', null, 'NOT NULL DEFAULT 0', 'Duration in milliseconds')
            ->addColumn(SocialExtensionStep::schema_fields_CREATED_AT, 'datetime', null, 'NULL DEFAULT NULL', 'Created at')
            ->addIndex('INDEX', 'idx_social_ext_step_job', [SocialExtensionStep::schema_fields_JOB_ID, SocialExtensionStep::schema_fields_STEP_NO], '任务步骤')
            ->create(false);
    }

    private function ensurePublishTargetColumns(): void
    {
        /** @var SocialPublishTarget $model */
        $model = ObjectManager::getInstance(SocialPublishTarget::class);
        $this->addColumns($model, [
            SocialPublishTarget::schema_fields_CHANNEL => [
                'after' => SocialPublishTarget::schema_fields_PLATFORM_CODE,
                'type' => 'varchar',
                'length' => 32,
                'options' => "NOT NULL DEFAULT ''",
                'comment' => 'Publish channel',
            ],
            SocialPublishTarget::schema_fields_CONTENT_KIND => [
                'after' => SocialPublishTarget::schema_fields_CHANNEL,
                'type' => 'varchar',
                'length' => 16,
                'options' => "NOT NULL DEFAULT ''",
                'comment' => 'Content kind',
            ],
            SocialPublishTarget::schema_fields_BLOCK_REASON => [
                'after' => SocialPublishTarget::schema_fields_CONTENT_KIND,
                'type' => 'varchar',
                'length' => 64,
                'options' => 'NULL DEFAULT NULL',
                'comment' => 'Blocked reason',
            ],
        ]);
    }

    private function ensureCreativeDraftColumns(): void
    {
        /** @var SocialCreativeDraft $model */
        $model = ObjectManager::getInstance(SocialCreativeDraft::class);
        $this->addColumns($model, [
            SocialCreativeDraft::schema_fields_REVIEW_STATUS => [
                'after' => SocialCreativeDraft::schema_fields_STATUS,
                'type' => 'varchar',
                'length' => 20,
                'options' => "NOT NULL DEFAULT 'none'",
                'comment' => 'Review status',
            ],
            SocialCreativeDraft::schema_fields_TOPIC_SOURCE => [
                'after' => SocialCreativeDraft::schema_fields_REVIEW_STATUS,
                'type' => 'varchar',
                'length' => 16,
                'options' => "NOT NULL DEFAULT 'site'",
                'comment' => 'Topic source',
            ],
            SocialCreativeDraft::schema_fields_TOPIC_SOURCE_URL => [
                'after' => SocialCreativeDraft::schema_fields_TOPIC_SOURCE,
                'type' => 'varchar',
                'length' => 512,
                'options' => 'NULL DEFAULT NULL',
                'comment' => 'Topic source URL',
            ],
        ]);
    }

    /**
     * @param array<string, array{after:string,type:string,length:int|string|null,options:string,comment:string}> $columns
     */
    private function addColumns(AbstractModel $model, array $columns): void
    {
        $db = $this->dbSetup();
        $table = $model->getTable();
        if (!$db->tableExist($table)) {
            return;
        }

        $missing = [];
        foreach (\array_keys($columns) as $column) {
            if (!$db->columnExist($table, $column)) {
                $missing[] = $column;
            }
        }
        if ($missing === []) {
            return;
        }

        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($model);
        $alter = $modelSetup->alterTable();
        foreach ($missing as $column) {
            $definition = $columns[$column];
            $alter->addColumn(
                $column,
                $definition['after'],
                $definition['type'],
                $definition['length'],
                $definition['options'],
                $definition['comment']
            );
        }
    }

    private function dbSetup(): DbSetup
    {
        return (new DbSetup())->setConnection(ConnectionFactory::getInstance());
    }
}
