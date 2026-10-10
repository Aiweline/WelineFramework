<?php

declare(strict_types=1);

namespace Weline\Ops\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'AI 运营调度会话')]
#[Index(name: 'idx_ops_session_website_status', columns: ['website_id', 'status'])]
class OpsSession extends Model
{
    public const schema_table = 'weline_ops_session';
    public const schema_primary_key = 'session_id';

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    public array $_unit_primary_keys = ['session_id'];
    public array $_index_sort_keys = ['session_id', 'website_id', 'status'];

    #[Col(type: 'varchar', length: 64, primaryKey: true, nullable: false, comment: '会话UUID')]
    public const schema_fields_SESSION_ID = 'session_id';

    #[Col(type: 'int', nullable: false, default: 0, comment: '网站ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col(type: 'int', nullable: false, default: 0, comment: '运营程序ID')]
    public const schema_fields_PROGRAM_ID = 'program_id';

    #[Col(type: 'varchar', length: 32, nullable: false, default: 'open', comment: '状态 open/closed')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'text', comment: '用户配置 JSON')]
    public const schema_fields_CONFIG_JSON = 'config_json';

    #[Col(type: 'text', comment: '调度上下文 JSON')]
    public const schema_fields_CONTEXT_JSON = 'context_json';

    #[Col(type: 'timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col(type: 'timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
}
