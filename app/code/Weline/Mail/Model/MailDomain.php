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

    #[Col('varchar', 45, nullable: true, comment: '邮件公网源站IP')]
    public const schema_fields_ORIGIN_IP = 'origin_ip';

    #[Col('varchar', 63, nullable: true, comment: '实际DKIM选择器')]
    public const schema_fields_DKIM_SELECTOR = 'dkim_selector';

    #[Col('text', nullable: true, comment: '实际DKIM公钥（公开DNS值）')]
    public const schema_fields_DKIM_PUBLIC_KEY = 'dkim_public_key';

    #[Col('text', nullable: true, comment: 'DNS 检测结果JSON')]
    public const schema_fields_DNS_STATUS_JSON = 'dns_status_json';

    /** @deprecated 邮局⊥Smtp：业务停用，列保留兼容已部署库 */
    #[Col('int', nullable: true, comment: '（停用）旧默认 Smtp 提交账号 ID')]
    public const schema_fields_DEFAULT_SMTP_ACCOUNT_ID = 'default_smtp_account_id';

    #[Col('varchar', 255, nullable: true, comment: '企业邮箱外投 smarthost 主机')]
    public const schema_fields_OUTBOUND_RELAY_HOST = 'outbound_relay_host';

    #[Col('int', nullable: true, comment: '企业邮箱外投 smarthost 端口')]
    public const schema_fields_OUTBOUND_RELAY_PORT = 'outbound_relay_port';

    #[Col('varchar', 255, nullable: true, comment: '企业邮箱外投 smarthost 用户名')]
    public const schema_fields_OUTBOUND_RELAY_USERNAME = 'outbound_relay_username';

    #[Col('text', nullable: true, comment: '企业邮箱外投 smarthost 密码密封')]
    public const schema_fields_OUTBOUND_RELAY_PASSWORD_SEALED = 'outbound_relay_password_sealed';

    #[Col('varchar', 16, default: 'starttls', comment: '外投 TLS：starttls|smtps|none')]
    public const schema_fields_OUTBOUND_RELAY_TLS = 'outbound_relay_tls';

    #[Col('datetime', nullable: true, comment: '最后检测时间')]
    public const schema_fields_LAST_CHECKED_AT = 'last_checked_at';

    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
}
