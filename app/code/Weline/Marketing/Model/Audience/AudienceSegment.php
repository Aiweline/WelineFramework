<?php

declare(strict_types=1);

namespace Weline\Marketing\Model\Audience;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '营销薄分群')]
#[Index(name: 'uniq_audience_segment_code', columns: ['code'], type: 'UNIQUE')]
class AudienceSegment extends Model
{
    public const schema_table = 'weline_marketing_audience_segment';
    public const schema_primary_key = 'id';
    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['id', 'code', 'status'];

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '分群ID')]
    public const schema_fields_ID = 'id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: '编码')]
    public const schema_fields_CODE = 'code';

    #[Col(type: 'varchar', length: 255, nullable: false, comment: '名称')]
    public const schema_fields_NAME = 'name';

    #[Col(type: 'varchar', length: 32, nullable: false, default: 'new_customer', comment: '种类')]
    public const schema_fields_KIND = 'kind';

    #[Col(type: 'text', nullable: true, comment: '配置 JSON')]
    public const schema_fields_CONFIG_JSON = 'config_json';

    #[Col(type: 'varchar', length: 20, nullable: false, default: 'enabled', comment: '状态')]
    public const schema_fields_STATUS = 'status';

    public const KIND_NEW_CUSTOMER = 'new_customer';
    public const KIND_RETURNING = 'returning';
    public const KIND_IDLE_DAYS = 'idle_days';
    public const STATUS_ENABLED = 'enabled';
    public const STATUS_DISABLED = 'disabled';
}
