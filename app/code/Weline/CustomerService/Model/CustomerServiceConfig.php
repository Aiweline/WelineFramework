<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\CustomerService\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class CustomerServiceConfig extends Model
{
    public const table = 'customer_service_config';
    public const fields_ID = 'config_id';
    public const fields_key = 'key';
    public const fields_value = 'value';
    public const fields_created_at = 'created_at';
    public const fields_updated_at = 'updated_at';

    public function _init(): void
    {
        $this->_primary_key = self::fields_ID;
        $this->_table = self::table;
    }

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级逻辑
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('客服系统配置表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'primary key auto_increment', '配置ID')
                ->addColumn(self::fields_key, TableInterface::column_type_VARCHAR, 255, 'not null', '配置键')
                ->addColumn(self::fields_value, TableInterface::column_type_TEXT, 0, '', '配置值')
                ->addColumn(self::fields_created_at, TableInterface::column_type_DATETIME, 0, '', '创建时间')
                ->addColumn(self::fields_updated_at, TableInterface::column_type_DATETIME, 0, '', '更新时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_key', self::fields_key, '配置键索引')
                ->create();
        }
    }

    public function getKey(): string
    {
        return (string)$this->getData(self::fields_key);
    }

    public function setKey(string $key): static
    {
        return $this->setData(self::fields_key, $key);
    }

    public function getValue(): string
    {
        return (string)$this->getData(self::fields_value);
    }

    public function setValue(string $value): static
    {
        return $this->setData(self::fields_value, $value);
    }

    /**
     * 按键名获取配置值
     * @param string $key 配置键
     * @param string $default 默认值
     * @return string
     */
    public function getConfigValue(string $key, string $default = ''): string
    {
        $this->reset()
            ->where(self::fields_key, $key)
            ->find()
            ->fetch();

        return $this->getId() ? $this->getValue() : $default;
    }

    /**
     * 设置配置项（存在则更新，不存在则新增）
     * @param string $key 配置键
     * @param string $value 配置值
     */
    public function setConfigValue(string $key, string $value): void
    {
        $this->reset()
            ->where(self::fields_key, $key)
            ->find()
            ->fetch();

        if ($this->getId()) {
            $this->setValue($value)
                ->setData(self::fields_updated_at, date('Y-m-d H:i:s'))
                ->save();
        } else {
            $this->reset()
                ->setKey($key)
                ->setValue($value)
                ->setData(self::fields_created_at, date('Y-m-d H:i:s'))
                ->save();
        }
    }
}

