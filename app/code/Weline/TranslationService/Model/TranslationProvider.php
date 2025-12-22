<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\TranslationService\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 翻译渠道配置模型
 */
class TranslationProvider extends AbstractModel
{
    public const table = 'w_translation_provider';
    
    public const fields_ID = 'provider_id';
    public const fields_PROVIDER_CODE = 'provider_code';
    public const fields_PROVIDER_NAME = 'provider_name';
    public const fields_API_KEY = 'api_key';
    public const fields_API_SECRET = 'api_secret';
    public const fields_API_ENDPOINT = 'api_endpoint';
    public const fields_CONFIG = 'config';
    public const fields_IS_ENABLED = 'is_enabled';
    public const fields_IS_DEFAULT = 'is_default';
    public const fields_PRIORITY = 'priority';
    public const fields_SUPPORTED_LANGUAGES = 'supported_languages';
    public const fields_RATE_LIMIT = 'rate_limit';
    public const fields_DAILY_LIMIT = 'daily_limit';
    public const fields_COST_PER_CHARACTER = 'cost_per_character';
    public const fields_DESCRIPTION = 'description';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['provider_id'];

    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['provider_id', 'is_enabled', 'is_default', 'priority'];

    /**
     * 初始化模型
     */
    public function _init(): void
    {
        $this->_primary_key = self::fields_ID;
    }

    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        // 表结构已在Setup/Install.php中创建
    }

    /**
     * 设置表结构
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

    /**
     * 获取配置（JSON格式）
     */
    public function getConfig(): array
    {
        $config = $this->getData(self::fields_CONFIG);
        if (is_string($config)) {
            $config = json_decode($config, true) ?: [];
        }
        return is_array($config) ? $config : [];
    }

    /**
     * 设置配置（JSON格式）
     */
    public function setConfig(array $config): self
    {
        $this->setData(self::fields_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * 获取支持的语言列表
     */
    public function getSupportedLanguages(): array
    {
        $languages = $this->getData(self::fields_SUPPORTED_LANGUAGES);
        if (is_string($languages)) {
            $languages = json_decode($languages, true) ?: [];
        }
        return is_array($languages) ? $languages : [];
    }

    /**
     * 设置支持的语言列表
     */
    public function setSupportedLanguages(array $languages): self
    {
        $this->setData(self::fields_SUPPORTED_LANGUAGES, json_encode($languages, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * 是否启用
     */
    public function isEnabled(): bool
    {
        return (bool)$this->getData(self::fields_IS_ENABLED);
    }

    /**
     * 是否默认渠道
     */
    public function isDefault(): bool
    {
        return (bool)$this->getData(self::fields_IS_DEFAULT);
    }

    /**
     * 检查是否支持指定语言
     */
    public function supportsLanguage(string $languageCode): bool
    {
        $supported = $this->getSupportedLanguages();
        // 支持ISO 639-1和BCP 47格式
        $langCode = strtolower(explode('_', $languageCode)[0]);
        return in_array($langCode, $supported) || in_array($languageCode, $supported);
    }
}

