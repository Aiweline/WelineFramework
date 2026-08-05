<?php

declare(strict_types=1);

namespace Weline\Order\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Durable signal that a paid Order is ready for fulfillment orchestration.
 *
 * It is deliberately separate from OrderShipment: payment success prepares
 * work, but does not prove a parcel has shipped.
 */
#[Table(comment: 'Order fulfillment action')]
#[Index(name: 'uniq_fulfillment_action_uuid', columns: ['fulfillment_action_uuid'], type: 'UNIQUE')]
#[Index(name: 'uniq_fulfillment_action_effect', columns: ['effect_key'], type: 'UNIQUE')]
#[Index(name: 'idx_fulfillment_action_order', columns: ['order_uuid', 'status'])]
class FulfillmentAction extends Model
{
    public const schema_table = 'weline_order_fulfillment_action';
    public const schema_primary_key = 'fulfillment_action_id';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE = 'done';
    public const STATUS_CANCELLED = 'cancelled';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Action ID')]
    public const schema_fields_ID = 'fulfillment_action_id';
    #[Col('varchar', 36, nullable: false, comment: 'Action UUID')]
    public const schema_fields_ACTION_UUID = 'fulfillment_action_uuid';
    #[Col('varchar', 191, nullable: false, comment: 'Deterministic payment effect key')]
    public const schema_fields_EFFECT_KEY = 'effect_key';
    #[Col('varchar', 96, nullable: false, comment: 'Payment attempt code')]
    public const schema_fields_ATTEMPT_CODE = 'attempt_code';
    #[Col('varchar', 36, nullable: false, comment: 'Order UUID')]
    public const schema_fields_ORDER_UUID = 'order_uuid';
    #[Col('varchar', 32, nullable: false, default: self::STATUS_PENDING, comment: 'Action status')]
    public const schema_fields_STATUS = 'status';
    #[Col('varchar', 32, nullable: false, default: 'normal', comment: 'normal|historical_only')]
    public const schema_fields_RESOURCE_MODE = 'resource_mode';
    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['fulfillment_action_id'];
    public array $_index_sort_keys = ['effect_key', 'order_uuid', 'status', 'created_at'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
