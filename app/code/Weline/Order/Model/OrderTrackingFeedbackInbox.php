<?php

declare(strict_types=1);

namespace Weline\Order\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** 物流反馈不可变 Inbox（对齐支付 webhook inbox） */
#[Table(comment: '订单物流反馈 Inbox')]
#[Index(name: 'uk_inbox_code', columns: ['inbox_code'], type: 'UNIQUE')]
#[Index(name: 'uk_provider_event', columns: ['provider_code', 'method_code', 'event_id'], type: 'UNIQUE')]
#[Index(name: 'idx_created_at', columns: ['created_at'])]
class OrderTrackingFeedbackInbox extends Model
{
    public const schema_table = 'weline_order_tracking_feedback_inbox';
    public const schema_primary_key = 'inbox_id';

    #[Col('int', 11, nullable: false, primaryKey: true, autoIncrement: true, comment: 'Inbox ID')]
    public const schema_fields_ID = 'inbox_id';
    #[Col('varchar', 64, nullable: false, unique: true, comment: 'Inbox 业务码')]
    public const schema_fields_INBOX_CODE = 'inbox_code';
    #[Col('varchar', 64, nullable: false, default: '', comment: 'Endpoint code')]
    public const schema_fields_ENDPOINT_CODE = 'endpoint_code';
    #[Col('varchar', 64, nullable: false, comment: 'Provider code')]
    public const schema_fields_PROVIDER_CODE = 'provider_code';
    #[Col('varchar', 64, nullable: false, comment: 'Method code')]
    public const schema_fields_METHOD_CODE = 'method_code';
    #[Col('varchar', 128, nullable: false, comment: 'Provider event id')]
    public const schema_fields_EVENT_ID = 'event_id';
    #[Col('varchar', 64, nullable: false, default: '', comment: 'Event type')]
    public const schema_fields_EVENT_TYPE = 'event_type';
    #[Col('varchar', 64, nullable: false, default: '', comment: '订单号')]
    public const schema_fields_ORDER_NUMBER = 'order_number';
    #[Col('varchar', 100, nullable: false, default: '', comment: '物流单号')]
    public const schema_fields_TRACKING_NUMBER = 'tracking_number';
    #[Col('varchar', 50, nullable: false, default: '', comment: '建议状态')]
    public const schema_fields_SUGGESTED_STATUS = 'suggested_status';
    #[Col('varchar', 64, nullable: false, default: '', comment: '阶段码')]
    public const schema_fields_STAGE_CODE = 'stage_code';
    #[Col('varchar', 64, nullable: false, default: 'received', comment: '处理状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('varchar', 64, nullable: false, comment: 'Raw body sha256')]
    public const schema_fields_PAYLOAD_HASH = 'payload_hash';
    #[Col('mediumtext', nullable: false, comment: 'Raw body')]
    public const schema_fields_RAW_BODY = 'raw_body';
    #[Col('mediumtext', comment: '归一化 JSON')]
    public const schema_fields_PARSED_JSON = 'parsed_json';
    #[Col('timestamp', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    public const STATUS_RECEIVED = 'received';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_IGNORED = 'ignored';

    public array $_unit_primary_keys = ['inbox_id'];
    public array $_index_sort_keys = ['inbox_id', 'created_at', 'provider_code'];
}
