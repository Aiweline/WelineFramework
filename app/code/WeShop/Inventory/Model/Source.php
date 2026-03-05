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

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '库存源表（仓库/供应商）')]
#[Index(name: 'idx_is_enabled', columns: ['is_enabled'], comment: '启用状态索引')]
#[Index(name: 'idx_priority', columns: ['priority'], comment: '优先级索引')]
class Source extends Model
{
    public const schema_table = 'weshop_inventory_source';
    public const schema_primary_key = 'source_id';
    public const indexer = 'inventory_source_indexer';
    public array $_unit_primary_keys = ['source_id'];
    public array $_index_sort_keys = ['source_id', 'code', 'is_enabled', 'priority'];

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '库存源ID')]
    public const schema_fields_ID = 'source_id';
    #[Col(type: 'varchar', length: 60, nullable: false, comment: '库存源代码')]
    public const schema_fields_CODE = 'code';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '库存源名称')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'text', nullable: true, comment: '描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: '国家')]
    public const schema_fields_COUNTRY = 'country';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: '地区/省份')]
    public const schema_fields_REGION = 'region';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: '城市')]
    public const schema_fields_CITY = 'city';
    #[Col(type: 'varchar', length: 500, nullable: true, comment: '详细地址')]
    public const schema_fields_ADDRESS = 'address';
    #[Col(type: 'varchar', length: 20, nullable: true, comment: '邮编')]
    public const schema_fields_POSTCODE = 'postcode';
    #[Col(type: 'varchar', length: 30, nullable: true, comment: '联系电话')]
    public const schema_fields_PHONE = 'phone';
    #[Col(type: 'varchar', length: 128, nullable: true, comment: '联系邮箱')]
    public const schema_fields_EMAIL = 'email';
    #[Col(type: 'varchar', length: 128, nullable: true, comment: '联系人姓名')]
    public const schema_fields_CONTACT_NAME = 'contact_name';
    #[Col(type: 'int', nullable: true, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ENABLED = 'is_enabled';
    #[Col(type: 'int', nullable: true, default: 0, comment: '优先级（数字越小优先级越高）')]
    public const schema_fields_PRIORITY = 'priority';
    #[Col(type: 'int', nullable: true, default: 1, comment: '是否使用默认物流')]
    public const schema_fields_USE_DEFAULT_CARRIER = 'use_default_carrier';

    public function getCode(): string
    {
        return (string)$this->getData(self::schema_fields_CODE);
    }

    public function setCode(string $code): static
    {
        return $this->setData(self::schema_fields_CODE, $code);
    }

    public function getName(): string
    {
        return (string)$this->getData(self::schema_fields_NAME);
    }

    public function setName(string $name): static
    {
        return $this->setData(self::schema_fields_NAME, $name);
    }

    public function isEnabled(): bool
    {
        return (bool)$this->getData(self::schema_fields_IS_ENABLED);
    }

    public function setIsEnabled(bool $isEnabled): static
    {
        return $this->setData(self::schema_fields_IS_ENABLED, $isEnabled ? 1 : 0);
    }

    public function getPriority(): int
    {
        return (int)$this->getData(self::schema_fields_PRIORITY);
    }

    public function setPriority(int $priority): static
    {
        return $this->setData(self::schema_fields_PRIORITY, $priority);
    }

    /**
     * 获取启用的库存源列表（按优先级排序）
     */
    public function getEnabledSources(): array
    {
        return $this->where(self::schema_fields_IS_ENABLED, 1)
            ->order(self::schema_fields_PRIORITY, 'ASC')
            ->select()
            ->fetchArray();
    }
}

