<?php

declare(strict_types=1);

namespace Weline\SessionManager\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '认证设备会话表')]
#[Index(name: 'uk_authenticated_device_public_id', columns: ['public_id'], type: 'UNIQUE', comment: '公开设备 ID 唯一')]
#[Index(name: 'uk_authenticated_device_session', columns: ['auth_area', 'session_digest'], type: 'UNIQUE', comment: '认证区域 Session 绑定唯一')]
#[Index(name: 'idx_authenticated_device_owner', columns: ['auth_area', 'principal_id', 'revoked_at', 'last_seen_at'], comment: '身份设备列表')]
#[Index(name: 'idx_authenticated_device_expiry', columns: ['session_expires_at', 'remembered_until'], comment: '过期设备清理')]
class AuthenticatedDevice extends Model
{
    public const schema_table = 'weline_authenticated_device';
    public const schema_primary_key = 'device_row_id';
    public const schema_primary_keys = ['device_row_id'];

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '内部设备行 ID')]
    public const schema_fields_ID = 'device_row_id';

    #[Col('varchar', 43, nullable: false, comment: '随机公开设备 ID')]
    public const schema_fields_PUBLIC_ID = 'public_id';

    #[Col('varchar', 16, nullable: false, comment: '认证区域')]
    public const schema_fields_AUTH_AREA = 'auth_area';

    #[Col('varchar', 191, nullable: false, comment: '区域内身份 ID')]
    public const schema_fields_PRINCIPAL_ID = 'principal_id';

    #[Col('varchar', 64, nullable: false, comment: 'Session SHA-256 摘要')]
    public const schema_fields_SESSION_DIGEST = 'session_digest';

    #[Col('varchar', 160, nullable: false, default: '', comment: '设备显示名称')]
    public const schema_fields_DEVICE_NAME = 'device_name';

    #[Col('varchar', 80, nullable: false, default: '', comment: '浏览器')]
    public const schema_fields_BROWSER = 'browser';

    #[Col('varchar', 80, nullable: false, default: '', comment: '操作系统')]
    public const schema_fields_OPERATING_SYSTEM = 'operating_system';

    #[Col('varchar', 64, nullable: false, default: '', comment: '最近 IP')]
    public const schema_fields_LAST_IP = 'last_ip';

    #[Col('datetime', nullable: false, comment: '首次登录时间')]
    public const schema_fields_FIRST_SEEN_AT = 'first_seen_at';

    #[Col('datetime', nullable: false, comment: '最近活动时间')]
    public const schema_fields_LAST_SEEN_AT = 'last_seen_at';

    #[Col('datetime', nullable: false, comment: 'Session 到期时间')]
    public const schema_fields_SESSION_EXPIRES_AT = 'session_expires_at';

    #[Col('datetime', nullable: true, comment: '记住凭证到期时间')]
    public const schema_fields_REMEMBERED_UNTIL = 'remembered_until';

    #[Col('datetime', nullable: true, comment: '撤销时间')]
    public const schema_fields_REVOKED_AT = 'revoked_at';

    #[Col('varchar', 64, nullable: false, default: '', comment: '撤销原因')]
    public const schema_fields_REVOKE_REASON = 'revoke_reason';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
}
