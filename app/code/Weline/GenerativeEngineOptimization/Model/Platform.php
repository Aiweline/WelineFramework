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
 * 平台配置模型
 * 
 * @package Weline_GenerativeEngineOptimization
 */
class Platform extends Model
{
    public const table = 'geo_platform';
    
    /**
     * Primary keys
     */
    public array $_unit_primary_keys = ['id'];
    
    /**
     * Index sort keys
     */
    public array $_index_sort_keys = ['id', 'platform_code', 'is_enabled'];
    
    /**
     * Field name constants
     */
    public const fields_ID = 'id';
    public const fields_PLATFORM_CODE = 'platform_code';
    public const fields_PLATFORM_NAME = 'platform_name';
    public const fields_API_ENDPOINT = 'api_endpoint';
    public const fields_FEED_FORMAT = 'feed_format';
    public const fields_IS_ENABLED = 'is_enabled';
    public const fields_CONFIG = 'config';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    /**
     * Platform codes
     */
    public const PLATFORM_GOOGLE_SGE = 'google_sge';
    public const PLATFORM_PERPLEXITY = 'perplexity';
    public const PLATFORM_BING_CHAT = 'bing_chat';
    public const PLATFORM_OPENAI = 'openai';
    public const PLATFORM_CLAUDE = 'claude';
    // 新增国际平台
    public const PLATFORM_YOU = 'you';
    public const PLATFORM_BRAVE_SEARCH = 'brave_search';
    public const PLATFORM_DUCKDUCKGO = 'duckduckgo';
    public const PLATFORM_BAIDU_AI = 'baidu_ai';
    public const PLATFORM_COHERE = 'cohere';

    /**
     * Feed formats
     */
    public const FORMAT_JSON_FEED = 'json_feed';
    public const FORMAT_XML = 'xml';
    public const FORMAT_RSS = 'rss';

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
            $setup->createTable('GEO平台配置表')
                ->addColumn(self::fields_ID, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'ID')
                ->addColumn(self::fields_PLATFORM_CODE, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 50, 'not null', '平台代码')
                ->addColumn(self::fields_PLATFORM_NAME, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 100, 'not null', '平台名称')
                ->addColumn(self::fields_API_ENDPOINT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 255, 'null', 'API端点URL')
                ->addColumn(self::fields_FEED_FORMAT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 50, 'default \'json_feed\'', 'Feed格式')
                ->addColumn(self::fields_IS_ENABLED, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, 1, 'default 1', '是否启用')
                ->addColumn(self::fields_CONFIG, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'null', '平台特定配置JSON')
                ->addColumn(self::fields_CREATED_AT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '创建时间')
                ->addColumn(self::fields_UPDATED_AT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '更新时间')
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_UNIQUE, 'idx_platform_code', self::fields_PLATFORM_CODE, '平台代码唯一索引')
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY, 'idx_is_enabled', self::fields_IS_ENABLED, '启用状态索引')
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
     * 检查是否启用
     * 
     * @return bool
     */
    public function isEnabled(): bool
    {
        return (int)$this->getData(self::fields_IS_ENABLED) === 1;
    }
}
