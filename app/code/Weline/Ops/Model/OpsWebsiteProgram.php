<?php

declare(strict_types=1);

namespace Weline\Ops\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '网站 AI 运营程序')]
#[Index(name: 'uniq_ops_program_website', columns: ['website_id'], type: 'UNIQUE')]
class OpsWebsiteProgram extends Model
{
    public const schema_table = 'weline_ops_website_program';
    public const schema_primary_key = 'program_id';

    public array $_unit_primary_keys = ['program_id'];
    public array $_index_sort_keys = ['program_id', 'website_id', 'enabled'];

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '程序ID')]
    public const schema_fields_PROGRAM_ID = 'program_id';

    #[Col(type: 'int', nullable: false, default: 0, comment: '网站ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col(type: 'tinyint', nullable: false, default: 0, comment: '是否启用')]
    public const schema_fields_ENABLED = 'enabled';

    #[Col(type: 'tinyint', nullable: false, default: 1, comment: '外发须审批')]
    public const schema_fields_REQUIRE_APPROVAL = 'require_approval';

    #[Col(type: 'varchar', length: 32, nullable: false, default: '09:00', comment: '日更本地时刻 HH:MM')]
    public const schema_fields_DAILY_TIME = 'daily_time';

    #[Col(type: 'text', comment: '扩展 JSON')]
    public const schema_fields_META_JSON = 'meta_json';

    #[Col(type: 'timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col(type: 'timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
}
