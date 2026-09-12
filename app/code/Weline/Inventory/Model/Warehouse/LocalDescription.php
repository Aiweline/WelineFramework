<?php

declare(strict_types=1);

namespace Weline\Inventory\Model\Warehouse;

use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\I18n\Api\Localization\LocalModel;
use Weline\Inventory\Model\Warehouse;

/**
 * 仓库名称多语言。主表存源语回退；本表按 local_code 存译文（可点击翻译）。
 */
#[Table(comment: 'Inventory warehouse local descriptions')]
#[Index(name: 'uk_inv_wh_local', columns: ['warehouse_id', 'local_code'], type: 'UNIQUE')]
class LocalDescription extends LocalModel
{
    public const schema_table = 'weline_inventory_warehouse_local';
    public const schema_primary_key = Warehouse::schema_fields_ID;
    public const indexer = 'inventory_warehouse_local';

    #[Col(type: 'int', nullable: false, primaryKey: true, comment: 'Warehouse ID')]
    public const schema_fields_ID = Warehouse::schema_fields_ID;

    #[Col(type: 'varchar', length: 20, nullable: false, primaryKey: true, comment: 'Locale code')]
    public const schema_fields_LOCALE_CODE = self::schema_fields_local_code;

    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'Localized display name / alias')]
    public const schema_fields_NAME = Warehouse::schema_fields_NAME;

    /** TraitLocalModel::getName() 读此列 */
    public const schema_fields_name = Warehouse::schema_fields_NAME;
}
