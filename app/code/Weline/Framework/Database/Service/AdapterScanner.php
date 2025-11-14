<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2025/01/XX
 */

namespace Weline\Framework\Database\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Service\DriverRegistry;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\System\File\Scan;

/**
 * 数据库适配器扫描器服务
 * 
 * 功能：
 * - 自动扫描适配器目录
 * - 注册新发现的适配器
 * - 更新适配器信息
 * - 验证适配器有效性
 */
class AdapterScanner
{
    /**
     * Framework 适配器目录
     */
    private const FRAMEWORK_ADAPTER_DIR = 'app/code/Weline/Framework/Database/Connection/Adapter/';
    
    /**
     * 扩展适配器目录
     */
    private const EXTENDS_ADAPTER_DIR = 'extends/Weline_Framework/Connection/Adapter/';
    
    /**
     * 适配器文件名称
     */
    private const CONNECTOR_FILE = 'Connector.php';

    /**
     * @var DriverRegistry
     */
    private DriverRegistry $driverRegistry;

    /**
     * @var Scan
     */
    private Scan $fileScanner;

    /**
     * 构造函数
     * 
     * @param DriverRegistry $driverRegistry
     * @param Scan $fileScanner
     */
    public function __construct(
        DriverRegistry $driverRegistry,
        Scan $fileScanner
    ) {
        $this->driverRegistry = $driverRegistry;
        $this->fileScanner = $fileScanner;
    }

    /**
     * 扫描所有适配器
     * 
     * @return array 扫描到的适配器信息 ['driver_type' => 'class_name', ...]
     * @throws \Exception
     */
    public function scanAllAdapters(): array
    {
        $scannedAdapters = [];
        
        // 1. 扫描 Framework 内置适配器
        $frameworkAdapters = $this->scanFrameworkAdapters();
        $scannedAdapters = array_merge($scannedAdapters, $frameworkAdapters);
        
        // 2. 扫描扩展适配器
        $extendsAdapters = $this->scanExtendsAdapters();
        $scannedAdapters = array_merge($scannedAdapters, $extendsAdapters);

        // 3. 更新驱动注册表
        if (!empty($scannedAdapters)) {
            $this->driverRegistry->updateDrivers($scannedAdapters);
        }

        return $scannedAdapters;
    }
    
    /**
     * 扫描 Framework 内置适配器
     * 
     * @return array
     */
    private function scanFrameworkAdapters(): array
    {
        $adapters = [];
        
        try {
            $adapterDir = BP . DIRECTORY_SEPARATOR . self::FRAMEWORK_ADAPTER_DIR;
            
            if (!is_dir($adapterDir)) {
                return $adapters;
            }
            
            // 扫描所有子目录（每个子目录代表一个驱动类型）
            $subDirs = glob($adapterDir . '*', GLOB_ONLYDIR);
            
            foreach ($subDirs ?? [] as $subDir) {
                try {
                    $driverType = basename($subDir);
                    $connectorFile = $subDir . DIRECTORY_SEPARATOR . self::CONNECTOR_FILE;
                    
                    if (file_exists($connectorFile)) {
                        $className = $this->getClassNameFromFrameworkFile($connectorFile, $driverType);
                        
                        if ($className && $this->validateAdapter($className)) {
                            $adapters[strtolower($driverType)] = $className;
                        }
                    }
                } catch (\Throwable $e) {
                    // 单个适配器扫描失败不影响其他适配器
                    error_log("扫描 Framework 适配器 {$subDir} 失败: " . $e->getMessage());
                    continue;
                }
            }
        } catch (\Exception $e) {
            error_log("扫描 Framework 适配器失败: " . $e->getMessage());
        }
        
        return $adapters;
    }
    
    /**
     * 扫描扩展适配器
     * 使用 ExtendsData 读取 Weline_Framework 模块的 Connection/Adapter 扩展点文件
     * 
     * @return array
     */
    private function scanExtendsAdapters(): array
    {
        $adapters = [];
        
        try {
            // 从 ExtendsData 获取 Weline_Framework 模块的扩展信息
            // 注意：如果 Weline_Framework 模块没有定义 extends.php，这里会返回空数组
            $extendedBy = ExtendsData::getExtendedBy('Weline_Framework');
            
            if (empty($extendedBy)) {
                return $adapters;
            }
            
            // 遍历所有扩展该模块的源模块
            foreach ($extendedBy as $sourceModule => $extensions) {
                foreach ($extensions as $extension) {
                    // 检查是否是 Connection/Adapter 扩展点的文件
                    // 扩展点路径应该是 'extends/module/Weline_Framework/Connection/Adapter'
                    // 所以 relative_path 应该以 'extends/module/Weline_Framework/Connection/Adapter/' 开头
                    $filePath = $extension['file_path'] ?? '';
                    $relativePath = $extension['relative_path'] ?? '';
                    
                    // 检查路径是否匹配 Connection/Adapter 扩展点
                    $isAdapterFile = str_starts_with($relativePath, 'extends/module/Weline_Framework/Connection/Adapter/')
                        || (str_contains($relativePath, '/Connection/Adapter/') && str_ends_with($filePath ?? '', 'Connector.php'));
                    
                    if ($isAdapterFile) {
                        // 获取源文件路径
                        $sourceFile = $extension['source_file'] ?? '';
                        if (empty($sourceFile) || !file_exists($sourceFile)) {
                            continue;
                        }
                        
                        // 只处理 Connector.php 文件
                        if (!str_ends_with($sourceFile, 'Connector.php')) {
                            continue;
                        }
                        
                        // 从文件路径中提取驱动类型（目录名）
                        // 例如：extends/module/Weline_Framework/Connection/Adapter/Mysql/Connector.php
                        // driverType 应该是 'Mysql'
                        $pathParts = explode('/', str_replace('\\', '/', $relativePath));
                        $adapterIndex = array_search('Adapter', $pathParts);
                        if ($adapterIndex !== false && isset($pathParts[$adapterIndex + 1])) {
                            $driverType = $pathParts[$adapterIndex + 1];
                            
                            try {
                                $className = $this->getClassNameFromExtendsFile($sourceFile);
                                
                                if ($className && $this->validateAdapter($className)) {
                                    // 扩展适配器优先级更高，覆盖 Framework 内置适配器
                                    $adapters[strtolower($driverType)] = $className;
                                }
                            } catch (\Throwable $e) {
                                error_log("加载扩展适配器失败: {$sourceFile}, 错误: " . $e->getMessage());
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            error_log("从 ExtendsData 扫描扩展适配器失败: " . $e->getMessage());
        }
        
        return $adapters;
    }

    /**
     * 从 Framework 文件获取类名
     * 
     * @param string $filePath
     * @param string $driverType
     * @return string|null
     */
    private function getClassNameFromFrameworkFile(string $filePath, string $driverType): ?string
    {
        // Framework 内置适配器的命名空间是固定的
        $driverType = ucfirst(strtolower($driverType));
        return "\\Weline\\Framework\\Database\\Connection\\Adapter\\{$driverType}\\Connector";
    }

    /**
     * 从扩展文件获取类名
     * 
     * @param string $filePath
     * @return string|null
     */
    private function getClassNameFromExtendsFile(string $filePath): ?string
    {
        // 读取文件内容，解析命名空间和类名
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }
        
        // 解析命名空间
        if (preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatches)) {
            $namespace = trim($namespaceMatches[1]);
            
            // 解析类名
            if (preg_match('/class\s+(\w+)/', $content, $classMatches)) {
                $className = $classMatches[1];
                return "\\{$namespace}\\{$className}";
            }
        }
        
        return null;
    }

    /**
     * 验证适配器
     * 
     * @param string $className
     * @return bool
     */
    private function validateAdapter(string $className): bool
    {
        try {
            // 检查类是否存在
            if (!class_exists($className, false)) {
                // 尝试加载文件
                try {
                    $reflection = new \ReflectionClass($className);
                } catch (\ReflectionException $e) {
                    error_log("适配器类不存在: {$className}, 错误: " . $e->getMessage());
                    return false;
                }
            }
            
            // 检查是否实现了接口
            if (!is_subclass_of($className, ConnectorInterface::class)) {
                error_log("适配器类 {$className} 未实现 ConnectorInterface 接口");
                return false;
            }
            
            return true;
        } catch (\Throwable $e) {
            // 捕获所有异常，避免单个适配器问题影响整个扫描
            error_log("验证适配器 {$className} 时发生错误: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取适配器统计信息
     * 
     * @return array
     */
    public function getAdapterStats(): array
    {
        $drivers = $this->driverRegistry->getAllDrivers();
        
        return [
            'total' => count($drivers ?? []),
            'drivers' => array_keys($drivers ?? [])
        ];
    }
}

