<?php

declare(strict_types=1);

namespace Weline\Framework\Model\Cache;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '缓存命名空间代际权威表')]
class NamespaceVersion extends Model
{
    public const use_main_db_master = true;
    public const schema_table = 'weline_cache_namespace_version';
    public const schema_primary_key = 'namespace_hash';

    #[Col(type: 'char', length: 64, primaryKey: true, nullable: false, comment: '命名空间 SHA-256')]
    public const schema_fields_HASH = 'namespace_hash';
    #[Col(type: 'varchar', length: 512, nullable: false, comment: '完整规范命名空间')]
    public const schema_fields_NAMESPACE = 'namespace';
    #[Col(type: 'bigint', length: 20, nullable: false, default: 0, comment: '单调递增代际')]
    public const schema_fields_GENERATION = 'generation';
    #[Col(type: 'datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = [self::schema_fields_HASH];
    public array $_index_sort_keys = [self::schema_fields_NAMESPACE];

    public function save_before(): void
    {
        $this->setData(self::schema_fields_UPDATED_AT, gmdate('Y-m-d H:i:s'));
        parent::save_before();
    }
}
