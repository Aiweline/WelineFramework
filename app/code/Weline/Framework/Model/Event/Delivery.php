<?php

declare(strict_types=1);

namespace Weline\Framework\Model\Event;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '异步观察者投递权威记录')]
#[Index(name: 'uk_event_delivery_target', columns: ['event_id', 'observer_key'], type: 'UNIQUE')]
#[Index(name: 'uk_event_delivery_transport_key', columns: ['transport_idempotency_key'], type: 'UNIQUE')]
#[Index(name: 'idx_event_delivery_retry', columns: ['status', 'next_retry_at'])]
#[Index(name: 'idx_event_delivery_lease', columns: ['lease_until'])]
#[Index(name: 'idx_event_delivery_termination', columns: ['status', 'termination_next_at'])]
#[Index(name: 'idx_event_delivery_resource', columns: ['observer_key', 'resource_key', 'revision'])]
#[Index(name: 'idx_event_delivery_outbox', columns: ['outbox_id'])]
class Delivery extends Model
{
    public const schema_table = 'weline_framework_event_delivery';
    public const schema_primary_key = 'delivery_id';

    #[Col(type: 'bigint', length: 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Delivery ID')]
    public const schema_fields_ID = 'delivery_id';
    #[Col(type: 'bigint', length: 20, nullable: false, comment: 'Outbox ID')]
    public const schema_fields_OUTBOX_ID = 'outbox_id';
    #[Col(type: 'char', length: 32, nullable: false, comment: '事件 ID')]
    public const schema_fields_EVENT_ID = 'event_id';
    #[Col(type: 'varchar', length: 191, nullable: false, comment: '稳定观察者键')]
    public const schema_fields_OBSERVER_KEY = 'observer_key';
    #[Col(type: 'varchar', length: 128, nullable: false, comment: '观察者模块')]
    public const schema_fields_OBSERVER_MODULE = 'observer_module';
    #[Col(type: 'varchar', length: 128, nullable: false, comment: '观察者名称')]
    public const schema_fields_OBSERVER_NAME = 'observer_name';
    #[Col(type: 'char', length: 64, nullable: false, comment: '实现指纹')]
    public const schema_fields_OBSERVER_INSTANCE_HASH = 'observer_instance_hash';
    #[Col(type: 'int', length: 11, nullable: false, comment: '载荷 schema 版本')]
    public const schema_fields_PAYLOAD_SCHEMA_VERSION = 'payload_schema_version';
    #[Col(type: 'longtext', nullable: false, comment: '事件载荷 JSON')]
    public const schema_fields_PAYLOAD_JSON = 'payload_json';
    #[Col(type: 'longtext', nullable: false, comment: '运行上下文 JSON')]
    public const schema_fields_CONTEXT_JSON = 'context_json';
    #[Col(type: 'char', length: 64, nullable: false, comment: '载荷 SHA-256')]
    public const schema_fields_PAYLOAD_SHA256 = 'payload_sha256';
    #[Col(type: 'char', length: 64, nullable: false, default: '', comment: '资源键')]
    public const schema_fields_RESOURCE_KEY = 'resource_key';
    #[Col(type: 'bigint', length: 20, nullable: false, default: 0, comment: '资源修订版')]
    public const schema_fields_REVISION = 'revision';
    #[Col(type: 'varchar', length: 16, nullable: false, default: 'standard', comment: '重试策略')]
    public const schema_fields_RETRY_POLICY = 'retry_policy';
    #[Col(type: 'varchar', length: 16, nullable: false, default: 'none', comment: '合并策略')]
    public const schema_fields_COALESCE_MODE = 'coalesce_mode';
    #[Col(type: 'varchar', length: 512, nullable: false, default: '', comment: '合并键')]
    public const schema_fields_COALESCE_KEY = 'coalesce_key';
    #[Col(type: 'int', length: 11, nullable: false, default: 30, comment: '执行超时秒')]
    public const schema_fields_TIMEOUT_SECONDS = 'timeout_seconds';
    #[Col(type: 'int', length: 11, nullable: false, default: 6, comment: '最大 Observer 尝试次数')]
    public const schema_fields_MAX_ATTEMPTS = 'max_attempts';
    #[Col(type: 'varchar', length: 24, nullable: false, default: 'pending', comment: '投递状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'int', length: 11, nullable: false, default: 0, comment: '当前 Observer 尝试')]
    public const schema_fields_ATTEMPT_NO = 'attempt_no';
    #[Col(type: 'datetime', nullable: true, comment: '下次 Observer 重试时间')]
    public const schema_fields_NEXT_RETRY_AT = 'next_retry_at';
    #[Col(type: 'int', length: 11, nullable: false, default: 0, comment: 'Transport 建件重试次数')]
    public const schema_fields_TRANSPORT_RETRY_COUNT = 'transport_retry_count';
    #[Col(type: 'datetime', nullable: true, comment: 'Transport 再次建件时间')]
    public const schema_fields_PROVISION_AVAILABLE_AT = 'provision_available_at';
    #[Col(type: 'char', length: 64, nullable: true, comment: '租约令牌')]
    public const schema_fields_LEASE_TOKEN = 'lease_token';
    #[Col(type: 'varchar', length: 191, nullable: false, default: '', comment: '租约所有者')]
    public const schema_fields_LEASE_OWNER = 'lease_owner';
    #[Col(type: 'datetime', nullable: true, comment: '租约到期时间')]
    public const schema_fields_LEASE_UNTIL = 'lease_until';
    #[Col(type: 'int', length: 11, nullable: false, default: 0, comment: 'Transport 终止确认尝试次数')]
    public const schema_fields_TERMINATION_ATTEMPT_COUNT = 'termination_attempt_count';
    #[Col(type: 'datetime', nullable: true, comment: '下次 Transport 终止确认时间')]
    public const schema_fields_TERMINATION_NEXT_AT = 'termination_next_at';
    #[Col(type: 'int', length: 11, nullable: false, default: 0, comment: '乐观锁版本')]
    public const schema_fields_LOCK_VERSION = 'lock_version';
    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: 'Transport 名称')]
    public const schema_fields_TRANSPORT_NAME = 'transport_name';
    #[Col(type: 'bigint', length: 20, nullable: true, comment: 'Queue ID 诊断字段')]
    public const schema_fields_QUEUE_ID = 'queue_id';
    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: 'Transport handle')]
    public const schema_fields_TRANSPORT_HANDLE = 'transport_handle';
    #[Col(type: 'varchar', length: 191, nullable: true, comment: 'Transport 幂等键')]
    public const schema_fields_TRANSPORT_IDEMPOTENCY_KEY = 'transport_idempotency_key';
    #[Col(type: 'bigint', length: 20, nullable: true, comment: '替代当前投递的 Delivery')]
    public const schema_fields_SUPERSEDED_BY = 'superseded_by';
    #[Col(type: 'bigint', length: 20, nullable: true, comment: '重放源 Delivery')]
    public const schema_fields_REPLAY_OF_DELIVERY_ID = 'replay_of_delivery_id';
    #[Col(type: 'varchar', length: 128, nullable: false, default: '', comment: '重放发起人')]
    public const schema_fields_REPLAY_REQUESTED_BY = 'replay_requested_by';
    #[Col(type: 'datetime', nullable: true, comment: '重放发起时间')]
    public const schema_fields_REPLAY_REQUESTED_AT = 'replay_requested_at';
    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: '最后错误码')]
    public const schema_fields_LAST_ERROR_CODE = 'last_error_code';
    #[Col(type: 'text', nullable: true, comment: '脱敏错误摘要')]
    public const schema_fields_LAST_ERROR = 'last_error';
    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: '终态原因')]
    public const schema_fields_TERMINAL_REASON = 'terminal_reason';
    #[Col(type: 'datetime', nullable: true, comment: '开始时间')]
    public const schema_fields_STARTED_AT = 'started_at';
    #[Col(type: 'datetime', nullable: true, comment: '结束时间')]
    public const schema_fields_FINISHED_AT = 'finished_at';
    #[Col(type: 'datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [self::schema_fields_EVENT_ID, self::schema_fields_OBSERVER_KEY, self::schema_fields_STATUS];

    public function save_before(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->setData(self::schema_fields_UPDATED_AT, $now);
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        parent::save_before();
    }
}
