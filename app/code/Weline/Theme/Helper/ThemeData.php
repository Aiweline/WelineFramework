<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Helper;

use Weline\Framework\App\State;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Locale\Dictionary;
use Weline\Meta\Helper\MetaData;
use Weline\Meta\Model\MetaConfig;
use Weline\Theme\Model\WelineTheme;
use Weline\Widget\Ui\ParamType\AbstractParamType;

/**
 * ThemeData 静态类
 * 
 * 统一管理Theme模块对Meta模块的调用
 * 内部统一使用MetaData获取值，不直接调用MetaConfig
 * 利用MetaData的performanceLoad方法优化性能
 * 
 * 使用示例：
 * // 获取配置值
 * $value = ThemeData::get('partials.header.value');
 * 
 * // 设置配置值
 * ThemeData::set('partials.header.value', 'minimal');
 * 
 * // 获取Meta数据（从缓存，返回数组）
 * $metaArr = ThemeData::getMeta('theme.frontend.layouts.default');
 * // 返回：['meta_id' => 1, 'meta_identify' => '...', 'meta_data' => [...], 'setting' => [...], ...]
 */
class ThemeData
{
    /** @var WelineTheme|null 当前主题 */
    private static ?WelineTheme $currentTheme = null;
    
    /** @var string|null 当前区域 */
    private static ?string $currentArea = null;
    
    /** @var string|null 性能缓存key */
    private static ?string $performanceKey = null;
    
    /** @var bool 是否已初始化 */
    private static bool $initialized = false;
    
    /** @var bool 是否正在加载性能缓存（防止循环调用） */
    private static bool $performanceLoading = false;

    /** @var array 性能缓存：按 key 存储预加载的数据 [key => [metaRecords => [], metaConfigs => [], namespace => '', metaIdentify => '', scope => '', locale => '']] */
    private static array $performanceCache = [];
    
    /**
     * 自动初始化（延迟加载），使用performanceLoad预加载配置
     */
    private static function ensureInitialized(): void
    {
        if (self::$initialized) {
            return;
        }
        
        try {
            // 获取当前主题
            if (self::$currentTheme === null) {
                /** @var WelineTheme $theme */
                $theme = ObjectManager::getInstance(WelineTheme::class);
                self::$currentTheme = $theme->getActiveTheme();
            }
            
            // 自动识别区域
            if (self::$currentArea === null) {
                self::$currentArea = State::isBackend() ? 'backend' : 'frontend';
            }
            
            // 如果主题存在且不在加载中，预加载配置（防止循环调用）
            if (self::$currentTheme && self::$currentTheme->getId() && !self::$performanceLoading) {
                self::performanceLoad();
            }
            
            self::$initialized = true;
        } catch (\Exception $e) {
            // 初始化失败，继续执行但不预加载
            self::$initialized = true;
        }
    }
    
    /**
     * 主要接口：获取配置值
     * 
     * 优先从本地缓存读取，避免触发额外的数据库查询
     * 
     * @param string $identify meta_identify（如 partials.header.value 或 theme.frontend.layouts.default.value）
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function get(string $identify, $default = null)
    {
        self::ensureInitialized();
        
        // 规范化identify（自动补全前缀）
        $identify = self::normalizeIdentify($identify);
        
        // 优先从本地缓存读取配置值
        // 检查是否是 .value 格式
        if (preg_match('/^(.+)\.value$/', $identify, $matches)) {
            $baseIdentify = $matches[1]; // 如 theme.frontend.partials.header
            
            // 从 baseIdentify 解析出 configKey
            // theme.frontend.partials.header -> partials.header
            $parts = explode('.', $baseIdentify);
            if (count($parts) >= 3 && $parts[0] === 'theme') {
                // 移除 theme.{area} 前缀
                $configKey = implode('.', array_slice($parts, 2)) . '.value';
                
                // 从 performanceCache 中查找
                if (self::$performanceKey && isset(self::$performanceCache[self::$performanceKey])) {
                    $themeConfigs = self::$performanceCache[self::$performanceKey];
                    if (isset($themeConfigs[$configKey])) {
                        return $themeConfigs[$configKey];
                    }
                }
            }
        }
        
        // 如果缓存中没有，回退到 MetaData::get()
        $result = MetaData::get($identify);
        
        return $result !== null ? $result : $default;
    }
    
    /**
     * 主要接口：设置配置值（统一使用MetaData）
     * 
     * @param string $identify meta_identify（如 partials.header.value）
     * @param string $value 配置值
     * @param string $scope 作用域，默认 'default'
     * @param string|null $locale 语言代码，如果为 null 表示默认语言（通用配置）
     * @return bool 是否设置成功
     */
    public static function set(string $identify, string $value, string $scope = 'default', ?string $locale = null): bool
    {
        self::ensureInitialized();
        
        // 规范化identify（自动补全前缀）
        $identify = self::normalizeIdentify($identify);
        
        // 获取主题ID
        $themeId = null;
        if (self::$currentTheme && self::$currentTheme->getId()) {
            $themeId = self::$currentTheme->getId();
        }
        
        // 统一使用MetaData设置值
        $result = MetaData::set($identify, $value, $scope, $locale, $themeId);
        
        // 清除缓存
        if ($result) {
            self::clearCache();
        }
        
        return $result;
    }
    
    /**
     * 获取Meta数据对象（用于获取完整meta信息）
     * 
     * 优先从缓存中查找，如果缓存中没有则返回null
     * 不再触发额外的数据库查询
     * 
     * @param string $identify meta_identify（如 theme.frontend.layouts.default）
     * @return array|null 返回缓存的meta数据数组，包含 meta_id, meta_identify, meta_data, setting 等
     */
    public static function getMeta(string $identify): ?array
    {
        self::ensureInitialized();
        
        // 规范化identify（自动补全前缀）
        $identify = self::normalizeIdentify($identify);
        
        // 先从单条缓存中查找
        $cacheKey = "meta_single_{$identify}";
        if (isset(self::$performanceCache[$cacheKey])) {
            return self::$performanceCache[$cacheKey];
        }
        
        // 解析 identify 提取 area 和 type
        // 格式：theme.{area}.{type}.{rest}
        $parts = explode('.', $identify);
        if (count($parts) < 4 || $parts[0] !== 'theme') {
            return null;
        }
        
        $area = $parts[1]; // frontend 或 backend
        $type = $parts[2]; // layouts, colors, variables, components, partials
        
        // 从已缓存的 MetaList 中查找
        $metaList = self::getMetaList($area, $type);
        
        foreach ($metaList as $meta) {
            if (($meta['meta_identify'] ?? '') === $identify) {
                // 缓存单条记录
                self::$performanceCache[$cacheKey] = $meta;
                return $meta;
            }
        }
        
        return null;
    }
    
    /**
     * 快速获取文件的参数定义和配置值
     * 从 Meta 表的 setting 字段中读取参数定义，并获取每个参数的配置值
     * 
     * @param string $identify meta_identify（如 layouts.account.dashboard 或 theme.frontend.layouts.account.dashboard）
     * @return array 参数数组，键为参数名，值为配置值（或默认值）
     */
    public static function getFileParams(string $identify, string $scope = 'default', ?string $locale = null): array
    {
        return self::getParamValues($identify, $scope, $locale);
    }

    /**
     * 获取参数定义（结构信息，不含值）
     *
     * @param string $identify meta_identify（如 layouts.account）
     * @return array
     */
    public static function getParamDefinitions(string $identify): array
    {
        self::ensureInitialized();
        $identify = self::normalizeIdentify($identify);

        $metaData = self::getMeta($identify);
        if (!$metaData) {
            return [];
        }

        $params = $metaData['setting']['param'] ?? [];
        if (empty($params) || !is_array($params)) {
            return [];
        }

        $definitions = [];
        foreach ($params as $name => $definition) {
            if (!is_array($definition)) {
                $definition = ['default' => $definition];
            }
            $definitions[$name] = [
                'name' => $definition['name'] ?? $name,
                'description' => $definition['description'] ?? '',
                'default' => $definition['default'] ?? null,
                'translate' => !empty($definition['translate']) || !empty($definition['translatable']),
                'input' => $definition['input'] ?? $definition['type'] ?? 'text',
                'options' => $definition['options'] ?? $definition['option'] ?? [],
                'meta' => $definition,
            ];
        }

        return $definitions;
    }

    /**
     * 获取指定 scope/locale 下的参数值集合
     *
     * @param string $identify meta_identify（如 layouts.account）
     * @param string $scope    作用域，默认 default
     * @param string|null $locale 语言代码，null 时使用当前语言
     * @return array
     */
    public static function getParamValues(string $identify, string $scope = 'default', ?string $locale = null): array
    {
        self::ensureInitialized();
        $identify = self::normalizeIdentify($identify);

        $definitions = self::getParamDefinitions($identify);
        if (empty($definitions)) {
            return [];
        }

        $values = [];
        foreach ($definitions as $paramName => $definition) {
            $defaultValue = $definition['default'] ?? null;
            $isTranslatable = !empty($definition['translate']);

            if ($isTranslatable) {
                $values[$paramName] = self::getParamTranslation(
                    $identify,
                    $paramName,
                    $scope,
                    $locale,
                    is_scalar($defaultValue) ? (string)$defaultValue : null
                );
                continue;
            }

            [$namespace, $configKey] = self::resolveNamespaceAndConfigKey($identify, "param.{$paramName}");

            /** @var MetaConfig $metaConfig */
            $metaConfig = ObjectManager::getInstance(MetaConfig::class);
            $themeId = self::$currentTheme?->getId();
            $resolvedLocale = $locale ?? (Cookie::getLang() ?? 'zh_Hans_CN');

            $value = null;
            if ($themeId) {
                $value = $metaConfig->getConfig($themeId, $namespace, $configKey, $scope, $resolvedLocale);
            }

            if ($value === null && is_scalar($defaultValue)) {
                $value = (string)$defaultValue;
            }

            $values[$paramName] = $value;
        }

        return $values;
    }

    /**
     * 批量设置参数值
     *
     * @param string $identify meta_identify（如 layouts.account）
     * @param array $values    形如 ['title' => 'xxx']
     * @param string $scope    作用域，默认 default
     * @param string|null $locale 语言代码，可选
     */
    public static function setParamValues(string $identify, array $values, string $scope = 'default', ?string $locale = null): void
    {
        self::ensureInitialized();
        if (empty($values)) {
            return;
        }

        $identify = self::normalizeIdentify($identify);
        $definitions = self::getParamDefinitions($identify);

        foreach ($values as $paramName => $value) {
            $definition = $definitions[$paramName] ?? null;
            $isTranslatable = $definition && !empty($definition['translate']);

            if ($isTranslatable) {
                self::setParamTranslation($identify, (string)$paramName, (string)$value, $scope, $locale);
                continue;
            }

            $configIdentify = "{$identify}.param.{$paramName}.value";
            self::set($configIdentify, (string)$value, $scope, $locale);
        }
    }

    /**
     * 删除参数值（恢复默认）
     */
    public static function deleteParamValue(string $identify, string $paramName, string $scope = 'default', ?string $locale = null): void
    {
        self::ensureInitialized();
        $identify = self::normalizeIdentify($identify);
        $definitions = self::getParamDefinitions($identify);
        $definition = $definitions[$paramName] ?? null;
        $isTranslatable = $definition && !empty($definition['translate']);

        if ($isTranslatable) {
            self::deleteParamTranslation($identify, $paramName, $scope, $locale);
            return;
        }

        [$namespace, $configKey] = self::resolveNamespaceAndConfigKey($identify, "param.{$paramName}.value");
        $themeId = self::$currentTheme?->getId();

        if ($themeId) {
            /** @var MetaConfig $metaConfig */
            $metaConfig = ObjectManager::getInstance(MetaConfig::class);
            $metaConfig->deleteConfig($themeId, $namespace, $configKey, $scope, $locale);
            self::clearCache();
        }
    }

    /**
     * 获取某个参数在指定语言下的翻译值
     *
     * @param string      $identify  meta_identify（如 layouts.account）
     * @param string      $paramName 参数名
     * @param string      $scope     作用域，默认 default
     * @param string|null $locale    语言代码，null 时使用当前语言
     * @param string|null $default   默认值
     * @return string
     */
    public static function getParamTranslation(string $identify, string $paramName, string $scope = 'default', ?string $locale = null, ?string $default = null): string
    {
        self::ensureInitialized();
        $identify = self::normalizeIdentify($identify);
        $configIdentify = "{$identify}.param.{$paramName}.value";

        return MetaTranslation::getTranslatedValueWithScope(
            $configIdentify,
            $scope,
            $locale,
            $default
        );
    }

    /**
     * 设置某个参数在指定语言下的翻译值
     *
     * 注意：翻译值写入 I18n Dictionary 表，而不是 MetaData 表，
     * 读取时通过 MetaTranslation 统一取值。
     *
     * @param string      $identify  meta_identify（如 layouts.account）
     * @param string      $paramName 参数名
     * @param string      $value     翻译值
     * @param string      $scope     作用域，默认 default
     * @param string|null $locale    语言代码，null 时使用当前语言
     * @return bool
     */
    public static function setParamTranslation(string $identify, string $paramName, string $value, string $scope = 'default', ?string $locale = null): bool
    {
        self::ensureInitialized();
        $identify = self::normalizeIdentify($identify);

        if ($locale === null) {
            $locale = Cookie::getLangLocal() ?? 'zh_Hans_CN';
        }

        // 与 MetaTranslation::getTranslatedValueWithScope 保持相同的 key 约定
        $metaKey = "{$identify}.param.{$paramName}.value";
        $translationKey = '@meta::' . $metaKey;
        if ($scope !== 'default') {
            $translationKey .= '|scope:' . $scope;
        }

        /** @var Dictionary $dict */
        $dict = ObjectManager::getInstance(Dictionary::class);
        $md5 = Dictionary::generateMd5($translationKey, $locale);
        $dict->load(Dictionary::fields_MD5, $md5);

        $dict->setData(Dictionary::fields_MD5, $md5);
        $dict->setData(Dictionary::fields_WORD, $translationKey);
        $dict->setData(Dictionary::fields_LOCALE_CODE, $locale);
        $dict->setData(Dictionary::fields_TRANSLATE, $value);

        $dict->save();

        // 清除性能缓存中与该 meta 相关的翻译缓存（如果以后有需要，可以在此扩展）
        return true;
    }

    /**
     * 删除参数翻译（恢复默认）
     */
    public static function deleteParamTranslation(string $identify, string $paramName, string $scope = 'default', ?string $locale = null): bool
    {
        self::ensureInitialized();
        $identify = self::normalizeIdentify($identify);

        if ($locale === null) {
            $locale = Cookie::getLangLocal() ?? 'zh_Hans_CN';
        }

        $metaKey = "{$identify}.param.{$paramName}.value";
        $translationKey = '@meta::' . $metaKey;
        if ($scope !== 'default') {
            $translationKey .= '|scope:' . $scope;
        }

        /** @var Dictionary $dict */
        $dict = ObjectManager::getInstance(Dictionary::class);
        $md5 = Dictionary::generateMd5($translationKey, $locale);
        $dict->load(Dictionary::fields_MD5, $md5);

        if ($dict->getId()) {
            $dict->delete();
            return true;
        }
        return false;
    }

    /**
     * 解析 namespace 与 config key
     *
     * @param string $identify 形如 theme.frontend.layouts.default
     * @param string $extraPath 追加到 config key 的路径，例如 param.title
     * @return array{0:string,1:string}
     */
    private static function resolveNamespaceAndConfigKey(string $identify, string $extraPath = ''): array
    {
        $identify = self::normalizeIdentify($identify);
        $segments = explode('.', $identify);
        if (count($segments) < 3) {
            return ['', ''];
        }

        $namespace = $segments[0] . '.' . ($segments[1] ?? 'frontend');
        $configKey = implode('.', array_slice($segments, 2));

        if ($extraPath !== '') {
            $configKey .= '.' . ltrim($extraPath, '.');
        }

        return [$namespace, $configKey];
    }
    
    /**
     * 性能预加载：一次性加载当前主题的所有Meta配置
     * 
     * 默认加载：
     * - 主题命名空间下的（theme.frontend 或 theme.backend）
     * - 主题ID的（当前主题的ID）
     * - 关于当前区域的（frontend 或 backend）
     * - 当前scope的所有数据（默认 'default'）
     * 
     * @param string|null $namespace 命名空间（如 theme.frontend），如果为null则自动生成
     * @param string|null $metaIdentify Meta标识（如 theme.frontend.layouts.*），如果为null则使用通配符加载所有
     * @param string|null $scope 作用域，如果为null则使用 'default'
     * @param string|null $locale 语言代码，如果为null则使用当前语言
     * @return void
     */
    public static function performanceLoad(?string $namespace = null, ?string $metaIdentify = null, ?string $scope = null, ?string $locale = null): void
    {
        // 如果正在加载，直接返回，防止循环调用
        if (self::$performanceLoading) {
            return;
        }
        
        try {
            // 标记为正在加载
            self::$performanceLoading = true;
            
            // 确保已初始化（获取当前主题和区域）
            // 注意：ensureInitialized 中会检查 performanceLoading 标志，不会再次调用 performanceLoad
            self::ensureInitialized();
            
            // 如果没有提供namespace，自动生成（基于当前区域）
            if ($namespace === null) {
                if (self::$currentArea === null) {
                    self::$currentArea = State::isBackend() ? 'backend' : 'frontend';
                }
                $namespace = "theme." . self::$currentArea;
            }
            
            // 如果没有提供metaIdentify，使用通配符加载所有
            if ($metaIdentify === null) {
                $metaIdentify = $namespace . ".*";
            }
            
            // 如果没有提供scope，使用 'default'
            if ($scope === null) {
                $scope = 'default';
            }
            
            // 如果没有提供locale，使用当前语言
            if ($locale === null) {
                $locale = Cookie::getLangLocal() ?? null;
            }
            
            // 获取当前主题ID
            $themeId = null;
            if (self::$currentTheme && self::$currentTheme->getId()) {
                $themeId = self::$currentTheme->getId();
            }
            
            // 生成缓存key（包含主题ID）
            $keyParts = array_filter([$namespace, $metaIdentify, $scope, $locale, $themeId]);
            $key = md5(implode('.', $keyParts));
            
            // 如果已经加载过相同的配置，直接返回
            if (self::$performanceKey === $key) {
                return;
            }
            // 先调用MetaData的performanceLoad方法加载Meta记录
            MetaData::performanceLoad($key, $namespace, $metaIdentify, $scope, $locale);
            
            // 如果有主题ID，预加载该主题的MetaConfig配置
            if ($themeId) {
                try {
                    /** @var \Weline\Meta\Model\MetaConfig $metaConfig */
                    $metaConfig = ObjectManager::getInstance(\Weline\Meta\Model\MetaConfig::class);
                    
                    // 重要：清除之前的查询状态，避免参数绑定冲突
                    $metaConfig->clearQuery();
                    
                    // 查询该主题在指定命名空间下的所有配置
                    $metaConfig->where(\Weline\Meta\Model\MetaConfig::fields_IDENTIFY_ID, (string)$themeId)
                        ->where(\Weline\Meta\Model\MetaConfig::fields_NAMESPACE, $namespace)
                        ->where(\Weline\Meta\Model\MetaConfig::fields_SCOPE, $scope);
                    
                    $collection = $metaConfig->select()->fetch();
                    $items = $collection->getItems();
                    
                    $themeConfigs = [];
                    foreach ($items as $item) {
                        if (!$item instanceof \Weline\Meta\Model\MetaConfig) {
                            continue;
                        }
                        $configKey = $item->getData(\Weline\Meta\Model\MetaConfig::fields_CONFIG_KEY);
                        $configValue = $item->getData(\Weline\Meta\Model\MetaConfig::fields_CONFIG_VALUE);
                        $themeConfigs[$configKey] = $configValue;
                    }
                    
                    self::$performanceCache[$key] = $themeConfigs;
                } catch (\Throwable $e) {
                    self::$performanceCache[$key] = [];
                }
            }
            
            // 保存performanceKey
            self::$performanceKey = $key;
        } catch (\Exception $e) {
            // 预加载失败，继续执行
        } finally {
            // 重置加载标志，允许后续调用
            self::$performanceLoading = false;
        }
    }
    
    /**
     * 清除缓存
     */
    public static function clearCache(): void
    {
        MetaData::clearCache();
        self::$currentTheme = null;
        self::$currentArea = null;
        self::$performanceKey = null;
        self::$performanceCache = []; // 清除本地缓存
        self::$initialized = false;
    }
    
    /**
     * 规范化 identify，自动补全前缀
     * 如果键名不包含 frontend/backend，自动根据当前请求判断并添加
     * 
     * @param string $identify 原始 identify
     * @return string 规范化后的 identify
     */
    private static function normalizeIdentify(string $identify): string
    {
        // 如果已经包含 theme. 前缀，检查是否包含 frontend/backend
        if (str_starts_with($identify, 'theme.')) {
            // 检查是否包含 frontend 或 backend
            if (preg_match('/^theme\.(frontend|backend)\./', $identify)) {
                // 已经包含 frontend 或 backend，直接返回
                return $identify;
            }
            
            // 包含 theme. 但不包含 frontend/backend，自动添加
            if (self::$currentArea === null) {
                self::$currentArea = State::isBackend() ? 'backend' : 'frontend';
            }
            // 去掉 theme. 前缀，添加 area
            $rest = substr($identify, 6); // 去掉 'theme.'
            return "theme." . self::$currentArea . "." . $rest;
        }
        
        // 如果不包含 theme. 前缀，检查是否包含 frontend/backend
        if (preg_match('/^(frontend|backend)\./', $identify)) {
            // 包含 frontend 或 backend，添加 theme. 前缀
            return "theme." . $identify;
        }
        
        // 既不包含 theme. 也不包含 frontend/backend，自动添加
        if (self::$currentArea === null) {
            self::$currentArea = State::isBackend() ? 'backend' : 'frontend';
        }
        return "theme." . self::$currentArea . "." . $identify;
    }
    
    /**
     * 设置当前主题（用于特殊场景）
     * 
     * @param WelineTheme|null $theme 主题对象
     * @return void
     */
    public static function setCurrentTheme(?WelineTheme $theme): void
    {
        self::$currentTheme = $theme;
        self::$initialized = false; // 重置初始化状态，下次调用时会重新初始化
    }
    
    /**
     * 设置当前区域（用于特殊场景）
     * 
     * @param string|null $area 区域（frontend/backend）
     * @return void
     */
    public static function setCurrentArea(?string $area): void
    {
        self::$currentArea = $area;
        self::$initialized = false; // 重置初始化状态，下次调用时会重新初始化
    }
    
    /**
     * 获取当前主题
     * 
     * @return WelineTheme|null
     */
    public static function getCurrentTheme(): ?WelineTheme
    {
        self::ensureInitialized();
        return self::$currentTheme;
    }
    
    /**
     * 获取当前区域
     * 
     * @return string|null
     */
    public static function getCurrentArea(): ?string
    {
        self::ensureInitialized();
        return self::$currentArea;
    }
    
    /**
     * 获取指定区域和类型的所有Meta记录
     * 
     * 根据 meta_identify 前缀查询 w_meta 表，获取所有符合条件的记录
     * 例如：getMetaList('frontend', 'layouts') 会查询 meta_identify LIKE 'theme.frontend.layouts.%'
     * 
     * @param string $area 区域（frontend/backend）
     * @param string $type 类型（layouts/colors/variables/components/partials）
     * @return array Meta记录数组，每个元素包含 meta_id, meta_identify, meta_data, setting 等
     */
    public static function getMetaList(string $area, string $type): array
    {
        self::ensureInitialized();
        
        $cacheKey = "meta_list_{$area}_{$type}";
        if (isset(self::$performanceCache[$cacheKey])) {
            return self::$performanceCache[$cacheKey];
        }
        
        try {
            /** @var \Weline\Meta\Model\Meta $metaModel */
            $metaModel = ObjectManager::getInstance(\Weline\Meta\Model\Meta::class);
            
            // 重要：清除之前的查询状态，避免参数绑定冲突
            $metaModel->clearQuery();
            
            // 构建查询条件：meta_identify LIKE 'theme.{area}.{type}.%'
            $identifyPrefix = "theme.{$area}.{$type}.%";
            
            $metaModel->where(\Weline\Meta\Model\Meta::fields_NAMESPACE, 'theme')
                ->where(\Weline\Meta\Model\Meta::fields_META_IDENTIFY, $identifyPrefix, 'LIKE');
            
            $collection = $metaModel->select()->fetch();
            $items = $collection->getItems();
            
            $result = [];
            foreach ($items as $item) {
                if (!$item instanceof \Weline\Meta\Model\Meta) {
                    continue;
                }
                
                $metaId = $item->getId();
                $identify = $item->getData(\Weline\Meta\Model\Meta::fields_META_IDENTIFY);
                $metaDataJson = $item->getData(\Weline\Meta\Model\Meta::fields_META_DATA);
                $settingJson = $item->getData(\Weline\Meta\Model\Meta::fields_SETTING);
                $filePath = $item->getData(\Weline\Meta\Model\Meta::fields_FILE_PATH);
                $fileFullPath = $item->getData(\Weline\Meta\Model\Meta::fields_FILE_FULL_PATH);
                $category = $item->getData(\Weline\Meta\Model\Meta::fields_CATEGORY);
                
                $metaData = [];
                if ($metaDataJson) {
                    $metaData = json_decode($metaDataJson, true) ?? [];
                }
                
                $setting = [];
                if ($settingJson) {
                    $setting = json_decode($settingJson, true) ?? [];
                }
                
                $result[] = [
                    'meta_id' => $metaId,
                    'meta_identify' => $identify,
                    'file_path' => $filePath,
                    'file_full_path' => $fileFullPath,
                    'category' => $category,
                    'meta_data' => $metaData,
                    'setting' => $setting,
                    '_model' => $item, // 保留原始模型对象，方便后续操作
                ];
            }
            
            self::$performanceCache[$cacheKey] = $result;
            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * 根据类型获取可用选项列表
     * 
     * 从 Meta 表读取数据，并转换为前端可用的选项格式
     * 支持分组（如 layouts、partials）和非分组（如 colors、variables、components）
     * 
     * @param string $area 区域（frontend/backend）
     * @param string $type 类型（layouts/colors/variables/components/partials）
     * @return array 选项数组
     */
    public static function getAvailableOptions(string $area, string $type): array
    {
        self::ensureInitialized();
        
        $metaList = self::getMetaList($area, $type);
        
        if (empty($metaList)) {
            return [];
        }
        
        // 判断是否需要分组
        $grouped = in_array($type, ['layouts', 'partials'], true);
        $result = $grouped ? [] : [];
        
        foreach ($metaList as $meta) {
            $identify = $meta['meta_identify'] ?? '';
            $metaData = $meta['meta_data'] ?? [];
            $setting = $meta['setting'] ?? [];
            $filePath = $meta['file_path'] ?? '';
            $fileFullPath = $meta['file_full_path'] ?? '';
            $category = $meta['category'] ?? '';
            
            // 从 identify 提取选项值
            // 例如：theme.frontend.layouts.account -> account
            // 或：theme.frontend.layouts.account.dashboard -> dashboard（group=account）
            $parts = explode('.', $identify);
            // 格式：theme.{area}.{type}.{...rest}
            // 移除前3个部分
            $restParts = array_slice($parts, 3);
            
            if (empty($restParts)) {
                continue;
            }
            
            // 提取分组和值
            $group = 'default';
            $value = '';
            
            if ($grouped) {
                // 统一规则：
                // group = 第一个段（布局类型：default/account/cart/...）
                // value = 最后一个段（具体布局名：default/category/...）
                $last = $restParts[count($restParts) - 1];

                // 跳过仅用于 name/description 元信息的记录：
                // theme.frontend.layouts.default.name / .description 等不应作为可选布局出现
                if (in_array($last, ['name', 'description'], true)) {
                    continue;
                }

                $group = $restParts[0];
                $value = $last;
            } else {
                // 非分组类型，直接取最后一个部分作为值
                $value = $restParts[count($restParts) - 1];
            }
            
            if ($value === '') {
                continue;
            }
            
            // 构建选项
            $option = [
                'value' => $value,
                'meta' => self::buildOptionMeta($metaData, $setting, $filePath, $fileFullPath),
                'file' => $fileFullPath ?: $filePath,
                'meta_id' => $meta['meta_id'],
                'meta_identify' => $identify,
            ];
            
            if ($grouped) {
                if (!isset($result[$group])) {
                    $result[$group] = [];
                }
                // 检查是否已存在相同值
                $exists = false;
                foreach ($result[$group] as $existing) {
                    if ($existing['value'] === $value) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $result[$group][] = $option;
                }
            } else {
                // 检查是否已存在相同值
                $exists = false;
                foreach ($result as $existing) {
                    if ($existing['value'] === $value) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $result[] = $option;
                }
            }
        }
        
        // 排序
        if ($grouped) {
            foreach ($result as $group => $options) {
                usort($result[$group], function($a, $b) {
                    $nameA = $a['meta']['name'] ?? $a['value'];
                    $nameB = $b['meta']['name'] ?? $b['value'];

                    // 兼容数组结构的 name，优先取其中的 name/default 字段
                    if (is_array($nameA)) {
                        $nameA = $nameA['name'] ?? $nameA['default'] ?? reset($nameA) ?? '';
                    }
                    if (is_array($nameB)) {
                        $nameB = $nameB['name'] ?? $nameB['default'] ?? reset($nameB) ?? '';
                    }
                    $nameA = (string)$nameA;
                    $nameB = (string)$nameB;
                    return strcmp($nameA, $nameB);
                });
            }
        } else {
            usort($result, function($a, $b) {
                $nameA = $a['meta']['name'] ?? $a['value'];
                $nameB = $b['meta']['name'] ?? $b['value'];

                if (is_array($nameA)) {
                    $nameA = $nameA['name'] ?? $nameA['default'] ?? reset($nameA) ?? '';
                }
                if (is_array($nameB)) {
                    $nameB = $nameB['name'] ?? $nameB['default'] ?? reset($nameB) ?? '';
                }
                $nameA = (string)$nameA;
                $nameB = (string)$nameB;
                return strcmp($nameA, $nameB);
            });
        }
        
        return $result;
    }
    
    /**
     * 构建选项的 meta 信息
     * 
     * @param array $metaData meta_data 字段解析后的数组
     * @param array $setting setting 字段解析后的数组
     * @param string $filePath 文件相对路径
     * @param string $fileFullPath 文件完整路径
     * @return array meta 信息数组
     */
    private static function buildOptionMeta(array $metaData, array $setting, string $filePath, string $fileFullPath): array
    {
        $meta = [
            'name' => '',
            'description' => '',
            'icon' => '',
            'version' => '',
            'author' => '',
            'params' => [],
        ];
        
        // 从 meta_data 中提取信息
        // 支持多种数据结构：attributes、meta、直接字段
        $attributes = $metaData['attributes'] ?? $metaData['meta'] ?? $metaData;
        
        if (isset($attributes['name'])) {
            $meta['name'] = is_array($attributes['name']) ? ($attributes['name']['name'] ?? $attributes['name']['default'] ?? '') : $attributes['name'];
        }
        if (isset($attributes['description'])) {
            $meta['description'] = is_array($attributes['description']) ? ($attributes['description']['name'] ?? $attributes['description']['default'] ?? '') : $attributes['description'];
        }
        if (isset($attributes['icon'])) {
            $meta['icon'] = $attributes['icon'];
        }
        if (isset($attributes['version'])) {
            $meta['version'] = $attributes['version'];
        }
        if (isset($attributes['author'])) {
            $meta['author'] = $attributes['author'];
        }
        
        // 从 setting 中提取参数
        if (isset($setting['param']) && is_array($setting['param'])) {
            $meta['params'] = $setting['param'];
        }
        
        // 如果 meta_data 和 setting 都没有参数，尝试从文件解析
        if (empty($meta['params']) && !empty($fileFullPath)) {
            $absolutePath = $fileFullPath;
            if (!str_starts_with($absolutePath, '/') && !preg_match('/^[A-Za-z]:/', $absolutePath)) {
                $absolutePath = BP . DS . ltrim(str_replace(['/', '\\'], DS, $fileFullPath), DS);
            }
            
            if (is_file($absolutePath)) {
                try {
                    $parsed = ComponentMetaParser::parse($absolutePath);
                    if (!empty($parsed['params'])) {
                        $meta['params'] = self::formatParsedParams($parsed['params']);
                    }
                    // 如果 name/description 为空，也从文件解析
                    if (empty($meta['name']) && !empty($parsed['meta']['name'])) {
                        $meta['name'] = $parsed['meta']['name'];
                    }
                    if (empty($meta['description']) && !empty($parsed['meta']['description'])) {
                        $meta['description'] = $parsed['meta']['description'];
                    }
                } catch (\Throwable $e) {
                    // 解析失败，忽略
                }
            }
        }
        
        // 如果 name 仍然为空，使用文件名
        if (empty($meta['name'])) {
            $fileName = pathinfo($fileFullPath ?: $filePath, PATHINFO_FILENAME);
            $meta['name'] = ucfirst($fileName);
        }
        
        return $meta;
    }
    
    /**
     * 格式化解析的参数
     * 
     * @param array $parsedParams ComponentMetaParser 解析的参数
     * @return array 格式化后的参数
     */
    private static function formatParsedParams(array $parsedParams): array
    {
        $params = [];
        foreach ($parsedParams as $param) {
            $key = $param['name'] ?? null;
            if (!$key) {
                continue;
            }
            $params[$key] = [
                'name' => $param['name_label'] ?? $key,
                'description' => $param['description'] ?? '',
                'default' => $param['default'] ?? '',
                'type' => $param['type'] ?? 'text',
                'required' => (bool)($param['required'] ?? false),
                'options' => $param['options'] ?? null,
            ];
        }
        return $params;
    }
    
    /**
     * 获取指定类型的配置值
     * 
     * 从 w_meta_config 表读取配置值
     * 
     * @param string $area 区域（frontend/backend）
     * @param string $type 类型（layouts/colors/variables/components/partials）
     * @param string $scope 作用域，默认 'default'
     * @return array 配置数组，键为配置键，值为配置值
     */
    public static function getConfigList(string $area, string $type, string $scope = 'default'): array
    {
        self::ensureInitialized();
        
        // 获取主题ID（用于查询配置）
        $themeId = null;
        if (self::$currentTheme && self::$currentTheme->getId()) {
            $themeId = self::$currentTheme->getId();
        }
        
        $cacheKey = "config_list_{$area}_{$type}_{$scope}_{$themeId}";
        if (isset(self::$performanceCache[$cacheKey])) {
            return self::$performanceCache[$cacheKey];
        }
        
        try {
            /** @var \Weline\Meta\Model\MetaConfig $metaConfig */
            $metaConfig = ObjectManager::getInstance(\Weline\Meta\Model\MetaConfig::class);
            
            // 重要：清除之前的查询状态，避免参数绑定冲突
            $metaConfig->clearQuery();
            
            $namespace = "theme.{$area}";
            $configKeyPrefix = "{$type}.%";
            
            $query = $metaConfig->where(\Weline\Meta\Model\MetaConfig::fields_NAMESPACE, $namespace)
                ->where(\Weline\Meta\Model\MetaConfig::fields_CONFIG_KEY, $configKeyPrefix, 'LIKE')
                ->where(\Weline\Meta\Model\MetaConfig::fields_SCOPE, $scope);
            
            // 如果提供了主题ID，添加 identify_id 条件
            if ($themeId !== null) {
                $query->where(\Weline\Meta\Model\MetaConfig::fields_IDENTIFY_ID, (string)$themeId);
            }
            
            $collection = $query->select()->fetch();
            $items = $collection->getItems();
            
            $result = [];
            foreach ($items as $item) {
                if (!$item instanceof \Weline\Meta\Model\MetaConfig) {
                    continue;
                }
                
                $configKey = $item->getData(\Weline\Meta\Model\MetaConfig::fields_CONFIG_KEY);
                $configValue = $item->getData(\Weline\Meta\Model\MetaConfig::fields_CONFIG_VALUE);
                
                // 尝试解析 JSON 字符串（如果值是 JSON 格式）
                if (is_string($configValue) && !empty($configValue)) {
                    // 检查是否是 JSON 字符串
                    if (($configValue[0] === '{' || $configValue[0] === '[') && 
                        ($decoded = json_decode($configValue, true)) !== null && 
                        json_last_error() === JSON_ERROR_NONE) {
                        // 如果是 JSON 对象，需要进一步处理
                        // 对于 layouts 类型，如果值是对象，需要提取对应的值
                        if (is_array($decoded)) {
                            $configValue = $decoded;
                        } else {
                            $configValue = $decoded;
                        }
                    }
                }
                
                // 移除类型前缀，例如：layouts.account.value -> account.value
                $keyWithoutType = substr($configKey, strlen($type) + 1);
                
                $result[$keyWithoutType] = $configValue;
            }
            
            self::$performanceCache[$cacheKey] = $result;
            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * 获取布局配置
     * 
     * @param string $area 区域（frontend/backend）
     * @param string $scope 作用域，默认 'default'
     * @return array 布局配置数组，格式：['account' => 'dashboard', 'default' => 'default']
     */
    public static function getLayoutConfig(string $area, string $scope = 'default'): array
    {
        $configList = self::getConfigList($area, 'layouts', $scope);
        
        $result = [];
        foreach ($configList as $key => $value) {
            // key 格式：{layoutType}.value 或 {layoutType}
            $parts = explode('.', $key);
            $layoutType = $parts[0];
            
            // 处理值：如果是数组（可能是从 JSON 解析出来的对象），需要提取对应的值
            $layoutValue = $value;
            if (is_array($value)) {
                // 如果值是数组，可能是 {"homepage":"minimal"} 这样的结构
                // 尝试从数组中提取对应的 layoutType 的值
                if (isset($value[$layoutType])) {
                    $layoutValue = $value[$layoutType];
                } elseif (count($value) === 1) {
                    // 如果数组只有一个元素，直接取第一个值
                    $layoutValue = reset($value);
                } else {
                    // 如果数组有多个元素，保持原样（这种情况不应该发生）
                    $layoutValue = $value;
                }
            } elseif (is_string($value)) {
                // 如果是字符串，检查是否是 JSON
                if (($value[0] === '{' || $value[0] === '[') && 
                    ($decoded = json_decode($value, true)) !== null && 
                    json_last_error() === JSON_ERROR_NONE) {
                    // 解析 JSON
                    if (is_array($decoded)) {
                        if (isset($decoded[$layoutType])) {
                            $layoutValue = $decoded[$layoutType];
                        } elseif (count($decoded) === 1) {
                            $layoutValue = reset($decoded);
                        } else {
                            $layoutValue = $decoded;
                        }
                    } else {
                        $layoutValue = $decoded;
                    }
                }
            }
            
            // 只取 .value 结尾的配置
            if (count($parts) >= 2 && $parts[count($parts) - 1] === 'value') {
                $result[$layoutType] = $layoutValue;
            } elseif (count($parts) === 1) {
                // 兼容没有 .value 后缀的配置
                $result[$layoutType] = $layoutValue;
            }
        }
        
        return $result;
    }
    
    /**
     * 获取色系配置
     * 
     * @param string $area 区域（frontend/backend）
     * @param string $scope 作用域，默认 'default'
     * @return string|null 色系配置值
     */
    public static function getColorConfig(string $area, string $scope = 'default'): ?string
    {
        $configList = self::getConfigList($area, 'colors', $scope);
        
        // 查找 primary.value 或直接的值
        if (isset($configList['primary.value'])) {
            return $configList['primary.value'];
        }
        if (isset($configList['value'])) {
            return $configList['value'];
        }
        
        // 返回第一个值
        foreach ($configList as $value) {
            return $value;
        }
        
        return null;
    }
    
    /**
     * 获取变量配置
     * 
     * @param string $area 区域（frontend/backend）
     * @param string $scope 作用域，默认 'default'
     * @return array 变量配置数组
     */
    public static function getVariablesConfig(string $area, string $scope = 'default'): array
    {
        $configList = self::getConfigList($area, 'variables', $scope);
        
        // 查找 value 键
        if (isset($configList['value'])) {
            $value = $configList['value'];
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                return is_array($decoded) ? $decoded : [$value];
            }
            return is_array($value) ? $value : [$value];
        }
        
        return [];
    }
    
    /**
     * 获取部件配置
     * 
     * @param string $area 区域（frontend/backend）
     * @param string $scope 作用域，默认 'default'
     * @return array 部件配置数组，格式：['header' => 'minimal', 'footer' => 'default']
     */
    public static function getPartialsConfig(string $area, string $scope = 'default'): array
    {
        $configList = self::getConfigList($area, 'partials', $scope);
        
        $result = [];
        foreach ($configList as $key => $value) {
            // key 格式：{partialType}.value 或 {partialType}
            $parts = explode('.', $key);
            $partialType = $parts[0];
            
            // 只取 .value 结尾的配置
            if (count($parts) >= 2 && $parts[count($parts) - 1] === 'value') {
                $result[$partialType] = $value;
            } elseif (count($parts) === 1) {
                // 兼容没有 .value 后缀的配置
                $result[$partialType] = $value;
            }
        }
        
        return $result;
    }
    
    // ========================================
    // 部件专用函数（Widget-specific functions）
    // ========================================
    
    /**
     * 获取部件的meta_identify
     * 
     * @param string $widgetModule 部件模块名（如 Weline_Theme）
     * @param string $widgetCode 部件代码（如 footer-social）
     * @param string $area 区域（frontend 或 backend）
     * @return string 例如：theme.frontend.widgets.Weline_Theme.footer-social
     */
    public static function getWidgetIdentify(string $widgetModule, string $widgetCode, string $area = 'frontend'): string
    {
        return "theme.{$area}.widgets.{$widgetModule}.{$widgetCode}";
    }
    
    /**
     * 获取部件参数定义（不含值，仅结构）
     * 
     * 优先从 WidgetRegistry（widget.php）获取，回退到 Meta 扫描（@param 注释）
     * 
     * @param string $widgetModule 部件模块名
     * @param string $widgetCode 部件代码
     * @param string $area 区域
     * @param \Weline\Widget\Service\WidgetRegistry|null $widgetRegistry WidgetRegistry 实例（可选）
     * @return array 参数定义数组，格式：['param_name' => ['type' => 'string', 'label' => '标题', ...]]
     */
    public static function getWidgetParamDefinitions(
        string $widgetModule,
        string $widgetCode,
        string $area = 'frontend',
        $widgetRegistry = null
    ): array {
        $eventData = [
            'data' => [
                'operation' => 'getParamDefinitions',
                'params' => [
                    'widget_module' => $widgetModule,
                    'widget_code' => $widgetCode,
                    'area' => $area,
                ],
            ],
        ];
        /** @var EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        $eventsManager->dispatch('Weline_Widget::query', $eventData);
        $params = $eventData['data']['result'] ?? null;
        return is_array($params) ? $params : [];
    }
    
    /**
     * 获取部件参数定义（使用 WidgetRegistry，推荐使用此方法）
     * 
     * 此方法接受 WidgetRegistry 实例，确保能获取到 widget.php 中定义的参数
     * 
     * @param string $widgetModule 部件模块名
     * @param string $widgetCode 部件代码
     * @param \Weline\Widget\Service\WidgetRegistry $widgetRegistry WidgetRegistry 实例
     * @param string $area 区域
     * @return array 参数定义数组
     */
    public static function getWidgetParamDefinitionsWithRegistry(
        string $widgetModule,
        string $widgetCode,
        $widgetRegistry,
        string $area = 'frontend'
    ): array {
        return self::getWidgetParamDefinitions($widgetModule, $widgetCode, $area, $widgetRegistry);
    }
    
    /**
     * 获取部件单个参数值（支持多语言）
     * 
     * @param string $widgetModule 部件模块名
     * @param string $widgetCode 部件代码
     * @param string $paramName 参数名
     * @param string|null $locale 语言代码，null时使用当前语言
     * @param mixed $default 默认值
     * @param string $area 区域
     * @return mixed
     */
    public static function getWidgetParam(
        string $widgetModule,
        string $widgetCode,
        string $paramName,
        ?string $locale = null,
        $default = null,
        string $area = 'frontend'
    ) {
        $identify = self::getWidgetIdentify($widgetModule, $widgetCode, $area);
        $definitions = self::getParamDefinitions($identify);
        
        if (!isset($definitions[$paramName])) {
            return $default;
        }
        
        $definition = $definitions[$paramName];
        $isTranslatable = !empty($definition['translate']);
        
        if ($isTranslatable) {
            $value = self::getParamTranslation($identify, $paramName, 'default', $locale, $default);
            return $value ?? $default;
        }
        
        $configIdentify = "{$identify}.param.{$paramName}.value";
        $value = self::get($configIdentify);
        
        return $value ?? $definition['default'] ?? $default;
    }
    
    /**
     * 设置部件单个参数值（支持多语言）
     * 
     * @param string $widgetModule 部件模块名
     * @param string $widgetCode 部件代码
     * @param string $paramName 参数名
     * @param mixed $value 参数值
     * @param string|null $locale 语言代码，null时保存为默认值
     * @param string $area 区域
     * @return bool
     */
    public static function setWidgetParam(
        string $widgetModule,
        string $widgetCode,
        string $paramName,
        $value,
        ?string $locale = null,
        string $area = 'frontend'
    ): bool {
        $identify = self::getWidgetIdentify($widgetModule, $widgetCode, $area);
        $definitions = self::getParamDefinitions($identify);
        
        if (!isset($definitions[$paramName])) {
            return false;
        }
        
        $definition = $definitions[$paramName];
        $isTranslatable = !empty($definition['translate']);
        
        if ($isTranslatable) {
            return self::setParamTranslation($identify, $paramName, (string)$value, 'default', $locale);
        }
        
        $configIdentify = "{$identify}.param.{$paramName}.value";
        return self::set($configIdentify, (string)$value, 'default', $locale);
    }
    
    /**
     * 批量获取部件所有参数（支持多语言）
     * 
     * @param string $widgetModule 部件模块名
     * @param string $widgetCode 部件代码
     * @param string|null $locale 语言代码，null时使用当前语言
     * @param string $area 区域
     * @return array 参数数组
     */
    public static function getWidgetParams(
        string $widgetModule,
        string $widgetCode,
        ?string $locale = null,
        string $area = 'frontend'
    ): array {
        $identify = self::getWidgetIdentify($widgetModule, $widgetCode, $area);
        return self::getParamValues($identify, 'default', $locale);
    }
    
    /**
     * 批量设置部件参数（支持多语言）
     * 
     * @param string $widgetModule 部件模块名
     * @param string $widgetCode 部件代码
     * @param array $params 参数数组
     * @param string|null $locale 语言代码，null时保存为默认值
     * @param string $area 区域
     */
    public static function setWidgetParams(
        string $widgetModule,
        string $widgetCode,
        array $params,
        ?string $locale = null,
        string $area = 'frontend'
    ): void {
        $identify = self::getWidgetIdentify($widgetModule, $widgetCode, $area);
        self::setParamValues($identify, $params, 'default', $locale);
    }
    
    /**
     * 删除部件单个参数值
     * 
     * @param string $widgetModule 部件模块名
     * @param string $widgetCode 部件代码
     * @param string $paramName 参数名
     * @param string|null $locale 语言代码
     * @param string $area 区域
     */
    public static function deleteWidgetParam(
        string $widgetModule,
        string $widgetCode,
        string $paramName,
        ?string $locale = null,
        string $area = 'frontend'
    ): void {
        $identify = self::getWidgetIdentify($widgetModule, $widgetCode, $area);
        self::deleteParamValue($identify, $paramName, 'default', $locale);
    }

    // ─── 按路径的多语言读写（支持数组子字段） ─────────────────────

    /**
     * 获取部件所有可翻译路径模式
     *
     * 返回格式:
     *   'top'   => ['title', 'subtitle'],                    // 顶级可翻译参数名
     *   'array' => [ 'slides' => ['title', 'subtitle'] ]     // 数组参数 => 可翻译子字段列表
     *
     * @param array $paramDefs 参数定义（来自 Weline_Widget::query 或 widget.php）
     */
    public static function getTranslatablePaths(array $paramDefs): array
    {
        $top = [];
        $arrayFields = [];

        foreach ($paramDefs as $paramName => $def) {
            if (!is_array($def)) {
                continue;
            }

            $type = $def['type'] ?? 'string';

            if ($type !== 'array' && AbstractParamType::isTranslatable($def)) {
                $top[] = $paramName;
            }

            if ($type === 'array' && !empty($def['item_schema']) && is_array($def['item_schema'])) {
                $subTranslatable = [];
                foreach ($def['item_schema'] as $fieldKey => $fieldDef) {
                    if (is_array($fieldDef) && AbstractParamType::isTranslatable($fieldDef)) {
                        $subTranslatable[] = $fieldKey;
                    }
                }
                if (!empty($subTranslatable)) {
                    $arrayFields[$paramName] = $subTranslatable;
                }
            }
        }

        return ['top' => $top, 'array' => $arrayFields];
    }

    /**
     * 按路径读取翻译值（支持 slides.0.title 格式）
     */
    public static function getPathTranslation(
        string $identify,
        string $path,
        string $scope = 'default',
        ?string $locale = null,
        ?string $default = null
    ): ?string {
        self::ensureInitialized();
        $identify = self::normalizeIdentify($identify);
        $configIdentify = "{$identify}.path.{$path}.value";

        $result = MetaTranslation::getTranslatedValueWithScope(
            $configIdentify,
            $scope,
            $locale,
            $default
        );
        return $result !== '' ? $result : $default;
    }

    /**
     * 按路径写入翻译值
     */
    public static function setPathTranslation(
        string $identify,
        string $path,
        string $value,
        string $scope = 'default',
        ?string $locale = null
    ): bool {
        self::ensureInitialized();
        $identify = self::normalizeIdentify($identify);

        if ($locale === null) {
            $locale = Cookie::getLangLocal() ?? 'zh_Hans_CN';
        }

        $metaKey = "{$identify}.path.{$path}.value";
        $translationKey = '@meta::' . $metaKey;
        if ($scope !== 'default') {
            $translationKey .= '|scope:' . $scope;
        }

        /** @var Dictionary $dict */
        $dict = ObjectManager::getInstance(Dictionary::class);
        $md5 = Dictionary::generateMd5($translationKey, $locale);
        $dict->load(Dictionary::fields_MD5, $md5);

        $dict->setData(Dictionary::fields_MD5, $md5);
        $dict->setData(Dictionary::fields_WORD, $translationKey);
        $dict->setData(Dictionary::fields_LOCALE_CODE, $locale);
        $dict->setData(Dictionary::fields_TRANSLATE, $value);

        $dict->save();
        return true;
    }

    /**
     * 将 locale 的可翻译路径值合并进 base config
     *
     * @param array $baseConfig   基础配置（来自 m_theme_layout.config）
     * @param array $paramDefs    参数定义
     * @param string $identify    meta identify
     * @param string|null $locale 语言代码
     * @return array 合并后的 config
     */
    public static function mergeTranslatedPaths(
        array $baseConfig,
        array $paramDefs,
        string $identify,
        ?string $locale = null
    ): array {
        $paths = self::getTranslatablePaths($paramDefs);

        foreach ($paths['top'] as $paramName) {
            $val = self::getParamTranslation($identify, $paramName, 'default', $locale);
            if ($val !== '' && $val !== null) {
                $baseConfig[$paramName] = $val;
            }
        }

        foreach ($paths['array'] as $arrayKey => $subFields) {
            if (!isset($baseConfig[$arrayKey]) || !is_array($baseConfig[$arrayKey])) {
                continue;
            }
            foreach ($baseConfig[$arrayKey] as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }
                foreach ($subFields as $fieldKey) {
                    $path = "{$arrayKey}.{$index}.{$fieldKey}";
                    $val = self::getPathTranslation($identify, $path, 'default', $locale);
                    if ($val !== null) {
                        $baseConfig[$arrayKey][$index][$fieldKey] = $val;
                    }
                }
            }
        }

        return $baseConfig;
    }

    /**
     * 将 config 中的可翻译路径值保存到翻译存储
     *
     * @param array $configData  用户提交的 config（可能包含 "slides.0.title" 形式的路径 key）
     * @param array $paramDefs   参数定义
     * @param string $identify   meta identify
     * @param string|null $locale 语言代码
     * @return array 过滤掉路径 key 后的普通 config（用于写入 m_theme_layout.config）
     */
    public static function saveTranslatablePaths(
        array $configData,
        array $paramDefs,
        string $identify,
        ?string $locale = null
    ): array {
        $paths = self::getTranslatablePaths($paramDefs);
        $normalConfig = [];

        foreach ($configData as $key => $value) {
            if (str_contains($key, '.')) {
                self::setPathTranslation($identify, $key, (string)$value, 'default', $locale);
            } else {
                $normalConfig[$key] = $value;
            }
        }

        if ($locale === null) {
            $resolvedLocale = Cookie::getLangLocal() ?? 'zh_Hans_CN';
            foreach ($paths['top'] as $paramName) {
                if (array_key_exists($paramName, $normalConfig) && is_scalar($normalConfig[$paramName])) {
                    self::setParamTranslation($identify, $paramName, (string)$normalConfig[$paramName], 'default', $resolvedLocale);
                }
            }

            foreach ($paths['array'] as $arrayKey => $subFields) {
                if (!isset($normalConfig[$arrayKey]) || !is_array($normalConfig[$arrayKey])) {
                    continue;
                }
                foreach ($normalConfig[$arrayKey] as $index => $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    foreach ($subFields as $fieldKey) {
                        if (array_key_exists($fieldKey, $item) && is_scalar($item[$fieldKey])) {
                            $path = "{$arrayKey}.{$index}.{$fieldKey}";
                            self::setPathTranslation($identify, $path, (string)$item[$fieldKey], 'default', $resolvedLocale);
                        }
                    }
                }
            }
        }

        return $normalConfig;
    }
}

