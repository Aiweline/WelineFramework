<?php

declare(strict_types=1);

namespace Weline\Shipping\Model\Region;

use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\I18n\Api\Localization\LocalModel;
use Weline\Shipping\Model\Region;

/**
 * Region（国家 / 省 / 市 / 区）多语言名称。
 * 主表 {@see Region} 存默认名；本表按 local_code 存翻译。
 */
#[Table(comment: '配送地区多语言')]
#[Index(name: 'uk_shipping_region_local', columns: ['region_id', 'local_code'], type: 'UNIQUE')]
class LocalDescription extends LocalModel
{
    public const schema_table = 'w_shipping_region_local';
    public const schema_primary_key = Region::schema_fields_ID;
    public const indexer = 'shipping_region_local';

    #[Col(type: 'int', nullable: false, primaryKey: true, comment: '地区ID')]
    public const schema_fields_ID = Region::schema_fields_ID;

    #[Col(type: 'varchar', length: 20, nullable: false, primaryKey: true, comment: '语言代码')]
    public const schema_fields_LOCALE_CODE = self::schema_fields_local_code;

    #[Col(type: 'varchar', length: 255, nullable: true, comment: '地区名称')]
    public const schema_fields_REGION_NAME = Region::schema_fields_REGION_NAME;

    /** TraitLocalModel::getName() 读此列 */
    public const schema_fields_name = Region::schema_fields_REGION_NAME;
}
