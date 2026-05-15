<?php
declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Model\Document;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Developer workspace document translation')]
#[Index(name: 'idx_doc_locale_unique', columns: ['source_document_id', 'locale'], type: 'UNIQUE')]
#[Index(name: 'idx_locale_status', columns: ['locale', 'status'])]
#[Index(name: 'idx_source_hash', columns: ['source_hash'])]
class Translation extends Model
{
    public const schema_table = 'developer_workspace_document_translation';
    public const schema_primary_key = 'id';

    public const STATUS_MISSING = 'missing';
    public const STATUS_PENDING = 'pending';
    public const STATUS_TRANSLATING = 'translating';
    public const STATUS_TRANSLATED = 'translated';
    public const STATUS_STALE = 'stale';
    public const STATUS_FAILED = 'failed';
    public const STATUS_BLOCKED_CONFIG = 'blocked_config';
    public const STATUS_DISABLED = 'disabled';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col('int', nullable: false, comment: 'Source document ID')]
    public const schema_fields_SOURCE_DOCUMENT_ID = 'source_document_id';
    #[Col('varchar', 64, nullable: false, comment: 'Target locale')]
    public const schema_fields_LOCALE = 'locale';
    #[Col('varchar', 64, nullable: false, default: 'zh_Hans_CN', comment: 'Source locale')]
    public const schema_fields_SOURCE_LOCALE = 'source_locale';
    #[Col('varchar', 500, nullable: false, comment: 'Translated title')]
    public const schema_fields_TITLE = 'title';
    #[Col('varchar', 1000, nullable: false, comment: 'Translated summary')]
    public const schema_fields_SUMMARY = 'summary';
    #[Col('longtext', nullable: false, comment: 'Translated content')]
    public const schema_fields_CONTENT = 'content';
    #[Col('varchar', 64, nullable: false, comment: 'Source hash')]
    public const schema_fields_SOURCE_HASH = 'source_hash';
    #[Col('varchar', 32, nullable: false, default: self::STATUS_PENDING, comment: 'Translation status')]
    public const schema_fields_STATUS = 'status';
    #[Col('int', 1, default: 0, comment: 'Manual override')]
    public const schema_fields_IS_MANUAL_OVERRIDE = 'is_manual_override';
    #[Col('text', nullable: true, comment: 'Last error')]
    public const schema_fields_ERROR_MESSAGE = 'error_message';
    #[Col('int', nullable: true, comment: 'Translated at timestamp')]
    public const schema_fields_TRANSLATED_AT = 'translated_at';
    #[Col('int', nullable: true, default: 0, comment: 'Created at timestamp')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('int', nullable: true, default: 0, comment: 'Updated at timestamp')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [self::schema_fields_ID, self::schema_fields_SOURCE_DOCUMENT_ID, self::schema_fields_LOCALE];

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
