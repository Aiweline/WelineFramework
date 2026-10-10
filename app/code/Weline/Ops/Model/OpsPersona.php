<?php

declare(strict_types=1);

namespace Weline\Ops\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '网站 AI 运营画像')]
#[Index(name: 'uniq_ops_persona_website', columns: ['website_id'], type: 'UNIQUE')]
class OpsPersona extends Model
{
    public const schema_table = 'weline_ops_persona';
    public const schema_primary_key = 'persona_id';

    public array $_unit_primary_keys = ['persona_id'];
    public array $_index_sort_keys = ['persona_id', 'website_id'];

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '画像ID')]
    public const schema_fields_PERSONA_ID = 'persona_id';

    #[Col(type: 'int', nullable: false, default: 0, comment: '网站ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: '摘要标题')]
    public const schema_fields_TITLE = 'title';

    #[Col(type: 'text', comment: '画像正文（结构化记忆）')]
    public const schema_fields_SUMMARY = 'summary';

    #[Col(type: 'text', comment: '受众 JSON')]
    public const schema_fields_AUDIENCE_JSON = 'audience_json';

    #[Col(type: 'text', comment: '内容支柱 JSON')]
    public const schema_fields_PILLARS_JSON = 'pillars_json';

    #[Col(type: 'text', comment: '语气/风格 JSON')]
    public const schema_fields_TONE_JSON = 'tone_json';

    #[Col(type: 'text', comment: '原始 Agent 输出 JSON')]
    public const schema_fields_RAW_JSON = 'raw_json';

    #[Col(type: 'timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col(type: 'timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
}
