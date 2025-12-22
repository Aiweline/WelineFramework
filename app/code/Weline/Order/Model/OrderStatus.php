<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Order\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 订单状态模型
 * 
 * 管理订单状态定义和翻译
 */
class OrderStatus extends AbstractModel
{
    public const table = 'weline_order_status';
    
    public const fields_ID = 'status_id';
    public const fields_CODE = 'code';
    public const fields_NAME = 'name';
    public const fields_DESCRIPTION = 'description';
    public const fields_COLOR = 'color';
    public const fields_ICON = 'icon';
    public const fields_IS_SYSTEM = 'is_system';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_SORT_ORDER = 'sort_order';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['status_id'];

    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['status_id', 'code', 'is_active', 'sort_order'];

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
            $setup->createTable('订单状态表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'primary key auto_increment',
                    '状态ID'
                )
                ->addColumn(
                    self::fields_CODE,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null unique',
                    '状态代码（唯一）'
                )
                ->addColumn(
                    self::fields_NAME,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'not null',
                    '状态名称（默认语言）'
                )
                ->addColumn(
                    self::fields_DESCRIPTION,
                    TableInterface::column_type_TEXT,
                    null,
                    'default null',
                    '状态描述'
                )
                ->addColumn(
                    self::fields_COLOR,
                    TableInterface::column_type_VARCHAR,
                    20,
                    'default \'secondary\'',
                    '状态颜色（用于UI显示）'
                )
                ->addColumn(
                    self::fields_ICON,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'default null',
                    '状态图标'
                )
                ->addColumn(
                    self::fields_IS_SYSTEM,
                    TableInterface::column_type_INTEGER,
                    1,
                    'default 0',
                    '是否系统状态：1-是，0-否'
                )
                ->addColumn(
                    self::fields_IS_ACTIVE,
                    TableInterface::column_type_INTEGER,
                    1,
                    'default 1',
                    '是否启用：1-是，0-否'
                )
                ->addColumn(
                    self::fields_SORT_ORDER,
                    TableInterface::column_type_INTEGER,
                    null,
                    'default 0',
                    '排序'
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_DATETIME,
                    null,
                    'default CURRENT_TIMESTAMP',
                    '创建时间'
                )
                ->addColumn(
                    self::fields_UPDATED_AT,
                    TableInterface::column_type_DATETIME,
                    null,
                    'default CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                    '更新时间'
                )
                ->addIndex(
                    TableInterface::index_type_UNIQUE,
                    'idx_code',
                    self::fields_CODE,
                    '状态代码唯一索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_is_active',
                    self::fields_IS_ACTIVE,
                    '启用状态索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_sort_order',
                    self::fields_SORT_ORDER,
                    '排序索引'
                )
                ->create();
        }
    }

    /**
     * 设置表结构（开发模式）
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * 升级表结构
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级逻辑
    }

    /**
     * 是否系统状态
     * 
     * @return bool
     */
    public function isSystem(): bool
    {
        return (bool)$this->getData(self::fields_IS_SYSTEM);
    }

    /**
     * 是否启用
     * 
     * @return bool
     */
    public function isActive(): bool
    {
        return (bool)$this->getData(self::fields_IS_ACTIVE);
    }

    /**
     * 获取状态翻译名称
     * 
     * 状态名称存储在name字段，通过翻译系统进行翻译
     * 翻译key格式：order_status_{code}
     * 
     * @return string
     */
    public function getTranslatedName(): string
    {
        $code = $this->getData(self::fields_CODE);
        $name = $this->getData(self::fields_NAME);
        
        // 使用翻译系统，翻译key为 order_status_{code}
        $translationKey = 'order_status_' . $code;
        $translated = __($translationKey);
        
        // 如果翻译不存在，返回默认名称
        if ($translated === $translationKey) {
            return $name;
        }
        
        return $translated;
    }
}

