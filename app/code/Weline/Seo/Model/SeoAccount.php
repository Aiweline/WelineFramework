<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/** SEO 提交账户模型 - 按 scope + provider 维度管理各搜索引擎/渠道提交账户配置 */
#[Table(comment: 'SEO提交账户表')]
#[Index(name: 'idx_scope_module', columns: ['scope', 'module'])]
#[Index(name: 'idx_platform', columns: ['platform'])]
#[Index(name: 'idx_provider_active', columns: ['provider', 'is_active'])]
class SeoAccount extends Model
{

    public const schema_table = 'weline_seo_account';
    public const schema_primary_key = 'account_id';
    public array $_unit_primary_keys = ['account_id'];
    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '账户ID')]
    public const schema_fields_ID = 'account_id';
    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '账户ID')]
    public const schema_fields_ACCOUNT_ID = 'account_id';
    #[Col('varchar', 100, comment: '业务scope标识')]
    public const schema_fields_SCOPE = 'scope';
    #[Col('varchar', 150, comment: '来源模块名')]
    public const schema_fields_MODULE = 'module';
    #[Col('varchar', 50, nullable: false, comment: 'Sitemap平台代码')]
    public const schema_fields_PLATFORM = 'platform';
    #[Col('varchar', 100, nullable: false, comment: '供应商代码')]
    public const schema_fields_PROVIDER = 'provider';
    #[Col('varchar', 255, nullable: false, comment: '账户名称')]
    public const schema_fields_NAME = 'name';
    #[Col('text', comment: '账户描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col('text', comment: '账户配置JSON')]
    public const schema_fields_CONFIG = 'config_json';
    #[Col('int', 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col('int', 1, nullable: false, default: 1, comment: '是否启用URL定时推送')]
    public const schema_fields_ENABLE_CRON_PUSH_URLS = 'enable_cron_push_urls';
    #[Col('int', 1, nullable: false, default: 0, comment: '是否启用Sitemap定时提交')]
    public const schema_fields_ENABLE_CRON_SITEMAP = 'enable_cron_sitemap';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

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
        return self::schema_fields_ACCOUNT_ID;
    }
/**
     * 获取配置数组
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
     */
    public function setConfigArray(array $config): self
    {
        $this->setData(self::schema_fields_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    public function isActive(): bool
    {
        return (int)$this->getData(self::schema_fields_IS_ACTIVE) === self::STATUS_ACTIVE;
    }

    public function isCronPushUrlsEnabled(): bool
    {
        return (int)$this->getData(self::schema_fields_ENABLE_CRON_PUSH_URLS) === 1;
    }

    public function isCronSitemapEnabled(): bool
    {
        return (int)$this->getData(self::schema_fields_ENABLE_CRON_SITEMAP) === 1;
    }

    /**
     * 获取平台代码
     */
    public function getPlatform(): string
    {
        return (string)$this->getData(self::schema_fields_PLATFORM);
    }

    /**
     * 设置平台代码
     */
    public function setPlatform(string $platform): self
    {
        $this->setData(self::schema_fields_PLATFORM, $platform);
        return $this;
    }

    public function save_before(): void
    {
        $now = date('Y-m-d H:i:s');
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);
    }
}


