<?php
declare(strict_types=1);

namespace Weline\Shipping\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '配送街道表')]
#[Index(name: 'idx_street_parent', columns: ['parent_region_id'])]
#[Index(name: 'uk_street_country_code', columns: ['country_code', 'street_code'], type: 'UNIQUE')]
#[Index(name: 'idx_street_country', columns: ['country_code'])]
class Street extends AbstractModel
{
    public const schema_table = 'w_shipping_streets';
    public const schema_primary_key = 'street_id';
    public const schema_primary_keys = ['street_id'];

    #[Col('int', null, nullable: false, primaryKey: true, autoIncrement: true, comment: '街道ID')]
    public const schema_fields_ID = 'street_id';
    #[Col('varchar', 2, nullable: false, comment: 'ISO国家代码')]
    public const schema_fields_COUNTRY_CODE = 'country_code';
    #[Col('int', null, nullable: false, comment: '父级区划 region_id')]
    public const schema_fields_PARENT_REGION_ID = 'parent_region_id';
    #[Col('varchar', 96, nullable: false, comment: '街道业务码')]
    public const schema_fields_STREET_CODE = 'street_code';
    #[Col('varchar', 255, nullable: false, comment: '街道名称')]
    public const schema_fields_STREET_NAME = 'street_name';
    #[Col('varchar', 32, comment: '邮编')]
    public const schema_fields_POSTAL_CODE = 'postal_code';
    #[Col('int', 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col('int', null, nullable: false, default: 0, comment: '排序')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_index_sort_keys = ['street_id', 'country_code', 'parent_region_id'];

    public function _init(): void
    {
    }
}
