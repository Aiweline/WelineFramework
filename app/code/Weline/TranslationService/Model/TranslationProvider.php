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
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** 翻译渠道配置模型 */
#[Table(comment: '翻译渠道配置表')]
#[Index(name: 'idx_w_translation_provider_code', columns: ['provider_code'], type: 'UNIQUE')]
#[Index(name: 'idx_w_translation_provider_is_enabled', columns: ['is_enabled'])]
#[Index(name: 'idx_w_translation_provider_is_default', columns: ['is_default'])]
#[Index(name: 'idx_w_translation_provider_priority', columns: ['priority'])]
class TranslationProvider extends AbstractModel
{

    public const schema_table = 'w_translation_provider';
    public const schema_primary_key = 'provider_id';
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '渠道ID')]
    public const schema_fields_ID = 'provider_id';
    #[Col('varchar', 50, nullable: false, comment: '渠道代码')]
    public const schema_fields_PROVIDER_CODE = 'provider_code';
    #[Col('varchar', 100, nullable: false, comment: '渠道名称')]
    public const schema_fields_PROVIDER_NAME = 'provider_name';
    #[Col('varchar', 500, comment: 'API密钥')]
    public const schema_fields_API_KEY = 'api_key';
    #[Col('varchar', 500, comment: 'API密钥加密')]
    public const schema_fields_API_SECRET = 'api_secret';
    #[Col('varchar', 500, comment: 'API端点URL')]
    public const schema_fields_API_ENDPOINT = 'api_endpoint';
    #[Col('text', comment: '额外配置JSON')]
    public const schema_fields_CONFIG = 'config';
    #[Col('int', 1, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ENABLED = 'is_enabled';
    #[Col('int', 1, default: 0, comment: '是否默认渠道')]
    public const schema_fields_IS_DEFAULT = 'is_default';
    #[Col('int', default: 0, comment: '优先级')]
    public const schema_fields_PRIORITY = 'priority';
    #[Col('text', comment: '支持的语言列表JSON')]
    public const schema_fields_SUPPORTED_LANGUAGES = 'supported_languages';
    #[Col('int', comment: '速率限制')]
    public const schema_fields_RATE_LIMIT = 'rate_limit';
    #[Col('int', comment: '每日限制')]
    public const schema_fields_DAILY_LIMIT = 'daily_limit';
    #[Col('decimal', '10,6', default: '0', comment: '每字符成本')]
    public const schema_fields_COST_PER_CHARACTER = 'cost_per_character';
    #[Col('text', comment: '渠道描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col('datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    public array $_unit_primary_keys = ['provider_id'];
    public array $_index_sort_keys = ['provider_id', 'is_enabled', 'is_default', 'priority'];

    public function _init(): void
    {
        $this->_primary_key = self::schema_fields_ID;
    }

    /**
     * 获取配置（JSON格式）
     */
    public function getConfig(): array
    {
        $config = $this->getData(self::schema_fields_CONFIG);
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
        $this->setData(self::schema_fields_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * 获取支持的语言列表
     */
    public function getSupportedLanguages(): array
    {
        $languages = $this->getData(self::schema_fields_SUPPORTED_LANGUAGES);
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
        $this->setData(self::schema_fields_SUPPORTED_LANGUAGES, json_encode($languages, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * 是否启用
     */
    public function isEnabled(): bool
    {
        return (bool)$this->getData(self::schema_fields_IS_ENABLED);
    }

    /**
     * 是否默认渠道
     */
    public function isDefault(): bool
    {
        return (bool)$this->getData(self::schema_fields_IS_DEFAULT);
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


