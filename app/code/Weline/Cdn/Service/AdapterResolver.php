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
use Weline\Framework\App\Exception;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\File\Scan;

/**
 * CDN适配器解析器
 * 
 * 支持多种方式发现适配器：
 * 1. 内建默认适配器（cloudflare）
 * 2. 文件配置（etc/cdn_adapters.php）
 * 3. 事件收集（Weline_Cdn::adapters_collect）
 * 4. 扫描已激活模块（*\Cdn\Adapter\*）
 */
class AdapterResolver
{
    /**
     * 内建适配器映射
     */
    private const BUILTIN_ADAPTERS = [
        'cloudflare' => \Weline\Cdn\Model\Adapter\Cloudflare::class
    ];

    /**
     * 适配器缓存
     * 
     * @var array<string, AdapterInterface>
     */
    private static array $adaptersCache = [];

    /**
     * 适配器列表缓存
     * 
     * @var array<string, string> [code => class]
     */
    private static ?array $adaptersListCache = null;

    /**
     * @var Scan
     */
    private Scan $fileScanner;

    /**
     * @var EventsManager
     */
    private EventsManager $eventsManager;

    /**
     * 构造函数
     */
    public function __construct(
        Scan $fileScanner,
        EventsManager $eventsManager
    ) {
        $this->fileScanner = $fileScanner;
        $this->eventsManager = $eventsManager;
    }

    /**
     * @DESC          # 获取所有适配器列表
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @param bool $forceRefresh 强制刷新缓存
     * @return array<string, string> [code => class]
     */
    public function list(bool $forceRefresh = false): array
    {
        if (self::$adaptersListCache !== null && !$forceRefresh) {
            return self::$adaptersListCache;
        }

        $adapters = [];

        // 1. 内建适配器（优先级最低，可被覆盖）
        foreach (self::BUILTIN_ADAPTERS as $code => $class) {
            if (!isset($adapters[$code])) {
                $adapters[$code] = $class;
            }
        }

        // 2. 从文件加载（etc/cdn_adapters.php）
        $fileAdapters = $this->loadFromFile();
        foreach ($fileAdapters as $code => $class) {
            $adapters[$code] = $class; // 覆盖内建
        }

        // 3. 从事件收集（其他模块通过事件注册）
        $eventAdapters = $this->collectFromEvent();
        foreach ($eventAdapters as $code => $class) {
            $adapters[$code] = $class; // 覆盖文件和内建
        }

        // 4. 扫描已激活模块（优先级最高）
        $scannedAdapters = $this->scanActiveModules();
        foreach ($scannedAdapters as $code => $class) {
            $adapters[$code] = $class; // 覆盖所有
        }

        self::$adaptersListCache = $adapters;
        return $adapters;
    }

    /**
     * @DESC          # 获取指定适配器实例
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @param string $code 适配器代码
     * @return AdapterInterface|null
     * @throws Exception
     */
    public function get(string $code): ?AdapterInterface
    {
        // 从缓存获取
        if (isset(self::$adaptersCache[$code])) {
            return self::$adaptersCache[$code];
        }

        // 获取适配器列表
        $adapters = $this->list();
        
        if (!isset($adapters[$code])) {
            return null;
        }

        $className = $adapters[$code];

        // 验证类是否存在
        if (!class_exists($className)) {
            throw new Exception(__("适配器类不存在: %{1}", [$className]));
        }

        // 创建实例
        $instance = ObjectManager::getInstance($className);

        // 验证是否实现接口
        if (!$instance instanceof AdapterInterface) {
            throw new Exception(__("适配器类 %{1} 必须实现 AdapterInterface 接口", [$className]));
        }

        // 缓存实例
        self::$adaptersCache[$code] = $instance;

        return $instance;
    }

    /**
     * @DESC          # 从文件加载适配器配置
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @return array<string, string> [code => class]
     */
    private function loadFromFile(): array
    {
        $configFile = __DIR__ . '/../../etc/cdn_adapters.php';
        
        if (!file_exists($configFile)) {
            return [];
        }

        $config = include $configFile;
        
        if (!is_array($config)) {
            return [];
        }

        return $config;
    }

    /**
     * @DESC          # 通过事件收集适配器
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @return array<string, string> [code => class]
     */
    private function collectFromEvent(): array
    {
        $adapters = [];
        
        // 派发事件让其他模块注册适配器
        $eventData = ['adapters' => []];
        $this->eventsManager->dispatch('Weline_Cdn::adapters_collect', $eventData);
        
        // 从事件数据中获取适配器
        $eventResult = $this->eventsManager->getEventData('Weline_Cdn::adapters_collect');
        if ($eventResult) {
            $collectedAdapters = $eventResult->getData('adapters') ?? [];
            foreach ($collectedAdapters as $code => $class) {
                if (is_string($code) && is_string($class) && class_exists($class)) {
                    $adapters[$code] = $class;
                }
            }
        }

        return $adapters;
    }

    /**
     * @DESC          # 扫描已激活模块的适配器
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @return array<string, string> [code => class]
     */
    private function scanActiveModules(): array
    {
        $adapters = [];

        try {
            // 获取所有已激活的模块
            $activeModules = Env::getInstance()->getActiveModules();
            
            foreach ($activeModules as $moduleName => $module) {
                // 获取模块基础路径
                $basePath = $module['base_path'] ?? '';
                if (empty($basePath)) {
                    continue;
                }

                // 构建 Cdn/Adapter 目录路径
                $adapterDir = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'Cdn' . DIRECTORY_SEPARATOR . 'Adapter' . DIRECTORY_SEPARATOR;
                
                // 检查目录是否存在
                if (!is_dir($adapterDir)) {
                    continue;
                }

                // 扫描适配器文件
                $adapterFiles = [];
                $this->fileScanner->globFile($adapterDir . '*Adapter.php', $adapterFiles);
                
                foreach ($adapterFiles as $adapterFile) {
                    try {
                        $className = $this->getClassNameFromFile($adapterFile, $moduleName, $module);
                        if (!$className || !class_exists($className)) {
                            continue;
                        }

                        // 创建实例验证
                        $instance = ObjectManager::getInstance($className);
                        if (!$instance instanceof AdapterInterface) {
                            continue;
                        }

                        // 获取适配器代码
                        $code = $instance->getAdapterCode();
                        if (empty($code)) {
                            continue;
                        }

                        $adapters[$code] = $className;
                    } catch (\Exception $e) {
                        // 忽略加载失败的适配器
                        error_log("加载CDN适配器失败: {$adapterFile}, 错误: " . $e->getMessage());
                    }
                }
            }
        } catch (\Exception $e) {
            error_log("扫描CDN适配器失败: " . $e->getMessage());
        }

        return $adapters;
    }

    /**
     * @DESC          # 从文件路径获取类名
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @param string $filePath
     * @param string $moduleName
     * @param array $module
     * @return string|null
     */
    private function getClassNameFromFile(string $filePath, string $moduleName, array $module): ?string
    {
        $fileName = basename($filePath, '.php');
        
        // 验证文件名格式
        if (!str_ends_with($fileName, 'Adapter')) {
            return null;
        }

        // 从模块信息获取命名空间
        $namespacePath = $module['namespace_path'] ?? '';
        if (empty($namespacePath)) {
            return null;
        }

        // 构建完整类名
        return "\\{$namespacePath}\\Cdn\\Adapter\\{$fileName}";
    }

    /**
     * @DESC          # 清除缓存
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @return void
     */
    public function clearCache(): void
    {
        self::$adaptersCache = [];
        self::$adaptersListCache = null;
    }
}

