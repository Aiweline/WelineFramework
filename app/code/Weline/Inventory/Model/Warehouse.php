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
 * Hierarchy: country → province → warehouse；warehouse_code is immutable after create.
 */
#[Table(comment: 'Inventory warehouse')]
#[Index(name: 'uk_inv_wh_code', columns: ['website_id', 'warehouse_code'], type: 'UNIQUE')]
#[Index(name: 'uk_inv_wh_default', columns: ['website_id', 'mode', 'default_logical_guard'], type: 'UNIQUE')]
#[Index(name: 'idx_inv_wh_mode', columns: ['website_id', 'mode', 'is_default_logical'])]
#[Index(name: 'idx_inv_wh_parent', columns: ['website_id', 'parent_id'])]
#[Index(name: 'idx_inv_wh_country', columns: ['website_id', 'country_code', 'region_code'])]
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

    public const NODE_COUNTRY = 'country';
    public const NODE_PROVINCE = 'province';
    public const NODE_WAREHOUSE = 'warehouse';
    public const NODE_KINDS = [self::NODE_COUNTRY, self::NODE_PROVINCE, self::NODE_WAREHOUSE];

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Warehouse ID')]
    public const schema_fields_ID = 'warehouse_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID (>=0)')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Parent warehouse_id (0=root country)')]
    public const schema_fields_PARENT_ID = 'parent_id';

    #[Col('varchar', 16, nullable: false, default: self::NODE_WAREHOUSE, comment: 'country|province|warehouse')]
    public const schema_fields_NODE_KIND = 'node_kind';

    #[Col('varchar', 2, nullable: true, comment: 'ISO 3166-1 alpha-2 country code')]
    public const schema_fields_COUNTRY_CODE = 'country_code';

    #[Col('varchar', 32, nullable: true, comment: 'Province/region code segment')]
    public const schema_fields_REGION_CODE = 'region_code';

    #[Col('varchar', 64, nullable: false, comment: 'Warehouse code (immutable)')]
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

    #[Col('tinyint', 1, nullable: false, default: 0, comment: 'System seed warehouse (immutable delete)')]
    public const schema_fields_IS_SEED = 'is_seed';

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
        $id = (int) $this->getData(self::schema_fields_ID);
        if ($id > 0) {
            $existing = clone $this;
            $existing->clear()->load($id);
            if ((int)$existing->getId() === $id) {
                $oldCode = trim((string)$existing->getData(self::schema_fields_WAREHOUSE_CODE));
                if ($oldCode !== '' && strcasecmp($oldCode, $code) !== 0) {
                    throw new \InvalidArgumentException(__('仓库码一旦确定不可修改，请删除后重建'));
                }
                $code = $oldCode !== '' ? $oldCode : $code;
            }
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
        $nodeKind = strtolower(trim((string) $this->getData(self::schema_fields_NODE_KIND)));
        $nodeKind = $nodeKind !== '' ? $nodeKind : self::NODE_WAREHOUSE;
        if (!in_array($nodeKind, self::NODE_KINDS, true)) {
            throw new \InvalidArgumentException(__('仓节点类型无效：%{1}', [$nodeKind]));
        }
        $parentId = (int) $this->getData(self::schema_fields_PARENT_ID);
        if ($parentId < 0) {
            throw new \InvalidArgumentException(__('父仓库 ID 不能为负'));
        }
        $country = strtoupper(trim((string) $this->getData(self::schema_fields_COUNTRY_CODE)));
        $country = $country !== '' ? $country : null;
        if ($country !== null && !preg_match('/^[A-Z]{2}$/', $country)) {
            throw new \InvalidArgumentException(__('国家码无效'));
        }
        $region = strtoupper(trim((string) $this->getData(self::schema_fields_REGION_CODE)));
        $region = $region !== '' ? $region : null;
        $isDefault = (int) $this->getData(self::schema_fields_IS_DEFAULT_LOGICAL) === 1;
        if ($isDefault) {
            $type = self::TYPE_LOGICAL;
        }
        $isSeed = (int) $this->getData(self::schema_fields_IS_SEED) === 1;
        $this->setData(self::schema_fields_WAREHOUSE_CODE, $code);
        $this->setData(self::schema_fields_MODE, $mode);
        $this->setData(self::schema_fields_WAREHOUSE_TYPE, $type);
        $this->setData(self::schema_fields_NODE_KIND, $nodeKind);
        $this->setData(self::schema_fields_PARENT_ID, $parentId);
        $this->setData(self::schema_fields_COUNTRY_CODE, $country);
        $this->setData(self::schema_fields_REGION_CODE, $region);
        $this->setData(self::schema_fields_IS_DEFAULT_LOGICAL, $isDefault ? 1 : 0);
        $this->setData(self::schema_fields_IS_SEED, $isSeed ? 1 : 0);
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
