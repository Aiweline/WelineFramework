<?php

declare(strict_types=1);

namespace Weline\Inventory\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Physical/logical warehouse（P3A-001；additive）.
 * Warehouse mode is normal|test; Store environment compatibility is enforced by the authorization service.
 */
#[Table(comment: 'Inventory warehouse')]
#[Index(name: 'uk_inv_wh_code', columns: ['website_id', 'warehouse_code'], type: 'UNIQUE')]
#[Index(name: 'uk_inv_wh_default', columns: ['website_id', 'mode', 'default_logical_guard'], type: 'UNIQUE')]
#[Index(name: 'idx_inv_wh_mode', columns: ['website_id', 'mode', 'is_default_logical'])]
class Warehouse extends Model
{
    public const schema_table = 'weline_inventory_warehouse';
    public const schema_primary_key = 'warehouse_id';

    public const MODE_NORMAL = 'normal';
    public const MODE_TEST = 'test';
    public const MODES = [self::MODE_NORMAL, self::MODE_TEST];

    public const TYPE_PHYSICAL = 'physical';
    public const TYPE_LOGICAL = 'logical';
    public const TYPES = [self::TYPE_PHYSICAL, self::TYPE_LOGICAL];
    public const DEFAULT_GUARD = 'default';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Warehouse ID')]
    public const schema_fields_ID = 'warehouse_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID (>=0)')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 64, nullable: false, comment: 'Warehouse code')]
    public const schema_fields_WAREHOUSE_CODE = 'warehouse_code';

    #[Col('varchar', 255, nullable: false, default: '', comment: 'Display name')]
    public const schema_fields_NAME = 'name';

    #[Col('varchar', 16, nullable: false, default: self::MODE_NORMAL, comment: 'normal|test')]
    public const schema_fields_MODE = 'mode';

    #[Col('varchar', 16, nullable: false, default: self::TYPE_PHYSICAL, comment: 'physical|logical')]
    public const schema_fields_WAREHOUSE_TYPE = 'warehouse_type';

    #[Col('tinyint', 1, nullable: false, default: 0, comment: 'Default logical warehouse for Website environment')]
    public const schema_fields_IS_DEFAULT_LOGICAL = 'is_default_logical';

    #[Col('varchar', 16, nullable: true, comment: 'Nullable unique guard for Website environment default')]
    public const schema_fields_DEFAULT_LOGICAL_GUARD = 'default_logical_guard';

    #[Col('tinyint', 1, nullable: false, default: 1, comment: 'Enabled')]
    public const schema_fields_ENABLED = 'enabled';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function save_before(): void
    {
        $websiteId = (int) $this->getData(self::schema_fields_WEBSITE_ID);
        if ($websiteId < 0) {
            throw new \InvalidArgumentException(__('Website ID 不能为负'));
        }
        $code = trim((string) $this->getData(self::schema_fields_WAREHOUSE_CODE));
        if ($code === '') {
            throw new \InvalidArgumentException(__('仓代码不能为空'));
        }
        $mode = strtolower(trim((string) $this->getData(self::schema_fields_MODE)));
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException(__('仓模式无效：%{1}', [$mode]));
        }
        $type = strtolower(trim((string) $this->getData(self::schema_fields_WAREHOUSE_TYPE)));
        $type = $type !== '' ? $type : self::TYPE_PHYSICAL;
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(__('仓类型无效：%{1}', [$type]));
        }
        $isDefault = (int) $this->getData(self::schema_fields_IS_DEFAULT_LOGICAL) === 1;
        if ($isDefault) {
            $type = self::TYPE_LOGICAL;
        }
        $this->setData(self::schema_fields_WAREHOUSE_CODE, $code);
        $this->setData(self::schema_fields_MODE, $mode);
        $this->setData(self::schema_fields_WAREHOUSE_TYPE, $type);
        $this->setData(self::schema_fields_IS_DEFAULT_LOGICAL, $isDefault ? 1 : 0);
        $this->setData(
            self::schema_fields_DEFAULT_LOGICAL_GUARD,
            $isDefault ? self::DEFAULT_GUARD : null,
        );

        parent::save_before();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
