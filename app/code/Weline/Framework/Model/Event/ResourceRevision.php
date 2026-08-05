<?php

declare(strict_types=1);

namespace Weline\Framework\Model\Event;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '业务资源单调修订版')]
#[Index(name: 'idx_resource_revision_identity', columns: ['resource_type', 'resource_id'])]
class ResourceRevision extends Model
{
    public const schema_table = 'weline_framework_resource_revision';
    public const schema_primary_key = 'resource_key';

    #[Col(type: 'char', length: 64, primaryKey: true, nullable: false, comment: '资源键 SHA-256')]
    public const schema_fields_ID = 'resource_key';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: '资源类型')]
    public const schema_fields_RESOURCE_TYPE = 'resource_type';
    #[Col(type: 'varchar', length: 191, nullable: false, comment: '资源 ID')]
    public const schema_fields_RESOURCE_ID = 'resource_id';
    #[Col(type: 'bigint', length: 20, nullable: false, default: 0, comment: '当前修订版')]
    public const schema_fields_REVISION = 'revision';
    #[Col(type: 'datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [self::schema_fields_RESOURCE_TYPE, self::schema_fields_RESOURCE_ID];

    public function save_before(): void
    {
        $this->setData(self::schema_fields_UPDATED_AT, gmdate('Y-m-d H:i:s'));
        parent::save_before();
    }
}
