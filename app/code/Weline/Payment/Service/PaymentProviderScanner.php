<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Payment\Service;

use Weline\Payment\Interface\PaymentProviderInterface;
use Weline\Framework\App\Env;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\File\Scan;

/**
 * 支付提供商扫描器
 * 
 * 扫描所有实现 PaymentProviderInterface 的支付提供商类
 */
class PaymentProviderScanner
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
     * 扫描所有支付提供商
     * 
     * @param bool $forceReload 强制重新加载
     * @return array 支付提供商类名数组
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
            // 从 ExtendsData 读取所有扩展 Weline_Payment 的模块信息
            $extendedBy = ExtendsData::getExtendedBy('Weline_Payment', $forceReload);
            
            if (empty($extendedBy)) {
                $this->cachedProviders = [];
                $this->cachedExtendsMtime = ExtendsData::getRegistryFileMtime();
                return [];
            }

            $modules = Env::getInstance()->getModuleList();
            
            // 遍历所有扩展 Weline_Payment 的模块
            foreach ($extendedBy as $sourceModule => $extensions) {
                foreach ($extensions as $extension) {
                    // 只处理模块级扩展
                    if (($extension['is_sticker_extension'] ?? false) === true) {
                        continue;
                    }
                    
                    // 只处理路径为 extends/module/Weline_Payment/PaymentProvider 的扩展
                    $relativePath = $extension['relative_path'] ?? '';
                    if (!str_starts_with($relativePath, 'extends/module/Weline_Payment/PaymentProvider/')) {
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
                        // 检查类是否实现了 PaymentProviderInterface 接口
                        try {
                            $reflection = new \ReflectionClass($className);
                            if ($reflection->implementsInterface(PaymentProviderInterface::class)) {
                                $providers[] = $className;
                            }
                        } catch (\Throwable $e) {
                            w_log_error("检查支付提供商接口失败: {$className}, 错误: " . $e->getMessage());
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            w_log_error("扫描支付提供商失败: " . $e->getMessage());
        }
        
        $this->cachedProviders = $providers;
        $this->cachedExtendsMtime = ExtendsData::getRegistryFileMtime();
        
        return $providers;
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
        // 读取文件内容
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }
        
        // 提取命名空间
        if (!preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatch)) {
            return null;
        }
        
        // 提取类名
        if (!preg_match('/class\s+(\w+)/', $content, $classMatch)) {
            return null;
        }
        
        $namespace = trim($namespaceMatch[1]);
        $className = trim($classMatch[1]);
        
        return $namespace . '\\' . $className;
    }

    /**
     * 获取所有支付提供商实例
     * 
     * @param bool $forceReload 强制重新加载
     * @return PaymentProviderInterface[]
     */
    public function getProviderInstances(bool $forceReload = false): array
    {
        $providers = [];
        $providerClasses = $this->scanProviders($forceReload);
        
        foreach ($providerClasses as $className) {
            try {
                if (class_exists($className)) {
                    $provider = $this->objectManager->getInstance($className);
                    if ($provider instanceof PaymentProviderInterface) {
                        $providers[] = $provider;
                    }
                }
            } catch (\Throwable $e) {
                w_log_error("实例化支付提供商失败: {$className}, 错误: " . $e->getMessage());
            }
        }
        
        return $providers;
    }
}

