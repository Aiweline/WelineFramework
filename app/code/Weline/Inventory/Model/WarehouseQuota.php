<?php

declare(strict_types=1);

namespace Weline\Inventory\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Offer 在仓/池上的配额（P3A-001；qty 为 minor）.
 */
#[Table(comment: 'Inventory warehouse quota')]
#[Index(name: 'uk_inv_quota_offer', columns: ['website_id', 'warehouse_id', 'offer_id'], type: 'UNIQUE')]
#[Index(name: 'idx_inv_quota_pool', columns: ['pool_id', 'offer_id'])]
class WarehouseQuota extends Model
{
    public const schema_table = 'weline_inventory_warehouse_quota';
    public const schema_primary_key = 'quota_id';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Quota ID')]
    public const schema_fields_ID = 'quota_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID (>=0)')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('int', 11, nullable: false, comment: 'Warehouse ID')]
    public const schema_fields_WAREHOUSE_ID = 'warehouse_id';

    #[Col('int', 11, nullable: true, comment: 'Optional pool ID')]
    public const schema_fields_POOL_ID = 'pool_id';

    #[Col('bigint', 20, nullable: false, comment: 'Offer ID')]
    public const schema_fields_OFFER_ID = 'offer_id';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Quota minor qty')]
    public const schema_fields_QTY_MINOR = 'qty_minor';

    #[Col('int', 11, nullable: false, default: 0, comment: 'CAS version')]
    public const schema_fields_QUOTA_VERSION = 'quota_version';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
