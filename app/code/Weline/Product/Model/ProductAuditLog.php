<?php

declare(strict_types=1);

namespace Weline\Product\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Immutable Product V2 audit events')]
#[Index(name: 'idx_product_audit_product_time', columns: ['global_product_uuid', 'created_at'])]
#[Index(name: 'idx_product_audit_request', columns: ['request_hash'])]
final class ProductAuditLog extends Model
{
    public const schema_table = 'weline_product_audit_log';
    public const schema_primary_key = 'event_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Event ID')]
    public const schema_fields_ID = 'event_id';

    #[Col('varchar', 36, nullable: false, comment: 'Global Product UUID')]
    public const schema_fields_PRODUCT_UUID = 'global_product_uuid';

    #[Col('varchar', 36, nullable: true, comment: 'Global Offer UUID')]
    public const schema_fields_OFFER_UUID = 'global_offer_uuid';

    #[Col('int', 11, nullable: false, comment: 'Acting Website ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 64, nullable: false, comment: 'Stable action code')]
    public const schema_fields_ACTION = 'action';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Version before')]
    public const schema_fields_BEFORE_VERSION = 'before_version';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Version after')]
    public const schema_fields_AFTER_VERSION = 'after_version';

    #[Col('varchar', 128, nullable: false, comment: 'Request hash')]
    public const schema_fields_REQUEST_HASH = 'request_hash';

    #[Col('text', nullable: false, comment: 'Redacted audit payload JSON')]
    public const schema_fields_PAYLOAD_JSON = 'payload_json';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';


    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
