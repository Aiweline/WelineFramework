<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Geo\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/** Feed配置模型 @package Weline_Geo */
#[Table(comment: 'GEO Feed配置表')]
#[Index(name: 'idx_feed_type', columns: ['feed_type'], comment: 'Feed类型索引')]
#[Index(name: 'idx_is_enabled', columns: ['is_enabled'], comment: '启用状态索引')]
#[Index(name: 'idx_is_auto_push', columns: ['is_auto_push'], comment: '自动推送索引')]
class Feed extends Model
{

    public const schema_table = 'geo_feed';
    public const schema_primary_key = 'id';
    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['id', 'feed_type', 'is_enabled', 'is_auto_push'];
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col('varchar', 100, nullable: false, comment: 'Feed名称')]
    public const schema_fields_FEED_NAME = 'feed_name';
    #[Col('varchar', 50, default: 'content', comment: 'Feed类型')]
    public const schema_fields_FEED_TYPE = 'feed_type';
    #[Col('varchar', 50, default: 'database', comment: '数据源类型')]
    public const schema_fields_SOURCE_TYPE = 'source_type';
    #[Col('text', comment: '数据源配置JSON')]
    public const schema_fields_SOURCE_CONFIG = 'source_config';
    #[Col('varchar', 255, comment: 'Feed访问URL')]
    public const schema_fields_FEED_URL = 'feed_url';
    #[Col('varchar', 50, default: 'daily', comment: '更新频率')]
    public const schema_fields_UPDATE_FREQUENCY = 'update_frequency';
    #[Col('int', 1, default: 1, comment: '是否自动推送')]
    public const schema_fields_IS_AUTO_PUSH = 'is_auto_push';
    #[Col('int', 1, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ENABLED = 'is_enabled';
    #[Col('int', comment: '最后生成时间')]
    public const schema_fields_LAST_GENERATED_AT = 'last_generated_at';
    #[Col('int', comment: '最后推送时间')]
    public const schema_fields_LAST_PUSHED_AT = 'last_pushed_at';
    #[Col('text', comment: 'Feed配置JSON')]
    public const schema_fields_CONFIG = 'config';
    #[Col('int', default: 0, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('int', default: 0, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

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
        return self::schema_fields_ID;
    }
/**
     * 获取数据源配置数组
     * @return array
     */
    public function getSourceConfigArray(): array
    {
        $config = $this->getData(self::schema_fields_SOURCE_CONFIG);
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
        $this->setData(self::schema_fields_SOURCE_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
        return $this;
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

    /**
     * 检查是否自动推送
     * 
     * @return bool
     */
    public function isAutoPush(): bool
    {
        return (int)$this->getData(self::schema_fields_IS_AUTO_PUSH) === 1;
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
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);
    }
}

