<?php

declare(strict_types=1);

namespace Weline\Ops\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'AI 运营运行日志（日更分析等）')]
#[Index(name: 'idx_ops_run_website_kind', columns: ['website_id', 'kind', 'created_at'])]
class OpsRunLog extends Model
{
    public const schema_table = 'weline_ops_run_log';
    public const schema_primary_key = 'run_id';

    public const KIND_PERSONA = 'persona';
    public const KIND_DAILY_ANALYSIS = 'daily_analysis';
    public const KIND_DRAFT = 'draft';
    public const KIND_PUBLISH = 'publish';

    public array $_unit_primary_keys = ['run_id'];
    public array $_index_sort_keys = ['run_id', 'website_id', 'kind'];

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '运行ID')]
    public const schema_fields_RUN_ID = 'run_id';

    #[Col(type: 'int', nullable: false, default: 0, comment: '网站ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: '种类')]
    public const schema_fields_KIND = 'kind';

    #[Col(type: 'varchar', length: 32, nullable: false, default: 'ok', comment: '状态 ok/error')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: '摘要')]
    public const schema_fields_SUMMARY = 'summary';

    #[Col(type: 'text', comment: '正文/报告')]
    public const schema_fields_BODY = 'body';

    #[Col(type: 'text', comment: '证据 JSON')]
    public const schema_fields_EVIDENCE_JSON = 'evidence_json';

    #[Col(type: 'timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
}
