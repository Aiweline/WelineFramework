<?php

declare(strict_types=1);

namespace Weline\Framework\Model\Event;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '可靠异步事件 Outbox')]
#[Index(name: 'uk_event_outbox_event', columns: ['event_id'], type: 'UNIQUE')]
#[Index(name: 'idx_event_outbox_available', columns: ['status', 'available_at'])]
#[Index(name: 'idx_event_outbox_lease', columns: ['lease_expires_at'])]
#[Index(name: 'idx_event_outbox_name_time', columns: ['event_name', 'occurred_at'])]
class Outbox extends Model
{
    public const schema_table = 'weline_framework_event_outbox';
    public const schema_primary_key = 'outbox_id';

    #[Col(type: 'bigint', length: 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Outbox ID')]
    public const schema_fields_ID = 'outbox_id';
    #[Col(type: 'char', length: 32, nullable: false, comment: '事件 ID')]
    public const schema_fields_EVENT_ID = 'event_id';
    #[Col(type: 'varchar', length: 191, nullable: false, comment: '事件名')]
    public const schema_fields_EVENT_NAME = 'event_name';
    #[Col(type: 'int', length: 11, nullable: false, comment: '载荷 schema 版本')]
    public const schema_fields_PAYLOAD_SCHEMA_VERSION = 'payload_schema_version';
    #[Col(type: 'longtext', nullable: false, comment: '规范化事件载荷 JSON')]
    public const schema_fields_PAYLOAD_JSON = 'payload_json';
    #[Col(type: 'longtext', nullable: false, comment: '白名单运行上下文 JSON')]
    public const schema_fields_CONTEXT_JSON = 'context_json';
    #[Col(type: 'longtext', nullable: false, comment: '观察者目标快照 JSON')]
    public const schema_fields_OBSERVER_TARGETS_JSON = 'observer_targets_json';
    #[Col(type: 'char', length: 64, nullable: false, comment: '载荷与目标清单 SHA-256')]
    public const schema_fields_PAYLOAD_SHA256 = 'payload_sha256';
    #[Col(type: 'varchar', length: 16, nullable: false, default: 'pending', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'int', length: 11, nullable: false, default: 0, comment: '扩展尝试次数')]
    public const schema_fields_ATTEMPT_COUNT = 'attempt_count';
    #[Col(type: 'int', length: 11, nullable: false, default: 0, comment: '乐观锁版本')]
    public const schema_fields_LOCK_VERSION = 'lock_version';
    #[Col(type: 'datetime', nullable: false, comment: '下次可执行时间')]
    public const schema_fields_AVAILABLE_AT = 'available_at';
    #[Col(type: 'char', length: 64, nullable: true, comment: '租约令牌')]
    public const schema_fields_LEASE_TOKEN = 'lease_token';
    #[Col(type: 'datetime', nullable: true, comment: '租约到期时间')]
    public const schema_fields_LEASE_EXPIRES_AT = 'lease_expires_at';
    #[Col(type: 'datetime', nullable: true, comment: '展开完成时间')]
    public const schema_fields_EXPANDED_AT = 'expanded_at';
    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: '最后错误码')]
    public const schema_fields_LAST_ERROR_CODE = 'last_error_code';
    #[Col(type: 'text', nullable: true, comment: '脱敏错误摘要')]
    public const schema_fields_LAST_ERROR = 'last_error';
    #[Col(type: 'datetime', nullable: false, comment: '事件发生时间')]
    public const schema_fields_OCCURRED_AT = 'occurred_at';
    #[Col(type: 'datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [self::schema_fields_EVENT_ID, self::schema_fields_STATUS, self::schema_fields_AVAILABLE_AT];

    public function save_before(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->setData(self::schema_fields_UPDATED_AT, $now);
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
            $this->setData(self::schema_fields_AVAILABLE_AT, $this->getData(self::schema_fields_AVAILABLE_AT) ?: $now);
            $this->setData(self::schema_fields_OCCURRED_AT, $this->getData(self::schema_fields_OCCURRED_AT) ?: $now);
        }
        parent::save_before();
    }
}
