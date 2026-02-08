<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Service;

use Weline\Cdn\Api\AdapterInterface;
use Weline\Framework\App\Env;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\File\Scan;

/**
 * 适配器解析器服务
 * 
 * 通过 extends 规约机制扫描和解析 CDN 适配器
 * 使用 ExtendsData::getExtendedBy() 获取已注册的适配器，减少运行时目录扫描
 * 
 * @package Weline_Cdn
 */
class AdapterResolver
{
    private Scan $fileScanner;
    private ObjectManager $objectManager;

    /**
     * 已注册的适配器缓存
     * 
     * @var array<string, AdapterInterface>
     */
    private array $adapters = [];

    /**
     * ExtendsData 缓存的 mtime
     * 
     * @var int|null
     */
    private ?int $cachedExtendsMtime = null;

    public function __construct(
        Scan $fileScanner,
        ObjectManager $objectManager
    ) {
        $this->fileScanner = $fileScanner;
        $this->objectManager = $objectManager;
    }

    /**
     * 获取所有适配器
     * 
     * @param bool $forceReload 是否强制重新加载
     * @return array<string, AdapterInterface> 适配器代码 => 适配器实例
     */
    public function getAllAdapters(bool $forceReload = false): array
    {
        // 检查 extends 注册表是否更新
        if (!$forceReload && !empty($this->adapters)) {
            try {
                $currentMtime = ExtendsData::getRegistryFileMtime();
                if ($this->cachedExtendsMtime !== null && $currentMtime === $this->cachedExtendsMtime) {
                    return $this->adapters;
                }
            } catch (\Exception $e) {
                // 忽略，继续使用缓存
                if (!empty($this->adapters)) {
                    return $this->adapters;
                }
            }
        }

        if (empty($this->adapters) || $forceReload) {
            $this->adapters = [];
            $this->scanAdapters();
        }

        return $this->adapters;
    }

    /**
     * 获取适配器实例
     * 
     * @param string $adapterCode 适配器代码
     * @return AdapterInterface|null
     */
    public function getAdapter(string $adapterCode): ?AdapterInterface
    {
        $adapters = $this->getAllAdapters();
        return $adapters[$adapterCode] ?? null;
    }

    /**
     * 扫描所有适配器
     * 
     * @return void
     */
    private function scanAdapters(): void
    {
        // 1. 扫描 Weline_Cdn 模块本身的适配器
        $cdnAdapterDir = BP . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'code' 
            . DIRECTORY_SEPARATOR . 'Weline' . DIRECTORY_SEPARATOR . 'Cdn' . DIRECTORY_SEPARATOR . 'Adapter';
        
        if (is_dir($cdnAdapterDir)) {
            $adapterFiles = glob($cdnAdapterDir . DIRECTORY_SEPARATOR . '*.php');
            foreach ($adapterFiles as $adapterFile) {
                $this->loadAdapterFromFile($adapterFile);
            }
        }

        // 2. 通过 ExtendsData 获取其他模块的适配器（使用 extends 规约机制）
        $this->scanExtendsAdapters();

        // 更新缓存的 mtime
        try {
            $this->cachedExtendsMtime = ExtendsData::getRegistryFileMtime();
        } catch (\Exception $e) {
            // 忽略
        }
    }

    /**
     * 通过 ExtendsData 扫描其他模块的适配器
     * 
     * 使用 extends 规约机制，从 ExtendsData 获取已注册的适配器
     * 减少运行时目录扫描，提高性能
     * 
     * @return void
     */
    private function scanExtendsAdapters(): void
    {
        try {
            // 从 ExtendsData 获取扩展了 Weline_Cdn 的模块
            $extendedBy = ExtendsData::getExtendedBy('Weline_Cdn');
            
            if (empty($extendedBy)) {
                return;
            }
            
            // 获取模块列表以获取模块路径
            $moduleList = Env::getInstance()->getModuleList();
            
            // 遍历所有扩展了 Weline_Cdn 的源模块
            foreach ($extendedBy as $sourceModule => $extensions) {
                // 获取源模块的路径
                if (!isset($moduleList[$sourceModule])) {
                    continue;
                }
                
                $moduleBasePath = $moduleList[$sourceModule]['base_path'] ?? '';
                if (empty($moduleBasePath)) {
                    continue;
                }
                
                // 检查模块是否启用
                if (!($moduleList[$sourceModule]['status'] ?? false)) {
                    continue;
                }
                
                // 构建适配器目录路径：extends/module/Weline_Cdn/Adapter
                $adapterDir = rtrim($moduleBasePath, '/\\') . DIRECTORY_SEPARATOR 
                    . 'extends' . DIRECTORY_SEPARATOR 
                    . 'module' . DIRECTORY_SEPARATOR 
                    . 'Weline_Cdn' . DIRECTORY_SEPARATOR 
                    . 'Adapter';
                
                // 检查目录是否存在
                if (!is_dir($adapterDir)) {
                    continue;
                }
                
                // 扫描该目录下的所有适配器文件
                $adapterFiles = glob($adapterDir . DIRECTORY_SEPARATOR . '*.php');
                
                foreach ($adapterFiles as $adapterFile) {
                    $this->loadAdapterFromFile($adapterFile, $sourceModule, $moduleList[$sourceModule]);
                }
            }
        } catch (\Exception $e) {
            error_log("从 ExtendsData 扫描 CDN 适配器失败: " . $e->getMessage());
        }
    }

    /**
     * 从文件加载适配器
     * 
     * @param string $filePath 文件路径
     * @param string|null $moduleName 模块名称
     * @param array|null $module 模块信息
     * @return void
     */
    private function loadAdapterFromFile(string $filePath, ?string $moduleName = null, ?array $module = null): void
    {
        if (!file_exists($filePath)) {
            return;
        }

        require_once $filePath;

        // 获取类名
        $className = $this->getClassNameFromFile($filePath, $moduleName, $module);
        
        if (!$className || !class_exists($className, false)) {
            return;
        }

        try {
            $instance = $this->objectManager->getInstance($className);
            
            if (!$instance instanceof AdapterInterface) {
                return;
            }

            $adapterCode = $instance->getAdapterCode();
            $this->adapters[$adapterCode] = $instance;
        } catch (\Exception $e) {
            error_log("加载 CDN 适配器失败: {$filePath}, 错误: " . $e->getMessage());
        }
    }

    /**
     * 从文件路径获取类名
     * 
     * @param string $filePath 文件路径
     * @param string|null $moduleName 模块名称
     * @param array|null $module 模块信息
     * @return string|null
     */
    private function getClassNameFromFile(string $filePath, ?string $moduleName = null, ?array $module = null): ?string
    {
        // 如果是 extends 目录下的文件，从文件内容中解析命名空间
        if (str_contains($filePath, DIRECTORY_SEPARATOR . 'extends' . DIRECTORY_SEPARATOR) 
            || str_contains($filePath, '/extends/')) {
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
        
        $fileName = basename($filePath, '.php');
        
        // 如果是 Weline_Cdn 模块
        if (!$moduleName || $moduleName === 'Weline_Cdn') {
            return 'Weline\\Cdn\\Adapter\\' . $fileName;
        }

        // 其他模块：需要从模块信息中获取命名空间
        if ($module && isset($module['namespace_path'])) {
            return $module['namespace_path'] . '\\Cdn\\Adapter\\' . $fileName;
        }

        // 尝试从路径推断命名空间
        $relativePath = str_replace(BP . DIRECTORY_SEPARATOR, '', $filePath);
        $relativePath = str_replace(['.php', '\\'], ['', '\\'], $relativePath);
        $relativePath = str_replace('/', '\\', $relativePath);
        
        return $relativePath;
    }
}

