<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\AutoLeadAgent\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 搜索引擎映射模型
 * 
 * 存储地区-语言-搜索引擎映射关系
 */
class SearchEngineMapping extends AbstractModel
{
    public const table = 'weline_auto_lead_agent_search_engine_mapping';
    
    public const fields_ID = 'mapping_id';
    public const fields_REGION = 'region';
    public const fields_LANGUAGE = 'language';
    public const fields_SEARCH_ENGINES = 'search_engines';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_SORT_ORDER = 'sort_order';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['mapping_id'];

    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['mapping_id', 'region', 'language', 'sort_order'];

    /**
     * 初始化模型
     */
    public function _init(): void
    {
        $this->_primary_key = self::fields_ID;
    }

    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable(__('搜索引擎映射表'))
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'primary key auto_increment',
                    __('映射ID')
                )
                ->addColumn(
                    self::fields_REGION,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'not null',
                    __('地区名称（标准化）')
                )
                ->addColumn(
                    self::fields_LANGUAGE,
                    TableInterface::column_type_VARCHAR,
                    20,
                    'not null',
                    __('语言代码（标准化）')
                )
                ->addColumn(
                    self::fields_SEARCH_ENGINES,
                    TableInterface::column_type_TEXT,
                    null,
                    'null',
                    __('搜索引擎列表（JSON格式）')
                )
                ->addColumn(
                    self::fields_IS_ACTIVE,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'default 1',
                    __('是否启用')
                )
                ->addColumn(
                    self::fields_SORT_ORDER,
                    TableInterface::column_type_INTEGER,
                    null,
                    'default 0',
                    __('排序顺序')
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_DATETIME,
                    null,
                    'default CURRENT_TIMESTAMP',
                    __('创建时间')
                )
                ->addColumn(
                    self::fields_UPDATED_AT,
                    TableInterface::column_type_DATETIME,
                    null,
                    'default CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                    __('更新时间')
                )
                ->addIndex(
                    TableInterface::index_type_UNIQUE,
                    'UNIQUE_REGION_LANGUAGE',
                    [self::fields_REGION, self::fields_LANGUAGE],
                    __('地区语言唯一索引')
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'IDX_REGION',
                    [self::fields_REGION],
                    __('地区索引')
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'IDX_LANGUAGE',
                    [self::fields_LANGUAGE],
                    __('语言索引')
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'IDX_IS_ACTIVE',
                    [self::fields_IS_ACTIVE],
                    __('启用状态索引')
                )
                ->create();
        }
    }

    /**
     * 开发模式设置（用于测试）
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * 获取搜索引擎列表（JSON解码）
     * 
     * @return array
     */
    public function getSearchEnginesArray(): array
    {
        $engines = $this->getData(self::fields_SEARCH_ENGINES);
        if (empty($engines)) {
            return [];
        }
        
        if (is_string($engines)) {
            $decoded = json_decode($engines, true);
            return is_array($decoded) ? $decoded : [];
        }
        
        return is_array($engines) ? $engines : [];
    }

    /**
     * 设置搜索引擎列表（JSON编码）
     * 
     * @param array $engines
     * @return $this
     */
    public function setSearchEnginesArray(array $engines): static
    {
        $this->setData(self::fields_SEARCH_ENGINES, json_encode($engines, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * 验证数据
     * 
     * @return bool
     */
    public function validate(): bool
    {
        if (empty($this->getData(self::fields_REGION))) {
            $this->setError(__('地区名称不能为空'));
            return false;
        }
        
        if (empty($this->getData(self::fields_LANGUAGE))) {
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

    /**
     * 保存前处理
     * 
     * @return $this
     */
    public function beforeSave(): static
    {
        // 确保搜索引擎数据是JSON格式
        $engines = $this->getData(self::fields_SEARCH_ENGINES);
        if (is_array($engines)) {
            $this->setSearchEnginesArray($engines);
        }
        
        return $this;
    }
}

