<?php

declare(strict_types=1);

namespace Weline\Product\Model\Ai;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Pending review row: one generated field (or the main image) for one job.
 *
 * `before_hash` is the conflict guard: it is recomputed from the live product
 * at apply time, so a field edited while the draft was waiting cannot be
 * silently overwritten.
 */
#[Table(comment: 'Product AI review drafts')]
#[Index(name: 'uk_product_ai_draft_job_field', columns: ['job_id', 'field_key'], type: 'UNIQUE')]
#[Index(name: 'idx_product_ai_draft_status_time', columns: ['website_id', 'status', 'created_at'])]
#[Index(name: 'idx_product_ai_draft_product_status', columns: ['global_product_uuid', 'status'])]
final class AiDraft extends Model
{
    public const schema_table = 'weline_product_ai_draft';
    public const schema_primary_key = 'draft_id';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_ROLLED_BACK = 'rolled_back';

    /** Image drafts use a single reserved field key. */
    public const FIELD_MAIN_MEDIA = 'media:main';

    public const COPY_FIELDS = [
        'name',
        'short_description',
        'description',
        'meta_name',
        'meta_description',
        'meta_keywords',
    ];

    #[Col('varchar', 36, primaryKey: true, nullable: false, comment: 'Draft UUID')]
    public const schema_fields_ID = 'draft_id';

    #[Col('varchar', 36, nullable: false, comment: 'Job UUID')]
    public const schema_fields_JOB_ID = 'job_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 36, nullable: false, comment: 'Global Product UUID')]
    public const schema_fields_PRODUCT_UUID = 'global_product_uuid';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Website-local Product ID')]
    public const schema_fields_PRODUCT_ID = 'product_id';

    #[Col('varchar', 24, nullable: false, comment: 'copy / image')]
    public const schema_fields_TASK_TYPE = 'task_type';

    #[Col('varchar', 64, nullable: false, comment: 'Field key or media:main')]
    public const schema_fields_FIELD_KEY = 'field_key';

    #[Col('mediumtext', nullable: true, comment: 'Snapshot value at generation time JSON')]
    public const schema_fields_BEFORE_JSON = 'before_json';

    #[Col('varchar', 64, nullable: false, default: '', comment: 'Snapshot hash at generation time')]
    public const schema_fields_BEFORE_HASH = 'before_hash';

    #[Col('mediumtext', nullable: true, comment: 'AI suggestion JSON')]
    public const schema_fields_AFTER_JSON = 'after_json';

    #[Col('mediumtext', nullable: true, comment: 'Segment diff JSON for highlighting')]
    public const schema_fields_DIFF_JSON = 'diff_json';

    #[Col('varchar', 16, nullable: false, default: self::STATUS_PENDING, comment: 'Draft status')]
    public const schema_fields_STATUS = 'status';

    #[Col('varchar', 255, nullable: true, comment: 'Reviewer note')]
    public const schema_fields_REVIEW_NOTE = 'review_note';

    #[Col('int', 11, nullable: true, comment: 'Reviewer admin ID')]
    public const schema_fields_REVIEWED_BY = 'reviewed_by';

    #[Col('datetime', nullable: true, comment: 'Reviewed at')]
    public const schema_fields_REVIEWED_AT = 'reviewed_at';

    #[Col('int', 11, nullable: true, comment: 'Applying admin ID')]
    public const schema_fields_APPLIED_BY = 'applied_by';

    #[Col('datetime', nullable: true, comment: 'Applied at')]
    public const schema_fields_APPLIED_AT = 'applied_at';

    #[Col('varchar', 128, nullable: true, comment: 'Applied request hash')]
    public const schema_fields_APPLIED_REQUEST_HASH = 'applied_request_hash';

    #[Col('int', 11, nullable: true, comment: 'local_version before apply')]
    public const schema_fields_APPLIED_VERSION_BEFORE = 'applied_version_before';

    #[Col('int', 11, nullable: true, comment: 'local_version after apply')]
    public const schema_fields_APPLIED_VERSION_AFTER = 'applied_version_after';

    #[Col('mediumtext', nullable: true, comment: 'Pre-apply field/media snapshot JSON')]
    public const schema_fields_ROLLBACK_JSON = 'rollback_json';

    #[Col('datetime', nullable: false, comment: 'Draft expiry')]
    public const schema_fields_EXPIRES_AT = 'expires_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }

    public static function isReviewableStatus(string $status): bool
    {
        return \in_array($status, [self::STATUS_PENDING, self::STATUS_ACCEPTED], true);
    }
}
