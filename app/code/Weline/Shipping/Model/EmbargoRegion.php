<?php

declare(strict_types=1);

namespace Weline\Shipping\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '运输禁运区域表（网站/店铺/渠道）')]
#[Index(name: 'idx_embargo_scope', columns: ['scope_type', 'scope_id', 'is_active'])]
#[Index(name: 'idx_embargo_country_type', columns: ['country_code', 'region_type'])]
#[Index(name: 'idx_embargo_region_id', columns: ['region_id'])]
class EmbargoRegion extends AbstractModel
{
    public const schema_table = 'w_shipping_embargo_regions';
    public const schema_primary_key = 'embargo_id';
    public const schema_primary_keys = ['embargo_id'];

    public const SCOPE_WEBSITE = 'website';
    public const SCOPE_STORE = 'store';
    public const SCOPE_CHANNEL = 'channel';

    public const TYPE_COUNTRY = 'country';
    public const TYPE_PROVINCE = 'province';
    public const TYPE_CITY = 'city';
    public const TYPE_DISTRICT = 'district';
    public const TYPE_STREET = 'street';

    #[Col('int', null, nullable: false, primaryKey: true, autoIncrement: true, comment: '禁运ID')]
    public const schema_fields_ID = 'embargo_id';

    #[Col('varchar', 16, nullable: false, comment: '作用域类型 website|store|channel')]
    public const schema_fields_SCOPE_TYPE = 'scope_type';

    #[Col('int', null, nullable: false, default: 0, comment: '作用域ID')]
    public const schema_fields_SCOPE_ID = 'scope_id';

    #[Col('varchar', 16, nullable: false, comment: '地区类型')]
    public const schema_fields_REGION_TYPE = 'region_type';

    #[Col('varchar', 2, nullable: false, comment: 'ISO国家代码')]
    public const schema_fields_COUNTRY_CODE = 'country_code';

    #[Col('int', null, comment: '地区ID')]
    public const schema_fields_REGION_ID = 'region_id';

    #[Col('varchar', 96, comment: '地区代码')]
    public const schema_fields_REGION_CODE = 'region_code';

    #[Col('int', null, comment: '街道ID')]
    public const schema_fields_STREET_ID = 'street_id';

    #[Col('int', 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';

    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_index_sort_keys = ['embargo_id', 'scope_type', 'scope_id', 'country_code'];

    public function _init(): void
    {
    }
}
