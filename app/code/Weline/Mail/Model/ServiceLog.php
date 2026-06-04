<?php
declare(strict_types=1);

namespace Weline\Mail\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '企业邮箱服务日志表')]
#[Index(name: 'idx_weline_mail_log_type', columns: ['log_type'])]
#[Index(name: 'idx_weline_mail_log_created', columns: ['created_at'])]
class ServiceLog extends Model
{
    public const schema_table = 'weline_mail_service_log';
    public const schema_primary_key = 'log_id';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '日志ID')]
    public const schema_fields_ID = 'log_id';

    #[Col('varchar', 32, nullable: false, comment: '日志类型')]
    public const schema_fields_LOG_TYPE = 'log_type';

    #[Col('varchar', 32, default: 'info', comment: '日志级别')]
    public const schema_fields_LEVEL = 'level';

    #[Col('varchar', 255, nullable: false, comment: '标题')]
    public const schema_fields_TITLE = 'title';

    #[Col('text', nullable: true, comment: '日志内容')]
    public const schema_fields_CONTENT = 'content';

    #[Col('text', nullable: true, comment: '上下文JSON')]
    public const schema_fields_CONTEXT_JSON = 'context_json';

    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
}
