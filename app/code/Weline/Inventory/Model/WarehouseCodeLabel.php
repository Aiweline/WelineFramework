<?php

declare(strict_types=1);

namespace Weline\Inventory\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 仓库枚举码别名字典：主表存源语名称；多语言见 {@see WarehouseCodeLabel\LocalDescription}。
 */
#[Table(comment: 'Inventory warehouse code labels')]
#[Index(name: 'uk_inv_wh_code_label', columns: ['code_group', 'code'], type: 'UNIQUE')]
#[Index(name: 'idx_inv_wh_code_label_sort', columns: ['code_group', 'sort_order', 'label_id'])]
class WarehouseCodeLabel extends AbstractModel
{
    public const schema_table = 'weline_inventory_warehouse_code_label';
    public const schema_primary_key = 'label_id';
    public const schema_primary_keys = ['label_id'];

    public const ORIGIN_SEED = 'seed';
    public const ORIGIN_MANUAL = 'manual';

    public const GROUP_NODE_KIND = 'node_kind';
    public const GROUP_MODE = 'mode';
    public const GROUP_WAREHOUSE_TYPE = 'warehouse_type';

    public const GROUPS = [
        self::GROUP_NODE_KIND,
        self::GROUP_MODE,
        self::GROUP_WAREHOUSE_TYPE,
    ];

    #[Col('int', null, nullable: false, primaryKey: true, autoIncrement: true, comment: 'Label ID')]
    public const schema_fields_ID = 'label_id';

    #[Col('varchar', 32, nullable: false, comment: 'node_kind|mode|warehouse_type')]
    public const schema_fields_CODE_GROUP = 'code_group';

    #[Col('varchar', 32, nullable: false, comment: 'English code')]
    public const schema_fields_CODE = 'code';

    #[Col('varchar', 255, nullable: false, default: '', comment: 'Source-language display name')]
    public const schema_fields_LABEL_NAME = 'label_name';

    #[Col('varchar', 16, nullable: false, default: 'manual', comment: 'seed|manual')]
    public const schema_fields_ORIGIN = 'origin';

    #[Col('tinyint', 1, nullable: false, default: 1, comment: 'Enabled')]
    public const schema_fields_IS_ACTIVE = 'is_active';

    #[Col('int', null, nullable: false, default: 100, comment: 'Sort order')]
    public const schema_fields_SORT_ORDER = 'sort_order';

    #[Col('datetime', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_index_sort_keys = ['code_group', 'sort_order', 'label_id'];
}
