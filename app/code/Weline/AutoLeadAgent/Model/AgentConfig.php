<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\AutoLeadAgent\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 自动寻客配置模型
 * 用于存储模块配置信息
 */
class AgentConfig extends AbstractModel
{
    public const table = 'weline_auto_lead_agent_config';
    
    public const fields_ID = 'config_id';
    public const fields_CONFIG_KEY = 'config_key';
    public const fields_CONFIG_VALUE = 'config_value';
    public const fields_SCOPE = 'scope';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    // 默认作用域
    public const SCOPE_DEFAULT = 'default';

    // 配置键常量
    public const CONFIG_AGENT_INTERVAL = 'agent_interval';
    public const CONFIG_SCORE_THRESHOLD = 'score_threshold';
    public const CONFIG_KEYWORD_STRATEGY = 'keyword_strategy';
    public const CONFIG_API_RATE_LIMIT = 'api_rate_limit';
    public const CONFIG_MAX_CONCURRENT_TASKS = 'max_concurrent_tasks';
    public const CONFIG_WASM_MODEL_ENABLED = 'wasm_model_enabled';
    public const CONFIG_WASM_INFERENCE_TIMEOUT = 'wasm_inference_timeout';
    public const CONFIG_DEFAULT_TARGET_SITES = 'default_target_sites';
    
    // Hugging Face 模型配置
    public const CONFIG_HF_MODEL_ID = 'hf_model_id';
    public const CONFIG_HF_MODEL_ENABLED = 'hf_model_enabled';
    public const CONFIG_HF_MODEL_CACHE_SIZE = 'hf_model_cache_size';
    
    // HuggingFace API 网络配置
    public const CONFIG_HF_USE_MIRROR = 'hf_use_mirror';
    public const CONFIG_HF_MIRROR_URL = 'hf_mirror_url';
    public const CONFIG_HF_PROXY_ENABLED = 'hf_proxy_enabled';
    public const CONFIG_HF_PROXY_URL = 'hf_proxy_url';

    // 关键词生成策略
    public const KEYWORD_STRATEGY_AUTO = 'auto';
    public const KEYWORD_STRATEGY_MANUAL = 'manual';
    public const KEYWORD_STRATEGY_HYBRID = 'hybrid';

    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['config_id'];

    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['config_id', 'config_key', 'scope'];

    /**
     * 初始化模型
     */
    public function _init(): void
    {
        $this->_primary_key = self::fields_ID;
    }

    /**
     * 获取默认配置
     */
    public static function getDefaultConfigs(): array
    {
        return [
            self::CONFIG_AGENT_INTERVAL => 60,
            self::CONFIG_SCORE_THRESHOLD => 60,
            self::CONFIG_KEYWORD_STRATEGY => self::KEYWORD_STRATEGY_AUTO,
            self::CONFIG_API_RATE_LIMIT => 100,
            self::CONFIG_MAX_CONCURRENT_TASKS => 1,
            self::CONFIG_WASM_MODEL_ENABLED => true,
            self::CONFIG_WASM_INFERENCE_TIMEOUT => 3000, // 3秒
            self::CONFIG_DEFAULT_TARGET_SITES => [
                'linkedin.com',
                'twitter.com',
                'facebook.com',
                'instagram.com',
                'youtube.com',
            ],
            self::CONFIG_HF_MODEL_ID => '',
            self::CONFIG_HF_MODEL_ENABLED => false,
            self::CONFIG_HF_MODEL_CACHE_SIZE => 10240, // MB (最大默认值)
            self::CONFIG_HF_USE_MIRROR => false,
            self::CONFIG_HF_MIRROR_URL => 'https://hf-mirror.com',
            self::CONFIG_HF_PROXY_ENABLED => false,
            self::CONFIG_HF_PROXY_URL => '', // 格式: http://host:port 或 socks5://host:port
        ];
    }

    /**
     * 获取关键词策略选项
     */
    public static function getKeywordStrategyOptions(): array
    {
        return [
            self::KEYWORD_STRATEGY_AUTO => __('自动生成'),
            self::KEYWORD_STRATEGY_MANUAL => __('手动设置'),
            self::KEYWORD_STRATEGY_HYBRID => __('混合模式'),
        ];
    }

    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable(__('自动寻客配置表'))
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'primary key auto_increment',
                    __('配置ID')
                )
                ->addColumn(
                    self::fields_CONFIG_KEY,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'not null',
                    __('配置键')
                )
                ->addColumn(
                    self::fields_CONFIG_VALUE,
                    TableInterface::column_type_TEXT,
                    null,
                    '',
                    __('配置值')
                )
                ->addColumn(
                    self::fields_SCOPE,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null default \'default\'',
                    __('作用域')
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_TIMESTAMP,
                    null,
                    'not null default current_timestamp',
                    __('创建时间')
                )
                ->addColumn(
                    self::fields_UPDATED_AT,
                    TableInterface::column_type_TIMESTAMP,
                    null,
                    'not null default current_timestamp on update current_timestamp',
                    __('更新时间')
                )
                ->addIndex(
                    TableInterface::index_type_UNIQUE,
                    'uk_config_key_scope',
                    [self::fields_CONFIG_KEY, self::fields_SCOPE],
                    __('配置键+作用域唯一索引')
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_scope',
                    self::fields_SCOPE,
                    __('作用域索引')
                )
                ->create();
        }
    }

    /**
     * 设置表结构（开发模式）
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * 升级表结构
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级逻辑
    }
}

