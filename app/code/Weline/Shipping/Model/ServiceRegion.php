<?php

declare(strict_types=1);

namespace Weline\Shipping\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 配送服务（航线）目的地覆盖：相对发货仓，按收货地祖先命中。
 */
#[Table(comment: '配送服务航线目的地覆盖')]
#[Index(name: 'idx_service_lane_coverage', columns: ['service_id', 'is_active'])]
#[Index(name: 'idx_service_lane_country_type', columns: ['country_code', 'region_type'])]
#[Index(name: 'idx_service_lane_region_id', columns: ['region_id'])]
#[Index(
    name: 'uk_service_lane_region',
    columns: ['service_id', 'region_type', 'country_code', 'region_id', 'region_code'],
    type: 'UNIQUE',
)]
class ServiceRegion extends AbstractModel
{
    public const schema_table = 'w_shipping_service_regions';
    public const schema_primary_key = 'service_region_id';
    public const schema_primary_keys = ['service_region_id'];

    public const TYPE_COUNTRY = 'country';
    public const TYPE_PROVINCE = 'province';
    public const TYPE_CITY = 'city';
    public const TYPE_DISTRICT = 'district';

    #[Col('int', null, nullable: false, primaryKey: true, autoIncrement: true, comment: '航线覆盖ID')]
    public const schema_fields_ID = 'service_region_id';

    #[Col('int', null, nullable: false, comment: '配送服务ID')]
    public const schema_fields_SERVICE_ID = 'service_id';

    #[Col('varchar', 16, nullable: false, comment: '地区类型')]
    public const schema_fields_REGION_TYPE = 'region_type';

    #[Col('varchar', 2, nullable: false, comment: 'ISO国家代码')]
    public const schema_fields_COUNTRY_CODE = 'country_code';

    #[Col('int', null, comment: '地区ID')]
    public const schema_fields_REGION_ID = 'region_id';

    #[Col('varchar', 96, comment: '地区代码')]
    public const schema_fields_REGION_CODE = 'region_code';

    #[Col('int', 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';

    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_index_sort_keys = ['service_region_id', 'service_id', 'country_code'];

    public function _init(): void
    {
    }
}
