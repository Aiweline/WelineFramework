<?php

declare(strict_types=1);

namespace Weline\Framework\Model\Event;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '异步事件 latest 合并槽')]
#[Index(name: 'uk_event_coalesce_slot', columns: ['observer_key_hash', 'coalesce_key_hash'], type: 'UNIQUE')]
#[Index(name: 'idx_event_coalesce_delivery', columns: ['current_delivery_id'])]
class CoalesceSlot extends Model
{
    public const schema_table = 'weline_framework_event_coalesce_slot';
    public const schema_primary_key = 'slot_id';

    #[Col(type: 'bigint', length: 20, primaryKey: true, autoIncrement: true, nullable: false, comment: '合并槽 ID')]
    public const schema_fields_ID = 'slot_id';
    #[Col(type: 'char', length: 64, nullable: false, comment: '观察者键哈希')]
    public const schema_fields_OBSERVER_KEY_HASH = 'observer_key_hash';
    #[Col(type: 'char', length: 64, nullable: false, comment: '合并键哈希')]
    public const schema_fields_COALESCE_KEY_HASH = 'coalesce_key_hash';
    #[Col(type: 'varchar', length: 191, nullable: false, comment: '完整观察者键')]
    public const schema_fields_OBSERVER_KEY = 'observer_key';
    #[Col(type: 'varchar', length: 512, nullable: false, comment: '完整合并键')]
    public const schema_fields_COALESCE_KEY = 'coalesce_key';
    #[Col(type: 'bigint', length: 20, nullable: true, comment: '当前 pending Delivery')]
    public const schema_fields_CURRENT_DELIVERY_ID = 'current_delivery_id';
    #[Col(type: 'bigint', length: 20, nullable: false, default: 0, comment: '最后成功修订版')]
    public const schema_fields_LAST_SUCCESS_REVISION = 'last_success_revision';
    #[Col(type: 'int', length: 11, nullable: false, default: 0, comment: '乐观锁版本')]
    public const schema_fields_LOCK_VERSION = 'lock_version';
    #[Col(type: 'datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [self::schema_fields_OBSERVER_KEY_HASH, self::schema_fields_COALESCE_KEY_HASH];

    public function save_before(): void
    {
        $this->setData(self::schema_fields_UPDATED_AT, gmdate('Y-m-d H:i:s'));
        parent::save_before();
    }
}
