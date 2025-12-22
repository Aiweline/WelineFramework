<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\AutoLeadAgent\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\AutoLeadAgent\Model\SearchEngineMapping;

/**
 * 搜索引擎映射服务
 * 
 * 根据地区和语言确定使用的搜索引擎
 */
class SearchEngineMappingService
{
    /**
     * 映射缓存
     * 
     * @var array
     */
    private static array $mappingCache = [];
    
    /**
     * 缓存是否已加载
     * 
     * @var bool
     */
    private static bool $cacheLoaded = false;
    
    /**
     * 映射模型
     * 
     * @var SearchEngineMapping|null
     */
    private ?SearchEngineMapping $mappingModel = null;
    
    /**
     * 默认映射（当数据库中没有数据时使用）
     * 
     * @var array
     */
    private array $defaultMapping = [
        '中国' => [
            'zh' => ['Baidu', '360搜索', '搜狗'],
            'zh-CN' => ['Baidu', '360搜索', '搜狗'],
            'zh-Hans' => ['Baidu', '360搜索', '搜狗'],
            'en' => ['Google', 'Bing'],
        ],
        '美国' => [
            'en' => ['Google', 'Bing', 'DuckDuckGo'],
            'es' => ['Google', 'Bing'],
        ],
        '俄罗斯' => [
            'ru' => ['Yandex', 'Google'],
            'en' => ['Google', 'Bing'],
        ],
        '日本' => [
            'ja' => ['Google', 'Bing'],
            'en' => ['Google', 'Bing'],
        ],
        '韩国' => [
            'ko' => ['Google', 'Bing'],
            'en' => ['Google', 'Bing'],
        ],
        '欧洲' => [
            'en' => ['Google', 'Bing', 'DuckDuckGo'],
            'de' => ['Google', 'Bing'],
            'fr' => ['Google', 'Bing'],
            'es' => ['Google', 'Bing'],
            'it' => ['Google', 'Bing'],
        ],
        '英国' => [
            'en' => ['Google', 'Bing', 'DuckDuckGo'],
        ],
        '加拿大' => [
            'en' => ['Google', 'Bing', 'DuckDuckGo'],
            'fr' => ['Google', 'Bing'],
        ],
        '澳大利亚' => [
            'en' => ['Google', 'Bing', 'DuckDuckGo'],
        ],
        '印度' => [
            'en' => ['Google', 'Bing'],
            'hi' => ['Google', 'Bing'],
        ],
        '巴西' => [
            'pt' => ['Google', 'Bing'],
            'en' => ['Google', 'Bing'],
        ],
        '中东' => [
            'ar' => ['Google', 'Bing'],
            'en' => ['Google', 'Bing'],
        ],
    ];

    /**
     * 语言别名映射（标准化语言代码）
     * 
     * @var array
     */
    private array $languageAliases = [
        '中文' => 'zh',
        '简体中文' => 'zh-CN',
        '繁体中文' => 'zh-TW',
        '英文' => 'en',
        '英语' => 'en',
        '日文' => 'ja',
        '日语' => 'ja',
        '韩文' => 'ko',
        '韩语' => 'ko',
        '俄文' => 'ru',
        '俄语' => 'ru',
        '德文' => 'de',
        '德语' => 'de',
        '法文' => 'fr',
        '法语' => 'fr',
        '西班牙文' => 'es',
        '西班牙语' => 'es',
        '意大利文' => 'it',
        '意大利语' => 'it',
        '葡萄牙文' => 'pt',
        '葡萄牙语' => 'pt',
        '阿拉伯文' => 'ar',
        '阿拉伯语' => 'ar',
        '印地语' => 'hi',
    ];

    /**
     * 地区别名映射
     * 
     * @var array
     */
    private array $regionAliases = [
        'China' => '中国',
        'CN' => '中国',
        'United States' => '美国',
        'USA' => '美国',
        'US' => '美国',
        'Russia' => '俄罗斯',
        'RU' => '俄罗斯',
        'Japan' => '日本',
        'JP' => '日本',
        'Korea' => '韩国',
        'South Korea' => '韩国',
        'KR' => '韩国',
        'Europe' => '欧洲',
        'EU' => '欧洲',
        'United Kingdom' => '英国',
        'UK' => '英国',
        'Canada' => '加拿大',
        'CA' => '加拿大',
        'Australia' => '澳大利亚',
        'AU' => '澳大利亚',
        'India' => '印度',
        'IN' => '印度',
        'Brazil' => '巴西',
        'BR' => '巴西',
        'Middle East' => '中东',
    ];

    /**
     * 默认搜索引擎（当无法匹配时使用）
     * 
     * @var array
     */
    private array $defaultSearchEngines = ['Google', 'Bing', 'DuckDuckGo'];

    /**
     * 根据地区和语言获取搜索引擎列表
     * 
     * @param string $region 目标地区
     * @param string $language 搜索语言
     * @return array 搜索引擎名称数组
     */
    public function getSearchEnginesByRegionAndLanguage(string $region, string $language): array
    {
        // 标准化地区和语言
        $normalizedRegion = $this->normalizeRegion($region);
        $normalizedLanguage = $this->normalizeLanguage($language);

        // 从数据库或缓存获取映射
        $mapping = $this->getMapping();

        // 查找映射
        if (isset($mapping[$normalizedRegion])) {
            $regionMapping = $mapping[$normalizedRegion];
            
            // 优先查找精确匹配
            if (isset($regionMapping[$normalizedLanguage])) {
                return $regionMapping[$normalizedLanguage];
            }
            
            // 如果没有精确匹配，尝试查找语言代码的前缀（如 zh-CN -> zh）
            $languagePrefix = explode('-', $normalizedLanguage)[0];
            if (isset($regionMapping[$languagePrefix])) {
                return $regionMapping[$languagePrefix];
            }
            
            // 如果该地区支持英文，使用英文搜索引擎
            if (isset($regionMapping['en'])) {
                return $regionMapping['en'];
            }
        }

        // 如果无法匹配，根据语言推断
        return $this->getSearchEnginesByLanguage($normalizedLanguage);
    }

    /**
     * 根据语言获取搜索引擎列表（当无法匹配地区时）
     * 
     * @param string $language 搜索语言
     * @return array 搜索引擎名称数组
     */
    public function getSearchEnginesByLanguage(string $language): array
    {
        $normalizedLanguage = $this->normalizeLanguage($language);

        // 中文使用中文搜索引擎
        if (in_array($normalizedLanguage, ['zh', 'zh-CN', 'zh-Hans', 'zh-TW'])) {
            return ['Baidu', '360搜索', '搜狗'];
        }

        // 俄文使用Yandex
        if ($normalizedLanguage === 'ru') {
            return ['Yandex', 'Google', 'Bing'];
        }

        // 其他语言使用国际搜索引擎
        return $this->defaultSearchEngines;
    }

    /**
     * 标准化语言代码
     * 
     * @param string $language 语言代码或名称
     * @return string 标准化后的语言代码
     */
    public function normalizeLanguage(string $language): string
    {
        $language = trim($language);
        
        // 如果是别名，转换为标准代码
        if (isset($this->languageAliases[$language])) {
            return $this->languageAliases[$language];
        }

        // 转换为小写
        $lowerLanguage = strtolower($language);

        // 处理常见的语言代码格式
        if (strpos($lowerLanguage, 'zh') === 0) {
            // 中文变体统一处理
            if (strpos($lowerLanguage, 'hans') !== false || strpos($lowerLanguage, 'cn') !== false) {
                return 'zh-CN';
            }
            if (strpos($lowerLanguage, 'hant') !== false || strpos($lowerLanguage, 'tw') !== false) {
                return 'zh-TW';
            }
            return 'zh';
        }

        // 其他语言代码标准化
        $langMap = [
            'en' => 'en',
            'en-us' => 'en',
            'ja' => 'ja',
            'ko' => 'ko',
            'ru' => 'ru',
            'de' => 'de',
            'fr' => 'fr',
            'es' => 'es',
            'it' => 'it',
            'pt' => 'pt',
            'ar' => 'ar',
            'hi' => 'hi',
        ];

        return $langMap[$lowerLanguage] ?? $lowerLanguage;
    }

    /**
     * 标准化地区名称
     * 
     * @param string $region 地区名称或代码
     * @return string 标准化后的地区名称
     */
    public function normalizeRegion(string $region): string
    {
        $region = trim($region);
        
        // 如果是别名，转换为标准名称
        if (isset($this->regionAliases[$region])) {
            return $this->regionAliases[$region];
        }

        // 直接返回（可能已经是标准名称）
        return $region;
    }

    /**
     * 从店铺地区推断语言
     * 
     * @param string $region 地区名称
     * @return string 推断的语言代码
     */
    public function inferLanguageFromRegion(string $region): string
    {
        $normalizedRegion = $this->normalizeRegion($region);

        $regionLanguageMap = [
            '中国' => 'zh',
            '日本' => 'ja',
            '韩国' => 'ko',
            '俄罗斯' => 'ru',
            '德国' => 'de',
            '法国' => 'fr',
            '西班牙' => 'es',
            '意大利' => 'it',
            '葡萄牙' => 'pt',
            '巴西' => 'pt',
            '阿拉伯' => 'ar',
            '印度' => 'hi',
        ];

        // 精确匹配
        if (isset($regionLanguageMap[$normalizedRegion])) {
            return $regionLanguageMap[$normalizedRegion];
        }

        // 部分匹配
        foreach ($regionLanguageMap as $key => $lang) {
            if (strpos($normalizedRegion, $key) !== false || strpos($key, $normalizedRegion) !== false) {
                return $lang;
            }
        }

        // 默认返回英文
        return 'en';
    }

    /**
     * 获取所有支持的地区
     * 
     * @return array
     */
    public function getSupportedRegions(): array
    {
        $mapping = $this->getMapping();
        return array_keys($mapping);
    }

    /**
     * 获取所有支持的语言
     * 
     * @return array
     */
    public function getSupportedLanguages(): array
    {
        $mapping = $this->getMapping();
        $languages = [];
        foreach ($mapping as $regionMapping) {
            $languages = array_merge($languages, array_keys($regionMapping));
        }
        return array_unique($languages);
    }
}

