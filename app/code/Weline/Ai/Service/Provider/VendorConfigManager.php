<?php
declare(strict_types=1);

namespace Weline\Ai\Service\Provider;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;

/**
 * 供应商配置管理器
 * 
 * 负责动态读取和管理供应商配置文件
 * 
 * @package Weline_Ai
 */
class VendorConfigManager
{
    /**
     * @var array 供应商配置缓存
     */
    private static array $configCache = [];

    /**
     * @var string 配置文件目录
     */
    private static string $configDir = 'etc/vendors';

    /**
     * 获取所有支持的供应商配置
     * 
     * @return array
     */
    public static function getSupportedProviders(): array
    {
        if (empty(self::$configCache)) {
            self::loadVendorConfigs();
        }
        
        return self::$configCache;
    }

    /**
     * 获取指定供应商的配置
     * 
     * @param string $providerCode
     * @return array|null
     */
    public static function getProviderConfig(string $providerCode): ?array
    {
        $providers = self::getSupportedProviders();
        return $providers[$providerCode] ?? null;
    }

    /**
     * 检查供应商是否支持
     * 
     * @param string $providerCode
     * @return bool
     */
    public static function isProviderSupported(string $providerCode): bool
    {
        return self::getProviderConfig($providerCode) !== null;
    }

    /**
     * 获取供应商的测试模型
     * 
     * @param string $providerCode
     * @return string|null
     */
    public static function getTestModel(string $providerCode): ?string
    {
        $config = self::getProviderConfig($providerCode);
        return $config['test_model'] ?? null;
    }

    /**
     * 获取供应商的基础URL
     * 
     * @param string $providerCode
     * @return string|null
     */
    public static function getBaseUrl(string $providerCode): ?string
    {
        $config = self::getProviderConfig($providerCode);
        return $config['base_url'] ?? null;
    }

    /**
     * 检查模型是否属于指定供应商
     * 
     * @param string $modelCode
     * @param string $providerCode
     * @return bool
     */
    public static function isModelFromProvider(string $modelCode, string $providerCode): bool
    {
        $config = self::getProviderConfig($providerCode);
        if (!$config) {
            return false;
        }

        $prefixes = $config['models_prefix'] ?? [];
        foreach ($prefixes as $prefix) {
            if (str_starts_with($modelCode, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 根据模型代码推断供应商
     * 
     * @param string $modelCode
     * @return string|null
     */
    public static function getProviderByModelCode(string $modelCode): ?string
    {
        $providers = self::getSupportedProviders();
        
        foreach ($providers as $providerCode => $config) {
            if (self::isModelFromProvider($modelCode, $providerCode)) {
                return $providerCode;
            }
        }

        return null;
    }

    /**
     * 加载所有供应商配置文件
     * 
     * @return void
     */
    private static function loadVendorConfigs(): void
    {
        // 获取当前文件的目录，然后相对于当前模块的etc目录
        // VendorConfigManager.php 位于: app/code/Weline/Ai/Service/Provider/
        // 需要回到模块根目录: app/code/Weline/Ai/
        // 然后进入etc目录: app/code/Weline/Ai/etc/
        $currentFileDir = dirname(__FILE__); // Service/Provider
        $serviceDir = dirname($currentFileDir); // Service
        $moduleDir = dirname($serviceDir); // Ai
        $configDir = $moduleDir . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'vendors';
        
        if (!is_dir($configDir)) {
            Env::log('ai_vendor_config.log', "供应商配置目录不存在: {$configDir}", 'WARNING');
            return;
        }

        $files = glob($configDir . '/*.json');
        $loadedProviders = [];

        foreach ($files as $file) {
            $providerCode = basename($file, '.json');
            
            // 供应商去重检查
            if (isset($loadedProviders[$providerCode])) {
                Env::log('ai_vendor_config.log', "发现重复的供应商配置: {$providerCode}", 'WARNING');
                continue;
            }

            $config = self::loadConfigFile($file);
            if ($config) {
                // 验证配置完整性
                if (self::validateConfig($config, $providerCode)) {
                    self::$configCache[$providerCode] = $config;
                    $loadedProviders[$providerCode] = true;
                }
            }
        }

        Env::log('ai_vendor_config.log', "成功加载 " . count(self::$configCache) . " 个供应商配置", 'INFO');
    }

    /**
     * 加载单个配置文件
     * 
     * @param string $filePath
     * @return array|null
     */
    private static function loadConfigFile(string $filePath): ?array
    {
        if (!file_exists($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            Env::log('ai_vendor_config.log', "无法读取配置文件: {$filePath}", 'ERROR');
            return null;
        }

        $config = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Env::log('ai_vendor_config.log', "配置文件JSON解析失败: {$filePath}, 错误: " . json_last_error_msg(), 'ERROR');
            return null;
        }

        return $config;
    }

    /**
     * 验证配置完整性
     * 
     * @param array $config
     * @param string $providerCode
     * @return bool
     */
    private static function validateConfig(array $config, string $providerCode): bool
    {
        $requiredFields = ['name', 'code', 'base_url', 'test_model', 'api_key_field', 'model_field'];
        
        foreach ($requiredFields as $field) {
            if (!isset($config[$field]) || empty($config[$field])) {
                Env::log('ai_vendor_config.log', "供应商配置缺少必需字段 '{$field}': {$providerCode}", 'ERROR');
                return false;
            }
        }

        // 验证code字段与文件名一致
        if ($config['code'] !== $providerCode) {
            Env::log('ai_vendor_config.log', "供应商配置code字段与文件名不一致: {$providerCode} vs {$config['code']}", 'ERROR');
            return false;
        }

        return true;
    }

    /**
     * 清除配置缓存
     * 
     * @return void
     */
    public static function clearCache(): void
    {
        self::$configCache = [];
    }

    /**
     * 重新加载配置
     * 
     * @return void
     */
    public static function reloadConfigs(): void
    {
        self::clearCache();
        self::loadVendorConfigs();
    }
}
