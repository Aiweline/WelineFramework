<?php

declare(strict_types=1);

namespace Weline\Dropship\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Dropship fulfillment projection')]
#[Index(name: 'uk_dropship_fulfillment', columns: ['provider_code', 'order_uuid'], type: 'UNIQUE')]
class DropshipFulfillment extends Model
{
    public const schema_table = 'weline_dropship_fulfillment';
    public const schema_primary_key = 'fulfillment_id';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'fulfillment_id';

    #[Col('varchar', 64, nullable: false, comment: 'Provider')]
    public const schema_fields_PROVIDER_CODE = 'provider_code';

    #[Col('varchar', 64, nullable: false, comment: 'Local order UUID')]
    public const schema_fields_ORDER_UUID = 'order_uuid';

    #[Col('varchar', 128, nullable: false, default: '', comment: 'External order id')]
    public const schema_fields_EXTERNAL_ORDER_ID = 'external_order_id';

    #[Col('varchar', 32, nullable: false, default: 'created', comment: 'Status')]
    public const schema_fields_STATUS = 'status';

    #[Col('varchar', 128, nullable: false, default: '', comment: 'Tracking number')]
    public const schema_fields_TRACKING_NUMBER = 'tracking_number';

    #[Col('varchar', 64, nullable: false, default: '', comment: 'Carrier')]
    public const schema_fields_CARRIER = 'carrier';

    #[Col('text', nullable: true, comment: 'Tracking JSON')]
    public const schema_fields_TRACKING_JSON = 'tracking_json';

    #[Col('text', nullable: true, comment: 'Last error')]
    public const schema_fields_LAST_ERROR = 'last_error';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';
}
