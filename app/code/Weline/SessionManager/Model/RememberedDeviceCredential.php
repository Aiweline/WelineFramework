<?php

declare(strict_types=1);

namespace Weline\SessionManager\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '逐设备记住登录凭证表')]
#[Index(name: 'uk_remembered_device', columns: ['device_id'], type: 'UNIQUE', comment: '每个设备最多一个凭证')]
#[Index(name: 'uk_remembered_device_token', columns: ['token_digest'], type: 'UNIQUE', comment: 'Token 摘要唯一')]
#[Index(name: 'idx_remembered_device_expiry', columns: ['expires_at', 'revoked_at'], comment: '凭证过期清理')]
class RememberedDeviceCredential extends Model
{
    public const schema_table = 'weline_remembered_device_credential';
    public const schema_primary_key = 'credential_id';
    public const schema_primary_keys = ['credential_id'];

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '凭证 ID')]
    public const schema_fields_ID = 'credential_id';

    #[Col('int', nullable: false, comment: '内部设备行 ID')]
    public const schema_fields_DEVICE_ID = 'device_id';

    #[Col('varchar', 64, nullable: false, comment: 'Token SHA-256 摘要')]
    public const schema_fields_TOKEN_DIGEST = 'token_digest';

    #[Col('datetime', nullable: false, comment: '到期时间')]
    public const schema_fields_EXPIRES_AT = 'expires_at';

    #[Col('datetime', nullable: true, comment: '最近使用时间')]
    public const schema_fields_LAST_USED_AT = 'last_used_at';

    #[Col('datetime', nullable: true, comment: '撤销时间')]
    public const schema_fields_REVOKED_AT = 'revoked_at';

    #[Col('varchar', 64, nullable: false, default: '', comment: '撤销原因')]
    public const schema_fields_REVOKE_REASON = 'revoke_reason';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
}
