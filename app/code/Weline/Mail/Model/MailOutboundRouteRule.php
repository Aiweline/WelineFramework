<?php
declare(strict_types=1);

namespace Weline\Mail\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 收件域 → 认证中继导流规则（本机邮件库全局）。
 */
#[Table(comment: '企业邮箱收件域外投导流规则')]
#[Index(name: 'idx_weline_mail_ob_route_domain', columns: ['match_domain'])]
#[Index(name: 'idx_weline_mail_ob_route_priority', columns: ['priority', 'enabled'])]
class MailOutboundRouteRule extends Model
{
    public const schema_table = 'weline_mail_outbound_route_rule';
    public const schema_primary_key = 'rule_id';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '规则ID')]
    public const schema_fields_ID = 'rule_id';

    #[Col('varchar', 255, nullable: false, comment: '收件域字面量（如 qq.com）')]
    public const schema_fields_MATCH_DOMAIN = 'match_domain';

    #[Col('int', default: 100, comment: '优先级（升序先匹配）')]
    public const schema_fields_PRIORITY = 'priority';

    #[Col('tinyint', default: 1, comment: '是否启用')]
    public const schema_fields_ENABLED = 'enabled';

    #[Col('varchar', 255, nullable: false, comment: '中继主机')]
    public const schema_fields_RELAY_HOST = 'relay_host';

    #[Col('int', default: 587, comment: '中继端口（禁 25）')]
    public const schema_fields_RELAY_PORT = 'relay_port';

    #[Col('varchar', 255, nullable: true, comment: '中继用户名')]
    public const schema_fields_RELAY_USERNAME = 'relay_username';

    #[Col('text', nullable: true, comment: '中继密码密封')]
    public const schema_fields_RELAY_PASSWORD_SEALED = 'relay_password_sealed';

    #[Col('varchar', 16, default: 'starttls', comment: 'TLS：starttls|smtps|none')]
    public const schema_fields_RELAY_TLS = 'relay_tls';

    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
}
