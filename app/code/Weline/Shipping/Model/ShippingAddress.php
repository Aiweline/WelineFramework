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
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class ShippingAddress extends AbstractModel
{
    public const table = 'w_shipping_addresses';
    
    public const fields_ID = 'shipping_address_id';
    public const fields_NAME = 'name';
    public const fields_CONTACT_NAME = 'contact_name';
    public const fields_CONTACT_PHONE = 'contact_phone';
    public const fields_COUNTRY = 'country';
    public const fields_PROVINCE = 'province';
    public const fields_CITY = 'city';
    public const fields_DISTRICT = 'district';
    public const fields_STREET = 'street';
    public const fields_POSTAL_CODE = 'postal_code';
    public const fields_IS_DEFAULT = 'is_default';
    public const fields_IS_ENABLED = 'is_enabled';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['shipping_address_id'];

    /**
     * 索引排序键（用于提升查询效率）
     */
    public array $_index_sort_keys = ['shipping_address_id', 'is_default', 'is_enabled'];

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
            $setup->createTable('发货地址表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'primary key auto_increment',
                    '发货地址ID'
                )
                ->addColumn(
                    self::fields_NAME,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'not null',
                    '地址名称/标签'
                )
                ->addColumn(
                    self::fields_CONTACT_NAME,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'not null',
                    '联系人姓名'
                )
                ->addColumn(
                    self::fields_CONTACT_PHONE,
                    TableInterface::column_type_VARCHAR,
                    20,
                    'not null',
                    '联系电话'
                )
                ->addColumn(
                    self::fields_COUNTRY,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'default \'中国\'',
                    '国家'
                )
                ->addColumn(
                    self::fields_PROVINCE,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null',
                    '省份'
                )
                ->addColumn(
                    self::fields_CITY,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null',
                    '城市'
                )
                ->addColumn(
                    self::fields_DISTRICT,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'default null',
                    '区县'
                )
                ->addColumn(
                    self::fields_STREET,
                    TableInterface::column_type_VARCHAR,
                    200,
                    'not null',
                    '街道地址'
                )
                ->addColumn(
                    self::fields_POSTAL_CODE,
                    TableInterface::column_type_VARCHAR,
                    20,
                    'default null',
                    '邮政编码'
                )
                ->addColumn(
                    self::fields_IS_DEFAULT,
                    TableInterface::column_type_INTEGER,
                    1,
                    'default 0',
                    '是否默认地址：1-是，0-否'
                )
                ->addColumn(
                    self::fields_IS_ENABLED,
                    TableInterface::column_type_INTEGER,
                    1,
                    'default 1',
                    '是否启用：1-是，0-否'
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
                    TableInterface::index_type_KEY,
                    'idx_is_default',
                    self::fields_IS_DEFAULT,
                    '默认地址索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_is_enabled',
                    self::fields_IS_ENABLED,
                    '启用状态索引'
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
     * 获取完整地址
     */
    public function getFullAddress(): string
    {
        $parts = array_filter([
            $this->getData(self::fields_COUNTRY),
            $this->getData(self::fields_PROVINCE),
            $this->getData(self::fields_CITY),
            $this->getData(self::fields_DISTRICT),
            $this->getData(self::fields_STREET),
        ]);
        return implode('', $parts);
    }

    /**
     * 是否默认地址
     */
    public function isDefault(): bool
    {
        return (bool)$this->getData(self::fields_IS_DEFAULT);
    }

    /**
     * 是否启用
     */
    public function isEnabled(): bool
    {
        return (bool)$this->getData(self::fields_IS_ENABLED);
    }
}

