<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2025/10/11
 */

namespace Weline\Ai\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 场景适配器配置模型
 * 
 * 功能：
 * - 管理场景适配器配置
 * - 支持多租户配置隔离
 * - 提供配置版本管理
 */
class AiScenarioAdapterConfig extends \Weline\Framework\Database\Model
{
    public const table = 'ai_scenario_adapter_config';
    public const fields_ID = 'id';
    public const fields_ADAPTER_ID = 'adapter_id';
    public const fields_TENANT_ID = 'tenant_id';
    public const fields_CONFIG_KEY = 'config_key';
    public const fields_CONFIG_VALUE = 'config_value'; // JSON
    public const fields_CONFIG_TYPE = 'config_type'; // string, int, bool, json
    public const fields_IS_ENCRYPTED = 'is_encrypted';
    public const fields_IS_OVERRIDABLE = 'is_overridable';
    public const fields_CREATED_TIME = 'created_time';
    public const fields_UPDATED_TIME = 'updated_time';

    /**
     * 配置类型常量
     */
    public const CONFIG_TYPE_STRING = 'string';
    public const CONFIG_TYPE_INT = 'int';
    public const CONFIG_TYPE_BOOL = 'bool';
    public const CONFIG_TYPE_JSON = 'json';

    /**
     * 设置模型
     * 
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * 升级模型
     * 
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // TODO: 实现升级逻辑
    }

    /**
     * 安装数据表
     * 
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', '主键ID')
                ->addColumn(self::fields_ADAPTER_ID, TableInterface::column_type_INTEGER, null, 'not null', '适配器ID')
                ->addColumn(self::fields_TENANT_ID, TableInterface::column_type_INTEGER, null, 'null', '租户ID')
                ->addColumn(self::fields_CONFIG_KEY, TableInterface::column_type_VARCHAR, 255, 'not null', '配置键')
                ->addColumn(self::fields_CONFIG_VALUE, TableInterface::column_type_TEXT, null, 'null', '配置值')
                ->addColumn(self::fields_CONFIG_TYPE, TableInterface::column_type_VARCHAR, 50, 'default "string"', '配置类型')
                ->addColumn(self::fields_IS_ENCRYPTED, TableInterface::column_type_SMALLINT, 1, 'default 0', '是否加密')
                ->addColumn(self::fields_IS_OVERRIDABLE, TableInterface::column_type_SMALLINT, 1, 'default 1', '是否可覆盖')
                ->addColumn(self::fields_CREATED_TIME, TableInterface::column_type_INTEGER, null, 'not null', '创建时间')
                ->addColumn(self::fields_UPDATED_TIME, TableInterface::column_type_INTEGER, null, 'not null', '更新时间')
                ->addIndex(TableInterface::index_type_UNIQUE, 'idx_unique_config', [self::fields_ADAPTER_ID, self::fields_TENANT_ID, self::fields_CONFIG_KEY], '唯一配置索引')
                ->create();
        }
    }

    /**
     * 获取配置值（按类型解析）
     * 
     * @return mixed
     */
    public function getConfigValue()
    {
        $value = $this->getData(self::fields_CONFIG_VALUE);
        $type = $this->getData(self::fields_CONFIG_TYPE);

        switch ($type) {
            case self::CONFIG_TYPE_INT:
                return (int)$value;
            case self::CONFIG_TYPE_BOOL:
                return (bool)$value;
            case self::CONFIG_TYPE_JSON:
                return json_decode($value, true);
            default:
                return $value;
        }
    }

    /**
     * 保存前处理
     * 
     * @return $this
     */
    public function beforeSave(): self
    {
        parent::beforeSave();
        
        $currentTime = time();
        
        if (!$this->getId()) {
            $this->setData(self::fields_CREATED_TIME, $currentTime);
        }
        
        $this->setData(self::fields_UPDATED_TIME, $currentTime);
        
        return $this;
    }
}

