<?php

declare(strict_types=1);

namespace Weline\Seo\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * URL 健康观测历史（每次探测一行，append-only）。
 */
#[Table(comment: 'SEO URL 健康观测历史')]
#[Index(name: 'idx_health_obs_health', columns: ['health_id', 'checked_at'], comment: '按 URL 查历史')]
#[Index(name: 'idx_health_obs_run', columns: ['run_id'], comment: '按跑批查')]
#[Index(name: 'idx_health_obs_website', columns: ['website_id', 'checked_at'], comment: '按站点查')]
class UrlHealthObservation extends Model
{
    public const schema_table = 'weline_seo_url_health_observation';
    public const schema_primary_key = 'observation_id';
    public array $_unit_primary_keys = ['observation_id'];
    public array $_index_sort_keys = ['observation_id', 'run_id'];

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '主键')]
    public const schema_fields_ID = 'observation_id';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'URL 健康记录ID')]
    public const schema_fields_HEALTH_ID = 'health_id';
    #[Col(type: 'int', nullable: false, default: 0, comment: '跑批ID')]
    public const schema_fields_RUN_ID = 'run_id';
    #[Col(type: 'int', nullable: false, default: 0, comment: '站点ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col(type: 'varchar', length: 40, nullable: false, default: 'unknown', comment: '问题类型')]
    public const schema_fields_ISSUE_CODE = 'issue_code';
    #[Col(type: 'smallint', nullable: false, default: 0, comment: 'HTTP 状态码')]
    public const schema_fields_HTTP_STATUS = 'http_status';
    #[Col(type: 'int', nullable: false, default: 0, comment: '响应耗时 ms')]
    public const schema_fields_RESPONSE_MS = 'response_ms';
    #[Col(type: 'text', nullable: true, comment: '重定向目标')]
    public const schema_fields_REDIRECT_TO = 'redirect_to';
    #[Col(type: 'varchar', length: 20, nullable: false, default: 'unknown', comment: 'robots 状态')]
    public const schema_fields_ROBOTS_STATE = 'robots_state';
    #[Col(type: 'smallint', nullable: false, default: 0, comment: '是否软 404')]
    public const schema_fields_SOFT_404 = 'soft_404';
    #[Col(type: 'text', nullable: true, comment: '判定证据 JSON')]
    public const schema_fields_EVIDENCE = 'evidence';
    #[Col(type: 'datetime', nullable: true, comment: '检查时间')]
    public const schema_fields_CHECKED_AT = 'checked_at';
    #[Col(type: 'datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

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
        if (!$this->getData(self::schema_fields_CHECKED_AT)) {
            $this->setData(self::schema_fields_CHECKED_AT, date('Y-m-d H:i:s'));
        }
    }
}
