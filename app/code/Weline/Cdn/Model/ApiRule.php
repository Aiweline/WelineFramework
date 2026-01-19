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
 * API规则模型
 * 
 * 存储从Api和Controller方法注释中收集到的CDN缓存规则
 * 规则格式对标Cloudflare Cache Rules
 * 
 * @package Weline_Cdn
 */
class ApiRule extends Model
{
    public const table = 'cdn_api_rule';
    
    /**
     * Primary key
     */
    public string $_primary_key = 'rule_id';
    
    /**
     * Primary keys
     */
    public array $_unit_primary_keys = ['rule_id'];
    
    /**
     * Field name constants
     */
    public const fields_RULE_ID = 'rule_id';
    public const fields_MODULE = 'module';
    public const fields_CLASS = 'class';
    public const fields_METHOD = 'method';
    public const fields_ROUTE = 'route';
    public const fields_EXPRESSION = 'expression';
    public const fields_ACTION = 'action';
    public const fields_DESCRIPTION = 'description';
    public const fields_ENABLED = 'enabled';
    public const fields_TRIGGER = 'trigger';
    public const fields_PRIORITY = 'priority';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

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
        return self::fields_RULE_ID;
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
            $setup->createTable('CDN API规则表')
                ->addColumn(
                    self::fields_RULE_ID,
                    \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                    null,
                    'primary key auto_increment',
                    '规则ID'
                )
                ->addColumn(
                    self::fields_MODULE,
                    \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                    100,
                    'not null',
                    '模块名称'
                )
                ->addColumn(
                    self::fields_CLASS,
                    \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                    500,
                    'not null',
                    '完整类名'
                )
                ->addColumn(
                    self::fields_METHOD,
                    \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                    100,
                    'not null',
                    '方法名'
                )
                ->addColumn(
                    self::fields_ROUTE,
                    \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                    500,
                    'not null',
                    '路由路径'
                )
                ->addColumn(
                    self::fields_EXPRESSION,
                    \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                    1000,
                    'not null',
                    '规则表达式（Cloudflare格式）'
                )
                ->addColumn(
                    self::fields_ACTION,
                    \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT,
                    null,
                    'not null',
                    '动作配置JSON（Cloudflare格式）'
                )
                ->addColumn(
                    self::fields_DESCRIPTION,
                    \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                    500,
                    'null',
                    '规则描述'
                )
                ->addColumn(
                    self::fields_ENABLED,
                    \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TINYINT,
                    1,
                    'not null default 1',
                    '是否启用'
                )
                ->addColumn(
                    self::fields_TRIGGER,
                    \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                    20,
                    'not null default "cron"',
                    '触发方式：cron（定时）或realtime（实时）'
                )
                ->addColumn(
                    self::fields_PRIORITY,
                    \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                    null,
                    'null',
                    '优先级'
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DATETIME,
                    null,
                    'not null default CURRENT_TIMESTAMP',
                    '创建时间'
                )
                ->addColumn(
                    self::fields_UPDATED_AT,
                    \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DATETIME,
                    null,
                    'not null default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP',
                    '更新时间'
                )
                ->addIndex(
                    \Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_UNIQUE,
                    'idx_module_class_method',
                    [self::fields_MODULE, self::fields_CLASS, self::fields_METHOD],
                    '模块、类、方法唯一索引'
                )
                ->addIndex(
                    \Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY,
                    'idx_route',
                    [self::fields_ROUTE],
                    '路由索引'
                )
                ->addIndex(
                    \Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY,
                    'idx_trigger',
                    [self::fields_TRIGGER],
                    '触发方式索引'
                )
                ->addIndex(
                    \Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY,
                    'idx_enabled',
                    [self::fields_ENABLED],
                    '启用状态索引'
                )
                ->create();
        }
    }

    /**
     * 获取动作配置数组
     * 
     * @return array
     */
    public function getActionArray(): array
    {
        $action = $this->getData(self::fields_ACTION);
        if (empty($action)) {
            return [];
        }
        
        $decoded = json_decode($action, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 设置动作配置数组
     * 
     * @param array $action
     * @return $this
     */
    public function setActionArray(array $action): self
    {
        $this->setData(self::fields_ACTION, json_encode($action, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * 转换为Cloudflare规则格式
     * 
     * @return array
     */
    public function toCloudflareRule(): array
    {
        return [
            'expression' => $this->getData(self::fields_EXPRESSION),
            'action' => $this->getActionArray(),
            'description' => $this->getData(self::fields_DESCRIPTION) ?? '',
            'enabled' => (bool)$this->getData(self::fields_ENABLED)
        ];
    }
}
