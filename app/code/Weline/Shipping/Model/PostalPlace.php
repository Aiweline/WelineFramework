<?php
declare(strict_types=1);

namespace Weline\Shipping\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '配送邮编反查表')]
#[Index(name: 'idx_postal_country_norm', columns: ['country_code', 'postal_code_norm'])]
#[Index(name: 'idx_postal_attach_region', columns: ['attach_region_id'])]
class PostalPlace extends AbstractModel
{
    public const schema_table = 'w_shipping_postal_places';
    public const schema_primary_key = 'postal_place_id';
    public const schema_primary_keys = ['postal_place_id'];

    #[Col('int', null, nullable: false, primaryKey: true, autoIncrement: true, comment: '邮编地点ID')]
    public const schema_fields_ID = 'postal_place_id';
    #[Col('varchar', 2, nullable: false, comment: 'ISO国家代码')]
    public const schema_fields_COUNTRY_CODE = 'country_code';
    #[Col('varchar', 32, nullable: false, comment: '原始邮编')]
    public const schema_fields_POSTAL_CODE = 'postal_code';
    #[Col('varchar', 32, nullable: false, comment: '规范化邮编')]
    public const schema_fields_POSTAL_CODE_NORM = 'postal_code_norm';
    #[Col('varchar', 255, nullable: false, default: '', comment: '地点展示名')]
    public const schema_fields_PLACE_NAME = 'place_name';
    #[Col('varchar', 16, nullable: false, default: 'city', comment: '挂载层级 province|city|district')]
    public const schema_fields_ATTACH_LEVEL = 'attach_level';
    #[Col('varchar', 96, comment: '省业务码')]
    public const schema_fields_PROVINCE_CODE = 'province_code';
    #[Col('varchar', 96, comment: '市业务码')]
    public const schema_fields_CITY_CODE = 'city_code';
    #[Col('varchar', 96, comment: '区业务码')]
    public const schema_fields_DISTRICT_CODE = 'district_code';
    #[Col('int', null, comment: '挂载区划 region_id')]
    public const schema_fields_ATTACH_REGION_ID = 'attach_region_id';
    #[Col('int', null, comment: '省 region_id')]
    public const schema_fields_PROVINCE_REGION_ID = 'province_region_id';
    #[Col('int', null, comment: '市 region_id')]
    public const schema_fields_CITY_REGION_ID = 'city_region_id';
    #[Col('int', null, comment: '区 region_id')]
    public const schema_fields_DISTRICT_REGION_ID = 'district_region_id';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_index_sort_keys = ['postal_place_id', 'country_code', 'postal_code_norm'];

    public function _init(): void
    {
    }
}
