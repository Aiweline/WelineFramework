<?php

declare(strict_types=1);

namespace Weline\Social\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Social publish target')]
#[Index(name: 'idx_social_target_batch', columns: ['batch_id'])]
#[Index(name: 'idx_social_target_account', columns: ['account_id'])]
#[Index(name: 'idx_social_target_status', columns: ['status'])]
#[Index(name: 'uniq_social_target_idempotency', columns: ['idempotency_key'], type: 'UNIQUE')]
class SocialPublishTarget extends Model
{
    public const schema_table = 'weline_social_publish_target';
    public const schema_primary_key = 'target_id';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    #[Col('int', 0, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Target ID')]
    public const schema_fields_ID = 'target_id';
    #[Col('int', 0, nullable: false, comment: 'Batch ID')]
    public const schema_fields_BATCH_ID = 'batch_id';
    #[Col('int', 0, nullable: false, comment: 'Account ID')]
    public const schema_fields_ACCOUNT_ID = 'account_id';
    #[Col('varchar', 64, nullable: false, comment: 'Platform code')]
    public const schema_fields_PLATFORM_CODE = 'platform_code';
    #[Col('varchar', 32, nullable: false, default: 'pending', comment: 'Target status')]
    public const schema_fields_STATUS = 'status';
    #[Col('varchar', 190, nullable: false, comment: 'Idempotency key')]
    public const schema_fields_IDEMPOTENCY_KEY = 'idempotency_key';
    #[Col('varchar', 190, nullable: true, comment: 'Remote ID')]
    public const schema_fields_REMOTE_ID = 'remote_id';
    #[Col('varchar', 512, nullable: true, comment: 'Remote URL')]
    public const schema_fields_REMOTE_URL = 'remote_url';
    #[Col('text', nullable: true, comment: 'Last error')]
    public const schema_fields_ERROR_MESSAGE = 'error_message';
    #[Col('datetime', nullable: true, comment: 'Scheduled at')]
    public const schema_fields_SCHEDULED_AT = 'scheduled_at';
    #[Col('datetime', nullable: true, comment: 'Published at')]
    public const schema_fields_PUBLISHED_AT = 'published_at';
    #[Col('datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: true, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['target_id'];
    public array $_index_sort_keys = ['batch_id', 'account_id', 'platform_code', 'status'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}

