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
 * 订单状态翻译模型
 * 
 * 存储订单状态的多语言翻译
 */
class OrderStatusTranslation extends AbstractModel
{
    public const table = 'weline_order_status_translation';
    
    public const fields_ID = 'translation_id';
    public const fields_STATUS_CODE = 'status_code';
    public const fields_LOCALE = 'locale';
    public const fields_NAME = 'name';
    public const fields_DESCRIPTION = 'description';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['translation_id'];

    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['translation_id', 'status_code', 'locale'];

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
            $setup->createTable('订单状态翻译表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'primary key auto_increment',
                    '翻译ID'
                )
                ->addColumn(
                    self::fields_STATUS_CODE,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null',
                    '状态代码'
                )
                ->addColumn(
                    self::fields_LOCALE,
                    TableInterface::column_type_VARCHAR,
                    20,
                    'not null',
                    '语言代码（如：zh_Hans_CN, en_US）'
                )
                ->addColumn(
                    self::fields_NAME,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'not null',
                    '状态名称（翻译后）'
                )
                ->addColumn(
                    self::fields_DESCRIPTION,
                    TableInterface::column_type_TEXT,
                    null,
                    'default null',
                    '状态描述（翻译后）'
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
                    'idx_status_locale',
                    [self::fields_STATUS_CODE, self::fields_LOCALE],
                    '状态代码和语言代码唯一索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_status_code',
                    self::fields_STATUS_CODE,
                    '状态代码索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_locale',
                    self::fields_LOCALE,
                    '语言代码索引'
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
}

