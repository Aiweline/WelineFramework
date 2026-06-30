<?php
declare(strict_types=1);

namespace Weline\Mail\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '企业邮箱域名表')]
#[Index(name: 'idx_weline_mail_domain_name', columns: ['domain_name'], type: 'UNIQUE')]
#[Index(name: 'idx_weline_mail_domain_status', columns: ['status'])]
class MailDomain extends Model
{
    public const schema_table = 'weline_mail_domain';
    public const schema_primary_key = 'domain_id';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '域名ID')]
    public const schema_fields_ID = 'domain_id';

    #[Col('varchar', 255, nullable: false, comment: '邮箱域名')]
    public const schema_fields_DOMAIN_NAME = 'domain_name';

    #[Col('varchar', 255, nullable: false, comment: '邮件服务主机名')]
    public const schema_fields_HOSTNAME = 'hostname';

    #[Col('varchar', 32, default: 'stalwart', comment: '邮件引擎')]
    public const schema_fields_ENGINE = 'engine';

    #[Col('varchar', 32, default: 'pending', comment: '状态')]
    public const schema_fields_STATUS = 'status';

    #[Col('int', default: 1024, comment: '默认邮箱容量MB')]
    public const schema_fields_DEFAULT_QUOTA_MB = 'default_quota_mb';

    #[Col('text', nullable: true, comment: 'DNS 检测结果JSON')]
    public const schema_fields_DNS_STATUS_JSON = 'dns_status_json';

    #[Col('datetime', nullable: true, comment: '最后检测时间')]
    public const schema_fields_LAST_CHECKED_AT = 'last_checked_at';

    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
}
