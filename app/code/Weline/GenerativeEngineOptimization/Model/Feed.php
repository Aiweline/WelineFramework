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
 * Feed配置模型
 * 
 * @package Weline_GenerativeEngineOptimization
 */
class Feed extends Model
{
    public const table = 'geo_feed';
    
    /**
     * Primary keys
     */
    public array $_unit_primary_keys = ['id'];
    
    /**
     * Index sort keys
     */
    public array $_index_sort_keys = ['id', 'feed_type', 'is_enabled', 'is_auto_push'];
    
    /**
     * Field name constants
     */
    public const fields_ID = 'id';
    public const fields_FEED_NAME = 'feed_name';
    public const fields_FEED_TYPE = 'feed_type';
    public const fields_SOURCE_TYPE = 'source_type';
    public const fields_SOURCE_CONFIG = 'source_config';
    public const fields_FEED_URL = 'feed_url';
    public const fields_UPDATE_FREQUENCY = 'update_frequency';
    public const fields_IS_AUTO_PUSH = 'is_auto_push';
    public const fields_IS_ENABLED = 'is_enabled';
    public const fields_LAST_GENERATED_AT = 'last_generated_at';
    public const fields_LAST_PUSHED_AT = 'last_pushed_at';
    public const fields_CONFIG = 'config';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    /**
     * Feed types
     */
    public const TYPE_CONTENT = 'content';
    public const TYPE_PRODUCT = 'product';
    public const TYPE_ARTICLE = 'article';
    public const TYPE_CUSTOM = 'custom';

    /**
     * Source types
     */
    public const SOURCE_DATABASE = 'database';
    public const SOURCE_API = 'api';
    public const SOURCE_CUSTOM = 'custom';

    /**
     * Update frequencies
     */
    public const FREQUENCY_REALTIME = 'realtime';
    public const FREQUENCY_HOURLY = 'hourly';
    public const FREQUENCY_DAILY = 'daily';
    public const FREQUENCY_WEEKLY = 'weekly';

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
            $setup->createTable('GEO Feed配置表')
                ->addColumn(self::fields_ID, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'ID')
                ->addColumn(self::fields_FEED_NAME, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 100, 'not null', 'Feed名称')
                ->addColumn(self::fields_FEED_TYPE, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 50, 'default \'content\'', 'Feed类型')
                ->addColumn(self::fields_SOURCE_TYPE, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 50, 'default \'database\'', '数据源类型')
                ->addColumn(self::fields_SOURCE_CONFIG, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'null', '数据源配置JSON')
                ->addColumn(self::fields_FEED_URL, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 255, 'null', 'Feed访问URL')
                ->addColumn(self::fields_UPDATE_FREQUENCY, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 50, 'default \'daily\'', '更新频率')
                ->addColumn(self::fields_IS_AUTO_PUSH, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, 1, 'default 1', '是否自动推送')
                ->addColumn(self::fields_IS_ENABLED, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, 1, 'default 1', '是否启用')
                ->addColumn(self::fields_LAST_GENERATED_AT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'null', '最后生成时间')
                ->addColumn(self::fields_LAST_PUSHED_AT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'null', '最后推送时间')
                ->addColumn(self::fields_CONFIG, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'null', 'Feed配置JSON')
                ->addColumn(self::fields_CREATED_AT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '创建时间')
                ->addColumn(self::fields_UPDATED_AT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '更新时间')
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY, 'idx_feed_type', self::fields_FEED_TYPE, 'Feed类型索引')
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY, 'idx_is_enabled', self::fields_IS_ENABLED, '启用状态索引')
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY, 'idx_is_auto_push', self::fields_IS_AUTO_PUSH, '自动推送索引')
                ->create();
        }
    }

    /**
     * 获取数据源配置数组
     * 
     * @return array
     */
    public function getSourceConfigArray(): array
    {
        $config = $this->getData(self::fields_SOURCE_CONFIG);
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($config) ? $config : [];
    }

    /**
     * 设置数据源配置数组
     * 
     * @param array $config
     * @return self
     */
    public function setSourceConfigArray(array $config): self
    {
        $this->setData(self::fields_SOURCE_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
        return $this;
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
     * 检查是否启用
     * 
     * @return bool
     */
    public function isEnabled(): bool
    {
        return (int)$this->getData(self::fields_IS_ENABLED) === 1;
    }

    /**
     * 检查是否自动推送
     * 
     * @return bool
     */
    public function isAutoPush(): bool
    {
        return (int)$this->getData(self::fields_IS_AUTO_PUSH) === 1;
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
