<?php

declare(strict_types=1);

namespace Weline\Seo\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 自动/手动处理台账：记录对 sitemap 做过的动作，供 cron 每轮重新断言与回滚。
 *
 * 之所以单独一张表：Provider 同步会把 status=0 的 URL 重新置 1，
 * 只靠 sitemap_url.status 无法保持“已移出”。
 */
#[Table(comment: 'SEO Sitemap 处理动作台账')]
#[Index(name: 'uk_sitemap_action_dedupe', columns: ['dedupe_key'], type: 'UNIQUE', comment: '幂等键')]
#[Index(name: 'idx_sitemap_action_website_status', columns: ['website_id', 'status', 'action_type'], comment: '按站点与状态查')]
#[Index(name: 'idx_sitemap_action_url', columns: ['website_id', 'url_hash', 'status'], comment: '按 URL 查')]
#[Index(name: 'idx_sitemap_action_run', columns: ['run_id'], comment: '按跑批查')]
class SitemapAction extends Model
{
    public const schema_table = 'weline_seo_sitemap_action';
    public const schema_primary_key = 'action_id';
    public array $_unit_primary_keys = ['action_id'];
    public array $_index_sort_keys = ['action_id', 'website_id', 'status'];

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_REVERTED = 'reverted';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DRY_RUN = 'dry_run';

    public const TYPE_REMOVE = 'remove';
    public const TYPE_RESTORE = 'restore';
    public const TYPE_REPLACE = 'replace';
    public const TYPE_REDIRECT = 'redirect_add';
    public const TYPE_NOTIFY = 'notify';
    public const TYPE_EXCLUDE = 'exclude';

    public const SOURCE_AUTO = 'auto';
    public const SOURCE_MANUAL = 'manual';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '主键')]
    public const schema_fields_ID = 'action_id';
    #[Col(type: 'int', nullable: false, default: 0, comment: '站点ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'URL 哈希')]
    public const schema_fields_URL_HASH = 'url_hash';
    #[Col(type: 'text', nullable: false, comment: 'URL 地址')]
    public const schema_fields_URL = 'url';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'Sitemap URL ID')]
    public const schema_fields_SITEMAP_URL_ID = 'sitemap_url_id';
    #[Col(type: 'varchar', length: 40, nullable: false, default: '', comment: '问题类型')]
    public const schema_fields_ISSUE_CODE = 'issue_code';
    #[Col(type: 'varchar', length: 30, nullable: false, comment: '动作类型')]
    public const schema_fields_ACTION_TYPE = 'action_type';
    #[Col(type: 'varchar', length: 20, nullable: false, default: 'pending', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'varchar', length: 20, nullable: false, default: 'auto', comment: '来源')]
    public const schema_fields_SOURCE = 'source';
    #[Col(type: 'text', nullable: true, comment: '规则快照 JSON')]
    public const schema_fields_POLICY_SNAPSHOT = 'policy_snapshot';
    #[Col(type: 'text', nullable: true, comment: '动作前状态 JSON')]
    public const schema_fields_BEFORE_STATE = 'before_state';
    #[Col(type: 'text', nullable: true, comment: '动作后状态 JSON')]
    public const schema_fields_AFTER_STATE = 'after_state';
    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: '原因')]
    public const schema_fields_REASON = 'reason';
    #[Col(type: 'int', nullable: false, default: 0, comment: '跑批ID')]
    public const schema_fields_RUN_ID = 'run_id';
    #[Col(type: 'varchar', length: 191, nullable: true, comment: '幂等键')]
    public const schema_fields_DEDUPE_KEY = 'dedupe_key';
    #[Col(type: 'datetime', nullable: true, comment: '生效时间')]
    public const schema_fields_APPLIED_AT = 'applied_at';
    #[Col(type: 'datetime', nullable: true, comment: '回滚时间')]
    public const schema_fields_REVERT_AT = 'revert_at';
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
        if ($this->getData(self::schema_fields_DEDUPE_KEY) === '') {
            $this->setData(self::schema_fields_DEDUPE_KEY, null);
        }
    }

    public function getBeforeState(): array
    {
        return $this->decodeJson(self::schema_fields_BEFORE_STATE);
    }

    public function setBeforeState(array $state): static
    {
        return $this->setData(self::schema_fields_BEFORE_STATE, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function getAfterState(): array
    {
        return $this->decodeJson(self::schema_fields_AFTER_STATE);
    }

    public function setAfterState(array $state): static
    {
        return $this->setData(self::schema_fields_AFTER_STATE, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function getPolicySnapshot(): array
    {
        return $this->decodeJson(self::schema_fields_POLICY_SNAPSHOT);
    }

    public function setPolicySnapshot(array $snapshot): static
    {
        return $this->setData(self::schema_fields_POLICY_SNAPSHOT, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $field): array
    {
        $raw = $this->getData($field);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
