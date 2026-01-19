<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Plugin;

use Weline\Framework\Plugin\Config\PluginXmlReader;

/**
 * 插件注册表管理
 * 管理 generated/plugins.php 文件的读取和写入
 * 扫描所有模块的 plugin.xml 文件
 */
class PluginRegistry
{
    private const REGISTRY_FILE = BP . 'generated' . DIRECTORY_SEPARATOR . 'plugins.php';

    private ?array $cachedRegistry = null;
    private ?int $cachedFileMtime = null;
    private PluginXmlReader $xmlReader;

    public function __construct(
        PluginXmlReader $xmlReader
    ) {
        $this->xmlReader = $xmlReader;
    }

    /**
     * 获取注册表内容
     *
     * @param bool $forceReload 强制重新加载
     * @return array
     */
    public function getRegistry(bool $forceReload = false): array
    {
        // 内存缓存机制
        if (!$forceReload && $this->cachedRegistry !== null) {
            $currentMtime = file_exists(self::REGISTRY_FILE) ? filemtime(self::REGISTRY_FILE) : 0;
            if ($currentMtime === $this->cachedFileMtime) {
                return $this->cachedRegistry;
            }
        }

        if (!file_exists(self::REGISTRY_FILE)) {
            $this->cachedRegistry = ['plugins' => [], 'class_to_plugins' => []];
            $this->cachedFileMtime = 0;
            return $this->cachedRegistry;
        }

        $registry = include self::REGISTRY_FILE;
        if (!is_array($registry)) {
            $registry = ['plugins' => [], 'class_to_plugins' => []];
        }

        // 兼容旧格式
        if (!isset($registry['class_to_plugins']) && isset($registry['plugins'])) {
            $classToPlugins = [];
            foreach ($registry['plugins'] as $className => $pluginInfo) {
                if (isset($pluginInfo['class'])) {
                    $classToPlugins[$pluginInfo['class']] = $className;
                }
            }
            $registry['class_to_plugins'] = $classToPlugins;
        } elseif (!isset($registry['plugins'])) {
            // 兼容旧格式（如果直接是插件数组）
            $plugins = $registry;
            $classToPlugins = [];
            foreach ($plugins as $className => $pluginInfo) {
                if (isset($pluginInfo['class'])) {
                    $classToPlugins[$pluginInfo['class']] = $className;
                }
            }
            $registry = ['plugins' => $plugins, 'class_to_plugins' => $classToPlugins];
        }

        $this->cachedRegistry = $registry;
        $this->cachedFileMtime = file_exists(self::REGISTRY_FILE) ? filemtime(self::REGISTRY_FILE) : 0;

        return $registry;
    }

    /**
     * 刷新注册表（重新扫描并保存）
     * 扫描所有模块的 plugin.xml 文件
     *
     * @return bool
     */
    public function refresh(): bool
    {
        // 读取所有 plugin.xml 配置
        $pluginData = $this->xmlReader->read();

        // 组织数据结构
        $registry = $this->organizeRegistryData($pluginData);

        // 保存注册表
        return $this->saveRegistry($registry);
    }

    /**
     * 组织注册表数据
     * 将模块级别的插件信息转换为类名索引的结构
     *
     * @param array $pluginData 从 PluginXmlReader 读取的数据
     * @return array
     */
    private function organizeRegistryData(array $pluginData): array
    {
        $registry = ['plugins' => [], 'class_to_plugins' => []];

        foreach ($pluginData as $moduleAndFile => $modulePlugins) {
            // 解析模块名和文件路径
            $parts = explode('::', $moduleAndFile, 2);
            $moduleName = $parts[0] ?? '';
            $filePath = $parts[1] ?? '';

            foreach ($modulePlugins as $pluginName => $interceptors) {
                foreach ($interceptors as $interceptor) {
                    $className = $interceptor['class'] ?? '';
                    $instanceClass = $interceptor['plugins']['instance'] ?? '';
                    $interceptorName = $interceptor['plugins']['name'] ?? '';
                    $sort = (int)($interceptor['plugins']['sort'] ?? 10000);
                    $disabled = ($interceptor['plugins']['disabled'] ?? 'false') === 'true';

                    if (empty($className) || empty($instanceClass)) {
                        continue;
                    }

                    // 构建插件键（类名::拦截器名）
                    $pluginKey = $className . '::' . $interceptorName;

                    if (!isset($registry['plugins'][$pluginKey])) {
                        $registry['plugins'][$pluginKey] = [
                            'class' => $className,
                            'interceptors' => []
                        ];
                    }

                    // 添加拦截器信息
                    $registry['plugins'][$pluginKey]['interceptors'][] = [
                        'name' => $interceptorName,
                        'instance' => $instanceClass,
                        'sort' => $sort,
                        'disabled' => $disabled,
                        'module' => $moduleName,
                        'file' => $filePath
                    ];

                    // 构建类名到插件的映射
                    if (!isset($registry['class_to_plugins'][$className])) {
                        $registry['class_to_plugins'][$className] = [];
                    }
                    if (!in_array($pluginKey, $registry['class_to_plugins'][$className])) {
                        $registry['class_to_plugins'][$className][] = $pluginKey;
                    }
                }
            }
        }

        // 对每个类的拦截器按 sort 值排序
        foreach ($registry['plugins'] as $pluginKey => &$pluginInfo) {
            if (isset($pluginInfo['interceptors']) && !empty($pluginInfo['interceptors'])) {
                usort($pluginInfo['interceptors'], function ($a, $b) {
                    $sortA = (int)($a['sort'] ?? 10000);
                    $sortB = (int)($b['sort'] ?? 10000);
                    return $sortA <=> $sortB;
                });
            }
        }

        return $registry;
    }

    /**
     * 保存注册表
     *
     * @param array $registry 注册表数据
     * @return bool
     */
    public function saveRegistry(array $registry): bool
    {
        $content = "<?php return " . w_var_export($registry, true) . ";\n";

        // 确保目录存在
        $dir = dirname(self::REGISTRY_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $result = file_put_contents(self::REGISTRY_FILE, $content, LOCK_EX);

        if ($result !== false) {
            $this->cachedRegistry = $registry;
            $this->cachedFileMtime = file_exists(self::REGISTRY_FILE) ? filemtime(self::REGISTRY_FILE) : 0;
            return true;
        }

        return false;
    }

    /**
     * 获取类的所有插件
     * 返回的插件数组中的每个拦截器数组已经按 sort 值排序
     *
     * @param string $className 类名
     * @return array
     */
    public function getClassPlugins(string $className): array
    {
        $registry = $this->getRegistry();
        $pluginKeys = $registry['class_to_plugins'][$className] ?? [];
        
        $plugins = [];
        foreach ($pluginKeys as $pluginKey) {
            if (isset($registry['plugins'][$pluginKey])) {
                $plugins[$pluginKey] = $registry['plugins'][$pluginKey];
            }
        }
        
        // 如果同一类有多个插件（不同的拦截器名），按第一个拦截器的 sort 值对插件键排序
        // 这样可以确保跨拦截器的执行顺序
        if (count($plugins) > 1) {
            uksort($plugins, function ($a, $b) use ($plugins) {
                $sortA = 10000;
                $sortB = 10000;
                
                // 获取每个插件的第一个拦截器的 sort 值
                if (isset($plugins[$a]['interceptors'][0]['sort'])) {
                    $sortA = (int)$plugins[$a]['interceptors'][0]['sort'];
                }
                if (isset($plugins[$b]['interceptors'][0]['sort'])) {
                    $sortB = (int)$plugins[$b]['interceptors'][0]['sort'];
                }
                
                return $sortA <=> $sortB;
            });
        }
        
        return $plugins;
    }

    /**
     * 检查类是否有插件
     *
     * @param string $className 类名
     * @return bool
     */
    public function hasPlugins(string $className): bool
    {
        $registry = $this->getRegistry();
        return isset($registry['class_to_plugins'][$className]) && 
               !empty($registry['class_to_plugins'][$className]);
    }
}
