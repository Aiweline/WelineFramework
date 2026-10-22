<?php

declare(strict_types=1);

namespace Weline\Shipping\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** 买家退换货寄回指令（按售后申请幂等，不创建前向面单）。 */
#[Table(comment: '买家售后退换货寄回指令')]
#[Index(name: 'uk_ship_aftersale_return_request', columns: ['request_uuid'], type: 'UNIQUE')]
class BuyerAftersaleReturnInstruction extends Model
{
    public const schema_table = 'w_shipping_buyer_aftersale_return_instructions';
    public const schema_primary_key = 'instruction_id';

    public const STATUS_PENDING = 'pending';
    public const STATUS_FAILED = 'failed';

    #[Col('int', null, nullable: false, primaryKey: true, autoIncrement: true, comment: '寄回指令ID')]
    public const schema_fields_ID = 'instruction_id';

    #[Col('varchar', 64, nullable: false, comment: '售后申请 UUID')]
    public const schema_fields_REQUEST_UUID = 'request_uuid';

    #[Col('varchar', 64, nullable: false, default: '', comment: '订单 UUID')]
    public const schema_fields_ORDER_UUID = 'order_uuid';

    #[Col('varchar', 16, nullable: false, default: self::STATUS_PENDING, comment: 'pending|failed')]
    public const schema_fields_STATUS = 'status';

    #[Col('text', nullable: true, comment: '寄回地址文本快照')]
    public const schema_fields_RETURN_ADDRESS = 'return_address';

    #[Col('varchar', 128, nullable: false, default: '', comment: '寄回物流单号')]
    public const schema_fields_TRACKING_NO = 'tracking_no';

    #[Col('text', nullable: true, comment: '失败原因')]
    public const schema_fields_ERROR_MESSAGE = 'error_message';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
}
