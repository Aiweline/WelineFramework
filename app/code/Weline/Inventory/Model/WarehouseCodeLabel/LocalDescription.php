<?php

declare(strict_types=1);

namespace Weline\Inventory\Model\WarehouseCodeLabel;

use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\I18n\Api\Localization\LocalModel;
use Weline\Inventory\Model\WarehouseCodeLabel;

/**
 * 仓库枚举码别名多语言。主表存源语回退；本表按 local_code 存译文。
 */
#[Table(comment: 'Inventory warehouse code label locals')]
#[Index(name: 'uk_inv_wh_code_label_local', columns: ['label_id', 'local_code'], type: 'UNIQUE')]
class LocalDescription extends LocalModel
{
    public const schema_table = 'weline_inventory_warehouse_code_label_local';
    public const schema_primary_key = WarehouseCodeLabel::schema_fields_ID;
    public const indexer = 'inventory_warehouse_code_label_local';

    #[Col(type: 'int', nullable: false, primaryKey: true, comment: 'Label ID')]
    public const schema_fields_ID = WarehouseCodeLabel::schema_fields_ID;

    #[Col(type: 'varchar', length: 20, nullable: false, primaryKey: true, comment: 'Locale code')]
    public const schema_fields_LOCALE_CODE = self::schema_fields_local_code;

    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'Localized label name')]
    public const schema_fields_LABEL_NAME = WarehouseCodeLabel::schema_fields_LABEL_NAME;

    /** TraitLocalModel::getName() 读此列 */
    public const schema_fields_name = WarehouseCodeLabel::schema_fields_LABEL_NAME;
}
