<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\GenerativeEngineOptimization\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * 平台账户模型
 * 
 * @package Weline_GenerativeEngineOptimization
 */
class PlatformAccount extends Model
{
    public const table = 'geo_platform_account';
    
    /**
     * Primary keys
     */
    public array $_unit_primary_keys = ['id'];
    
    /**
     * Index sort keys
     */
    public array $_index_sort_keys = ['id', 'platform_id', 'is_active', 'is_default'];
    
    /**
     * Field name constants
     */
    public const fields_ID = 'id';
    public const fields_PLATFORM_ID = 'platform_id';
    public const fields_ACCOUNT_NAME = 'account_name';
    public const fields_API_KEY = 'api_key';
    public const fields_API_SECRET = 'api_secret';
    public const fields_IS_DEFAULT = 'is_default';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_STATUS = 'status';
    public const fields_LAST_TEST_TIME = 'last_test_time';
    public const fields_LAST_TEST_MESSAGE = 'last_test_message';
    public const fields_CONFIG = 'config';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_FAILED = 'failed';

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
        if ($setup->tableExist() === false) {
            $setup->createTable('GEO平台账户表')
                ->addColumn(self::fields_ID, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'ID')
                ->addColumn(self::fields_PLATFORM_ID, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'not null', '关联平台ID')
                ->addColumn(self::fields_ACCOUNT_NAME, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 100, 'not null', '账户名称')
                ->addColumn(self::fields_API_KEY, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'not null', 'API密钥（加密存储）')
                ->addColumn(self::fields_API_SECRET, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'null', 'API密钥（如果需要，加密存储）')
                ->addColumn(self::fields_IS_DEFAULT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, 1, 'default 0', '是否为默认账户')
                ->addColumn(self::fields_IS_ACTIVE, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, 1, 'default 0', '是否激活')
                ->addColumn(self::fields_STATUS, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, 'default \'pending\'', '状态')
                ->addColumn(self::fields_LAST_TEST_TIME, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'null', '最后测试时间')
                ->addColumn(self::fields_LAST_TEST_MESSAGE, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'null', '测试消息')
                ->addColumn(self::fields_CONFIG, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'null', '额外配置JSON')
                ->addColumn(self::fields_CREATED_AT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '创建时间')
                ->addColumn(self::fields_UPDATED_AT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '更新时间')
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY, 'idx_platform_id', self::fields_PLATFORM_ID, '平台ID索引')
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY, 'idx_is_active', self::fields_IS_ACTIVE, '激活状态索引')
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY, 'idx_status', self::fields_STATUS, '状态索引')
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY, 'idx_platform_default', [self::fields_PLATFORM_ID, self::fields_IS_DEFAULT], '平台默认账户复合索引')
                ->create();
        }
    }

    /**
     * 获取配置数组
     * 
     * @return array
     */
    public function getConfigArray(): array
    {
        $config = $this->getData(self::fields_CONFIG);
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($config) ? $config : [];
    }

    /**
     * 设置配置数组
     * 
     * @param array $config
     * @return self
     */
    public function setConfigArray(array $config): self
    {
        $this->setData(self::fields_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
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
        return (int)$this->getData(self::fields_IS_ACTIVE) === 1;
    }

    /**
     * 检查是否可用
     * 
     * @return bool
     */
    public function isAvailable(): bool
    {
        return $this->isActive() && $this->getData(self::fields_STATUS) === self::STATUS_ACTIVE;
    }

    /**
     * 保存前处理
     * 
     * @return void
     */
    public function save_before(): void
    {
        $now = time();
        if (!$this->getId()) {
            $this->setData(self::fields_CREATED_AT, $now);
        }
        $this->setData(self::fields_UPDATED_AT, $now);
    }
}
