<?php
declare(strict_types=1);

namespace Weline\Mail\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '企业邮箱账号表')]
#[Index(name: 'idx_weline_mail_account_email', columns: ['email'], type: 'UNIQUE')]
#[Index(name: 'idx_weline_mail_account_domain', columns: ['domain_id'])]
#[Index(name: 'idx_weline_mail_account_customer', columns: ['customer_id'])]
#[Index(name: 'idx_weline_mail_account_status', columns: ['status'])]
class MailAccount extends Model
{
    public const schema_table = 'weline_mail_account';
    public const schema_primary_key = 'account_id';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '账号ID')]
    public const schema_fields_ID = 'account_id';

    #[Col('int', nullable: false, comment: '域名ID')]
    public const schema_fields_DOMAIN_ID = 'domain_id';

    #[Col('int', default: 0, comment: '前台客户ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';

    #[Col('varchar', 255, nullable: false, comment: '邮箱地址')]
    public const schema_fields_EMAIL = 'email';

    #[Col('varchar', 120, nullable: true, comment: '显示名称')]
    public const schema_fields_DISPLAY_NAME = 'display_name';

    #[Col('int', default: 1024, comment: '容量MB')]
    public const schema_fields_QUOTA_MB = 'quota_mb';

    #[Col('varchar', 32, default: 'pending', comment: '账号状态')]
    public const schema_fields_STATUS = 'status';

    #[Col('datetime', nullable: true, comment: '最后同步时间')]
    public const schema_fields_LAST_SYNCED_AT = 'last_synced_at';

    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
}
