<?php

declare(strict_types=1);

namespace Weline\Inventory\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Warehouse pool（可跨仓聚合配额的逻辑池；P3A-001）.
 */
#[Table(comment: 'Inventory warehouse pool')]
#[Index(name: 'uk_inv_pool_code', columns: ['website_id', 'pool_code'], type: 'UNIQUE')]
class WarehousePool extends Model
{
    public const schema_table = 'weline_inventory_warehouse_pool';
    public const schema_primary_key = 'pool_id';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Pool ID')]
    public const schema_fields_ID = 'pool_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID (>=0)')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 64, nullable: false, comment: 'Pool code')]
    public const schema_fields_POOL_CODE = 'pool_code';

    #[Col('varchar', 255, nullable: false, default: '', comment: 'Display name')]
    public const schema_fields_NAME = 'name';

    #[Col('varchar', 16, nullable: false, default: 'normal', comment: 'normal|test')]
    public const schema_fields_MODE = 'mode';

    #[Col('tinyint', 1, nullable: false, default: 1, comment: 'Enabled')]
    public const schema_fields_ENABLED = 'enabled';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
