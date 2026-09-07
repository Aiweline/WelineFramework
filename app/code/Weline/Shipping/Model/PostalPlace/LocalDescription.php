<?php

declare(strict_types=1);

namespace Weline\Shipping\Model\PostalPlace;

use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\I18n\Api\Localization\LocalModel;
use Weline\Shipping\Model\PostalPlace;

/**
 * 邮编地点多语言名称。主表 {@see PostalPlace} 存默认名；本表按 local_code 存翻译。
 * 邮编反查在区划名缺失/不透明时会用 place_name 作为地址展示名。
 */
#[Table(comment: '配送邮编地点多语言')]
#[Index(name: 'uk_shipping_postal_place_local', columns: ['postal_place_id', 'local_code'], type: 'UNIQUE')]
class LocalDescription extends LocalModel
{
    public const schema_table = 'w_shipping_postal_place_local';
    public const schema_primary_key = PostalPlace::schema_fields_ID;
    public const indexer = 'shipping_postal_place_local';

    #[Col(type: 'int', nullable: false, primaryKey: true, comment: '邮编地点ID')]
    public const schema_fields_ID = PostalPlace::schema_fields_ID;

    #[Col(type: 'varchar', length: 20, nullable: false, primaryKey: true, comment: '语言代码')]
    public const schema_fields_LOCALE_CODE = self::schema_fields_local_code;

    #[Col(type: 'varchar', length: 255, nullable: true, comment: '地点展示名')]
    public const schema_fields_PLACE_NAME = PostalPlace::schema_fields_PLACE_NAME;

    /** TraitLocalModel::getName() 读此列 */
    public const schema_fields_name = PostalPlace::schema_fields_PLACE_NAME;
}
