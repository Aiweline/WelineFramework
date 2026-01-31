<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * SEO 提交账户模型
 *
 * 负责存储各搜索引擎/渠道的提交账户配置，
 * 按 scope + provider 维度进行管理。
 *
 * @package Weline_Seo
 */
class SeoAccount extends Model
{
    public const table = 'weline_seo_account';

    /**
     * Primary key
     */
    public string $_primary_key = 'account_id';

    /**
     * Primary keys
     */
    public array $_unit_primary_keys = ['account_id'];

    /**
     * 字段常量
     */
    public const fields_ACCOUNT_ID = 'account_id';
    public const fields_SCOPE = 'scope';
    public const fields_MODULE = 'module';
    public const fields_PLATFORM = 'platform';
    public const fields_PROVIDER = 'provider';
    public const fields_NAME = 'name';
    public const fields_DESCRIPTION = 'description';
    public const fields_CONFIG = 'config_json';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_ENABLE_CRON_PUSH_URLS = 'enable_cron_push_urls';
    public const fields_ENABLE_CRON_SITEMAP = 'enable_cron_sitemap';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    /**
     * 状态常量
     */
    public const STATUS_ACTIVE = 1;
    public const STATUS_INACTIVE = 0;

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::fields_ACCOUNT_ID;
    }

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist() === false) {
            $setup->createTable('SEO提交账户表')
                ->addColumn(
                    self::fields_ACCOUNT_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'primary key auto_increment',
                    '账户ID'
                )
                ->addColumn(
                    self::fields_SCOPE,
                    TableInterface::column_type_VARCHAR,
                    100,
                    '',
                    '业务scope标识，如page_builder、catalog等'
                )
                ->addColumn(
                    self::fields_MODULE,
                    TableInterface::column_type_VARCHAR,
                    150,
                    '',
                    '来源模块名，例如GuoLaiRen_PageBuilder'
                )
                ->addColumn(
                    self::fields_PLATFORM,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null',
                    'Sitemap平台代码：google/bing/baidu等'
                )
                ->addColumn(
                    self::fields_PROVIDER,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'not null',
                    '供应商代码，如google_indexing_api'
                )
                ->addColumn(
                    self::fields_NAME,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '账户名称'
                )
                ->addColumn(
                    self::fields_DESCRIPTION,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    '账户描述'
                )
                ->addColumn(
                    self::fields_CONFIG,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    '账户配置JSON（如Google service account配置、属性URL等）'
                )
                ->addColumn(
                    self::fields_IS_ACTIVE,
                    TableInterface::column_type_INTEGER,
                    1,
                    'default 1',
                    '是否启用：1启用，0禁用'
                )
                ->addColumn(
                    self::fields_ENABLE_CRON_PUSH_URLS,
                    TableInterface::column_type_INTEGER,
                    1,
                    'default 1',
                    '是否启用URL定时推送任务'
                )
                ->addColumn(
                    self::fields_ENABLE_CRON_SITEMAP,
                    TableInterface::column_type_INTEGER,
                    1,
                    'default 0',
                    '是否启用Sitemap定时提交任务'
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    '',
                    '创建时间'
                )
                ->addColumn(
                    self::fields_UPDATED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    '',
                    '更新时间'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_scope_module',
                    [self::fields_SCOPE, self::fields_MODULE],
                    'scope+module索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_platform',
                    [self::fields_PLATFORM],
                    '平台索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_provider_active',
                    [self::fields_PROVIDER, self::fields_IS_ACTIVE],
                    '供应商+启用状态索引'
                )
                ->create();
        }
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist() === false) {
            return;
        }

        // 添加 platform 字段（v1.0.1）
        if (!$setup->columnExist(self::fields_PLATFORM)) {
            $setup->addColumn(
                self::fields_PLATFORM,
                TableInterface::column_type_VARCHAR,
                50,
                'not null default ""',
                'Sitemap平台代码：google/bing/baidu等',
                self::fields_MODULE // 在 module 字段之后
            );
            
            $setup->addIndex(
                TableInterface::index_type_KEY,
                'idx_platform',
                [self::fields_PLATFORM],
                '平台索引'
            );
        }
    }

    /**
     * 获取配置数组
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
     */
    public function setConfigArray(array $config): self
    {
        $this->setData(self::fields_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    public function isActive(): bool
    {
        return (int)$this->getData(self::fields_IS_ACTIVE) === self::STATUS_ACTIVE;
    }

    public function isCronPushUrlsEnabled(): bool
    {
        return (int)$this->getData(self::fields_ENABLE_CRON_PUSH_URLS) === 1;
    }

    public function isCronSitemapEnabled(): bool
    {
        return (int)$this->getData(self::fields_ENABLE_CRON_SITEMAP) === 1;
    }

    /**
     * 获取平台代码
     */
    public function getPlatform(): string
    {
        return (string)$this->getData(self::fields_PLATFORM);
    }

    /**
     * 设置平台代码
     */
    public function setPlatform(string $platform): self
    {
        $this->setData(self::fields_PLATFORM, $platform);
        return $this;
    }

    public function save_before(): void
    {
        $now = date('Y-m-d H:i:s');
        if (!$this->getId()) {
            $this->setData(self::fields_CREATED_AT, $now);
        }
        $this->setData(self::fields_UPDATED_AT, $now);
    }
}

