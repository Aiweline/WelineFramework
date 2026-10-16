<?php
declare(strict_types=1);

namespace Weline\Smtp\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Smtp 服务（可多个；self=本机启用入池 / external=外部填入）')]
#[Index(name: 'idx_weline_smtp_service_status', columns: ['status'])]
#[Index(name: 'idx_weline_smtp_service_mode', columns: ['service_mode'])]
class SmtpService extends Model
{
    public const schema_table = 'weline_smtp_service';
    public const schema_primary_key = 'smtp_service_id';

    public const MODE_SELF = 'self';
    public const MODE_EXTERNAL = 'external';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Smtp 服务ID')]
    public const schema_fields_ID = 'smtp_service_id';

    #[Col('varchar', 120, nullable: false, comment: '显示名称')]
    public const schema_fields_NAME = 'name';

    #[Col('varchar', 16, default: 'self', comment: 'self|external')]
    public const schema_fields_MODE = 'service_mode';

    #[Col('varchar', 32, default: 'draft', comment: 'draft|active|disabled')]
    public const schema_fields_STATUS = 'status';

    #[Col('varchar', 255, nullable: true, comment: '根域名（self 入池用）')]
    public const schema_fields_DOMAIN_NAME = 'domain_name';

    #[Col('varchar', 255, nullable: true, comment: '提交主机名 smtp.* 或外接主机')]
    public const schema_fields_SMTP_HOSTNAME = 'smtp_hostname';

    #[Col('int', default: 587, comment: '端口')]
    public const schema_fields_PORT = 'port';

    #[Col('varchar', 16, default: 'starttls', comment: 'starttls|smtps|none')]
    public const schema_fields_TLS = 'tls_mode';

    #[Col('varchar', 255, nullable: true, comment: '外部填入用户名')]
    public const schema_fields_USERNAME = 'username';

    #[Col('text', nullable: true, comment: '外部填入密码密封')]
    public const schema_fields_PASSWORD_SEALED = 'password_sealed';

    #[Col('tinyint', default: 0, comment: '是否开启中继服务（仅 self）')]
    public const schema_fields_RELAY_SERVICE = 'relay_service';

    #[Col('int', default: 0, comment: '域名池 domain_id')]
    public const schema_fields_POOL_DOMAIN_ID = 'pool_domain_id';

    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
}
