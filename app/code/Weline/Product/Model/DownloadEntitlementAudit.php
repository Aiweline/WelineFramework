<?php

declare(strict_types=1);

namespace Weline\Product\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Product downloadable entitlement audit')]
#[Index(name: 'idx_product_download_audit_entitlement', columns: ['entitlement_uuid', 'audit_id'])]
#[Index(name: 'idx_product_download_audit_actor', columns: ['actor_customer_id', 'created_at'])]
final class DownloadEntitlementAudit extends Model
{
    public const schema_table = 'weline_product_download_entitlement_audit';
    public const schema_primary_key = 'audit_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false)]
    public const schema_fields_ID = 'audit_id';
    #[Col('varchar', 36, nullable: false)]
    public const schema_fields_ENTITLEMENT_UUID = 'entitlement_uuid';
    #[Col('bigint', 20, nullable: true)]
    public const schema_fields_OWNER_CUSTOMER_ID = 'owner_customer_id';
    #[Col('bigint', 20, nullable: false)]
    public const schema_fields_ACTOR_CUSTOMER_ID = 'actor_customer_id';
    #[Col('bigint', 20, nullable: false, default: 0)]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('varchar', 32, nullable: false)]
    public const schema_fields_ACTION = 'action';
    #[Col('varchar', 64, nullable: false)]
    public const schema_fields_RESULT_CODE = 'result_code';
    #[Col('text', nullable: true)]
    public const schema_fields_DETAILS_JSON = 'details_json';
    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP')]
    public const schema_fields_CREATED_AT = 'created_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
