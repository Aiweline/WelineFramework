<?php

declare(strict_types=1);

namespace Weline\Order\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Kind-qualified display number registry（DEC-017）.
 * Unique: (website_id, store_id, number_kind, display_number).
 */
#[Table(comment: 'Display number registry')]
#[Index(name: 'uk_display_number_kind', columns: ['website_id', 'store_id', 'number_kind', 'display_number'], type: 'UNIQUE')]
#[Index(name: 'idx_display_number_entity', columns: ['entity_uuid'])]
class DisplayNumberRegistry extends Model
{
    public const schema_table = 'weline_display_number_registry';
    public const schema_primary_key = 'registry_id';

    public const KIND_ORDER = 'order';
    public const KIND_INVOICE = 'invoice';
    public const KIND_REFUND = 'refund';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'registry_id';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Website ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Store ID')]
    public const schema_fields_STORE_ID = 'store_id';

    #[Col('varchar', 32, nullable: false, comment: 'Number kind')]
    public const schema_fields_NUMBER_KIND = 'number_kind';

    #[Col('varchar', 32, nullable: false, comment: 'Display number')]
    public const schema_fields_DISPLAY_NUMBER = 'display_number';

    #[Col('varchar', 36, nullable: false, comment: 'Entity UUID')]
    public const schema_fields_ENTITY_UUID = 'entity_uuid';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
