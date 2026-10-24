<?php

declare(strict_types=1);

namespace Weline\Social\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Social extension device')]
#[Index(name: 'uniq_social_ext_device', columns: ['user_id', 'fp_hash'], type: 'UNIQUE')]
class SocialExtensionDevice extends Model
{
    public const schema_table = 'weline_social_extension_device';
    public const schema_primary_key = 'device_id';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';

    #[Col('int', 0, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Device ID')]
    public const schema_fields_ID = 'device_id';
    #[Col('int', 0, nullable: false, comment: 'User ID')]
    public const schema_fields_USER_ID = 'user_id';
    #[Col('int', 0, nullable: false, default: 0, comment: 'Website ID (0 = all websites)')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('varchar', 100, nullable: false, default: '', comment: 'Device name')]
    public const schema_fields_NAME = 'name';
    #[Col('varchar', 64, nullable: false, comment: 'Fingerprint hash')]
    public const schema_fields_FP_HASH = 'fp_hash';
    #[Col('varchar', 64, nullable: false, comment: 'Token hash')]
    public const schema_fields_TOKEN_HASH = 'token_hash';
    #[Col('varchar', 32, nullable: false, default: '', comment: 'Extension version')]
    public const schema_fields_VERSION = 'version';
    #[Col('varchar', 20, nullable: false, default: 'active', comment: 'Status')]
    public const schema_fields_STATUS = 'status';
    #[Col('datetime', nullable: true, comment: 'Last heartbeat')]
    public const schema_fields_LAST_SEEN_AT = 'last_seen_at';
    #[Col('datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    public array $_unit_primary_keys = ['device_id'];
    public array $_index_sort_keys = ['user_id', 'website_id'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
