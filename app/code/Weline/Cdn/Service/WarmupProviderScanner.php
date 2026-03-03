<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Service;

use Weline\Cdn\Api\WarmupProviderInterface;
use Weline\Framework\App\Env;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\File\Scan;

/**
 * 预热Provider扫描器
 * 
 * 从 ExtendsData 读取所有模块的 WarmupProvider 扩展
 * 使用 extends 机制，参考 Sticker 模块的实现方式
 * 
 * @package Weline_Cdn
 */
class WarmupProviderScanner
{
    private Scan $fileScanner;
    private ObjectManager $objectManager;
    private ?array $cachedProviders = null;
    private ?int $cachedExtendsMtime = null;

    public function __construct(
        Scan $fileScanner,
        ObjectManager $objectManager
    ) {
        $this->fileScanner = $fileScanner;
        $this->objectManager = $objectManager;
    }

    /**
     * 扫描所有WarmupProvider
     * 从 ExtendsData 读取数据，不再直接扫描文件系统
     * 
     * @param bool $forceReload 强制重新加载
     * @return array Provider类名数组
     */
    public function scanProviders(bool $forceReload = false): array
    {
        // 内存缓存机制
        if (!$forceReload && $this->cachedProviders !== null) {
            $currentMtime = ExtendsData::getRegistryFileMtime();
            if ($currentMtime === $this->cachedExtendsMtime) {
                return $this->cachedProviders;
            }
        }

        $providers = [];
        
        try {
            // 从 ExtendsData 读取所有扩展 Weline_Cdn 的模块信息
            $extendedBy = ExtendsData::getExtendedBy('Weline_Cdn', $forceReload);
            
            if (empty($extendedBy)) {
                $this->cachedProviders = [];
                $this->cachedExtendsMtime = ExtendsData::getRegistryFileMtime();
                return [];
            }

            $modules = Env::getInstance()->getModuleList();
            
            // 遍历所有扩展 Weline_Cdn 的模块
            foreach ($extendedBy as $sourceModule => $extensions) {
                foreach ($extensions as $extension) {
                    // 只处理模块级扩展，排除 Sticker 扩展
                    if (($extension['is_sticker_extension'] ?? false) === true) {
                        continue;
                    }
                    
                    // 只处理路径为 extends/module/Weline_Cdn 的扩展
                    $relativePath = $extension['relative_path'] ?? '';
                    if (!str_starts_with($relativePath, 'extends/module/Weline_Cdn/')) {
                        continue;
                    }
                    
                    // 获取源文件路径
                    $sourceFile = $extension['source_file'] ?? '';
                    if (empty($sourceFile) || !file_exists($sourceFile)) {
                        continue;
                    }
                    
                    // 获取源模块信息
                    $sourceModuleInfo = $modules[$sourceModule] ?? null;
                    if (empty($sourceModuleInfo) || !($sourceModuleInfo['status'] ?? false)) {
                        continue;
                    }
                    
                    // 获取类名
                    $className = $this->getClassNameFromFile($sourceFile, $sourceModule, $sourceModuleInfo);
                    if ($className && class_exists($className)) {
                        // 检查类是否实现了 WarmupProviderInterface 接口
                        $reflection = new \ReflectionClass($className);
                        if ($reflection->implementsInterface(WarmupProviderInterface::class)) {
                            $providers[] = $className;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            w_log_error("扫描WarmupProvider失败: " . $e->getMessage());
        }
        
        $this->cachedProviders = $providers;
        $this->cachedExtendsMtime = ExtendsData::getRegistryFileMtime();
        
        return $providers;
    }

    /**
     * 执行所有Provider收集URL
     * 
     * @param bool $forceReload 强制重新加载Provider列表
     * @return array URL数组
     */
    public function collectUrls(bool $forceReload = false): array
    {
        $urls = [];
        $providers = $this->scanProviders($forceReload);
        
        foreach ($providers as $className) {
            try {
                if (class_exists($className)) {
                    $reflection = new \ReflectionClass($className);
                    if ($reflection->implementsInterface(WarmupProviderInterface::class)) {
                        $providerUrls = call_user_func([$className, 'execute']);
                        if (is_array($providerUrls)) {
                            $urls = array_merge($urls, $providerUrls);
                        }
                    }
                }
            } catch (\Exception $e) {
                w_log_error("执行WarmupProvider失败: {$className}, 错误: " . $e->getMessage());
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
        // 获取文件名（不含扩展名）
        $fileName = basename($filePath, '.php');
        
        // 优先从模块信息中获取命名空间
        if (isset($module['namespace_path'])) {
            return $module['namespace_path'] . '\\Extends\\Weline_Cdn\\' . $fileName;
        }

        // 尝试从路径推断命名空间
        // 将路径转换为命名空间格式：
        // app/code/Vendor/Module/extends/module/Weline_Cdn/File.php -> Vendor\Module\Extends\Weline_Cdn\File
        $relativePath = str_replace(BP . DIRECTORY_SEPARATOR, '', $filePath);
        $relativePath = str_replace(['.php', '\\'], ['', '\\'], $relativePath);
        $relativePath = str_replace('/', '\\', $relativePath);
        
        // 处理 extends/module/Weline_Cdn 路径
        // 将 extends\module\Weline_Cdn 或 extends/module/Weline_Cdn 转换为 Extends\Weline_Cdn
        $relativePath = preg_replace('/extends[\\\\\/]module[\\\\\/]Weline_Cdn/', 'Extends\\Weline_Cdn', $relativePath);
        
        // 如果路径中仍然包含 extends/Weline_Cdn（旧格式），也处理一下
        $relativePath = str_replace('extends\\Weline_Cdn', 'Extends\\Weline_Cdn', $relativePath);
        $relativePath = str_replace('extends/Weline_Cdn', 'Extends\\Weline_Cdn', $relativePath);
        
        return $relativePath;
    }
}

