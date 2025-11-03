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
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * CDN账户模型
 * 
 * @package Weline_Cdn
 */
class Account extends Model
{
    public const table = 'cdn_account';
    
    /**
     * Primary keys
     */
    public array $_unit_primary_keys = ['account_id'];
    
    /**
     * Field name constants
     */
    public const fields_ACCOUNT_ID = 'account_id';
    public const fields_ADAPTER = 'adapter';
    public const fields_NAME = 'name';
    public const fields_DESCRIPTION = 'description';
    public const fields_CREDENTIALS = 'credentials';
    public const fields_IS_DEFAULT = 'is_default';
    public const fields_STATUS = 'status';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    /**
     * Status constants
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    /**
     * Initialize model
     */
    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    /**
     * 获取主键字段名
     * 
     * @return string
     */
    public function getIdFieldName(): string
    {
        return self::fields_ACCOUNT_ID;
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
        if ($setup->tableExist() === false) {
            $setup->createTable('CDN账户表')
                ->addColumn(self::fields_ACCOUNT_ID, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'primary key auto_increment', '账户ID')
                ->addColumn(self::fields_ADAPTER, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 50, 'not null', '适配器代码')
                ->addColumn(self::fields_NAME, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 128, 'not null', '账户名称')
                ->addColumn(self::fields_DESCRIPTION, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'null', '账户描述')
                ->addColumn(self::fields_CREDENTIALS, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'not null', '凭据JSON')
                ->addColumn(self::fields_IS_DEFAULT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, 1, 'default 0', '是否默认账户')
                ->addColumn(self::fields_STATUS, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, 'default \'active\'', '状态')
                ->addColumn(self::fields_CREATED_AT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '创建时间')
                ->addColumn(self::fields_UPDATED_AT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '更新时间')
                ->addIndex(self::fields_ADAPTER, '', 'KEY', 'idx_adapter')
                ->addIndex(self::fields_STATUS, '', 'KEY', 'idx_status')
                ->addIndex(self::fields_ADAPTER . ',' . self::fields_IS_DEFAULT, '', 'KEY', 'idx_adapter_default')
                ->create();
        }
    }

    /**
     * 获取凭据数组
     * 
     * @return array
     */
    public function getCredentialsArray(): array
    {
        $credentials = $this->getData(self::fields_CREDENTIALS);
        if (is_string($credentials)) {
            $decoded = json_decode($credentials, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($credentials) ? $credentials : [];
    }

    /**
     * 设置凭据数组
     * 
     * @param array $credentials
     * @return self
     */
    public function setCredentialsArray(array $credentials): self
    {
        $this->setData(self::fields_CREDENTIALS, json_encode($credentials, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * 检查是否默认账户
     * 
     * @return bool
     */
    public function isDefault(): bool
    {
        return (int)$this->getData(self::fields_IS_DEFAULT) === 1;
    }

    /**
     * 检查是否激活
     * 
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->getData(self::fields_STATUS) === self::STATUS_ACTIVE;
    }

    /**
     * 保存前处理
     * 
     * @return self
     */
    public function beforeSave(): self
    {
        $now = time();
        if (!$this->getData(self::fields_CREATED_AT)) {
            $this->setData(self::fields_CREATED_AT, $now);
        }
        $this->setData(self::fields_UPDATED_AT, $now);
        return parent::beforeSave();
    }
}

