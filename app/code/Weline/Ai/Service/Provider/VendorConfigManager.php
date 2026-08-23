<?php
declare(strict_types=1);

namespace Weline\Ai\Service\Provider;

use Weline\Ai\Model\Provider\CustomVendor;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;

/**
 * 供应商配置管理器
 * 
 * 负责动态读取和管理供应商配置文件，并合并 DB 自定义/本地供应商
 * 
 * @package Weline_Ai
 */
class VendorConfigManager
{
    /**
     * @var array 供应商配置缓存（内置 JSON + 自定义 DB）
     */
    private static array $configCache = [];

    /**
     * @var list<string>|null 仅内置 JSON 的 code 列表
     */
    private static ?array $builtinCodes = null;

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
     * 仅内置 etc/vendors/*.json 的 code（不含自定义）
     *
     * @return list<string>
     */
    public static function getBuiltinProviderCodes(): array
    {
        if (self::$builtinCodes === null) {
            self::loadVendorConfigs();
        }

        return self::$builtinCodes ?? [];
    }

    /**
     * 是否为自定义/本地供应商（目录中 source=custom）
     */
    public static function isCustomProvider(string $providerCode): bool
    {
        $config = self::getProviderConfig($providerCode);
        return is_array($config) && (($config['source'] ?? '') === CustomVendor::SOURCE_CUSTOM);
    }

    /**
     * 获取指定供应商的配置
     * 
     * @param string $providerCode
     * @return array|null
     */
    public static function getProviderConfig(string $providerCode): ?array
    {
        $providerCode = strtolower(trim($providerCode));
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
        $providerCode = strtolower(trim($providerCode));
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
     * 获取指定供应商支持的模型列表
     * 
     * @param string $providerCode
     * @return array
     */
    public static function getProviderModels(string $providerCode): array
    {
        $config = self::getProviderConfig($providerCode);
        return $config['models'] ?? [];
    }

    /**
     * 获取所有供应商及其模型列表
     * 
     * @return array
     */
    public static function getAllProvidersWithModels(): array
    {
        $providers = self::getSupportedProviders();
        $result = [];
        
        foreach ($providers as $providerCode => $config) {
            $result[$providerCode] = [
                'name' => $config['name'] ?? $providerCode,
                'code' => $providerCode,
                'description' => $config['description'] ?? '',
                'base_url' => $config['base_url'] ?? '',
                'models' => $config['models'] ?? [],
                'source' => $config['source'] ?? 'builtin',
                'driver' => $config['driver'] ?? null,
            ];
        }
        
        return $result;
    }

    /**
     * 加载所有供应商配置文件并合并自定义供应商
     * 
     * @return void
     */
    private static function loadVendorConfigs(): void
    {
        self::$configCache = [];
        self::$builtinCodes = [];

        $configDir = self::getVendorsConfigDir();
        
        if (!is_dir($configDir)) {
            Env::log('ai_vendor_config.log', "供应商配置目录不存在: {$configDir}", 'WARNING');
        } else {
            $files = glob($configDir . '/*.json') ?: [];
            $loadedProviders = [];

            foreach ($files as $file) {
                $providerCode = basename($file, '.json');
                
                if (isset($loadedProviders[$providerCode])) {
                    Env::log('ai_vendor_config.log', "发现重复的供应商配置: {$providerCode}", 'WARNING');
                    continue;
                }

                $config = self::loadConfigFile($file);
                if ($config) {
                    if (self::validateConfig($config, $providerCode)) {
                        if (!isset($config['source'])) {
                            $config['source'] = 'builtin';
                        }
                        self::$configCache[$providerCode] = $config;
                        self::$builtinCodes[] = $providerCode;
                        $loadedProviders[$providerCode] = true;
                    }
                }
            }
        }

        self::mergeCustomVendors();

        Env::log('ai_vendor_config.log', "成功加载 " . count(self::$configCache) . " 个供应商配置", 'INFO');
    }

    private static function mergeCustomVendors(): void
    {
        try {
            /** @var CustomVendorService $service */
            $service = ObjectManager::getInstance(CustomVendorService::class);
            $rows = $service->listRows(true);
            foreach ($rows as $row) {
                $config = $service->toVendorConfig($row);
                $code = (string)($config['code'] ?? '');
                if ($code === '') {
                    continue;
                }
                if (isset(self::$configCache[$code]) && !self::isCustomProvider($code)) {
                    // 不应覆盖内置；跳过并记日志
                    Env::log('ai_vendor_config.log', "自定义供应商 code 与内置冲突，已跳过: {$code}", 'WARNING');
                    continue;
                }
                self::$configCache[$code] = $config;
            }
        } catch (\Throwable $e) {
            // 表尚未创建或 DB 不可用时不影响内置目录
            Env::log('ai_vendor_config.log', '合并自定义供应商失败: ' . $e->getMessage(), 'WARNING');
        }
    }

    private static function getVendorsConfigDir(): string
    {
        $currentFileDir = dirname(__FILE__); // Service/Provider
        $serviceDir = dirname($currentFileDir); // Service
        $moduleDir = dirname($serviceDir); // Ai
        return $moduleDir . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'vendors';
    }

    /**
     * 获取供应商配置文件路径
     *
     * @param string $providerCode
     * @return string|null
     */
    public static function getProviderConfigPath(string $providerCode): ?string
    {
        $configDir = self::getVendorsConfigDir();
        $path = $configDir . DIRECTORY_SEPARATOR . $providerCode . '.json';

        return file_exists($path) ? $path : null;
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
        self::$builtinCodes = null;
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
