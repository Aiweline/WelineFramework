<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */
namespace Weline\Shipping\Model;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '配送地区表')]
#[Index(name: 'idx_country_code', columns: ['country_code'])]
#[Index(name: 'idx_parent_region_id', columns: ['parent_region_id'])]
#[Index(name: 'idx_region_code', columns: ['region_code'])]
#[Index(name: 'idx_region_type', columns: ['region_type'])]
class Region extends AbstractModel
{
    public const schema_table = 'w_shipping_regions';
    public const schema_primary_key = 'region_id';
    public const schema_primary_keys = ['region_id'];
    #[Col('int', null, nullable: false, primaryKey: true, autoIncrement: true, comment: '地区ID')]
    public const schema_fields_ID = 'region_id';
    #[Col('varchar', 2, nullable: false, comment: 'ISO国家代码')]
    public const schema_fields_COUNTRY_CODE = 'country_code';
    #[Col('int', null, comment: '父级地区ID')]
    public const schema_fields_PARENT_REGION_ID = 'parent_region_id';
    #[Col('varchar', 50, nullable: false, comment: '地区代码')]
    public const schema_fields_REGION_CODE = 'region_code';
    #[Col('varchar', 255, nullable: false, comment: '地区名称')]
    public const schema_fields_REGION_NAME = 'region_name';
    #[Col('varchar', 20, nullable: false, comment: '地区类型')]
    public const schema_fields_REGION_TYPE = 'region_type';
    #[Col('varchar', 100, comment: '邮政编码格式')]
    public const schema_fields_POSTAL_CODE_PATTERN = 'postal_code_pattern';
    #[Col('int', 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col('int', null, nullable: false, default: 0, comment: '排序')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    // 地区类型常量
    public const TYPE_COUNTRY = 'country';
    public const TYPE_PROVINCE = 'province';
    public const TYPE_CITY = 'city';
    public const TYPE_DISTRICT = 'district';
    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['region_id', 'country_code', 'parent_region_id', 'region_type'];
    /**
     * 初始化模型
     */
    public function _init(): void
    {
    }
    /**
     * 获取表名
     */
    public function getTable(string $table = ''): string
    {
        return self::schema_table;
    }

    /**
     * 获取子地区列表
     *
     * @param int|null $parentRegionId 父级地区ID，null表示获取顶级地区
     * @return \Weline\Framework\Database\Model 含 getItems() 的模型实例
     */
    public function getChildren(?int $parentRegionId = null): \Weline\Framework\Database\Model
    {
        $model = $this->reset();
        if ($parentRegionId === null) {
            $model->where(self::schema_fields_PARENT_REGION_ID, null, 'IS');
        } else {
            $model->where(self::schema_fields_PARENT_REGION_ID, $parentRegionId);
        }
        $model->where(self::schema_fields_IS_ACTIVE, 1)
              ->order(self::schema_fields_SORT_ORDER, 'ASC')
              ->order(self::schema_fields_REGION_NAME, 'ASC');
        return $model->select()->fetch();
    }
    /**
     * 根据国家代码获取地区列表
     *
     * @param string $countryCode ISO国家代码
     * @param string|null $regionType 地区类型筛选
     * @return \Weline\Framework\Database\Model 含 getItems() 的模型实例
     */
    public function getByCountryCode(string $countryCode, ?string $regionType = null): \Weline\Framework\Database\Model
    {
        $model = $this->reset()
            ->where(self::schema_fields_COUNTRY_CODE, $countryCode);
        
        if ($regionType !== null) {
            $model->where(self::schema_fields_REGION_TYPE, $regionType);
        }
        
        $model->where(self::schema_fields_IS_ACTIVE, 1)
              ->order(self::schema_fields_SORT_ORDER, 'ASC')
              ->order(self::schema_fields_REGION_NAME, 'ASC');
        
        return $model->select()->fetch();
    }
    /**
     * 获取父级地区
     * 
     * @return Region|null
     */
    public function getParent(): ?Region
    {
        $parentId = $this->getData(self::schema_fields_PARENT_REGION_ID);
        if (!$parentId) {
            return null;
        }
        return $this->reset()->load($parentId);
    }
    /**
     * 获取完整地区路径（如：中国 > 北京 > 朝阳区）
     * 
     * @return string
     */
    public function getFullPath(): string
    {
        $path = [];
        $region = $this;
        
        while ($region && $region->getId()) {
            array_unshift($path, $region->getData(self::schema_fields_REGION_NAME));
            $region = $region->getParent();
        }
        
        return implode(' > ', $path);
    }
}
