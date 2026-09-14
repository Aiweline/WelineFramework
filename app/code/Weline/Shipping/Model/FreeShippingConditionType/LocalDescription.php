<?php

declare(strict_types=1);

namespace Weline\Shipping\Model\FreeShippingConditionType;

use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\I18n\Api\Localization\LocalModel;
use Weline\Shipping\Model\FreeShippingConditionType;

/**
 * 免邮条件类型多语言名称。主表 {@see FreeShippingConditionType} 存默认名；本表按 local_code 存翻译。
 */
#[Table(comment: '免邮条件类型多语言')]
#[Index(name: 'uk_shipping_free_condition_local', columns: ['type_id', 'local_code'], type: 'UNIQUE')]
class LocalDescription extends LocalModel
{
    public const schema_table = 'w_shipping_free_condition_type_local';
    public const schema_primary_key = FreeShippingConditionType::schema_fields_ID;
    public const indexer = 'shipping_free_condition_type_local';

    #[Col(type: 'int', nullable: false, primaryKey: true, comment: '类型ID')]
    public const schema_fields_ID = FreeShippingConditionType::schema_fields_ID;

    #[Col(type: 'varchar', length: 20, nullable: false, primaryKey: true, comment: '语言代码')]
    public const schema_fields_LOCALE_CODE = self::schema_fields_local_code;

    #[Col(type: 'varchar', length: 255, nullable: true, comment: '条件展示名')]
    public const schema_fields_CONDITION_NAME = FreeShippingConditionType::schema_fields_CONDITION_NAME;

    /** TraitLocalModel::getName() 读此列 */
    public const schema_fields_name = FreeShippingConditionType::schema_fields_CONDITION_NAME;
}
