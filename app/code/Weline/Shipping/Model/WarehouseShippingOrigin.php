<?php

declare(strict_types=1);

namespace Weline\Shipping\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** 仓 ↔ 发货地址权威绑定（多仓拆单运费）。 */
#[Table(comment: '仓库发货地址绑定')]
#[Index(name: 'uk_wh_ship_origin_website_wh', columns: ['website_id', 'warehouse_id'], type: 'UNIQUE')]
#[Index(name: 'idx_wh_ship_origin_address', columns: ['shipping_address_id', 'is_active'])]
class WarehouseShippingOrigin extends AbstractModel
{
    public const schema_table = 'w_shipping_warehouse_origins';
    public const schema_primary_key = 'origin_id';

    #[Col('int', null, nullable: false, primaryKey: true, autoIncrement: true, comment: 'ID')]
    public const schema_fields_ID = 'origin_id';

    #[Col('int', null, nullable: false, default: 0, comment: 'Website ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('int', null, nullable: false, comment: 'Inventory warehouse_id')]
    public const schema_fields_WAREHOUSE_ID = 'warehouse_id';

    #[Col('int', null, nullable: false, comment: 'Shipping address id')]
    public const schema_fields_SHIPPING_ADDRESS_ID = 'shipping_address_id';

    #[Col('int', 1, nullable: false, default: 1, comment: 'Active')]
    public const schema_fields_IS_ACTIVE = 'is_active';

    #[Col('datetime', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['origin_id'];
    public array $_index_sort_keys = ['origin_id', 'website_id', 'warehouse_id'];

    public function _init(): void
    {
        $this->_table = self::schema_table;
        $this->_primary_key = self::schema_fields_ID;
    }
}
