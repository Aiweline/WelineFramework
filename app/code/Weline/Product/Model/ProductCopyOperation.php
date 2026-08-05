<?php

declare(strict_types=1);

namespace Weline\Product\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Durable Store-copy draft, idempotency receipt and audit envelope.
 */
#[Table(comment: 'Product Store-copy durable operation')]
#[Index(name: 'uk_product_copy_draft_uuid', columns: ['draft_uuid'], type: 'UNIQUE')]
#[Index(name: 'idx_product_copy_target_state', columns: ['target_website_id', 'target_store_id', 'state'])]
class ProductCopyOperation extends Model
{
    public const schema_table = 'product_copy_operation';
    public const schema_primary_key = 'operation_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Operation ID')]
    public const schema_fields_ID = 'operation_id';

    #[Col('varchar', 64, nullable: false, unique: true, comment: 'Server-issued draft UUID')]
    public const schema_fields_DRAFT_UUID = 'draft_uuid';

    #[Col('varchar', 32, nullable: false, default: 'draft', comment: 'Draft state')]
    public const schema_fields_STATE = 'state';

    #[Col('varchar', 32, nullable: false, comment: 'Copy entry')]
    public const schema_fields_ENTRY = 'entry';

    #[Col('int', 11, nullable: false, comment: 'Target Website ID')]
    public const schema_fields_TARGET_WEBSITE_ID = 'target_website_id';

    #[Col('int', 11, nullable: false, comment: 'Target Store ID')]
    public const schema_fields_TARGET_STORE_ID = 'target_store_id';

    #[Col('int', 11, nullable: true, comment: 'Source Website ID')]
    public const schema_fields_SOURCE_WEBSITE_ID = 'source_website_id';

    #[Col('int', 11, nullable: true, comment: 'Source Store ID')]
    public const schema_fields_SOURCE_STORE_ID = 'source_store_id';

    #[Col('text', nullable: false, comment: 'Normalized CopyDraft JSON')]
    public const schema_fields_DRAFT_JSON = 'draft_json';

    #[Col('varchar', 128, nullable: true, comment: 'Commit request hash')]
    public const schema_fields_REQUEST_HASH = 'request_hash';

    #[Col('varchar', 64, nullable: true, comment: 'Commit claim CAS token')]
    public const schema_fields_CLAIM_TOKEN = 'claim_token';

    #[Col('text', nullable: true, comment: 'Successful receipt and audit JSON')]
    public const schema_fields_RESULT_JSON = 'result_json';

    #[Col('varchar', 64, nullable: true, comment: 'Stable public error code')]
    public const schema_fields_ERROR_CODE = 'error_code';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
