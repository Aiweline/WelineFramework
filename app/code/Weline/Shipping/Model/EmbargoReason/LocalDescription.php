<?php

declare(strict_types=1);

namespace Weline\Shipping\Model\EmbargoReason;

use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\I18n\Api\Localization\LocalModel;
use Weline\Shipping\Model\EmbargoReason;

/**
 * 禁运原因多语言名称。主表 {@see EmbargoReason} 存默认名；本表按 local_code 存翻译。
 */
#[Table(comment: '配送禁运原因多语言')]
#[Index(name: 'uk_shipping_embargo_reason_local', columns: ['reason_id', 'local_code'], type: 'UNIQUE')]
class LocalDescription extends LocalModel
{
    public const schema_table = 'w_shipping_embargo_reason_local';
    public const schema_primary_key = EmbargoReason::schema_fields_ID;
    public const indexer = 'shipping_embargo_reason_local';

    #[Col(type: 'int', nullable: false, primaryKey: true, comment: '原因ID')]
    public const schema_fields_ID = EmbargoReason::schema_fields_ID;

    #[Col(type: 'varchar', length: 20, nullable: false, primaryKey: true, comment: '语言代码')]
    public const schema_fields_LOCALE_CODE = self::schema_fields_local_code;

    #[Col(type: 'varchar', length: 255, nullable: true, comment: '原因展示名')]
    public const schema_fields_REASON_NAME = EmbargoReason::schema_fields_REASON_NAME;

    /** TraitLocalModel::getName() 读此列 */
    public const schema_fields_name = EmbargoReason::schema_fields_REASON_NAME;
}
