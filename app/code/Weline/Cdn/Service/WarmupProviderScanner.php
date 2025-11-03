<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\File\Scan;

/**
 * 预热Provider扫描器
 * 
 * 扫描所有模块的Cdn/WarmupProvider.php文件
 * 
 * @package Weline_Cdn
 */
class WarmupProviderScanner
{
    private Scan $fileScanner;
    private ObjectManager $objectManager;

    public function __construct(
        Scan $fileScanner,
        ObjectManager $objectManager
    ) {
        $this->fileScanner = $fileScanner;
        $this->objectManager = $objectManager;
    }

    /**
     * 扫描所有WarmupProvider
     * 
     * @return array Provider类名数组
     */
    public function scanProviders(): array
    {
        $providers = [];
        
        try {
            $modules = Env::getInstance()->getModuleList();
            
            foreach ($modules as $moduleName => $module) {
                $basePath = $module['base_path'] ?? '';
                if (empty($basePath) || !($module['status'] ?? false)) {
                    continue;
                }
                
                // 构建 Cdn/WarmupProvider.php 路径
                $providerFile = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'Cdn' . DIRECTORY_SEPARATOR . 'WarmupProvider.php';
                
                if (!file_exists($providerFile)) {
                    continue;
                }
                
                // 获取类名
                $className = $this->getClassNameFromFile($providerFile, $moduleName, $module);
                if ($className && class_exists($className)) {
                    $providers[] = $className;
                }
            }
        } catch (\Exception $e) {
            error_log("扫描WarmupProvider失败: " . $e->getMessage());
        }
        
        return $providers;
    }

    /**
     * 执行所有Provider收集URL
     * 
     * @return array URL数组
     */
    public function collectUrls(): array
    {
        $urls = [];
        $providers = $this->scanProviders();
        
        foreach ($providers as $className) {
            try {
                if (class_exists($className) && method_exists($className, 'execute')) {
                    $providerUrls = call_user_func([$className, 'execute']);
                    if (is_array($providerUrls)) {
                        $urls = array_merge($urls, $providerUrls);
                    }
                }
            } catch (\Exception $e) {
                error_log("执行WarmupProvider失败: {$className}, 错误: " . $e->getMessage());
            }
        }
        
        return $urls;
    }

    /**
     * 从文件路径获取类名
     * 
     * @param string $filePath 文件路径
     * @param string $moduleName 模块名称
     * @param array $module 模块信息
     * @return string|null
     */
    private function getClassNameFromFile(string $filePath, string $moduleName, array $module): ?string
    {
        // 尝试从模块信息中获取命名空间
        if (isset($module['namespace_path'])) {
            return $module['namespace_path'] . '\\Cdn\\WarmupProvider';
        }

        // 尝试从路径推断命名空间
        $relativePath = str_replace(BP . DIRECTORY_SEPARATOR, '', $filePath);
        $relativePath = str_replace(['.php', '\\'], ['', '\\'], $relativePath);
        $relativePath = str_replace('/', '\\', $relativePath);
        
        return $relativePath;
    }
}

