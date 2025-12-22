<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归WeShop所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2024/01/15
 * 描述：库存源模型（仓库、供应商等）
 */

namespace WeShop\Inventory\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Source extends Model
{
    public const indexer = 'inventory_source';
    public const fields_ID = 'source_id';
    public const fields_CODE = 'code';
    public const fields_NAME = 'name';
    public const fields_DESCRIPTION = 'description';
    public const fields_COUNTRY = 'country';
    public const fields_REGION = 'region';
    public const fields_CITY = 'city';
    public const fields_ADDRESS = 'address';
    public const fields_POSTCODE = 'postcode';
    public const fields_PHONE = 'phone';
    public const fields_EMAIL = 'email';
    public const fields_CONTACT_NAME = 'contact_name';
    public const fields_IS_ENABLED = 'is_enabled';
    public const fields_PRIORITY = 'priority';
    public const fields_USE_DEFAULT_CARRIER = 'use_default_carrier';

    public array $_unit_primary_keys = ['source_id', 'code'];
    public array $_index_sort_keys = ['source_id', 'code'];

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // TODO: Implement upgrade() method.
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('库存源表（仓库/供应商）')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                11,
                'primary key auto_increment',
                '库存源ID'
            )
            ->addColumn(
                self::fields_CODE,
                TableInterface::column_type_VARCHAR,
                60,
                'not null unique',
                '库存源代码'
            )
            ->addColumn(
                self::fields_NAME,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '库存源名称'
            )
            ->addColumn(
                self::fields_DESCRIPTION,
                TableInterface::column_type_TEXT,
                0,
                '',
                '描述'
            )
            ->addColumn(
                self::fields_COUNTRY,
                TableInterface::column_type_VARCHAR,
                64,
                "default ''",
                '国家'
            )
            ->addColumn(
                self::fields_REGION,
                TableInterface::column_type_VARCHAR,
                64,
                "default ''",
                '地区/省份'
            )
            ->addColumn(
                self::fields_CITY,
                TableInterface::column_type_VARCHAR,
                64,
                "default ''",
                '城市'
            )
            ->addColumn(
                self::fields_ADDRESS,
                TableInterface::column_type_VARCHAR,
                500,
                "default ''",
                '详细地址'
            )
            ->addColumn(
                self::fields_POSTCODE,
                TableInterface::column_type_VARCHAR,
                20,
                "default ''",
                '邮编'
            )
            ->addColumn(
                self::fields_PHONE,
                TableInterface::column_type_VARCHAR,
                30,
                "default ''",
                '联系电话'
            )
            ->addColumn(
                self::fields_EMAIL,
                TableInterface::column_type_VARCHAR,
                128,
                "default ''",
                '联系邮箱'
            )
            ->addColumn(
                self::fields_CONTACT_NAME,
                TableInterface::column_type_VARCHAR,
                128,
                "default ''",
                '联系人姓名'
            )
            ->addColumn(
                self::fields_IS_ENABLED,
                TableInterface::column_type_INTEGER,
                1,
                'default 1',
                '是否启用'
            )
            ->addColumn(
                self::fields_PRIORITY,
                TableInterface::column_type_INTEGER,
                11,
                'default 0',
                '优先级（数字越小优先级越高）'
            )
            ->addColumn(
                self::fields_USE_DEFAULT_CARRIER,
                TableInterface::column_type_INTEGER,
                1,
                'default 1',
                '是否使用默认物流'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_is_enabled',
                self::fields_IS_ENABLED,
                '启用状态索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_priority',
                self::fields_PRIORITY,
                '优先级索引'
            )
            ->create();

        // 创建默认库存源
        $this->setData([
            self::fields_CODE => 'default',
            self::fields_NAME => '默认仓库',
            self::fields_DESCRIPTION => '系统默认库存源',
            self::fields_IS_ENABLED => 1,
            self::fields_PRIORITY => 0
        ])->save();
    }

    // Getters and Setters
    public function getCode(): string
    {
        return (string)$this->getData(self::fields_CODE);
    }

    public function setCode(string $code): static
    {
        return $this->setData(self::fields_CODE, $code);
    }

    public function getName(): string
    {
        return (string)$this->getData(self::fields_NAME);
    }

    public function setName(string $name): static
    {
        return $this->setData(self::fields_NAME, $name);
    }

    public function isEnabled(): bool
    {
        return (bool)$this->getData(self::fields_IS_ENABLED);
    }

    public function setIsEnabled(bool $isEnabled): static
    {
        return $this->setData(self::fields_IS_ENABLED, $isEnabled ? 1 : 0);
    }

    public function getPriority(): int
    {
        return (int)$this->getData(self::fields_PRIORITY);
    }

    public function setPriority(int $priority): static
    {
        return $this->setData(self::fields_PRIORITY, $priority);
    }

    /**
     * 获取启用的库存源列表（按优先级排序）
     * @return array
     */
    public function getEnabledSources(): array
    {
        return $this->where(self::fields_IS_ENABLED, 1)
            ->order(self::fields_PRIORITY, 'ASC')
            ->select()
            ->fetchArray();
    }
}

