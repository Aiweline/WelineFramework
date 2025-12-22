<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2024/01/15
 * 描述：布局服务 - 扫描和管理 LayoutProvider
 */

namespace Weline\Layout\Service;

use Weline\Framework\Cache\CacheFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Layout\Api\LayoutProviderInterface;
use Weline\Layout\Model\Layout;

class LayoutService
{
    private const CACHE_KEY_PROVIDERS = 'weline_layout_providers';
    private const CACHE_TTL = 3600; // 1小时

    private CacheFactory $cacheFactory;
    private array $providers = [];
    private bool $loaded = false;

    public function __construct(CacheFactory $cacheFactory)
    {
        $this->cacheFactory = $cacheFactory;
    }

    /**
     * 获取所有注册的 LayoutProvider
     * 
     * @return LayoutProviderInterface[]
     */
    public function getProviders(): array
    {
        if (!$this->loaded) {
            $this->loadProviders();
        }
        return $this->providers;
    }

    /**
     * 根据模块代码获取 Provider
     * 
     * @param string $moduleCode
     * @return LayoutProviderInterface|null
     */
    public function getProviderByModuleCode(string $moduleCode): ?LayoutProviderInterface
    {
        $providers = $this->getProviders();
        foreach ($providers as $provider) {
            if ($provider->getModuleCode() === $moduleCode) {
                return $provider;
            }
        }
        return null;
    }

    /**
     * 获取所有模块的布局类型
     * 
     * @return array 格式: ['ModuleCode' => ['type1' => [...], 'type2' => [...]]]
     */
    public function getAllLayoutTypes(): array
    {
        $result = [];
        foreach ($this->getProviders() as $provider) {
            $moduleCode = $provider->getModuleCode();
            $result[$moduleCode] = $provider->getLayoutTypes();
        }
        return $result;
    }

    /**
     * 获取指定模块和布局类型的布局选项
     * 
     * @param string $moduleCode
     * @param string $layoutType
     * @return array
     */
    public function getLayoutOptions(string $moduleCode, string $layoutType): array
    {
        $provider = $this->getProviderByModuleCode($moduleCode);
        if (!$provider) {
            return [];
        }
        return $provider->getLayoutOptions($layoutType);
    }

    /**
     * 应用布局
     * 
     * @param string $moduleCode
     * @param string $layoutType
     * @param string $layoutCode
     * @param mixed $entity
     * @return bool
     */
    public function applyLayout(string $moduleCode, string $layoutType, string $layoutCode, mixed $entity): bool
    {
        $provider = $this->getProviderByModuleCode($moduleCode);
        if (!$provider) {
            return false;
        }

        // 获取当前布局
        $oldLayout = $provider->getCurrentLayout($layoutType, $entity) ?? '';

        // 触发切换前事件
        $this->dispatchLayoutSwitchBeforeEvent($moduleCode, $layoutType, $oldLayout, $layoutCode);

        // 应用布局
        $result = $provider->applyLayout($layoutType, $layoutCode, $entity);

        if ($result) {
            // 通知 Provider 布局已切换
            $provider->onLayoutSwitch($layoutType, $oldLayout, $layoutCode);

            // 触发切换后事件
            $this->dispatchLayoutSwitchAfterEvent($moduleCode, $layoutType, $layoutCode);
        }

        return $result;
    }

    /**
     * 获取当前布局
     * 
     * @param string $moduleCode
     * @param string $layoutType
     * @param mixed $entity
     * @return string|null
     */
    public function getCurrentLayout(string $moduleCode, string $layoutType, mixed $entity): ?string
    {
        $provider = $this->getProviderByModuleCode($moduleCode);
        if (!$provider) {
            return null;
        }
        return $provider->getCurrentLayout($layoutType, $entity);
    }

    /**
     * 获取默认布局
     * 
     * @param string $moduleCode
     * @param string $layoutType
     * @return string|null
     */
    public function getDefaultLayout(string $moduleCode, string $layoutType): ?string
    {
        $provider = $this->getProviderByModuleCode($moduleCode);
        if (!$provider) {
            return null;
        }
        return $provider->getDefaultLayout($layoutType);
    }

    /**
     * 清除 Provider 缓存
     */
    public function clearCache(): void
    {
        $cache = $this->cacheFactory->create();
        $cache->delete(self::CACHE_KEY_PROVIDERS);
        $this->providers = [];
        $this->loaded = false;
    }

    /**
     * 加载所有 LayoutProvider
     */
    protected function loadProviders(): void
    {
        $cache = $this->cacheFactory->create();

        // 尝试从缓存加载
        $cachedProviderClasses = $cache->get(self::CACHE_KEY_PROVIDERS);
        
        if ($cachedProviderClasses) {
            $this->instantiateProviders($cachedProviderClasses);
            $this->loaded = true;
            return;
        }

        // 扫描 Provider
        $providerClasses = $this->scanProviders();
        
        // 缓存 Provider 类名
        $cache->set(self::CACHE_KEY_PROVIDERS, $providerClasses, self::CACHE_TTL);
        
        // 实例化
        $this->instantiateProviders($providerClasses);
        $this->loaded = true;
    }

    /**
     * 扫描所有模块的 LayoutProvider
     * 
     * @return array Provider 类名数组
     */
    protected function scanProviders(): array
    {
        $providerClasses = [];
        $modulesPath = BP . DS . 'app' . DS . 'code';

        // 扫描所有模块的 Extends/Weline_Layout 目录
        $vendorDirs = glob($modulesPath . '/*', GLOB_ONLYDIR);
        foreach ($vendorDirs as $vendorDir) {
            $moduleDirs = glob($vendorDir . '/*', GLOB_ONLYDIR);
            foreach ($moduleDirs as $moduleDir) {
                $extendDir = $moduleDir . DS . 'Extends' . DS . 'Weline_Layout';
                if (!is_dir($extendDir)) {
                    continue;
                }

                $providerFiles = glob($extendDir . '/*.php');
                foreach ($providerFiles as $providerFile) {
                    $className = $this->getClassNameFromFile($providerFile);
                    if ($className && $this->isValidProvider($className)) {
                        $providerClasses[] = $className;
                    }
                }
            }
        }

        return $providerClasses;
    }

    /**
     * 从文件路径获取类名
     */
    protected function getClassNameFromFile(string $filePath): ?string
    {
        $content = file_get_contents($filePath);
        
        // 获取命名空间
        $namespace = '';
        if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
            $namespace = $matches[1];
        }

        // 获取类名
        $className = '';
        if (preg_match('/class\s+(\w+)/', $content, $matches)) {
            $className = $matches[1];
        }

        if (empty($className)) {
            return null;
        }

        return $namespace ? $namespace . '\\' . $className : $className;
    }

    /**
     * 验证类是否是有效的 LayoutProvider
     */
    protected function isValidProvider(string $className): bool
    {
        if (!class_exists($className)) {
            return false;
        }
        
        $reflection = new \ReflectionClass($className);
        return $reflection->implementsInterface(LayoutProviderInterface::class);
    }

    /**
     * 实例化 Provider
     */
    protected function instantiateProviders(array $providerClasses): void
    {
        foreach ($providerClasses as $className) {
            try {
                $provider = ObjectManager::getInstance($className);
                if ($provider instanceof LayoutProviderInterface) {
                    $this->providers[] = $provider;
                }
            } catch (\Exception $e) {
                // 忽略实例化失败的 Provider
            }
        }
    }

    /**
     * 触发布局切换前事件
     */
    protected function dispatchLayoutSwitchBeforeEvent(string $moduleCode, string $layoutType, string $oldLayout, string $newLayout): void
    {
        $eventManager = ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);
        $eventManager->dispatch('Weline_Layout::layout_switch_before', [
            'module_code' => $moduleCode,
            'layout_type' => $layoutType,
            'old_layout' => $oldLayout,
            'new_layout' => $newLayout
        ]);
    }

    /**
     * 触发布局切换后事件
     */
    protected function dispatchLayoutSwitchAfterEvent(string $moduleCode, string $layoutType, string $layoutCode): void
    {
        $eventManager = ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);
        $eventManager->dispatch('Weline_Layout::layout_switch_after', [
            'module_code' => $moduleCode,
            'layout_type' => $layoutType,
            'layout_code' => $layoutCode
        ]);
    }
}

