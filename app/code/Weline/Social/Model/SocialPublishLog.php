<?php

declare(strict_types=1);

namespace Weline\Social\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Social publish log')]
#[Index(name: 'idx_social_log_target', columns: ['target_id'])]
#[Index(name: 'idx_social_log_platform', columns: ['platform_code'])]
class SocialPublishLog extends Model
{
    public const schema_table = 'weline_social_publish_log';
    public const schema_primary_key = 'log_id';

    #[Col('int', 0, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Log ID')]
    public const schema_fields_ID = 'log_id';
    #[Col('int', 0, nullable: false, comment: 'Target ID')]
    public const schema_fields_TARGET_ID = 'target_id';
    #[Col('varchar', 64, nullable: false, comment: 'Platform code')]
    public const schema_fields_PLATFORM_CODE = 'platform_code';
    #[Col('varchar', 32, nullable: false, comment: 'Log status')]
    public const schema_fields_STATUS = 'status';
    #[Col('text', nullable: true, comment: 'Redacted request JSON')]
    public const schema_fields_REQUEST_JSON = 'request_json';
    #[Col('text', nullable: true, comment: 'Redacted response JSON')]
    public const schema_fields_RESPONSE_JSON = 'response_json';
    #[Col('text', nullable: true, comment: 'Error message')]
    public const schema_fields_ERROR_MESSAGE = 'error_message';
    #[Col('datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    public array $_unit_primary_keys = ['log_id'];
    public array $_index_sort_keys = ['target_id', 'platform_code', 'status'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}

