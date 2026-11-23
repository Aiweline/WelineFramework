<?php

declare(strict_types=1);

namespace Weline\Seo\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 站点 × 问题类型的自动处理规则。
 *
 * website_id=0 表示全站默认；站点行覆盖默认行。
 */
#[Table(comment: 'SEO URL 健康处理规则')]
#[Index(name: 'uk_health_policy_website_issue', columns: ['website_id', 'issue_code'], type: 'UNIQUE', comment: '站点内问题唯一')]
#[Index(name: 'idx_health_policy_issue', columns: ['issue_code'], comment: '按问题查')]
class HealthPolicy extends Model
{
    public const schema_table = 'weline_seo_health_policy';
    public const schema_primary_key = 'policy_id';
    public array $_unit_primary_keys = ['policy_id'];
    public array $_index_sort_keys = ['policy_id', 'website_id', 'issue_code'];

    public const AUTO_OFF = 'off';
    public const AUTO_NOTIFY = 'notify';
    public const AUTO_AUTO = 'auto';
    public const AUTO_DRY_RUN = 'dry_run';

    public const ACTION_REMOVE = 'remove';
    public const ACTION_REPLACE = 'replace';
    public const ACTION_REDIRECT = 'redirect';
    public const ACTION_NOTIFY = 'notify';
    public const ACTION_EXCLUDE = 'exclude';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '主键')]
    public const schema_fields_ID = 'policy_id';
    #[Col(type: 'int', nullable: false, default: 0, comment: '站点ID（0=默认）')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col(type: 'varchar', length: 40, nullable: false, comment: '问题类型')]
    public const schema_fields_ISSUE_CODE = 'issue_code';
    #[Col(type: 'varchar', length: 20, nullable: false, default: 'notify', comment: '自动模式 off/notify/auto/dry_run')]
    public const schema_fields_AUTO_MODE = 'auto_mode';
    #[Col(type: 'varchar', length: 30, nullable: false, default: 'notify', comment: '处理方式')]
    public const schema_fields_ACTION = 'action';
    #[Col(type: 'int', nullable: false, default: 2, comment: '连续异常阈值')]
    public const schema_fields_GRACE_RUNS = 'grace_runs';
    #[Col(type: 'smallint', nullable: false, default: 1, comment: '是否自动恢复')]
    public const schema_fields_RESTORE_ENABLED = 'restore_enabled';
    #[Col(type: 'int', nullable: false, default: 3, comment: '恢复所需连续正常次数')]
    public const schema_fields_RESTORE_GRACE_RUNS = 'restore_grace_runs';
    #[Col(type: 'text', nullable: true, comment: '通知渠道 JSON')]
    public const schema_fields_NOTIFY_CHANNELS = 'notify_channels';
    #[Col(type: 'smallint', nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ENABLED = 'is_enabled';
    #[Col(type: 'int', nullable: false, default: 0, comment: '更新人')]
    public const schema_fields_UPDATED_BY = 'updated_by';
    #[Col(type: 'datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }

    public function save_before(): void
    {
        parent::save_before();

        if (!$this->getData(self::schema_fields_CREATED_AT)) {
            $this->setData(self::schema_fields_CREATED_AT, date('Y-m-d H:i:s'));
        }
        $this->setData(self::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));
    }

    public function getNotifyChannels(): array
    {
        $raw = $this->getData(self::schema_fields_NOTIFY_CHANNELS);
        if (!is_string($raw) || $raw === '') {
            return ['backend'];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values($decoded) : ['backend'];
    }

    public function setNotifyChannels(array $channels): static
    {
        return $this->setData(self::schema_fields_NOTIFY_CHANNELS, json_encode(array_values($channels), JSON_UNESCAPED_UNICODE));
    }
}
