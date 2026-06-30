<?php

declare(strict_types=1);

namespace Weline\Social\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Social publish batch')]
#[Index(name: 'idx_social_batch_status', columns: ['status'])]
#[Index(name: 'idx_social_batch_draft', columns: ['draft_id'])]
#[Index(name: 'idx_social_batch_scope', columns: ['publish_scope'])]
class SocialPublishBatch extends Model
{
    public const schema_table = 'weline_social_publish_batch';
    public const schema_primary_key = 'batch_id';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';

    #[Col('int', 0, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Batch ID')]
    public const schema_fields_ID = 'batch_id';
    #[Col('int', 0, nullable: false, comment: 'Draft ID')]
    public const schema_fields_DRAFT_ID = 'draft_id';
    #[Col('varchar', 190, nullable: false, comment: 'Batch title')]
    public const schema_fields_TITLE = 'title';
    #[Col('varchar', 32, nullable: false, default: 'pending', comment: 'Batch status')]
    public const schema_fields_STATUS = 'status';
    #[Col('varchar', 32, nullable: false, default: 'accounts', comment: 'Publish scope')]
    public const schema_fields_PUBLISH_SCOPE = 'publish_scope';
    #[Col('varchar', 32, nullable: false, default: 'social', comment: 'Content kind')]
    public const schema_fields_CONTENT_KIND = 'content_kind';
    #[Col('text', nullable: true, comment: 'Website IDs JSON')]
    public const schema_fields_WEBSITE_IDS_JSON = 'website_ids_json';
    #[Col('text', nullable: true, comment: 'Publish scope context JSON')]
    public const schema_fields_SCOPE_JSON = 'scope_json';
    #[Col('int', 0, nullable: false, default: 0, comment: 'Target count')]
    public const schema_fields_TARGET_COUNT = 'target_count';
    #[Col('int', 0, nullable: false, default: 0, comment: 'Success count')]
    public const schema_fields_SUCCESS_COUNT = 'success_count';
    #[Col('int', 0, nullable: false, default: 0, comment: 'Failed count')]
    public const schema_fields_FAILED_COUNT = 'failed_count';
    #[Col('datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: true, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['batch_id'];
    public array $_index_sort_keys = ['draft_id', 'status', 'publish_scope'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
