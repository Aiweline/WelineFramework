<?php

declare(strict_types=1);

namespace Weline\Product\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Audited Product ownership transfer')]
#[Index(name: 'uk_product_transfer_uuid', columns: ['transfer_uuid'], type: 'UNIQUE')]
#[Index(name: 'idx_product_transfer_product_status', columns: ['global_product_uuid', 'status'])]
#[Index(name: 'idx_product_transfer_target_status', columns: ['target_website_id', 'status'])]
final class ProductOwnershipTransfer extends Model
{
    public const schema_table = 'weline_product_ownership_transfer';
    public const schema_primary_key = 'transfer_id';

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Transfer ID')]
    public const schema_fields_ID = 'transfer_id';

    #[Col('varchar', 36, nullable: false, unique: true, comment: 'Transfer UUID')]
    public const schema_fields_UUID = 'transfer_uuid';

    #[Col('varchar', 36, nullable: false, comment: 'Global Product UUID')]
    public const schema_fields_PRODUCT_UUID = 'global_product_uuid';

    #[Col('int', 11, nullable: false, comment: 'Source Website ID')]
    public const schema_fields_SOURCE_WEBSITE_ID = 'source_website_id';

    #[Col('int', 11, nullable: false, comment: 'Target Website ID')]
    public const schema_fields_TARGET_WEBSITE_ID = 'target_website_id';

    #[Col('int', 11, nullable: false, comment: 'Expected Product version')]
    public const schema_fields_PRODUCT_VERSION = 'product_version';

    #[Col('varchar', 32, nullable: false, default: self::STATUS_PENDING, comment: 'Transfer status')]
    public const schema_fields_STATUS = 'status';

    #[Col('varchar', 128, nullable: false, comment: 'Initiate request hash')]
    public const schema_fields_REQUEST_HASH = 'request_hash';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Requested at')]
    public const schema_fields_REQUESTED_AT = 'requested_at';

    #[Col('datetime', nullable: true, comment: 'Confirmed or closed at')]
    public const schema_fields_RESOLVED_AT = 'resolved_at';


    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
