<?php

namespace WelineTools\FontSubLetter\Service;

/**
 * 字体子集化工厂类
 * 根据系统环境和需求选择最合适的字体子集化方法
 */
class FontSubsetterFactory
{
    /**
     * 可用的字体子集化器
     */
    private const SUBSETTERS = [
        'practical_php' => PracticalPhpFontSubsetter::class,
        'advanced_native_php' => AdvancedPhpFontSubsetter::class,
        'native_php' => NativePhpFontSubsetter::class,
        'real' => RealFontSubsetter::class,
        'php' => PhpFontSubsetter::class
    ];

    /**
     * 获取最佳的字体子集化器
     */
    public static function getBestSubsetter(): object
    {
        // 按优先级尝试不同的子集化器
        $priorities = [
            'practical_php',
            'advanced_native_php',
            'native_php', 
            'real',
            'php'
        ];

        foreach ($priorities as $method) {
            if (isset(self::SUBSETTERS[$method])) {
                $subsetterClass = self::SUBSETTERS[$method];
                $subsetter = new $subsetterClass();
                
                if (self::isSubsetterAvailable($subsetter, $method)) {
                    return $subsetter;
                }
            }
        }

        // 如果都不可用，返回基础的PHP子集化器
        return new PhpFontSubsetter();
    }

    /**
     * 检查子集化器是否可用
     */
    private static function isSubsetterAvailable(object $subsetter, string $method): bool
    {
        try {
            switch ($method) {
                case 'practical_php':
                    // 检查PHP版本和必要扩展
                    if (version_compare(PHP_VERSION, '7.4.0', '<')) {
                        return false;
                    }
                    
                    // 检查必要的PHP扩展
                    $requiredExtensions = ['mbstring', 'gd'];
                    foreach ($requiredExtensions as $ext) {
                        if (!extension_loaded($ext)) {
                            return false;
                        }
                    }
                    
                    return true;

                case 'advanced_native_php':
                case 'native_php':
                    // 检查PHP版本和必要扩展
                    if (version_compare(PHP_VERSION, '7.4.0', '<')) {
                        return false;
                    }
                    
                    // 检查必要的PHP扩展
                    $requiredExtensions = ['mbstring'];
                    foreach ($requiredExtensions as $ext) {
                        if (!extension_loaded($ext)) {
                            return false;
                        }
                    }
                    
                    return true;



                case 'real':
                    // 检查php-font-lib库是否可用
                    return class_exists('FontLib\Font');

                case 'php':
                    // 基础PHP子集化器总是可用的
                    return true;

                default:
                    return false;
            }
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 获取指定的字体子集化器
     */
    public static function getSubsetter(string $method): object
    {
        if (!isset(self::SUBSETTERS[$method])) {
            throw new \Exception('不支持的字体子集化方法: ' . $method);
        }

        $subsetterClass = self::SUBSETTERS[$method];
        return new $subsetterClass();
    }

    /**
     * 获取所有可用的子集化器
     */
    public static function getAvailableSubsetters(): array
    {
        $available = [];

        foreach (self::SUBSETTERS as $method => $class) {
            try {
                $subsetter = new $class();
                if (self::isSubsetterAvailable($subsetter, $method)) {
                    $available[$method] = [
                        'class' => $class,
                        'method' => $method,
                        'description' => self::getMethodDescription($method)
                    ];
                }
            } catch (\Exception $e) {
                // 跳过不可用的子集化器
                continue;
            }
        }

        return $available;
    }

    /**
     * 获取方法描述
     */
    private static function getMethodDescription(string $method): string
    {
        $descriptions = [
            'practical_php' => '实用PHP实现，创建包含选定字符的有效字体文件',
            'advanced_native_php' => '高级原生PHP实现，支持真正的字体子集化，包括字符映射表和字形数据处理',
            'native_php' => '原生PHP实现，使用二进制处理能力实现字体子集化',
            'real' => '使用php-font-lib库实现字体子集化',
            'php' => '基础PHP实现，使用GD库处理字体'
        ];

        return $descriptions[$method] ?? '未知方法';
    }

    /**
     * 获取系统兼容性信息
     */
    public static function getSystemCompatibility(): array
    {
        $compatibility = [
            'php_version' => PHP_VERSION,
            'extensions' => [
                'mbstring' => extension_loaded('mbstring'),
                'iconv' => extension_loaded('iconv'),
                'gd' => extension_loaded('gd'),
                'curl' => extension_loaded('curl')
            ],
            'available_subsetters' => self::getAvailableSubsetters(),
            'recommended_method' => self::getRecommendedMethod()
        ];

        return $compatibility;
    }

    /**
     * 获取推荐的方法
     */
    private static function getRecommendedMethod(): string
    {
        $available = self::getAvailableSubsetters();
        
        // 按优先级返回第一个可用的方法
        $priorities = ['practical_php', 'advanced_native_php', 'native_php', 'real', 'php'];
        
        foreach ($priorities as $method) {
            if (isset($available[$method])) {
                return $method;
            }
        }

        return 'php'; // 默认返回基础PHP方法
    }

    /**
     * 创建字体子集（使用最佳方法）
     */
    public static function createSubset(string $inputPath, string $outputPath, array $selectedChars): array
    {
        $subsetter = self::getBestSubsetter();
        return $subsetter->createSubset($inputPath, $outputPath, $selectedChars);
    }

    /**
     * 创建字体子集（使用指定方法）
     */
    public static function createSubsetWithMethod(string $method, string $inputPath, string $outputPath, array $selectedChars): array
    {
        $subsetter = self::getSubsetter($method);
        return $subsetter->createSubset($inputPath, $outputPath, $selectedChars);
    }

    /**
     * 验证字体文件（使用最佳方法）
     */
    public static function validateFont(string $fontPath): bool
    {
        $subsetter = self::getBestSubsetter();
        return $subsetter->validateFont($fontPath);
    }

    /**
     * 获取字体信息（使用最佳方法）
     */
    public static function getFontInfo(string $fontPath): array
    {
        $subsetter = self::getBestSubsetter();
        return $subsetter->getFontInfo($fontPath);
    }

    /**
     * 获取支持的格式
     */
    public static function getSupportedFormats(): array
    {
        $subsetter = self::getBestSubsetter();
        if (method_exists($subsetter, 'getSupportedFormats')) {
            return $subsetter->getSupportedFormats();
        }
        
        // 默认支持的格式
        return ['ttf', 'otf'];
    }

    /**
     * 检查系统是否支持字体子集化
     */
    public static function isSupported(): bool
    {
        $available = self::getAvailableSubsetters();
        return !empty($available);
    }

    /**
     * 获取最佳子集化器的信息
     */
    public static function getBestSubsetterInfo(): array
    {
        $subsetter = self::getBestSubsetter();
        $method = self::getRecommendedMethod();
        
        return [
            'method' => $method,
            'class' => get_class($subsetter),
            'description' => self::getMethodDescription($method),
            'supported_formats' => self::getSupportedFormats()
        ];
    }
}
