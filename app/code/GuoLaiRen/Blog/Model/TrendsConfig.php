<?php

declare(strict_types=1);

/*
 * GuoLaiRen Blog Module
 * Trends 配置键常量与默认值
 */

namespace GuoLaiRen\Blog\Model;

use Weline\Backend\Model\Config as BackendConfig;
use Weline\Framework\Manager\ObjectManager;

class TrendsConfig
{
    public const MODULE = 'GuoLaiRen_Blog';

    /** 数据源类型：none / serpapi / official */
    public const KEY_API_TYPE = 'guolairen_blog_trends_api_type';
    /** SerpApi API Key */
    public const KEY_SERPAPI_KEY = 'guolairen_blog_trends_serpapi_key';
    /** 官方 Service Account JSON 内容（粘贴） */
    public const KEY_SERVICE_ACCOUNT_JSON = 'guolairen_blog_trends_service_account_json';
    /** 增长比较基准：day / week / both */
    public const KEY_GROWTH_COMPARISON = 'guolairen_blog_trends_growth_comparison';
    /** 增长率阈值（百分比，如 0、5、10） */
    public const KEY_GROWTH_THRESHOLD = 'guolairen_blog_trends_growth_threshold';
    /** 趋势地区（如 US、CN） */
    public const KEY_REGION = 'guolairen_blog_trends_region';
    /** 默认语言（如 en_US、zh_Hans_CN） */
    public const KEY_DEFAULT_LANGUAGE = 'guolairen_blog_trends_default_language';
    /** 自动发文为草稿：1 草稿，0 直接发布 */
    public const KEY_PUBLISH_AS_DRAFT = 'guolairen_blog_trends_publish_as_draft';

    public const API_TYPE_NONE = 'none';
    public const API_TYPE_SERPAPI = 'serpapi';
    public const API_TYPE_OFFICIAL = 'official';

    public const GROWTH_DAY = 'day';
    public const GROWTH_WEEK = 'week';
    public const GROWTH_BOTH = 'both';

    /**
     * 获取配置值
     */
    public static function get(string $key, string $default = ''): string
    {
        /** @var BackendConfig $config */
        $config = ObjectManager::getInstance(BackendConfig::class);
        $value = $config->getConfig($key, self::MODULE);
        return $value !== null && $value !== '' ? (string)$value : $default;
    }

    /**
     * 设置配置值
     */
    public static function set(string $key, string $value): bool
    {
        /** @var BackendConfig $config */
        $config = ObjectManager::getInstance(BackendConfig::class);
        return $config->setConfig($key, $value, self::MODULE);
    }

    /**
     * 是否使用 SerpApi
     */
    public static function useSerpApi(): bool
    {
        return self::get(self::KEY_API_TYPE, self::API_TYPE_NONE) === self::API_TYPE_SERPAPI;
    }

    /**
     * 是否使用官方 API
     */
    public static function useOfficialApi(): bool
    {
        return self::get(self::KEY_API_TYPE, self::API_TYPE_NONE) === self::API_TYPE_OFFICIAL;
    }

    /**
     * 增长率阈值（数字）
     */
    public static function getGrowthThreshold(): float
    {
        $v = self::get(self::KEY_GROWTH_THRESHOLD, '0');
        return (float)$v;
    }

    /**
     * 增长比较基准
     */
    public static function getGrowthComparison(): string
    {
        return self::get(self::KEY_GROWTH_COMPARISON, self::GROWTH_BOTH);
    }

    /**
     * 是否按草稿发布
     */
    public static function publishAsDraft(): bool
    {
        return self::get(self::KEY_PUBLISH_AS_DRAFT, '1') === '1';
    }
}
