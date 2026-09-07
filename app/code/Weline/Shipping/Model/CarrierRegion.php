<?php

declare(strict_types=1);

namespace Weline\Shipping\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '承运商支持范围（覆盖白名单）')]
#[Index(name: 'idx_carrier_coverage', columns: ['carrier_id', 'is_active'])]
#[Index(name: 'idx_carrier_country_type', columns: ['country_code', 'region_type'])]
#[Index(name: 'idx_carrier_region_id', columns: ['region_id'])]
#[Index(name: 'uk_carrier_region', columns: ['carrier_id', 'region_type', 'country_code', 'region_id', 'region_code', 'street_id'], type: 'UNIQUE')]
class CarrierRegion extends AbstractModel
{
    public const schema_table = 'w_shipping_carrier_regions';
    public const schema_primary_key = 'carrier_region_id';
    public const schema_primary_keys = ['carrier_region_id'];

    public const TYPE_COUNTRY = 'country';
    public const TYPE_PROVINCE = 'province';
    public const TYPE_CITY = 'city';
    public const TYPE_DISTRICT = 'district';
    public const TYPE_STREET = 'street';

    #[Col('int', null, nullable: false, primaryKey: true, autoIncrement: true, comment: '覆盖ID')]
    public const schema_fields_ID = 'carrier_region_id';

    #[Col('int', null, nullable: false, comment: '承运商ID')]
    public const schema_fields_CARRIER_ID = 'carrier_id';

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

    public array $_index_sort_keys = ['carrier_region_id', 'carrier_id', 'country_code'];

    public function _init(): void
    {
    }
}
