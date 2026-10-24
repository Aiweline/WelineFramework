<?php

declare(strict_types=1);

namespace Weline\Social\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Social extension publish job')]
#[Index(name: 'idx_social_ext_job_status', columns: ['status'])]
#[Index(name: 'idx_social_ext_job_account', columns: ['account_id', 'status'])]
class SocialExtensionJob extends Model
{
    public const schema_table = 'weline_social_extension_job';
    public const schema_primary_key = 'job_id';

    public const STATUS_PENDING = 'pending';
    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_STALE = 'stale';

    #[Col('int', 0, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Job ID')]
    public const schema_fields_ID = 'job_id';
    #[Col('int', 0, nullable: false, default: 0, comment: 'Publish target ID')]
    public const schema_fields_TARGET_ID = 'target_id';
    #[Col('int', 0, nullable: false, comment: 'Account ID')]
    public const schema_fields_ACCOUNT_ID = 'account_id';
    #[Col('int', 0, nullable: false, default: 0, comment: 'Website ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('varchar', 64, nullable: false, comment: 'Platform code')]
    public const schema_fields_PLATFORM_CODE = 'platform_code';
    #[Col('varchar', 16, nullable: false, default: 'text', comment: 'Content kind')]
    public const schema_fields_CONTENT_KIND = 'content_kind';
    #[Col('varchar', 16, nullable: false, default: '', comment: 'Recipe version')]
    public const schema_fields_RECIPE_VERSION = 'recipe_version';
    #[Col('text', nullable: true, comment: 'Job payload JSON')]
    public const schema_fields_PAYLOAD_JSON = 'payload_json';
    #[Col('varchar', 20, nullable: false, default: 'pending', comment: 'Job status')]
    public const schema_fields_STATUS = 'status';
    #[Col('int', 0, nullable: false, default: 0, comment: 'Attempts')]
    public const schema_fields_ATTEMPTS = 'attempts';
    #[Col('varchar', 64, nullable: true, comment: 'Claim token')]
    public const schema_fields_CLAIM_TOKEN = 'claim_token';
    #[Col('datetime', nullable: true, comment: 'Claim expires at')]
    public const schema_fields_CLAIM_EXPIRES_AT = 'claim_expires_at';
    #[Col('varchar', 64, nullable: true, comment: 'Claimed by device')]
    public const schema_fields_CLAIMED_BY = 'claimed_by';
    #[Col('text', nullable: true, comment: 'Result JSON')]
    public const schema_fields_RESULT_JSON = 'result_json';
    #[Col('varchar', 64, nullable: true, comment: 'Error code')]
    public const schema_fields_ERROR_CODE = 'error_code';
    #[Col('datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: true, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['job_id'];
    public array $_index_sort_keys = ['status', 'account_id'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
