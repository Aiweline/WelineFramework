<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * AI Extension Grant Entity
 *
 * @package Weline_Ai
 */
#[Table(comment: 'AI扩展助手授权表')]
#[Index(name: 'idx_user_id', columns: ['user_id'])]
#[Index(name: 'idx_status', columns: ['status'])]
class AiExtensionGrant extends Model
{
    public const schema_table = 'ai_extension_grant';
    public const schema_primary_key = 'grant_id';

    public array $_unit_primary_keys = ['grant_id'];

    #[Col(type: 'varchar', length: 64, primaryKey: true, nullable: false, comment: '授权ID')]
    public const schema_fields_GRANT_ID = 'grant_id';
    #[Col(type: 'int', nullable: false, comment: '用户ID')]
    public const schema_fields_USER_ID = 'user_id';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: '扩展ID')]
    public const schema_fields_EXT_ID = 'ext_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '设备指纹')]
    public const schema_fields_DEVICE_FP = 'device_fp';
    #[Col(type: 'char', length: 64, nullable: false, comment: '设备指纹哈希')]
    public const schema_fields_DEVICE_FP_HASH = 'device_fp_hash';
    #[Col(type: 'varchar', length: 20, nullable: false, default: 'active', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'timestamp', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'timestamp', nullable: true, comment: '最后续期时间')]
    public const schema_fields_LAST_RENEW_AT = 'last_renew_at';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';
}
