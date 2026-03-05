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
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/** 平台配置模型 @package Weline_GenerativeEngineOptimization */
#[Table(comment: 'GEO平台配置表')]
#[Index(name: 'idx_platform_code', columns: ['platform_code'], type: 'UNIQUE', comment: '平台代码唯一索引')]
#[Index(name: 'idx_is_enabled', columns: ['is_enabled'], comment: '启用状态索引')]
class Platform extends Model
{

    public const schema_table = 'geo_platform';
    public const schema_primary_key = 'id';
    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['id', 'platform_code', 'is_enabled'];
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col('varchar', 50, nullable: false, comment: '平台代码')]
    public const schema_fields_PLATFORM_CODE = 'platform_code';
    #[Col('varchar', 100, nullable: false, comment: '平台名称')]
    public const schema_fields_PLATFORM_NAME = 'platform_name';
    #[Col('varchar', 255, comment: 'API端点URL')]
    public const schema_fields_API_ENDPOINT = 'api_endpoint';
    #[Col('varchar', 50, default: 'json_feed', comment: 'Feed格式')]
    public const schema_fields_FEED_FORMAT = 'feed_format';
    #[Col('int', 1, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ENABLED = 'is_enabled';
    #[Col('text', comment: '平台特定配置JSON')]
    public const schema_fields_CONFIG = 'config';
    #[Col('int', default: 0, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('int', default: 0, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

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
        return self::schema_fields_ID;
    }
/**
     * 获取配置数组
     * 
     * @return array
     */
    public function getConfigArray(): array
    {
        $config = $this->getData(self::schema_fields_CONFIG);
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
        $this->setData(self::schema_fields_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * 检查是否启用
     * 
     * @return bool
     */
    public function isEnabled(): bool
    {
        return (int)$this->getData(self::schema_fields_IS_ENABLED) === 1;
    }
}

