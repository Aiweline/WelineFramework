<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Service;

use Weline\Cdn\Adapter\Cloudflare;
use Weline\Cdn\Api\AdapterInterface;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\File\Scan;

/**
 * 适配器解析器服务
 * 
 * 扫描和解析CDN适配器
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
     * @var array
     */
    private array $adapters = [];

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
     * @return array 适配器代码 => 适配器实例
     */
    public function getAllAdapters(): array
    {
        if (empty($this->adapters)) {
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
        // 1. 扫描 Weline_Cdn 模块的适配器
        $cdnAdapterDir = BP . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR . 'Weline' . DIRECTORY_SEPARATOR . 'Cdn' . DIRECTORY_SEPARATOR . 'Adapter';
        
        if (is_dir($cdnAdapterDir)) {
            $adapterFiles = glob($cdnAdapterDir . DIRECTORY_SEPARATOR . '*.php');
            foreach ($adapterFiles as $adapterFile) {
                $this->loadAdapterFromFile($adapterFile);
            }
        }

        // 2. 扫描其他模块的 Cdn/Adapter 目录
        $this->scanOtherModulesAdapters();
    }

    /**
     * 扫描其他模块的适配器
     * 
     * @return void
     */
    private function scanOtherModulesAdapters(): void
    {
        try {
            $modules = Env::getInstance()->getModuleList();
            
            foreach ($modules as $moduleName => $module) {
                // 跳过 Weline_Cdn 模块本身
                if ($moduleName === 'Weline_Cdn') {
                    continue;
                }
                
                $basePath = $module['base_path'] ?? '';
                if (empty($basePath) || !($module['status'] ?? false)) {
                    continue;
                }
                
                // 构建 Cdn/Adapter 目录路径
                $adapterDir = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'Cdn' . DIRECTORY_SEPARATOR . 'Adapter' . DIRECTORY_SEPARATOR;
                
                if (!is_dir($adapterDir)) {
                    continue;
                }
                
                $adapterFiles = glob($adapterDir . '*.php');
                foreach ($adapterFiles as $adapterFile) {
                    $this->loadAdapterFromFile($adapterFile, $moduleName, $module);
                }
            }
        } catch (\Exception $e) {
            error_log("扫描其他模块CDN适配器失败: " . $e->getMessage());
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
            error_log("加载CDN适配器失败: {$filePath}, 错误: " . $e->getMessage());
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

