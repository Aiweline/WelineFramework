<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Meta\Helper;

use Weline\Framework\App\State;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;
use Weline\Meta\Model\Meta;

/**
 * MetaData 辅助类
 * 
 * 用于读取 Meta 表中的 setting 数据
 * 支持通过 meta_identify 或文件路径加载 Meta 记录，然后读取 setting 中的参数
 * 
 * 性能优化：
 * - 使用静态缓存避免重复查询数据库
 * - 批量获取翻译，减少数据库查询次数
 * - 延迟翻译，只在需要时翻译标签
 * - 缓存翻译字典，避免重复查询
 * 
 * 使用示例：
 * // 读取配置值（.value 后缀）- 直接返回字符串
 * $headerValue = MetaData::load('theme.frontend.partials.header.value');
 * // $headerValue 是字符串，如 "minimal"
 * 
 * // 读取元数据信息（.info 后缀）- 直接返回字符串
 * $name = MetaData::load('theme.backend.components.info.name');
 * // $name 是字符串，如 "组件名称"
 * 
 * // 读取翻译值（.lang 后缀）- 直接返回字符串
 * $translation = MetaData::load('theme.layout.account_auth.info.name.lang');
 * // $translation 是字符串，支持多语言回退
 * 
 * // 读取完整 metaIdentify - 返回 MetaData 对象
 * $metaData = MetaData::load('theme.frontend.layouts.default');
 * $title = $metaData->getSetting('param.title'); // 或 $metaData->getSetting('title')
 * $content = $metaData->getSetting('param.content'); // 或 $metaData->getSetting('content')
 * 
 * // 静态方法 get() 和 load() 功能相同
 * $value = MetaData::get('partials.header.value'); // 等同于 MetaData::load('partials.header.value')
 * 
 * // 设置配置值
 * MetaData::set('partials.header.value', 'minimal', 'default', 'zh_Hans_CN');
 * 
 * // 设置翻译值
 * MetaData::set('theme.layout.account_auth.info.name.lang', '账户认证', 'default', 'zh_Hans_CN');
 */
class MetaData
{
    /** @var Meta|null */
    protected ?Meta $meta = null;
    
    /** @var array|null */
    protected ?array $setting = null;
    
    /** @var bool 是否已翻译标签 */
    protected bool $labelsTranslated = false;
    
    /** @var array 静态缓存：Meta 记录缓存 [identify => [meta, setting, meta_data]] */
    protected static array $metaCache = [];
    
    /** @var array 静态缓存：文件路径到 identify 的映射 [filePath => identify] */
    protected static array $filePathCache = [];
    
    /** @var array 静态缓存：翻译字典缓存 [locale => [md5 => translation]] */
    protected static array $translationCache = [];
    
    /** @var array 批量翻译键收集器 [locale => [md5 => translationKey]] */
    protected static array $translationKeysBatch = [];
    
    /** @var bool 是否已初始化批量翻译 */
    protected static bool $batchInitialized = false;
    
    /** @var array 性能缓存：按 key 存储预加载的数据 [key => [metaRecords => [], metaConfigs => [], namespace => '', metaIdentify => '', scope => '', locale => '']] */
    protected static array $performanceCache = [];
    
    /** @var string|null 当前实例使用的性能缓存 key */
    protected ?string $currentPerformanceKey = null;
    
    /**
     * 私有构造函数，使用 load 或 loadByFilePath 方法创建实例
     */
    private function __construct()
    {
    }
    
    /**
     * 根据 meta_identify 加载 Meta 记录
     * 支持从 MetaConfig 表读取配置值（如 theme.frontend.partials.header.value）
     * 
     * @param string $identify meta_identify（如 theme.frontend.layouts.default 或 theme.frontend.partials.header.value）
     * @param string|null $namespace 命名空间（可选，用于精确匹配）
     * @param string|null $type 类型（可选，用于精确匹配）
     * @return static|string|mixed
     *   - 如果是 .value 格式，直接返回配置值（字符串）
     *   - 如果是 .info 格式，直接返回信息值（字符串）
     *   - 如果是 .lang 格式，直接返回翻译值（字符串）
     *   - 其他格式，返回 MetaData 对象
     */
    public static function load(string $identify, ?string $namespace = null, ?string $type = null, ?string $locale = null)
    {
        // Meta 读取规则：
        // 1. 如果后缀是 .value，直接返回配置值（字符串）
        // 2. 如果后缀是 .info，直接返回信息值（字符串）
        // 3. 如果后缀是 .lang，直接返回翻译值（字符串）
        // 4. 其他格式，返回 MetaData 对象
        
        // 自动补全前缀：如果键名不包含 frontend/backend，自动根据当前请求判断并添加
        $identify = self::normalizeIdentify($identify);
        
        // 检查是否是字段直接读取格式（如 layouts.account.auth.name 或 layouts.account.auth.name.lang）
        // 格式：{metaIdentify}.{field} 或 {metaIdentify}.{field}.{suffix}
        // 支持的字段：name, description, default
        // 支持的后缀：.lang（翻译）, .value（优先返回 default）
        if (preg_match('/^(.+)\.(name|description|default)(\.(lang|value|info|config))?$/', $identify, $matches)) {
            $metaIdentify = $matches[1]; // 如 theme.frontend.layouts.account.auth
            $field = $matches[2]; // name, description, default
            $suffix = $matches[4] ?? null; // lang, value, info, config 或 null
            
            // 加载 Meta 记录
            $instance = new static();
            $cacheKey = self::buildCacheKey($metaIdentify, $namespace, $type);
            
            // 检查静态缓存
            if (isset(self::$metaCache[$cacheKey])) {
                $cached = self::$metaCache[$cacheKey];
                $instance->meta = $cached['meta'];
                $instance->setting = $cached['setting'];
                $instance->labelsTranslated = $cached['labelsTranslated'] ?? false;
            } else {
                // 尝试从性能缓存中获取（如果当前实例有 performanceKey）
                $meta = null;
                $setting = [];
                
                // 检查所有 performanceCache，看是否有匹配的 metaIdentify
                foreach (self::$performanceCache as $perfKey => $perfData) {
                    if (isset($perfData['metaRecords'][$metaIdentify])) {
                        $meta = $perfData['metaRecords'][$metaIdentify]['meta'];
                        $setting = $perfData['metaRecords'][$metaIdentify]['setting'];
                        $instance->currentPerformanceKey = $perfKey;
                        break;
                    }
                }
                
                if ($meta === null) {
                    // 从 Meta 表查询
                    /** @var Meta $meta */
                    $meta = ObjectManager::getInstance(Meta::class);
                    $meta->where(Meta::schema_fields_META_IDENTIFY, $metaIdentify);
                    
                    if ($namespace) {
                        $meta->where(Meta::schema_fields_NAMESPACE, $namespace);
                    }
                    if ($type) {
                        $meta->where(Meta::schema_fields_META_TYPE, $type);
                    }
                    
                    $meta->find()->fetch();
                    
                    if ($meta->getId()) {
                        $settingJson = $meta->getData(Meta::schema_fields_SETTING);
                        if ($settingJson) {
                            $setting = json_decode($settingJson, true) ?? [];
                        }
                    }
                }
                
                if ($meta && $meta->getId()) {
                    $instance->meta = $meta;
                    $instance->setting = $setting;
                    
                    // 缓存 Meta 记录
                    self::$metaCache[$cacheKey] = [
                        'meta' => $meta,
                        'setting' => $setting,
                        'labelsTranslated' => false
                    ];
                } else {
                    $instance->setting = [];
                    self::$metaCache[$cacheKey] = [
                        'meta' => null,
                        'setting' => [],
                        'labelsTranslated' => false
                    ];
                }
            }
            
            if (!$instance->isLoaded()) {
                return null;
            }
            
            // 从 meta_data JSON 中读取字段值
            $metaDataJson = $instance->meta->getData(Meta::schema_fields_META_DATA);
            $metaData = $metaDataJson ? json_decode($metaDataJson, true) : [];
            
            // 从 meta_data 中查找字段值（支持多种数据结构）
            // 1. 直接字段：meta_data['name']
            // 2. 层级结构：meta_data['meta']['name']
            // 3. attributes 结构：meta_data['attributes']['name']
            // 4. 字段对象：meta_data['name']['name'] 或 meta_data['name']['default']
            $fieldValue = null;
            $defaultValue = null;
            
            // 如果字段是 name 或 description，尝试从多个位置读取
            if ($field === 'name' || $field === 'description') {
                // 尝试直接读取
                if (isset($metaData[$field])) {
                    $fieldValue = is_array($metaData[$field]) ? ($metaData[$field]['name'] ?? $metaData[$field]['default'] ?? null) : $metaData[$field];
                }
                // 尝试从 meta 层级读取
                if ($fieldValue === null && isset($metaData['meta'][$field])) {
                    $fieldValue = is_array($metaData['meta'][$field]) ? ($metaData['meta'][$field]['name'] ?? $metaData['meta'][$field]['default'] ?? null) : $metaData['meta'][$field];
                }
                // 尝试从 attributes 读取
                if ($fieldValue === null && isset($metaData['attributes'][$field])) {
                    $fieldValue = is_array($metaData['attributes'][$field]) ? ($metaData['attributes'][$field]['name'] ?? $metaData['attributes'][$field]['default'] ?? null) : $metaData['attributes'][$field];
                }
                // 尝试从字段对象读取（如 meta_data['name']['name']）
                if ($fieldValue === null && isset($metaData[$field])) {
                    if (is_array($metaData[$field]) && isset($metaData[$field]['name'])) {
                        $fieldValue = $metaData[$field]['name'];
                    }
                }
            } elseif ($field === 'default') {
                // default 字段的特殊处理
                if (isset($metaData['default'])) {
                    $defaultValue = is_array($metaData['default']) ? ($metaData['default']['default'] ?? null) : $metaData['default'];
                } elseif (isset($metaData['meta']['default'])) {
                    $defaultValue = is_array($metaData['meta']['default']) ? ($metaData['meta']['default']['default'] ?? null) : $metaData['meta']['default'];
                } elseif (isset($metaData['attributes']['default'])) {
                    $defaultValue = is_array($metaData['attributes']['default']) ? ($metaData['attributes']['default']['default'] ?? null) : $metaData['attributes']['default'];
                }
            }
            
            // 读取 default 值（用于 .value 后缀）
            if ($defaultValue === null) {
                if (isset($metaData['default'])) {
                    $defaultValue = is_array($metaData['default']) ? ($metaData['default']['default'] ?? null) : $metaData['default'];
                } elseif (isset($metaData['meta']['default'])) {
                    $defaultValue = is_array($metaData['meta']['default']) ? ($metaData['meta']['default']['default'] ?? null) : $metaData['meta']['default'];
                } elseif (isset($metaData['attributes']['default'])) {
                    $defaultValue = is_array($metaData['attributes']['default']) ? ($metaData['attributes']['default']['default'] ?? null) : $metaData['attributes']['default'];
                }
            }
            
            // 处理不同的后缀
            if ($suffix === 'lang') {
                // .lang 后缀：返回翻译值
                if ($fieldValue === null) {
                    return null;
                }
                
                // 构建翻译键：@meta::{namespace}.{type}.{identify}.{field}
                $namespaceValue = $instance->meta->getData(Meta::schema_fields_NAMESPACE);
                $typeValue = $instance->meta->getData(Meta::schema_fields_META_TYPE);
                $identifyValue = $instance->meta->getData(Meta::schema_fields_META_IDENTIFY);
                $translationKey = "@meta::{$namespaceValue}.{$typeValue}.{$identifyValue}.{$field}";
                
                // 获取当前语言
                $currentLocale = $locale ?? Cookie::getLangLocal() ?? 'zh_Hans_CN';
                $defaultLocale = 'zh_Hans_CN';
                
                // 从I18n Dictionary获取翻译
                try {
                    /** @var LocaleDictionary $localeDict */
                    $localeDict = ObjectManager::getInstance(LocaleDictionary::class);
                    
                    // 先尝试当前语言
                    $md5 = LocaleDictionary::generateMd5($translationKey, $currentLocale);
                    $localeDict->load(LocaleDictionary::schema_fields_MD5, $md5);
                    
                    if ($localeDict->getId()) {
                        $translation = $localeDict->getData(LocaleDictionary::schema_fields_TRANSLATE);
                        if (!empty($translation)) {
                            return $translation;
                        }
                    }
                    
                    // 如果当前语言没有翻译，尝试默认语言
                    if ($currentLocale !== $defaultLocale) {
                        $md5Default = LocaleDictionary::generateMd5($translationKey, $defaultLocale);
                        $localeDict->load(LocaleDictionary::schema_fields_MD5, $md5Default);
                        
                        if ($localeDict->getId()) {
                            $translation = $localeDict->getData(LocaleDictionary::schema_fields_TRANSLATE);
                            if (!empty($translation)) {
                                return $translation;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // 如果获取翻译失败，返回原始值
                }
                
                // 如果没有翻译，返回原始值
                return $fieldValue;
            } elseif ($suffix === 'value') {
                // .value 后缀：优先返回 default，否则返回字段原始值
                if ($defaultValue !== null) {
                    return $defaultValue;
                } else {
                    return $fieldValue;
                }
            } else {
                // 无后缀：直接返回字段原始值
                return $fieldValue;
            }
        }
        
        // 检查是否是 .value 格式（读取配置值）
        if (preg_match('/^(.+)\.value$/', $identify, $matches)) {
            $baseIdentify = $matches[1]; // 如 theme.frontend.partials.header
            
            // 从 MetaConfig 表获取配置值（支持语言参数）
            try {
                /** @var \Weline\Meta\Model\MetaConfig $metaConfig */
                $metaConfig = ObjectManager::getInstance(\Weline\Meta\Model\MetaConfig::class);
                
                // 如果指定了语言，使用指定语言；否则使用 getConfigByIdentify 的默认语言回退逻辑
                if ($locale !== null) {
                    // 解析 identify 获取主题ID和命名空间
                    $parts = explode('.', $baseIdentify);
                    if (count($parts) >= 3) {
                        $namespacePrefix = $parts[0];
                        $area = $parts[1] ?? 'frontend';
                        $configKey = implode('.', array_slice($parts, 2));
                        $namespace = "{$namespacePrefix}.{$area}";
                        
                        // 获取主题ID
                        try {
                            /** @var \Weline\Theme\Service\ThemeContextService $themeContext */
                            $themeContext = ObjectManager::getInstance(\Weline\Theme\Service\ThemeContextService::class);
                            $theme = $themeContext->resolveTheme($area);
                            
                            if ($theme && $theme->getId()) {
                                $configValue = $metaConfig->getConfig($theme->getId(), $namespace, $configKey, 'default', $locale);
                                if ($configValue !== null) {
                                    return $configValue;
                                }
                            }
                        } catch (\Exception $e) {
                            // 如果获取主题失败，继续使用默认逻辑
                        }
                    }
                }
                
                // 使用默认语言回退逻辑
                $configValue = $metaConfig->getConfigByIdentify($baseIdentify, 'value', $locale);
                
                if ($configValue !== null) {
                    // 直接返回配置值（字符串）
                    return $configValue;
                }
            } catch (\Exception $e) {
                // 如果获取配置失败，返回 null
                return null;
            }
            return null;
        }
        
        // 检查是否是 .info 格式（读取元数据信息）
        $infoPath = null;
        if (preg_match('/^(.+)\.info\.(.+)$/', $identify, $matches)) {
            $metaIdentify = $matches[1]; // 如 theme.backend.components
            $infoPath = $matches[2]; // 如 info.name 或 group.field
            
            // 加载元数据
            $instance = new static();
            $cacheKey = self::buildCacheKey($metaIdentify, $namespace, $type);
            
            // 检查静态缓存
            if (isset(self::$metaCache[$cacheKey])) {
                $cached = self::$metaCache[$cacheKey];
                $instance->meta = $cached['meta'];
                $instance->setting = $cached['setting'];
                $instance->labelsTranslated = $cached['labelsTranslated'] ?? false;
            } else {
                // 尝试从性能缓存中获取（如果当前实例有 performanceKey）
                $meta = null;
                $setting = [];
                
                // 检查所有 performanceCache，看是否有匹配的 metaIdentify
                foreach (self::$performanceCache as $perfKey => $perfData) {
                    if (isset($perfData['metaRecords'][$metaIdentify])) {
                        $meta = $perfData['metaRecords'][$metaIdentify]['meta'];
                        $setting = $perfData['metaRecords'][$metaIdentify]['setting'];
                        $instance->currentPerformanceKey = $perfKey;
                        break;
                    }
                }
                
                if ($meta === null) {
                    // 从 Meta 表查询
                    /** @var Meta $meta */
                    $meta = ObjectManager::getInstance(Meta::class);
                    $meta->where(Meta::schema_fields_META_IDENTIFY, $metaIdentify);
                    
                    if ($namespace) {
                        $meta->where(Meta::schema_fields_NAMESPACE, $namespace);
                    }
                    if ($type) {
                        $meta->where(Meta::schema_fields_META_TYPE, $type);
                    }
                    
                    $meta->find()->fetch();
                    
                    if ($meta->getId()) {
                        $settingJson = $meta->getData(Meta::schema_fields_SETTING);
                        if ($settingJson) {
                            $setting = json_decode($settingJson, true) ?? [];
                        }
                    }
                }
                
                if ($meta && $meta->getId()) {
                    $instance->meta = $meta;
                    $instance->setting = $setting;
                    
                    // 缓存 Meta 记录
                    self::$metaCache[$cacheKey] = [
                        'meta' => $meta,
                        'setting' => $setting,
                        'labelsTranslated' => false
                    ];
                } else {
                    $instance->setting = [];
                    self::$metaCache[$cacheKey] = [
                        'meta' => null,
                        'setting' => [],
                        'labelsTranslated' => false
                    ];
                }
            }
            
            // 如果成功加载元数据，直接返回信息值
            if ($instance->isLoaded()) {
                $labelValue = $instance->getLabel($infoPath);
                if ($labelValue !== null) {
                    return $labelValue;
                }
            }
            return null;
        }
        
        // 检查是否是 .lang 格式（读取翻译值）
        if (preg_match('/^(.+)\.lang$/', $identify, $matches)) {
            $translationKey = $matches[1]; // 如 theme.layout.account_auth.info.name
            
            // 如果翻译键不是以 @meta:: 开头，自动添加
            if (!str_starts_with($translationKey, '@meta::')) {
                $translationKey = '@meta::' . $translationKey;
            }
            
            // 验证翻译键格式：@meta::namespace.type.identify.group.field（至少5部分）
            if (preg_match('/^@meta::(.+)$/', $translationKey, $keyMatches)) {
                $keyParts = explode('.', $keyMatches[1]);
                if (count($keyParts) >= 5) {
                    // 获取当前语言
                    $locale = Cookie::getLangLocal() ?? 'zh_Hans_CN';
                    $defaultLocale = 'zh_Hans_CN';
                    
                    // 从I18n Dictionary获取翻译
                    try {
                        /** @var LocaleDictionary $localeDict */
                        $localeDict = ObjectManager::getInstance(LocaleDictionary::class);
                        
                        // 先尝试当前语言
                        $md5 = LocaleDictionary::generateMd5($translationKey, $locale);
                        $localeDict->load(LocaleDictionary::schema_fields_MD5, $md5);
                        
                        if ($localeDict->getId()) {
                            $translation = $localeDict->getData(LocaleDictionary::schema_fields_TRANSLATE);
                            if (!empty($translation)) {
                                return $translation;
                            }
                        }
                        
                        // 如果当前语言没有翻译，尝试默认语言
                        if ($locale !== $defaultLocale) {
                            $md5Default = LocaleDictionary::generateMd5($translationKey, $defaultLocale);
                            $localeDict->load(LocaleDictionary::schema_fields_MD5, $md5Default);
                            
                            if ($localeDict->getId()) {
                                $translation = $localeDict->getData(LocaleDictionary::schema_fields_TRANSLATE);
                                if (!empty($translation)) {
                                    return $translation;
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        // 如果获取翻译失败，返回 null
                    }
                }
            }
            return null;
        }
        
        // 其他格式，返回 MetaData 对象
        $instance = new static();
        
        // 构建缓存键
        $cacheKey = self::buildCacheKey($identify, $namespace, $type);
        
        // 检查静态缓存
        if (isset(self::$metaCache[$cacheKey])) {
            $cached = self::$metaCache[$cacheKey];
            $instance->meta = $cached['meta'];
            $instance->setting = $cached['setting'];
            $instance->labelsTranslated = $cached['labelsTranslated'] ?? false;
            return $instance;
        }
        
        // 尝试从性能缓存中获取（如果当前实例有 performanceKey）
        $meta = null;
        $setting = [];
        
        // 检查所有 performanceCache，看是否有匹配的 metaIdentify
        foreach (self::$performanceCache as $perfKey => $perfData) {
            if (isset($perfData['metaRecords'][$identify])) {
                $meta = $perfData['metaRecords'][$identify]['meta'];
                $setting = $perfData['metaRecords'][$identify]['setting'];
                $instance->currentPerformanceKey = $perfKey;
                break;
            }
        }
        
        if ($meta === null) {
            // 原有的 Meta 表查询逻辑
            /** @var Meta $meta */
            $meta = ObjectManager::getInstance(Meta::class);
            $meta->where(Meta::schema_fields_META_IDENTIFY, $identify);
            
            if ($namespace) {
                $meta->where(Meta::schema_fields_NAMESPACE, $namespace);
            }
            if ($type) {
                $meta->where(Meta::schema_fields_META_TYPE, $type);
            }
            
            $meta->find()->fetch();
            
            if ($meta->getId()) {
                $settingJson = $meta->getData(Meta::schema_fields_SETTING);
                if ($settingJson) {
                    $setting = json_decode($settingJson, true) ?? [];
                }
            }
        }
        
        if ($meta && $meta->getId()) {
            $instance->meta = $meta;
            $instance->setting = $setting;
            
            // 延迟翻译：不在这里翻译，只在需要时翻译（通过 getLabel 或访问 meta_data 时）
            // 这样可以避免不必要的翻译操作
            
            // 缓存 Meta 记录（不包含翻译后的 meta_data，延迟加载）
            self::$metaCache[$cacheKey] = [
                'meta' => $meta,
                'setting' => $setting,
                'labelsTranslated' => false
            ];
        } else {
            $instance->setting = [];
            // 缓存空结果，避免重复查询
            self::$metaCache[$cacheKey] = [
                'meta' => null,
                'setting' => [],
                'labelsTranslated' => false
            ];
        }
        
        return $instance;
    }
    
    /**
     * 根据文件路径加载 Meta 记录
     * 
     * @param string $filePath 文件路径（如 Weline_Theme::theme/frontend/layouts/default/default.phtml）
     * @return static
     */
    public static function loadByFilePath(string $filePath): static
    {
        $instance = new static();
        
        // 检查文件路径缓存
        if (isset(self::$filePathCache[$filePath])) {
            $identify = self::$filePathCache[$filePath];
            return self::load($identify);
        }
        
        /** @var Meta $meta */
        $meta = ObjectManager::getInstance(Meta::class);
        $meta->loadByFilePath($filePath);
        
        if ($meta->getId()) {
            $identify = $meta->getData(Meta::schema_fields_META_IDENTIFY);
            // 缓存文件路径映射
            if ($identify) {
                self::$filePathCache[$filePath] = $identify;
            }
            
            $instance->meta = $meta;
            $settingJson = $meta->getData(Meta::schema_fields_SETTING);
            if ($settingJson) {
                $instance->setting = json_decode($settingJson, true) ?? [];
            } else {
                $instance->setting = [];
            }
            
            // 延迟翻译：不在这里翻译，只在需要时翻译
            // 缓存 Meta 记录
            if ($identify) {
                $cacheKey = self::buildCacheKey($identify, null, null);
                self::$metaCache[$cacheKey] = [
                    'meta' => $meta,
                    'setting' => $instance->setting,
                    'labelsTranslated' => false
                ];
            }
        } else {
            $instance->setting = [];
        }
        
        return $instance;
    }
    
    /**
     * 获取 setting 中的值
     * 
     * 支持两种格式：
     * 1. 完整路径：getSetting('param.title') - 从 setting.param.title 获取
     * 2. 简化路径：getSetting('title') - 自动从 setting.param.title 获取（如果 param 存在）
     * 
     * @param string $key 键名，支持点分隔的嵌套路径
     * @param mixed $default 默认值
     * @return mixed
     */
    public function getSetting(string $key, $default = null)
    {
        // 如果访问 meta_data，确保标签已翻译（延迟加载）
        if (strpos($key, 'meta_data') === 0) {
            $this->ensureLabelsTranslated();
        }
        
        if (empty($this->setting)) {
            return $default;
        }
        
        // 如果 key 以 param. 开头，直接查找
        if (strpos($key, 'param.') === 0) {
            $path = explode('.', $key);
            $value = $this->setting;
            foreach ($path as $part) {
                if (isset($value[$part])) {
                    $value = $value[$part];
                } else {
                    return $default;
                }
            }
            return $value;
        }
        
        // 如果 key 不以 param. 开头，尝试从 param 中查找
        if (isset($this->setting['param'])) {
            $path = explode('.', $key);
            $value = $this->setting['param'];
            foreach ($path as $part) {
                if (isset($value[$part])) {
                    $value = $value[$part];
                } else {
                    return $default;
                }
            }
            return $value;
        }
        
        // 如果 param 不存在，尝试直接从 setting 根级别查找
        $path = explode('.', $key);
        $value = $this->setting;
        foreach ($path as $part) {
            if (isset($value[$part])) {
                $value = $value[$part];
            } else {
                return $default;
            }
        }
        return $value;
    }
    
    /**
     * 获取所有 setting 数据
     * 
     * @return array
     */
    public function getAll(): array
    {
        // 如果访问了 getAll，确保标签已翻译（延迟加载）
        $this->ensureLabelsTranslated();
        return $this->setting ?? [];
    }

    /**
     * 获取翻译后的标签信息
     * 
     * @param string $key 标签键（如 'meta_data.info.name' 或 'info.name'）
     * @param mixed $default 默认值
     * @return mixed 翻译后的标签值
     */
    public function getLabel(string $key, $default = null)
    {
        // 确保标签已翻译
        $this->ensureLabelsTranslated();
        
        // 如果 key 以 meta_data. 开头，从 meta_data 中获取
        if (strpos($key, 'meta_data.') === 0) {
            $path = explode('.', $key);
            array_shift($path); // 移除 'meta_data'
            $value = $this->setting['meta_data'] ?? [];
            foreach ($path as $part) {
                if (isset($value[$part])) {
                    $value = $value[$part];
                } else {
                    return $default;
                }
            }
            return $value;
        }
        
        // 如果 key 不以 meta_data. 开头，尝试从 meta_data 中查找
        if (isset($this->setting['meta_data'])) {
            $path = explode('.', $key);
            $value = $this->setting['meta_data'];
            foreach ($path as $part) {
                if (isset($value[$part])) {
                    $value = $value[$part];
                } else {
                    return $default;
                }
            }
            return $value;
        }
        
        return $default;
    }
    
    /**
     * 获取 Meta 模型实例
     * 
     * @return Meta|null
     */
    public function getMeta(): ?Meta
    {
        return $this->meta;
    }
    
    /**
     * 检查是否成功加载了 Meta 记录
     * 
     * @return bool
     */
    public function isLoaded(): bool
    {
        return $this->meta !== null && $this->meta->getId() !== null;
    }

    /**
     * 确保标签已翻译（延迟加载）
     */
    protected function ensureLabelsTranslated(): void
    {
        if ($this->labelsTranslated || !$this->meta || !$this->meta->getId()) {
            return;
        }
        
        $metaDataJson = $this->meta->getData(Meta::schema_fields_META_DATA);
        if ($metaDataJson) {
            $metaData = json_decode($metaDataJson, true) ?? [];
            $translatedMetaData = $this->translateMetaDataLabels($this->meta, $metaData);
            
            // 将翻译后的标签信息合并到 setting 中
            if (!empty($translatedMetaData)) {
                $this->setting = array_merge($this->setting, ['meta_data' => $translatedMetaData]);
            }
            
            $this->labelsTranslated = true;
            
            // 更新缓存
            $identify = $this->meta->getData(Meta::schema_fields_META_IDENTIFY);
            if ($identify) {
                $cacheKey = self::buildCacheKey($identify, null, null);
                if (isset(self::$metaCache[$cacheKey])) {
                    self::$metaCache[$cacheKey]['setting'] = $this->setting;
                    self::$metaCache[$cacheKey]['labelsTranslated'] = true;
                }
            }
        } else {
            $this->labelsTranslated = true;
        }
    }

    /**
     * 翻译 meta_data 中的标签信息（支持多语言，批量优化）
     * 
     * @param Meta $meta Meta 模型实例
     * @param array $metaData 元数据数组
     * @param string|null $locale 语言代码，如果为 null 则使用当前语言
     * @return array 翻译后的元数据数组
     */
    protected function translateMetaDataLabels(Meta $meta, array $metaData, ?string $locale = null): array
    {
        // 获取当前语言
        if ($locale === null) {
            $locale = Cookie::getLangLocal() ?? 'zh_Hans_CN';
        }
        
        // 默认语言
        $defaultLocale = 'zh_Hans_CN';
        
        // 获取 meta 的基本信息
        $namespace = $meta->getData(Meta::schema_fields_NAMESPACE);
        $type = $meta->getData(Meta::schema_fields_META_TYPE);
        $identify = $meta->getData(Meta::schema_fields_META_IDENTIFY);
        
        // 翻译标签字段（如 info.name, info.description）
        $translatedData = $metaData;
        
        // 收集所有需要翻译的键（批量优化）
        $translationKeys = [];
        $this->collectTranslationKeys($translatedData, $namespace, $type, $identify, '', $translationKeys);
        
        // 批量获取翻译
        $translations = $this->batchGetTranslations($translationKeys, $locale, $defaultLocale);
        
        // 应用翻译
        $this->applyTranslations($translatedData, $namespace, $type, $identify, '', $translations);
        
        return $translatedData;
    }

    /**
     * 收集所有需要翻译的键（批量优化）
     * 
     * @param array $data 数据数组
     * @param string $namespace 命名空间
     * @param string $type 类型
     * @param string $identify 标识
     * @param string $path 当前路径
     * @param array &$translationKeys 翻译键数组（引用传递）[translationKey => [path, key, value]]
     */
    protected function collectTranslationKeys(array $data, string $namespace, string $type, string $identify, string $path, array &$translationKeys): void
    {
        $labelFields = ['name', 'description', 'label', 'title', 'text'];
        
        foreach ($data as $key => $value) {
            // 跳过非字符串值
            if (!is_string($value) && !is_array($value)) {
                continue;
            }
            
            // 如果是数组，递归处理
            if (is_array($value)) {
                $currentPath = $path ? "{$path}.{$key}" : $key;
                $this->collectTranslationKeys($value, $namespace, $type, $identify, $currentPath, $translationKeys);
                continue;
            }
            
            // 如果是字符串，检查是否是标签字段
            if (in_array($key, $labelFields, true)) {
                // 构建翻译键：@meta::{namespace}.{type}.{identify}.{path}.{key}
                $translationKey = "@meta::{$namespace}.{$type}.{$identify}";
                if ($path) {
                    $translationKey .= ".{$path}";
                }
                $translationKey .= ".{$key}";
                
                $translationKeys[$translationKey] = [
                    'path' => $path,
                    'key' => $key,
                    'value' => $value
                ];
            }
        }
    }

    /**
     * 批量获取翻译（性能优化：减少数据库查询次数）
     * 
     * @param array $translationKeys 翻译键数组 [translationKey => [path, key, value]]
     * @param string $locale 当前语言
     * @param string $defaultLocale 默认语言
     * @return array 翻译结果 [translationKey => translatedValue]
     */
    protected function batchGetTranslations(array $translationKeys, string $locale, string $defaultLocale): array
    {
        if (empty($translationKeys)) {
            return [];
        }
        
        $translations = [];
        $missingKeys = [];
        
        // 先检查缓存
        foreach ($translationKeys as $translationKey => $info) {
            $md5 = LocaleDictionary::generateMd5($translationKey, $locale);
            
            // 检查当前语言缓存
            if (isset(self::$translationCache[$locale][$md5])) {
                $translations[$translationKey] = self::$translationCache[$locale][$md5];
                continue;
            }
            
            // 检查默认语言缓存
            if ($locale !== $defaultLocale) {
                $md5Default = LocaleDictionary::generateMd5($translationKey, $defaultLocale);
                if (isset(self::$translationCache[$defaultLocale][$md5Default])) {
                    $translations[$translationKey] = self::$translationCache[$defaultLocale][$md5Default];
                    continue;
                }
            }
            
            // 需要查询数据库
            $missingKeys[$translationKey] = [
                'md5' => $md5,
                'md5Default' => $locale !== $defaultLocale ? LocaleDictionary::generateMd5($translationKey, $defaultLocale) : null,
                'fallback' => $info['value']
            ];
        }
        
        // 批量查询数据库（只查询缓存中没有的）
        if (!empty($missingKeys)) {
            try {
                /** @var LocaleDictionary $localeDict */
                $localeDict = ObjectManager::getInstance(LocaleDictionary::class);
                
                // 收集所有需要查询的 MD5
                $md5sToQuery = [];
                foreach ($missingKeys as $translationKey => $info) {
                    $md5sToQuery[$info['md5']] = ['key' => $translationKey, 'locale' => $locale];
                    if ($info['md5Default']) {
                        $md5sToQuery[$info['md5Default']] = ['key' => $translationKey, 'locale' => $defaultLocale];
                    }
                }
                
                // 批量查询（使用 IN 查询）
                if (!empty($md5sToQuery)) {
                    $md5List = array_keys($md5sToQuery);
                    
                    // 使用 select() 和 fetchArray() 批量获取结果
                    $results = [];
                    try {
                        $queryResults = $localeDict->reset()
                            ->where(LocaleDictionary::schema_fields_MD5, $md5List, 'IN')
                            ->select()
                            ->fetchArray();
                        
                        // 处理查询结果
                        foreach ($queryResults as $row) {
                            $md5 = $row[LocaleDictionary::schema_fields_MD5] ?? '';
                            $localeCode = $row[LocaleDictionary::schema_fields_LOCALE_CODE] ?? '';
                            $translate = $row[LocaleDictionary::schema_fields_TRANSLATE] ?? '';
                            
                            if (!empty($md5) && !empty($translate)) {
                                $results[$md5] = [
                                    'locale' => $localeCode,
                                    'translate' => $translate
                                ];
                                
                                // 更新缓存
                                if (!isset(self::$translationCache[$localeCode])) {
                                    self::$translationCache[$localeCode] = [];
                                }
                                self::$translationCache[$localeCode][$md5] = $translate;
                            }
                        }
                    } catch (\Exception $e) {
                        // 查询失败，使用回退值
                    }
                    
                    // 应用查询结果
                    foreach ($missingKeys as $translationKey => $info) {
                        // 优先使用当前语言的翻译
                        if (isset($results[$info['md5']]) && $results[$info['md5']]['locale'] === $locale) {
                            $translations[$translationKey] = $results[$info['md5']]['translate'];
                        } elseif ($info['md5Default'] && isset($results[$info['md5Default']])) {
                            // 使用默认语言的翻译
                            $translations[$translationKey] = $results[$info['md5Default']]['translate'];
                        } else {
                            // 使用回退值
                            $translations[$translationKey] = $info['fallback'];
                        }
                    }
                }
            } catch (\Exception $e) {
                // 如果查询失败，使用回退值
                foreach ($missingKeys as $translationKey => $info) {
                    if (!isset($translations[$translationKey])) {
                        $translations[$translationKey] = $info['fallback'];
                    }
                }
            }
        }
        
        return $translations;
    }

    /**
     * 应用翻译到数据数组
     * 
     * @param array &$data 数据数组（引用传递）
     * @param string $namespace 命名空间
     * @param string $type 类型
     * @param string $identify 标识
     * @param string $path 当前路径
     * @param array $translations 翻译结果 [translationKey => translatedValue]
     */
    protected function applyTranslations(array &$data, string $namespace, string $type, string $identify, string $path, array $translations): void
    {
        $labelFields = ['name', 'description', 'label', 'title', 'text'];
        
        foreach ($data as $key => &$value) {
            // 跳过非字符串值
            if (!is_string($value) && !is_array($value)) {
                continue;
            }
            
            // 如果是数组，递归处理
            if (is_array($value)) {
                $currentPath = $path ? "{$path}.{$key}" : $key;
                $this->applyTranslations($value, $namespace, $type, $identify, $currentPath, $translations);
                continue;
            }
            
            // 如果是字符串，检查是否是标签字段
            if (in_array($key, $labelFields, true)) {
                // 构建翻译键
                $translationKey = "@meta::{$namespace}.{$type}.{$identify}";
                if ($path) {
                    $translationKey .= ".{$path}";
                }
                $translationKey .= ".{$key}";
                
                // 应用翻译
                if (isset($translations[$translationKey])) {
                    $value = $translations[$translationKey];
                }
            }
        }
    }

    /**
     * 构建缓存键
     * 
     * @param string $identify 标识
     * @param string|null $namespace 命名空间
     * @param string|null $type 类型
     * @return string 缓存键
     */
    protected static function buildCacheKey(string $identify, ?string $namespace, ?string $type): string
    {
        $parts = [$identify];
        if ($namespace) {
            $parts[] = $namespace;
        }
        if ($type) {
            $parts[] = $type;
        }
        return implode('|', $parts);
    }

    /**
     * 获取配置值（和 load() 方法一样的功能）
     * 
     * @param string $identify meta_identify（如 theme.frontend.partials.header.value）
     * @param string|null $namespace 命名空间（可选，用于精确匹配）
     * @param string|null $type 类型（可选，用于精确匹配）
     * @param string|null $locale 语言代码（可选，用于 .value 和 .lang 格式）
     * @return static|string|mixed
     *   - 如果是 .value 格式，直接返回配置值（字符串）
     *   - 如果是 .info 格式，直接返回信息值（字符串）
     *   - 如果是 .lang 格式，直接返回翻译值（字符串）
     *   - 其他格式，返回 MetaData 对象
     */
    public static function getValue(string $identify, ?string $namespace = null, ?string $type = null, ?string $locale = null)
    {
        return self::load($identify, $namespace, $type, $locale);
    }
    
    /**
     * 获取配置值（get() 方法的别名，和 load() 方法一样的功能）
     * 
     * @param string $identify meta_identify（如 theme.frontend.partials.header.value）
     * @param string|null $namespace 命名空间（可选，用于精确匹配）
     * @param string|null $type 类型（可选，用于精确匹配）
     * @param string|null $locale 语言代码（可选，用于 .value 和 .lang 格式）
     * @return static|string|mixed
     *   - 如果是 .value 格式，直接返回配置值（字符串）
     *   - 如果是 .info 格式，直接返回信息值（字符串）
     *   - 如果是 .lang 格式，直接返回翻译值（字符串）
     *   - 其他格式，返回 MetaData 对象
     */
    public static function get(string $identify, ?string $namespace = null, ?string $type = null, ?string $locale = null)
    {
        return self::load($identify, $namespace, $type, $locale);
    }
    
    /**
     * 设置配置值
     * 
     * @param string $identify meta_identify（如 theme.frontend.partials.header.value）
     * @param string $value 配置值
     * @param string $scope 作用域，默认 'default'
     * @param string|null $locale 语言代码，如果为 null 表示默认语言（通用配置）
     * @param int|null $themeId 主题ID，如果为 null 则自动获取当前激活的主题
     * @return bool 是否设置成功
     */
    public static function set(string $identify, string $value, string $scope = 'default', ?string $locale = null, ?int $themeId = null): bool
    {
        // 自动补全前缀
        $identify = self::normalizeIdentify($identify);
        
        // 检查是否是 .value 格式（设置配置值）
        if (preg_match('/^(.+)\.value$/', $identify, $matches)) {
            $baseIdentify = $matches[1]; // 如 theme.frontend.layouts.homepage
            
            // 解析 identify：theme.frontend.layouts.homepage
            $parts = explode('.', $baseIdentify);
            if (count($parts) < 3) {
                return false;
            }
            
            // 第一部分是命名空间前缀（theme）
            // 第二部分是区域（frontend/backend）
            // 剩余部分是配置键（如 layouts.homepage）
            $namespacePrefix = $parts[0]; // theme
            $area = $parts[1] ?? 'frontend'; // frontend 或 backend
            $configKey = implode('.', array_slice($parts, 2)); // layouts.homepage
            
            $namespace = "{$namespacePrefix}.{$area}";
            
            // 获取主题ID
            if ($themeId === null) {
                try {
                    /** @var \Weline\Theme\Service\ThemeContextService $themeContext */
                    $themeContext = ObjectManager::getInstance(\Weline\Theme\Service\ThemeContextService::class);
                    $theme = $themeContext->resolveTheme($area);
                    
                    if (!$theme || !$theme->getId()) {
                        return false;
                    }
                    
                    $themeId = $theme->getId();
                } catch (\Exception $e) {
                    return false;
                }
            }
            
            // 设置配置值
            try {
                // 查找对应的 Meta 记录，获取 meta_id 和 meta_identify
                $metaId = null;
                $metaIdentify = null;
                
                // 尝试根据 baseIdentify 查找 Meta 记录
                // 例如：theme.frontend.layouts.homepage -> 查找 theme.frontend.layouts.homepage 的 Meta 记录
                try {
                    /** @var \Weline\Meta\Model\Meta $metaModel */
                    $metaModel = ObjectManager::getInstance(\Weline\Meta\Model\Meta::class);
                    
                    // 先尝试精确匹配
                    $metaRecord = $metaModel->reset()
                        ->where(\Weline\Meta\Model\Meta::schema_fields_META_IDENTIFY, $baseIdentify)
                        ->find()
                        ->fetch();
                    
                    // 如果精确匹配失败，尝试查找父级 Meta 记录
                    // 例如：如果找不到 theme.frontend.layouts.homepage，尝试查找 theme.frontend.layouts
                    if (!$metaRecord || !$metaRecord->getId()) {
                        $parts = explode('.', $baseIdentify);
                        // 尝试查找父级（去掉最后一部分）
                        if (count($parts) > 3) {
                            $parentIdentify = implode('.', array_slice($parts, 0, -1));
                            $metaRecord = $metaModel->reset()
                                ->where(\Weline\Meta\Model\Meta::schema_fields_META_IDENTIFY, $parentIdentify)
                                ->find()
                                ->fetch();
                        }
                    }
                    
                    if ($metaRecord && $metaRecord->getId()) {
                        $metaId = (int)$metaRecord->getId();
                        $metaIdentify = $metaRecord->getData(\Weline\Meta\Model\Meta::schema_fields_META_IDENTIFY) ?: $baseIdentify;
                    }
                } catch (\Exception $e) {
                    // 如果查找失败，继续使用 identifyId 方式
                }
                
                /** @var \Weline\Meta\Model\MetaConfig $metaConfig */
                $metaConfig = ObjectManager::getInstance(\Weline\Meta\Model\MetaConfig::class);
                $metaConfig->setConfig($themeId, $namespace, $configKey, $value, $scope, $locale, $metaId, $metaIdentify);
                
                // 清除相关缓存
                self::clearCache();
                
                return true;
            } catch (\Exception $e) {
                return false;
            }
        }
        
        // 检查是否是 .lang 格式（设置翻译值）
        if (preg_match('/^(.+)\.lang$/', $identify, $matches)) {
            $translationKey = $matches[1]; // 如 theme.layout.account_auth.info.name
            
            // 如果翻译键不是以 @meta:: 开头，自动添加
            if (!str_starts_with($translationKey, '@meta::')) {
                $translationKey = '@meta::' . $translationKey;
            }
            
            // 验证翻译键格式
            if (preg_match('/^@meta::(.+)$/', $translationKey, $keyMatches)) {
                $keyParts = explode('.', $keyMatches[1]);
                if (count($keyParts) >= 5) {
                    // 获取语言代码
                    if ($locale === null) {
                        $locale = Cookie::getLangLocal() ?? 'zh_Hans_CN';
                    }
                    
                    // 设置翻译值
                    try {
                        /** @var LocaleDictionary $localeDict */
                        $localeDict = ObjectManager::getInstance(LocaleDictionary::class);
                        
                        $md5 = LocaleDictionary::generateMd5($translationKey, $locale);
                        $localeDict->load(LocaleDictionary::schema_fields_MD5, $md5);
                        
                        if ($localeDict->getId()) {
                            // 更新
                            $localeDict->setData(LocaleDictionary::schema_fields_TRANSLATE, $value)
                                      ->save();
                        } else {
                            // 插入
                            $localeDict->setData(LocaleDictionary::schema_fields_MD5, $md5)
                                      ->setData(LocaleDictionary::schema_fields_WORD, $translationKey)
                                      ->setData(LocaleDictionary::schema_fields_LOCALE_CODE, $locale)
                                      ->setData(LocaleDictionary::schema_fields_TRANSLATE, $value)
                                      ->save();
                        }
                        
                        // 清除翻译缓存
                        if (isset(self::$translationCache[$locale])) {
                            unset(self::$translationCache[$locale][$md5]);
                        }
                        
                        return true;
                    } catch (\Exception $e) {
                        return false;
                    }
                }
            }
            return false;
        }
        
        // 其他格式不支持直接设置，返回 false
        return false;
    }
    
    /**
     * 规范化 identify，自动补全前缀
     * 如果键名不包含 frontend/backend，自动根据当前请求判断并添加
     * 
     * @param string $identify 原始 identify
     * @return string 规范化后的 identify
     */
    protected static function normalizeIdentify(string $identify): string
    {
        // 如果已经包含 theme. 前缀，检查是否包含 frontend/backend
        if (str_starts_with($identify, 'theme.')) {
            // 检查是否包含 frontend 或 backend
            if (preg_match('/^theme\.(frontend|backend)\./', $identify)) {
                // 已经包含 frontend 或 backend，直接返回
                return $identify;
            }
            
            // 包含 theme. 但不包含 frontend/backend，自动添加
            $area = State::isBackend() ? 'backend' : 'frontend';
            // 去掉 theme. 前缀，添加 area
            $rest = substr($identify, 6); // 去掉 'theme.'
            return "theme.{$area}.{$rest}";
        }
        
        // 如果不包含 theme. 前缀，检查是否包含 frontend/backend
        if (preg_match('/^(frontend|backend)\./', $identify)) {
            // 包含 frontend 或 backend，添加 theme. 前缀
            return "theme.{$identify}";
        }
        
        // 既不包含 theme. 也不包含 frontend/backend，自动添加
        $area = State::isBackend() ? 'backend' : 'frontend';
        return "theme.{$area}.{$identify}";
    }
    
    /**
     * 性能预加载：一次性加载指定 namespace 和 meta_identify 范围内的所有 Meta 记录和 MetaConfig 记录
     * 查询的数据会持久化在 MetaData 的实例上，后续如果再次 performanceLoad 相同 key 可以直接从实例上获取数据
     * 
     * @param string|null $key 缓存 key，如果为 null 则根据条件自动生成
     * @param string|null $namespace 命名空间（如 theme.frontend）
     * @param string|null $metaIdentify Meta标识（如 theme.frontend.layouts.default，支持通配符）
     * @param string|null $scope 作用域（可选）
     * @param string|null $locale 语言代码（可选）
     * @return static MetaData 实例
     */
    public static function performanceLoad(?string $key = null, ?string $namespace = null, ?string $metaIdentify = null, ?string $scope = null, ?string $locale = null): static
    {
        $instance = new static();
        
        // 自动生成 key
        if ($key === null) {
            $keyParts = array_filter([$namespace, $metaIdentify, $scope, $locale]);
            $key = md5(implode('.', $keyParts));
        }
        
        // 检查是否已有缓存
        if (isset(self::$performanceCache[$key])) {
            $instance->currentPerformanceKey = $key;
            return $instance;
        }
        
        // 查询 Meta 记录
        $metaRecords = [];
        if ($namespace || $metaIdentify) {
            /** @var Meta $metaModel */
            $metaModel = ObjectManager::getInstance(Meta::class);
            $query = $metaModel->reset();
            
            if ($namespace) {
                $query->where(Meta::schema_fields_NAMESPACE, $namespace);
            }
            
            if ($metaIdentify) {
                // 支持通配符：如果 metaIdentify 以 * 结尾，使用 LIKE 查询
                if (str_ends_with($metaIdentify, '*')) {
                    $pattern = rtrim($metaIdentify, '*');
                    $query->where(Meta::schema_fields_META_IDENTIFY, $pattern . '%', 'LIKE');
                } else {
                    $query->where(Meta::schema_fields_META_IDENTIFY, $metaIdentify);
                }
            }
            
            $metaList = $query->select()->fetchArray();
            foreach ($metaList as $meta) {
                $metaIdentifyValue = $meta->getData(Meta::schema_fields_META_IDENTIFY);
                $metaRecords[$metaIdentifyValue] = [
                    'meta' => $meta,
                    'setting' => json_decode($meta->getData(Meta::schema_fields_SETTING) ?? '{}', true) ?? []
                ];
            }
        }
        
        // 查询 MetaConfig 记录
        $metaConfigs = [];
        if ($namespace || $metaIdentify) {
            /** @var \Weline\Meta\Model\MetaConfig $metaConfigModel */
            $metaConfigModel = ObjectManager::getInstance(\Weline\Meta\Model\MetaConfig::class);
            $query = $metaConfigModel->reset();
            
            if ($namespace) {
                $query->where(\Weline\Meta\Model\MetaConfig::schema_fields_NAMESPACE, $namespace);
            }
            
            if ($metaIdentify) {
                // 支持通配符
                if (str_ends_with($metaIdentify, '*')) {
                    $pattern = rtrim($metaIdentify, '*');
                    $query->where(\Weline\Meta\Model\MetaConfig::schema_fields_META_IDENTIFY, $pattern . '%', 'LIKE');
                } else {
                    $query->where(\Weline\Meta\Model\MetaConfig::schema_fields_META_IDENTIFY, $metaIdentify);
                }
            }
            
            if ($scope) {
                $query->where(\Weline\Meta\Model\MetaConfig::schema_fields_SCOPE, $scope);
            }
            
            if ($locale) {
                $query->where(\Weline\Meta\Model\MetaConfig::schema_fields_LOCALE, $locale);
            }
            
            $configList = $query->select()->fetchArray();
            foreach ($configList as $config) {
                $configKey = $config->getData(\Weline\Meta\Model\MetaConfig::schema_fields_CONFIG_KEY);
                $metaIdentifyValue = $config->getData(\Weline\Meta\Model\MetaConfig::schema_fields_META_IDENTIFY);
                $configScope = $config->getData(\Weline\Meta\Model\MetaConfig::schema_fields_SCOPE) ?? 'default';
                $configLocale = $config->getData(\Weline\Meta\Model\MetaConfig::schema_fields_LOCALE);
                
                if (!isset($metaConfigs[$metaIdentifyValue])) {
                    $metaConfigs[$metaIdentifyValue] = [];
                }
                if (!isset($metaConfigs[$metaIdentifyValue][$configKey])) {
                    $metaConfigs[$metaIdentifyValue][$configKey] = [];
                }
                if (!isset($metaConfigs[$metaIdentifyValue][$configKey][$configScope])) {
                    $metaConfigs[$metaIdentifyValue][$configKey][$configScope] = [];
                }
                
                $metaConfigs[$metaIdentifyValue][$configKey][$configScope][$configLocale ?? ''] = $config->getData(\Weline\Meta\Model\MetaConfig::schema_fields_CONFIG_VALUE);
            }
        }
        
        // 存储到缓存
        self::$performanceCache[$key] = [
            'metaRecords' => $metaRecords,
            'metaConfigs' => $metaConfigs,
            'namespace' => $namespace,
            'metaIdentify' => $metaIdentify,
            'scope' => $scope,
            'locale' => $locale
        ];
        
        $instance->currentPerformanceKey = $key;
        return $instance;
    }
    
    /**
     * 切换性能缓存的 key
     * 
     * @param string $key 缓存 key
     * @return static 当前实例
     */
    public function switchPerformanceKey(string $key): static
    {
        if (isset(self::$performanceCache[$key])) {
            $this->currentPerformanceKey = $key;
        }
        return $this;
    }
    
    /**
     * 从性能缓存中获取 Meta 记录
     * 
     * @param string $metaIdentify Meta标识
     * @return Meta|null
     */
    protected function getMetaFromPerformanceCache(string $metaIdentify): ?Meta
    {
        if (!$this->currentPerformanceKey || !isset(self::$performanceCache[$this->currentPerformanceKey])) {
            return null;
        }
        
        $cache = self::$performanceCache[$this->currentPerformanceKey];
        return $cache['metaRecords'][$metaIdentify]['meta'] ?? null;
    }
    
    /**
     * 从性能缓存中获取 MetaConfig 值
     * 
     * @param string $metaIdentify Meta标识
     * @param string $configKey 配置键
     * @param string $scope 作用域
     * @param string|null $locale 语言代码
     * @return string|null
     */
    protected function getConfigFromPerformanceCache(string $metaIdentify, string $configKey, string $scope = 'default', ?string $locale = null): ?string
    {
        if (!$this->currentPerformanceKey || !isset(self::$performanceCache[$this->currentPerformanceKey])) {
            return null;
        }
        
        $cache = self::$performanceCache[$this->currentPerformanceKey];
        $metaConfigs = $cache['metaConfigs'] ?? [];
        
        if (!isset($metaConfigs[$metaIdentify][$configKey][$scope])) {
            return null;
        }
        
        $configs = $metaConfigs[$metaIdentify][$configKey][$scope];
        
        // 优先返回指定语言的配置
        if ($locale !== null && isset($configs[$locale])) {
            return $configs[$locale];
        }
        
        // 回退到默认语言
        $defaultLocale = \Weline\Framework\Http\Cookie::getLang() ?? 'zh_Hans_CN';
        if (isset($configs[$defaultLocale])) {
            return $configs[$defaultLocale];
        }
        
        // 回退到空语言（通用配置）
        if (isset($configs[''])) {
            return $configs[''];
        }
        
        return null;
    }
    
    /**
     * 清除静态缓存（用于测试或手动清除）
     */
    public static function clearCache(): void
    {
        self::$metaCache = [];
        self::$filePathCache = [];
        self::$translationCache = [];
        self::$translationKeysBatch = [];
        self::$batchInitialized = false;
        self::$performanceCache = [];
    }
}

