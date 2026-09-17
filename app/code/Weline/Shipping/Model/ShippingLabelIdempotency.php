<?php

declare(strict_types=1);

namespace Weline\Shipping\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** Durable label createShipment idempotency (plan #17). */
#[Table(comment: 'Shipping label idempotency')]
#[Index(name: 'uk_ship_label_idem', columns: ['idempotency_key'], type: 'UNIQUE')]
class ShippingLabelIdempotency extends Model
{
    public const schema_table = 'w_shipping_label_idempotency';
    public const schema_primary_key = 'id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false)]
    public const schema_fields_ID = 'id';

    #[Col('varchar', 128, nullable: false, comment: 'label:{order_id}:{unit_uuid}')]
    public const schema_fields_IDEMPOTENCY_KEY = 'idempotency_key';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_ORDER_ID = 'order_id';

    #[Col('varchar', 36, nullable: false, default: '')]
    public const schema_fields_UNIT_UUID = 'unit_uuid';

    #[Col('varchar', 100, nullable: false, default: '')]
    public const schema_fields_TRACKING_NUMBER = 'tracking_number';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_CARRIER_ID = 'carrier_id';

    #[Col('varchar', 64, nullable: false, default: '')]
    public const schema_fields_PROVIDER_CODE = 'provider_code';

    #[Col('varchar', 64, nullable: false, default: '')]
    public const schema_fields_SERVICE_CODE = 'service_code';

    #[Col('text', nullable: true)]
    public const schema_fields_PAYLOAD_JSON = 'payload_json';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP')]
    public const schema_fields_CREATED_AT = 'created_at';
}
