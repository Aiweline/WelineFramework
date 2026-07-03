<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Service;

use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Interface\SitemapProviderInterface;
use Weline\Seo\Interface\SitemapUrlProviderInterface;

/**
 * Sitemap Provider 注册服务
 *
 * 通过 extends/module/Weline_Seo/SitemapProvider 自动收集所有 SitemapProvider 实现。
 * 支持两种接口：
 * - SitemapProviderInterface（旧接口，生成 sitemap 文件）
 * - SitemapUrlProviderInterface（新接口，只提供 URL 数据）
 */
class SitemapRegistryService
{
    private ObjectManager $objectManager;
    private ?array $cachedProviders = null;
    private ?array $cachedUrlProviders = null;
    private ?int $cachedExtendsMtime = null;

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * 获取所有 Sitemap Provider 实例
     *
     * @param bool $forceReload
     * @return SitemapProviderInterface[]
     */
    public function getProviders(bool $forceReload = false): array
    {
        if (!$forceReload && $this->cachedProviders !== null) {
            if (method_exists(ExtendsData::class, 'getRegistryFileMtime')) {
                $currentMtime = ExtendsData::getRegistryFileMtime();
                if ($currentMtime === $this->cachedExtendsMtime) {
                    return $this->cachedProviders;
                }
            }
        }

        $providers = [];

        try {
            $extendedBy = ExtendsData::getExtendedBy('Weline_Seo', $forceReload);
            if (empty($extendedBy)) {
                $this->cachedProviders = [];
                $this->cachedExtendsMtime = method_exists(ExtendsData::class, 'getRegistryFileMtime')
                    ? ExtendsData::getRegistryFileMtime()
                    : 0;
                return [];
            }

            $extends = ExtendsData::getModuleExtends('Weline_Seo', $forceReload);
            $sitemapExtend = $extends['extends']['SitemapProvider'] ?? null;
            if (!$sitemapExtend) {
                $this->cachedProviders = [];
                $this->cachedExtendsMtime = method_exists(ExtendsData::class, 'getRegistryFileMtime')
                    ? ExtendsData::getRegistryFileMtime()
                    : 0;
                return [];
            }

            foreach ($extendedBy as $sourceModule => $extensions) {
                foreach ($extensions as $extension) {
                    // 检查源模块是否启用
                    $sourceModuleStatus = $extension['source_module_status'] ?? true;
                    if (!$sourceModuleStatus) {
                        continue; // 跳过禁用模块的扩展
                    }
                    
                    // 从 file_path 判断是否是 SitemapProvider 扩展
                    $filePath = $extension['file_path'] ?? '';
                    // 统一路径分隔符（兼容 Windows 和 Linux）
                    $filePath = str_replace('\\', '/', $filePath);
                    
                    if (strpos($filePath, 'SitemapProvider/') === 0) {
                        // 优先使用扫描时提取的完整类名（解决大小写问题）
                        $class = $extension['class_name'] ?? null;
                        
                        // 如果没有预存类名，回退到推断（兼容旧版本 extends.php）
                        if (!$class) {
                            $class = $this->inferClassName($sourceModule, $extension);
                        }
                        
                        // 如果类不存在，尝试手动加载文件（解决 Linux 大小写问题）
                        if ($class && !class_exists($class)) {
                            $sourceFile = $extension['source_file'] ?? '';
                            if ($sourceFile && file_exists($sourceFile)) {
                                require_once $sourceFile;
                            }
                        }
                        
                        if ($class && class_exists($class)) {
                            try {
                                $instance = $this->objectManager->getInstance($class);
                                if ($instance instanceof SitemapProviderInterface) {
                                    $providers[] = $instance;
                                }
                            } catch (\Throwable $e) {
                                // 忽略单个 provider 错误
                                if (defined('DEV') && DEV) {
                                    w_log_error('SitemapRegistryService: 无法实例化 ' . $class . ': ' . $e->getMessage());
                                }
                            }
                        } else {
                            // 类不存在时记录日志（帮助调试）
                            if (defined('DEV') && DEV) {
                                w_log_error('SitemapRegistryService: 类不存在 ' . ($class ?? 'null') . ', source_module=' . $sourceModule . ', file_path=' . $filePath);
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // 忽略扩展扫描错误
        }

        $this->cachedProviders = $providers;
        $this->cachedExtendsMtime = method_exists(ExtendsData::class, 'getRegistryFileMtime')
            ? ExtendsData::getRegistryFileMtime()
            : 0;

        return $providers;
    }

    /**
     * 按 scope/module 过滤 Sitemap Provider
     *
     * @return SitemapProviderInterface[]
     */
    public function getProvidersByScopeModule(string $scope, string $module, bool $forceReload = false): array
    {
        $all = $this->getProviders($forceReload);
        $filtered = [];

        foreach ($all as $provider) {
            if ($provider->getScope() === $scope && $provider->getModule() === $module) {
                $filtered[] = $provider;
            }
        }

        return $filtered;
    }
    
    /**
     * 获取所有实现 SitemapUrlProviderInterface 的 Provider
     *
     * 新架构：Provider 只提供 URL 数据，不生成 sitemap 文件
     *
     * @param bool $forceReload
     * @return SitemapUrlProviderInterface[]
     */
    public function getUrlProviders(bool $forceReload = false): array
    {
        if (!$forceReload && $this->cachedUrlProviders !== null) {
            if (method_exists(ExtendsData::class, 'getRegistryFileMtime')) {
                $currentMtime = ExtendsData::getRegistryFileMtime();
                if ($currentMtime === $this->cachedExtendsMtime) {
                    return $this->cachedUrlProviders;
                }
            }
        }

        $providers = [];

        try {
            $extendedBy = ExtendsData::getExtendedBy('Weline_Seo', $forceReload);
            if (empty($extendedBy)) {
                $this->cachedUrlProviders = [];
                return [];
            }

            $extends = ExtendsData::getModuleExtends('Weline_Seo', $forceReload);
            
            // 检查是否有 SitemapUrlProvider 扩展点（新架构）
            $hasUrlProviderExtend = isset($extends['extends']['SitemapUrlProvider']);
            // 检查是否有 SitemapProvider 扩展点（旧架构，向后兼容）
            $hasProviderExtend = isset($extends['extends']['SitemapProvider']);
            
            if (!$hasUrlProviderExtend && !$hasProviderExtend) {
                $this->cachedUrlProviders = [];
                return [];
            }

            foreach ($extendedBy as $sourceModule => $extensions) {
                foreach ($extensions as $extension) {
                    // 检查源模块是否启用
                    $sourceModuleStatus = $extension['source_module_status'] ?? true;
                    if (!$sourceModuleStatus) {
                        continue; // 跳过禁用模块的扩展
                    }
                    
                    $filePath = $extension['file_path'] ?? '';
                    // 统一路径分隔符（兼容 Windows 和 Linux）
                    $filePath = str_replace('\\', '/', $filePath);
                    
                    // 匹配 SitemapUrlProvider/ 或 SitemapProvider/ 路径
                    $isSitemapUrlProvider = strpos($filePath, 'SitemapUrlProvider/') === 0;
                    $isSitemapProvider = strpos($filePath, 'SitemapProvider/') === 0;
                    
                    if ($isSitemapUrlProvider || $isSitemapProvider) {
                        // 优先使用扫描时提取的完整类名（解决大小写问题）
                        $class = $extension['class_name'] ?? null;
                        
                        // 如果没有预存类名，回退到推断（兼容旧版本 extends.php）
                        if (!$class) {
                            $class = $this->inferClassName($sourceModule, $extension);
                        }
                        
                        // 如果类不存在，尝试手动加载文件（解决 Linux 大小写问题）
                        if ($class && !class_exists($class)) {
                            $sourceFile = $extension['source_file'] ?? '';
                            if ($sourceFile && file_exists($sourceFile)) {
                                require_once $sourceFile;
                            }
                        }
                        
                        if ($class && class_exists($class)) {
                            try {
                                $instance = $this->objectManager->getInstance($class);
                                // 检查是否实现新接口
                                if ($instance instanceof SitemapUrlProviderInterface) {
                                    $providers[] = $instance;
                                }
                            } catch (\Throwable $e) {
                                if (defined('DEV') && DEV) {
                                    w_log_error('SitemapRegistryService: 无法实例化 ' . $class . ': ' . $e->getMessage());
                                }
                            }
                        } else {
                            // 类不存在时记录日志（帮助调试）
                            if (defined('DEV') && DEV) {
                                w_log_error('SitemapRegistryService (URL): 类不存在 ' . ($class ?? 'null') . ', source_module=' . $sourceModule . ', file_path=' . $filePath);
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // 忽略扩展扫描错误
        }

        $this->cachedUrlProviders = $providers;
        $this->cachedExtendsMtime = method_exists(ExtendsData::class, 'getRegistryFileMtime')
            ? ExtendsData::getRegistryFileMtime()
            : 0;

        return $providers;
    }

    /**
     * 获取所有 Provider 的统计信息
     *
     * @param bool $forceReload
     * @return array
     */
    public function getProvidersInfo(bool $forceReload = false): array
    {
        $urlProviders = $this->getUrlProviders($forceReload);
        $legacyProviders = $this->getProviders($forceReload);

        $info = [
            'url_providers' => [],
            'legacy_providers' => [],
        ];

        foreach ($urlProviders as $provider) {
            $info['url_providers'][] = [
                'scope' => $provider->getScope(),
                'module' => $provider->getModule(),
                'description' => $provider->getDescription(),
                'enabled' => $provider->isEnabled(),
            ];
        }

        foreach ($legacyProviders as $provider) {
            // 排除已经实现新接口的
            if ($provider instanceof SitemapUrlProviderInterface) {
                continue;
            }
            $info['legacy_providers'][] = [
                'scope' => $provider->getScope(),
                'module' => $provider->getModule(),
                'description' => $provider->getDescription(),
            ];
        }

        return $info;
    }

    /**
     * 从扩展信息推断类名
     * 
     * @param string $sourceModule 来源模块名
     * @param array $extension 扩展信息
     * @return string|null 完整的类名
     */
    private function inferClassName(string $sourceModule, array $extension): ?string
    {
        $filePath = $extension['file_path'] ?? '';
        if (empty($filePath)) {
            return null;
        }
        
        // 统一路径分隔符（兼容 Windows 和 Linux）
        $filePath = str_replace('\\', '/', $filePath);
        
        // 移除 .php 扩展名
        $classPath = str_replace('.php', '', $filePath);
        
        // 将路径转换为类名
        $classPath = str_replace('/', '\\', $classPath);
        
        // 将模块名转换为命名空间
        $namespace = str_replace('_', '\\', $sourceModule);
        
        // 完整的类名 - 使用小写 extends/module 目录名（符合框架规约）
        $fullClass = $namespace . '\\extends\\module\\Weline_Seo\\' . $classPath;
        
        return $fullClass;
    }
}
