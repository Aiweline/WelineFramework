<?php

declare(strict_types=1);

namespace Weline\Dropship\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Frozen dropship provider on order lines')]
#[Index(name: 'uk_dropship_order_line', columns: ['order_uuid', 'line_key'], type: 'UNIQUE')]
class DropshipOrderLine extends Model
{
    public const schema_table = 'weline_dropship_order_line';
    public const schema_primary_key = 'id';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';

    #[Col('varchar', 64, nullable: false, comment: 'Order UUID')]
    public const schema_fields_ORDER_UUID = 'order_uuid';

    #[Col('varchar', 128, nullable: false, comment: 'Stable line key')]
    public const schema_fields_LINE_KEY = 'line_key';

    #[Col('varchar', 64, nullable: false, comment: 'Frozen provider')]
    public const schema_fields_PROVIDER_CODE = 'provider_code';

    #[Col('varchar', 128, nullable: false, default: '', comment: 'External SKU')]
    public const schema_fields_EXTERNAL_SKU = 'external_sku';

    #[Col('int', 11, nullable: true, comment: 'Local offer')]
    public const schema_fields_LOCAL_OFFER_ID = 'local_offer_id';

    #[Col('int', 11, nullable: false, default: 1, comment: 'Qty')]
    public const schema_fields_QTY = 'qty';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';
}
