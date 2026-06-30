<?php
declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Model\Document\Catalog;

use Weline\DeveloperWorkspace\Model\Document\Translation as DocumentTranslation;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Developer workspace document catalog translation')]
#[Index(name: 'idx_catalog_locale_unique', columns: ['catalog_id', 'locale'], type: 'UNIQUE')]
#[Index(name: 'idx_locale_status', columns: ['locale', 'status'])]
class Translation extends Model
{
    public const schema_table = 'developer_workspace_document_catalog_translation';
    public const schema_primary_key = 'id';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col('int', nullable: false, comment: 'Catalog ID')]
    public const schema_fields_CATALOG_ID = 'catalog_id';
    #[Col('varchar', 64, nullable: false, comment: 'Target locale')]
    public const schema_fields_LOCALE = 'locale';
    #[Col('varchar', 64, nullable: false, default: 'zh_Hans_CN', comment: 'Source locale')]
    public const schema_fields_SOURCE_LOCALE = 'source_locale';
    #[Col('varchar', 120, nullable: false, comment: 'Translated catalog name')]
    public const schema_fields_NAME = 'name';
    #[Col('text', nullable: false, comment: 'Translated description')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col('varchar', 64, nullable: false, comment: 'Source hash')]
    public const schema_fields_SOURCE_HASH = 'source_hash';
    #[Col('varchar', 32, nullable: false, default: DocumentTranslation::STATUS_PENDING, comment: 'Translation status')]
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
    public array $_index_sort_keys = [self::schema_fields_ID, self::schema_fields_CATALOG_ID, self::schema_fields_LOCALE];

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
