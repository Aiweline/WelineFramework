<?php

declare(strict_types=1);

namespace Weline\Framework\Model\Runtime;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '可恢复运行时客户端租约')]
#[Index(name: 'uk_runtime_lease_task_lease', columns: ['task_id', 'lease_id'], type: 'UNIQUE')]
#[Index(name: 'idx_runtime_lease_task_expiry', columns: ['task_id', 'expires_at'])]
#[Index(name: 'idx_runtime_lease_expiry', columns: ['expires_at'])]
class ResumableTaskLease extends Model
{
    public const schema_table = 'weline_runtime_task_lease';
    public const schema_primary_key = 'id';

    #[Col(type: 'bigint', length: 20, primaryKey: true, autoIncrement: true, nullable: false, comment: '内部主键')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: '任务标识')]
    public const schema_fields_TASK_ID = 'task_id';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: '页面租约标识')]
    public const schema_fields_LEASE_ID = 'lease_id';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: '所有者 area')]
    public const schema_fields_OWNER_AREA = 'owner_area';
    #[Col(type: 'varchar', length: 128, nullable: false, comment: '所有者主体')]
    public const schema_fields_OWNER_PRINCIPAL = 'owner_principal';
    #[Col(type: 'varchar', length: 128, nullable: false, default: '', comment: '订阅实例标识')]
    public const schema_fields_SUBSCRIPTION_ID = 'subscription_id';
    #[Col(type: 'datetime', nullable: false, comment: '最近客户端联系时间')]
    public const schema_fields_LAST_SEEN_AT = 'last_seen_at';
    #[Col(type: 'datetime', nullable: false, comment: '租约截止时间')]
    public const schema_fields_EXPIRES_AT = 'expires_at';
    #[Col(type: 'datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [self::schema_fields_TASK_ID, self::schema_fields_EXPIRES_AT];

    public function save_before(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->setData(self::schema_fields_UPDATED_AT, $now);
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        parent::save_before();
    }
}
