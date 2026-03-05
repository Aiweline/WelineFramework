<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\AutoLeadAgent\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * 搜索引擎映射模型
 * 存储地区-语言-搜索引擎映射关系
 */
#[Table(comment: '搜索引擎映射表')]
#[Index(name: 'UNIQUE_REGION_LANGUAGE', columns: ['region', 'language'], type: 'UNIQUE', comment: '地区语言唯一索引')]
#[Index(name: 'IDX_REGION', columns: ['region'], comment: '地区索引')]
#[Index(name: 'IDX_LANGUAGE', columns: ['language'], comment: '语言索引')]
#[Index(name: 'IDX_IS_ACTIVE', columns: ['is_active'], comment: '启用状态索引')]
class SearchEngineMapping extends Model
{
    public const schema_table = 'weline_auto_lead_agent_search_engine_mapping';
    public const schema_primary_key = 'mapping_id';
    public array $_unit_primary_keys = ['mapping_id'];
    public array $_index_sort_keys = ['mapping_id', 'region', 'language', 'sort_order'];

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '映射ID')]
    public const schema_fields_ID = 'mapping_id';
    #[Col(type: 'varchar', length: 100, nullable: false, comment: '地区名称（标准化）')]
    public const schema_fields_REGION = 'region';
    #[Col(type: 'varchar', length: 20, nullable: false, comment: '语言代码（标准化）')]
    public const schema_fields_LANGUAGE = 'language';
    #[Col(type: 'text', nullable: true, comment: '搜索引擎列表（JSON格式）')]
    public const schema_fields_SEARCH_ENGINES = 'search_engines';
    #[Col(type: 'smallint', length: 1, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col(type: 'int', default: 0, comment: '排序顺序')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function _init(): void
    {
        $this->_primary_key = self::schema_fields_ID;
    }

    public function getSearchEnginesArray(): array
    {
        $engines = $this->getData(self::schema_fields_SEARCH_ENGINES);
        if (empty($engines)) {
            return [];
        }
        if (is_string($engines)) {
            $decoded = json_decode($engines, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($engines) ? $engines : [];
    }

    public function setSearchEnginesArray(array $engines): static
    {
        $this->setData(self::schema_fields_SEARCH_ENGINES, json_encode($engines, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    public function validate(): bool
    {
        if (empty($this->getData(self::schema_fields_REGION))) {
            $this->setError(__('地区名称不能为空'));
            return false;
        }
        if (empty($this->getData(self::schema_fields_LANGUAGE))) {
            $this->setError(__('语言代码不能为空'));
            return false;
        }
        $engines = $this->getSearchEnginesArray();
        if (empty($engines)) {
            $this->setError(__('至少需要选择一个搜索引擎'));
            return false;
        }
        return true;
    }

    public function beforeSave(): static
    {
        $engines = $this->getData(self::schema_fields_SEARCH_ENGINES);
        if (is_array($engines)) {
            $this->setSearchEnginesArray($engines);
        }
        return $this;
    }
}
