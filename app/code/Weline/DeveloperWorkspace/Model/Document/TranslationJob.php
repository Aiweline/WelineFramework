<?php
declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Model\Document;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Developer workspace document translation job')]
#[Index(name: 'idx_target_locale_hash_unique', columns: ['target_type', 'target_id', 'locale', 'source_hash'], type: 'UNIQUE')]
#[Index(name: 'idx_status_locale', columns: ['status', 'locale'])]
#[Index(name: 'idx_request_id', columns: ['ai_request_id'])]
class TranslationJob extends Model
{
    public const schema_table = 'developer_workspace_document_translation_job';
    public const schema_primary_key = 'id';

    public const TARGET_DOCUMENT = 'document';
    public const TARGET_CATALOG = 'catalog';

    public const STATUS_PENDING = 'pending';
    public const STATUS_TRANSLATING = 'translating';
    public const STATUS_TRANSLATED = 'translated';
    public const STATUS_FAILED = 'failed';
    public const STATUS_BLOCKED_CONFIG = 'blocked_config';
    public const STATUS_DISABLED = 'disabled';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col('varchar', 32, nullable: false, comment: 'Target type')]
    public const schema_fields_TARGET_TYPE = 'target_type';
    #[Col('int', nullable: false, comment: 'Target ID')]
    public const schema_fields_TARGET_ID = 'target_id';
    #[Col('varchar', 64, nullable: false, comment: 'Target locale')]
    public const schema_fields_LOCALE = 'locale';
    #[Col('varchar', 64, nullable: false, default: 'zh_Hans_CN', comment: 'Source locale')]
    public const schema_fields_SOURCE_LOCALE = 'source_locale';
    #[Col('varchar', 64, nullable: false, comment: 'Source hash')]
    public const schema_fields_SOURCE_HASH = 'source_hash';
    #[Col('varchar', 32, nullable: false, default: self::STATUS_PENDING, comment: 'Job status')]
    public const schema_fields_STATUS = 'status';
    #[Col('int', nullable: false, default: 0, comment: 'Priority')]
    public const schema_fields_PRIORITY = 'priority';
    #[Col('int', nullable: false, default: 0, comment: 'Retry count')]
    public const schema_fields_RETRY_COUNT = 'retry_count';
    #[Col('int', nullable: false, default: 3, comment: 'Max retries')]
    public const schema_fields_MAX_RETRIES = 'max_retries';
    #[Col('int', nullable: true, default: 0, comment: 'Locked at timestamp')]
    public const schema_fields_LOCKED_AT = 'locked_at';
    #[Col('varchar', 120, nullable: true, comment: 'Locked by')]
    public const schema_fields_LOCKED_BY = 'locked_by';
    #[Col('text', nullable: true, comment: 'Last error')]
    public const schema_fields_ERROR_MESSAGE = 'error_message';
    #[Col('int', 1, default: 1, comment: 'Retryable')]
    public const schema_fields_RETRYABLE = 'retryable';
    #[Col('varchar', 128, nullable: true, comment: 'AI model code')]
    public const schema_fields_MODEL_CODE = 'model_code';
    #[Col('varchar', 120, nullable: true, comment: 'AI request ID')]
    public const schema_fields_AI_REQUEST_ID = 'ai_request_id';
    #[Col('int', nullable: true, default: 0, comment: 'Prompt tokens')]
    public const schema_fields_PROMPT_TOKENS = 'prompt_tokens';
    #[Col('int', nullable: true, default: 0, comment: 'Completion tokens')]
    public const schema_fields_COMPLETION_TOKENS = 'completion_tokens';
    #[Col('int', nullable: true, default: 0, comment: 'Total tokens')]
    public const schema_fields_TOTAL_TOKENS = 'total_tokens';
    #[Col('decimal', '12,6', default: 0, comment: 'Estimated cost')]
    public const schema_fields_ESTIMATED_COST = 'estimated_cost';
    #[Col('decimal', '12,6', default: 0, comment: 'Actual cost')]
    public const schema_fields_ACTUAL_COST = 'actual_cost';
    #[Col('int', 1, default: 0, comment: 'Usage is estimated')]
    public const schema_fields_USAGE_ESTIMATED = 'usage_estimated';
    #[Col('int', nullable: true, default: 0, comment: 'Created at timestamp')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('int', nullable: true, default: 0, comment: 'Updated at timestamp')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [self::schema_fields_ID, self::schema_fields_STATUS, self::schema_fields_LOCALE];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function beforeSave(): self
    {
        $now = time();
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);
        return parent::beforeSave();
    }
}
