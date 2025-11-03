<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;

/**
 * CDN账户模型
 */
class Account extends Model
{
    // 字段常量
    public const fields_ID = 'account_id';
    public const fields_ADAPTER = 'adapter';
    public const fields_NAME = 'name';
    public const fields_DESCRIPTION = 'description';
    public const fields_CREDENTIALS = 'credentials';
    public const fields_IS_DEFAULT = 'is_default';
    public const fields_STATUS = 'status';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    // 状态常量
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    /**
     * @inheritDoc
     */
    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    /**
     * 获取主键字段名
     */
    public function getIdFieldName(): string
    {
        return self::fields_ID;
    }

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
        // 升级逻辑
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('CDN账户表')
            ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 11, 'primary key auto_increment', '账户ID')
            ->addColumn(self::fields_ADAPTER, TableInterface::column_type_VARCHAR, 50, 'not null', '适配器代码')
            ->addColumn(self::fields_NAME, TableInterface::column_type_VARCHAR, 128, 'not null', '账户名称')
            ->addColumn(self::fields_DESCRIPTION, TableInterface::column_type_TEXT, null, '', '账户描述')
            ->addColumn(self::fields_CREDENTIALS, TableInterface::column_type_TEXT, null, 'not null', '凭据JSON')
            ->addColumn(self::fields_IS_DEFAULT, TableInterface::column_type_INTEGER, 1, 'default 0', '是否默认账户')
            ->addColumn(self::fields_STATUS, TableInterface::column_type_VARCHAR, 20, "default 'active'", '状态')
            ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_INTEGER, null, 'default 0', '创建时间')
            ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_INTEGER, null, 'default 0', '更新时间')
            ->addIndex(self::fields_ADAPTER, '', 'INDEX', 'idx_adapter')
            ->addIndex([self::fields_ADAPTER, self::fields_IS_DEFAULT], '', 'INDEX', 'idx_adapter_default')
            ->create();
    }

    /**
     * 获取凭据（解析JSON）
     */
    public function getCredentials(): array
    {
        $credentials = $this->getData(self::fields_CREDENTIALS);
        if (empty($credentials)) {
            return [];
        }
        
        if (is_string($credentials)) {
            $decoded = json_decode($credentials, true);
            return is_array($decoded) ? $decoded : [];
        }
        
        return is_array($credentials) ? $credentials : [];
    }

    /**
     * 设置凭据（自动编码JSON）
     */
    public function setCredentials(array $credentials): self
    {
        $this->setData(self::fields_CREDENTIALS, json_encode($credentials, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * 检查是否为默认账户
     */
    public function isDefault(): bool
    {
        return (bool)$this->getData(self::fields_IS_DEFAULT);
    }

    /**
     * 检查是否激活
     */
    public function isActive(): bool
    {
        return $this->getData(self::fields_STATUS) === self::STATUS_ACTIVE;
    }
}
