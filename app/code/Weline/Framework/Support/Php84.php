<?php
declare(strict_types=1);

/**
 * Weline Framework - PHP 8.4+ 特性支持
 * 
 * 封装 PHP 8.4+ 新特性，低版本回退到传统实现
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Support;

use ReflectionClass;

/**
 * PHP 8.4+ 特性支持类
 * 
 * 主要特性：
 * - Lazy Objects (延迟对象) - 显著减少启动时间和内存
 * - 新数组函数 (array_find, array_any, array_all) - 更简洁的数组操作
 * - Property Hooks - 更优雅的属性访问器（语法级特性，见下方说明）
 * 
 * Property Hooks 说明：
 * =====================
 * Property Hooks 是 PHP 8.4 的语法级特性，无法通过运行时封装兼容低版本。
 * 
 * 语法示例：
 * ```php
 * class User {
 *     public string $name {
 *         get => $this->name;
 *         set => $this->name = trim($value);
 *     }
 * }
 * ```
 * 
 * 使用场景：
 * - Model 字段自动 trim、格式化
 * - 默认值处理
 * - 数据验证（邮箱格式、数值范围等）
 * - 计算属性（fullName = firstName + lastName）
 * - 关联数据懒加载
 * 
 * 完整示例请参考：
 * @see \Weline\Framework\Support\Php84PropertyHooksExample
 * 
 * 注意：Php84PropertyHooksExample.php 文件仅在 PHP 8.4+ 环境下可加载！
 */
class Php84
{
    /**
     * PHP 版本是否 >= 8.4
     */
    private static ?bool $isPhp84 = null;
    
    /**
     * Lazy Object 缓存
     */
    private static array $lazyObjectCache = [];
    
    /**
     * 检查是否为 PHP 8.4+
     */
    public static function isPhp84(): bool
    {
        if (self::$isPhp84 === null) {
            self::$isPhp84 = PHP_VERSION_ID >= 80400;
        }
        return self::$isPhp84;
    }
    
    /**
     * 获取当前 PHP 版本信息
     */
    public static function getVersionInfo(): array
    {
        return [
            'version' => PHP_VERSION,
            'version_id' => PHP_VERSION_ID,
            'is_php84' => self::isPhp84(),
            'features' => self::getAvailableFeatures(),
        ];
    }
    
    /**
     * 获取可用的 PHP 8.4 特性
     */
    public static function getAvailableFeatures(): array
    {
        $features = [];
        
        if (self::isPhp84()) {
            $features['lazy_objects'] = \method_exists(ReflectionClass::class, 'newLazyGhost');
            $features['array_find'] = \function_exists('array_find');
            $features['array_any'] = \function_exists('array_any');
            $features['array_all'] = \function_exists('array_all');
        }
        
        return $features;
    }
    
    // ==================== Lazy Objects ====================
    
    /**
     * 创建延迟加载对象
     * 
     * PHP 8.4+ 使用 ReflectionClass::newLazyGhost()
     * 低版本使用代理模式模拟
     * 
     * @param string $className 类名
     * @param callable $initializer 初始化回调
     * @return object
     */
    public static function createLazyObject(string $className, callable $initializer): object
    {
        if (self::isPhp84() && \method_exists(ReflectionClass::class, 'newLazyGhost')) {
            $reflection = new ReflectionClass($className);
            return $reflection->newLazyGhost($initializer);
        }
        
        // 低版本回退：立即初始化
        // 这里可以扩展为代理模式，但为了简单起见直接初始化
        $instance = (new ReflectionClass($className))->newInstanceWithoutConstructor();
        $initializer($instance);
        return $instance;
    }
    
    /**
     * 使用代理工厂创建延迟对象
     * 
     * @param string $className 类名
     * @param callable $factory 创建实际对象的工厂函数
     * @return object
     */
    public static function createLazyProxy(string $className, callable $factory): object
    {
        if (self::isPhp84() && \method_exists(ReflectionClass::class, 'newLazyProxy')) {
            $reflection = new ReflectionClass($className);
            return $reflection->newLazyProxy($factory);
        }
        
        // 低版本回退：直接调用工厂
        return $factory();
    }
    
    // ==================== 新数组函数 ====================
    
    /**
     * 查找数组中第一个满足条件的元素
     * 
     * PHP 8.4+ 使用内置 array_find()
     * 低版本使用 foreach 模拟
     * 
     * @param array $array 数组
     * @param callable $callback 回调函数
     * @return mixed 找到的元素或 null
     */
    public static function arrayFind(array $array, callable $callback): mixed
    {
        if (\function_exists('array_find')) {
            return \array_find($array, $callback);
        }
        
        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }
        
        return null;
    }
    
    /**
     * 查找数组中第一个满足条件的元素的键
     * 
     * PHP 8.4+ 使用内置 array_find_key()
     * 低版本使用 foreach 模拟
     * 
     * @param array $array 数组
     * @param callable $callback 回调函数
     * @return mixed 找到的键或 null
     */
    public static function arrayFindKey(array $array, callable $callback): mixed
    {
        if (\function_exists('array_find_key')) {
            return \array_find_key($array, $callback);
        }
        
        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $key;
            }
        }
        
        return null;
    }
    
    /**
     * 检查数组中是否有任意元素满足条件
     * 
     * PHP 8.4+ 使用内置 array_any()
     * 低版本使用 foreach 模拟
     * 
     * @param array $array 数组
     * @param callable $callback 回调函数
     * @return bool
     */
    public static function arrayAny(array $array, callable $callback): bool
    {
        if (\function_exists('array_any')) {
            return \array_any($array, $callback);
        }
        
        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 检查数组中是否所有元素都满足条件
     * 
     * PHP 8.4+ 使用内置 array_all()
     * 低版本使用 foreach 模拟
     * 
     * @param array $array 数组
     * @param callable $callback 回调函数
     * @return bool
     */
    public static function arrayAll(array $array, callable $callback): bool
    {
        if (\function_exists('array_all')) {
            return \array_all($array, $callback);
        }
        
        foreach ($array as $key => $value) {
            if (!$callback($value, $key)) {
                return false;
            }
        }
        
        return true;
    }
    
    // ==================== 辅助方法 ====================
    
    /**
     * 清理缓存
     */
    public static function clearCache(): void
    {
        self::$lazyObjectCache = [];
    }
    
    /**
     * 获取性能优化建议
     */
    public static function getOptimizationTips(): array
    {
        $tips = [];
        
        if (!self::isPhp84()) {
            $tips[] = [
                'level' => 'info',
                'message' => __('升级到 PHP 8.4+ 可获得 Lazy Objects 支持，预计启动时间减少 40%%，内存减少 30%%'),
            ];
        }
        
        if (!self::isPhp84() || !\function_exists('array_find')) {
            $tips[] = [
                'level' => 'info',
                'message' => __('PHP 8.4+ 提供新数组函数 (array_find/array_any/array_all)，可简化代码并提升性能'),
            ];
        }
        
        if (!self::isPhp84()) {
            $tips[] = [
                'level' => 'info',
                'message' => __('PHP 8.4+ 支持 Property Hooks，可在 Model 属性上直接定义 get/set 行为'),
            ];
        }
        
        return $tips;
    }
    
    // ==================== Property Hooks 辅助 ====================
    
    /**
     * 获取 Property Hooks 使用指南
     * 
     * Property Hooks 是 PHP 8.4 的语法级特性，无法运行时兼容
     * 此方法返回使用说明和代码示例
     * 
     * @return array 使用指南信息
     */
    public static function getPropertyHooksGuide(): array
    {
        return [
            'available' => self::isPhp84(),
            'description' => 'Property Hooks 允许在属性上直接定义 get/set 行为，无需编写完整的 getter/setter 方法',
            'syntax' => <<<'PHP'
// 基础用法：自动 trim
public string $name {
    get => $this->name;
    set => $this->name = trim($value);
}

// 默认值处理
private string $_status = '';
public string $status {
    get => $this->_status ?: 'pending';
    set => $this->_status = $value;
}

// 数据验证
private string $_email = '';
public string $email {
    get => $this->_email;
    set {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("无效的邮箱格式");
        }
        $this->_email = strtolower(trim($value));
    }
}

// 只读计算属性
public string $formattedPrice {
    get => '¥' . number_format($this->price, 2);
}
PHP,
            'use_cases' => [
                'Model 字段自动处理（trim、格式化）',
                '默认值处理（status、sort_order 等）',
                '数据验证（邮箱格式、数值范围）',
                '计算属性（fullName、formattedPrice）',
                '关联数据懒加载（首次访问时查询）',
            ],
            'example_file' => 'Weline\Framework\Support\Php84PropertyHooksExample',
            'notes' => [
                'Property Hooks 是语法级特性，无法在 PHP 8.4 以下版本使用',
                '需要使用 backing property（如 $_name）存储实际值',
                'set hook 中的 $value 是自动传入的参数',
                '可以只定义 get 或只定义 set',
            ],
        ];
    }
    
    /**
     * 检查 Property Hooks 示例是否可用
     * 
     * @return bool PHP 8.4+ 且示例文件存在
     */
    public static function isPropertyHooksExampleAvailable(): bool
    {
        if (!self::isPhp84()) {
            return false;
        }
        
        // 检查示例文件是否存在
        $exampleFile = __DIR__ . '/Php84PropertyHooksExample.php';
        return \file_exists($exampleFile);
    }
    
    /**
     * 运行 Property Hooks 示例（仅 PHP 8.4+）
     * 
     * @return void
     * @throws \RuntimeException 低版本 PHP
     */
    public static function runPropertyHooksDemo(): void
    {
        if (!self::isPhp84()) {
            throw new \RuntimeException(
                'Property Hooks 示例仅支持 PHP 8.4+，当前版本：' . PHP_VERSION
            );
        }
        
        // 动态加载示例文件（避免低版本解析错误）
        require_once __DIR__ . '/Php84PropertyHooksExample.php';
        
        Php84PropertyHooksExample::demo();
    }
}
