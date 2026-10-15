<?php
declare(strict_types=1);

namespace Weline\Smtp\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Smtp 中继/提交账户（与邮局 MailAccount 分离）')]
#[Index(name: 'idx_weline_smtp_account_email', columns: ['email'], type: 'UNIQUE')]
#[Index(name: 'idx_weline_smtp_account_domain', columns: ['domain_name'])]
#[Index(name: 'idx_weline_smtp_account_status', columns: ['status'])]
class SmtpAccount extends Model
{
    public const schema_table = 'weline_smtp_account';
    public const schema_primary_key = 'smtp_account_id';

    public const ROLE_SUBMIT = 'submit';
    public const ROLE_RELAY = 'relay';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Smtp 账户ID')]
    public const schema_fields_ID = 'smtp_account_id';

    #[Col('varchar', 255, nullable: false, comment: 'Smtp 服务域名')]
    public const schema_fields_DOMAIN_NAME = 'domain_name';

    #[Col('varchar', 255, nullable: false, comment: '完整邮箱地址')]
    public const schema_fields_EMAIL = 'email';

    #[Col('varchar', 120, nullable: true, comment: '显示名称')]
    public const schema_fields_DISPLAY_NAME = 'display_name';

    #[Col('varchar', 16, default: 'submit', comment: 'submit|relay')]
    public const schema_fields_ROLE = 'account_role';

    #[Col('varchar', 32, default: 'active', comment: '状态')]
    public const schema_fields_STATUS = 'status';

    #[Col('text', nullable: true, comment: '密码密封')]
    public const schema_fields_PASSWORD_SEALED = 'password_sealed';

    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
}
