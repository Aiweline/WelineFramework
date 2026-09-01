<?php

declare(strict_types=1);

namespace Weline\Search\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Search provider 内容索引（Blog/CMS 等非 Product 类型）')]
#[Index(name: 'uk_search_provider_doc_scope', columns: ['indexer', 'website_id', 'store_id', 'channel_id', 'locale', 'entity_id'], type: 'UNIQUE')]
#[Index(name: 'idx_search_provider_doc_lookup', columns: ['indexer', 'website_id', 'status', 'updated_at'])]
class SearchProviderDocument extends Model
{
    public const schema_table = 'search_provider_document';
    public const schema_primary_key = 'document_id';

    public const STATUS_PUBLISHED = 'published';
    public const STATUS_DISABLED = 'disabled';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '文档 ID')]
    public const schema_fields_ID = 'document_id';
    #[Col('varchar', 32, nullable: false, comment: 'Provider code')]
    public const schema_fields_INDEXER = 'indexer';
    #[Col('varchar', 32, nullable: false, default: '', comment: 'Entity type')]
    public const schema_fields_ENTITY_TYPE = 'entity_type';
    #[Col('varchar', 64, nullable: false, comment: 'Entity id')]
    public const schema_fields_ENTITY_ID = 'entity_id';
    #[Col('int', nullable: false, default: 0, comment: 'Website ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('int', nullable: false, default: 0, comment: 'Store ID')]
    public const schema_fields_STORE_ID = 'store_id';
    #[Col('int', nullable: false, default: 0, comment: 'Channel ID')]
    public const schema_fields_CHANNEL_ID = 'channel_id';
    #[Col('varchar', 16, nullable: false, default: '', comment: 'Locale')]
    public const schema_fields_LOCALE = 'locale';
    #[Col('varchar', 8, nullable: false, default: '', comment: 'Currency')]
    public const schema_fields_CURRENCY = 'currency';
    #[Col('varchar', 255, nullable: false, default: '', comment: 'Title')]
    public const schema_fields_TITLE = 'title';
    #[Col('text', nullable: true, comment: 'Keywords JSON')]
    public const schema_fields_KEYWORDS = 'keywords';
    #[Col('varchar', 500, nullable: false, default: '', comment: 'Public URL')]
    public const schema_fields_URL = 'url';
    #[Col('mediumtext', nullable: true, comment: 'Payload JSON')]
    public const schema_fields_PAYLOAD = 'payload';
    #[Col('varchar', 32, nullable: false, default: 'published', comment: 'Status')]
    public const schema_fields_STATUS = 'status';
    #[Col('datetime', nullable: false, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    #[Col('int', nullable: false, default: 1, comment: 'Document version')]
    public const schema_fields_DOCUMENT_VERSION = 'document_version';
    #[Col('varchar', 64, nullable: false, default: '', comment: 'Payload hash')]
    public const schema_fields_PAYLOAD_HASH = 'payload_hash';

    public array $_unit_primary_keys = [self::schema_fields_ID];

    public function _init(): void
    {
        $this->_table = self::schema_table;
        $this->_id_field_name = self::schema_fields_ID;
    }
}
