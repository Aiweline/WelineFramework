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
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Meta\Helper\MetaData;
use Weline\Theme\Model\WelineTheme;

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
 * // 获取Meta数据对象
 * $metaData = ThemeData::getMeta('theme.frontend.layouts.default');
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
            
            // 如果主题存在，预加载配置
            if (self::$currentTheme && self::$currentTheme->getId()) {
                self::performanceLoad();
            }
            
            self::$initialized = true;
        } catch (\Exception $e) {
            // 初始化失败，继续执行但不预加载
            self::$initialized = true;
        }
    }
    
    /**
     * 主要接口：获取配置值（统一使用MetaData）
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
        
        // 统一使用MetaData获取值
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
     * @param string $identify meta_identify（如 theme.frontend.layouts.default）
     * @return \Weline\Meta\Helper\MetaData|null
     */
    public static function getMeta(string $identify)
    {
        self::ensureInitialized();
        
        // 规范化identify（自动补全前缀）
        $identify = self::normalizeIdentify($identify);
        
        // 统一使用MetaData加载
        $metaData = MetaData::load($identify);
        
        // 如果返回的是MetaData对象，直接返回
        if ($metaData instanceof MetaData) {
            return $metaData;
        }
        
        // 如果返回的是字符串或其他值，返回null（因为期望的是MetaData对象）
        return null;
    }
    
    /**
     * 快速获取文件的参数定义和配置值
     * 从 Meta 表的 setting 字段中读取参数定义，并获取每个参数的配置值
     * 
     * @param string $identify meta_identify（如 layouts.account.dashboard 或 theme.frontend.layouts.account.dashboard）
     * @return array 参数数组，键为参数名，值为配置值（或默认值）
     */
    public static function getFileParams(string $identify): array
    {
        self::ensureInitialized();
        
        // 规范化 identify（自动补全前缀）
        $identify = self::normalizeIdentify($identify);
        
        // 获取 MetaData 对象
        $metaData = self::getMeta($identify);
        if (!$metaData || !$metaData->isLoaded()) {
            return [];
        }
        
        // 获取 setting 字段内容
        $allData = $metaData->getAll();
        $params = $allData['param'] ?? [];
        
        // 如果参数为空，直接返回
        if (empty($params) || !is_array($params)) {
            return [];
        }
        
        // 提取参数配置值
        $result = [];
        foreach ($params as $paramName => $paramDef) {
            // 参数定义可能是数组（包含 default、name、description 等）或直接是值
            if (is_array($paramDef)) {
                $defaultValue = $paramDef['default'] ?? null;
            } else {
                $defaultValue = $paramDef;
            }
            
            // 构建配置 identify
            $configIdentify = "{$identify}.param.{$paramName}.value";
            
            // 从 ThemeData 读取配置值，如果读取不到会自动返回默认值
            $value = self::get($configIdentify, $defaultValue);
            
            $result[$paramName] = $value;
        }
        
        return $result;
    }
    
    /**
     * 性能预加载：一次性加载当前主题的所有Meta配置
     * 
     * @param string|null $namespace 命名空间（如 theme.frontend），如果为null则自动生成
     * @param string|null $metaIdentify Meta标识（如 theme.frontend.layouts.*），如果为null则使用通配符加载所有
     * @param string|null $scope 作用域（可选）
     * @param string|null $locale 语言代码（可选）
     * @return void
     */
    public static function performanceLoad(?string $namespace = null, ?string $metaIdentify = null, ?string $scope = null, ?string $locale = null): void
    {
        try {
            // 如果没有提供namespace，自动生成
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
            
            // 如果没有提供locale，使用当前语言
            if ($locale === null) {
                $locale = Cookie::getLangLocal() ?? null;
            }
            
            // 生成缓存key
            $keyParts = array_filter([$namespace, $metaIdentify, $scope, $locale]);
            $key = md5(implode('.', $keyParts));
            
            // 如果已经加载过相同的配置，直接返回
            if (self::$performanceKey === $key) {
                return;
            }
            
            // 调用MetaData的performanceLoad方法
            MetaData::performanceLoad($key, $namespace, $metaIdentify, $scope, $locale);
            
            // 保存performanceKey
            self::$performanceKey = $key;
        } catch (\Exception $e) {
            // 预加载失败，继续执行
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
}

