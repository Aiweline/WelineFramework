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
use Weline\Framework\Registry\Service\RegistryProgress;
use Weline\Framework\Registry\Service\RegistryModulePresence;

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
        RegistryProgress::log('Plugin scan: plugin.xml configs started');
        $pluginData = $this->xmlReader->read();
        RegistryProgress::count('Plugin XML scan', count($pluginData), 'modules with plugin configs');

        // 组织数据结构
        RegistryProgress::log('Plugin organize registry data');
        $registry = $this->organizeRegistryData($pluginData);
        RegistryProgress::count('Plugin registry', count($registry['plugins'] ?? []), 'plugin entries organized');
        unset($pluginData);
        RegistryProgress::log('Plugin raw XML data released');

        // 保存注册表
        return $this->saveRegistry($registry);
    }
    
    /**
     * 增量刷新指定模块的插件注册表
     * 仅重新扫描指定模块的 plugin.xml，合并到现有注册表
     *
     * @param array $moduleNames 需要刷新的模块名列表
     * @return bool
     */
    public function refreshForModules(array $moduleNames): bool
    {
        // 1. 加载现有注册表
        $registry = $this->getRegistry(true);
        
        // 确保注册表结构完整
        if (!isset($registry['plugins'])) {
            $registry['plugins'] = [];
        }
        if (!isset($registry['class_to_plugins'])) {
            $registry['class_to_plugins'] = [];
        }
        
        // 2. 清除目标模块的旧数据
        $this->purgeUnavailablePluginsFromRegistry($registry);
        $this->removeModulePlugins($registry, $moduleNames);
        
        // 3. 扫描目标模块的新数据
        RegistryProgress::log('Plugin incremental: scanning target plugin configs');
        $pluginData = $this->xmlReader->readForModules($moduleNames);
        RegistryProgress::count('Plugin incremental XML scan', count($pluginData), 'modules with plugin configs');
        
        // 4. 组织新数据
        RegistryProgress::log('Plugin incremental: organizing new data');
        $newRegistry = $this->organizeRegistryData($pluginData);
        unset($pluginData);
        RegistryProgress::log('Plugin incremental raw XML data released');
        
        // 5. 合并到现有注册表
        $this->mergePluginRegistry($registry, $newRegistry);
        unset($newRegistry);
        
        // 6. 保存注册表
        return $this->saveRegistry($registry);
    }
    
    /**
     * 清除指定模块的插件数据
     *
     * @param array &$registry 注册表数据（引用传递）
     * @param array $moduleNames 要清除的模块名列表
     * @return void
     */
    private function purgeUnavailablePluginsFromRegistry(array &$registry): void
    {
        foreach ($registry['plugins'] as $pluginKey => &$pluginInfo) {
            if (!isset($pluginInfo['interceptors']) || !is_array($pluginInfo['interceptors'])) {
                unset($registry['plugins'][$pluginKey]);
                continue;
            }

            $pluginInfo['interceptors'] = array_values(array_filter(
                $pluginInfo['interceptors'],
                static fn($interceptor): bool => RegistryModulePresence::isActivePresent((string)($interceptor['module'] ?? ''))
            ));

            if (empty($pluginInfo['interceptors'])) {
                unset($registry['plugins'][$pluginKey]);
            }
        }
        unset($pluginInfo);

        $this->rebuildClassToPlugins($registry);
    }

    private function removeModulePlugins(array &$registry, array $moduleNames): void
    {
        foreach ($registry['plugins'] as $pluginKey => &$pluginInfo) {
            if (isset($pluginInfo['interceptors']) && is_array($pluginInfo['interceptors'])) {
                // 过滤掉属于目标模块的拦截器
                $pluginInfo['interceptors'] = array_values(array_filter(
                    $pluginInfo['interceptors'],
                    fn($interceptor) => !in_array($interceptor['module'] ?? '', $moduleNames, true)
                ));
                
                // 如果该插件没有任何拦截器了，移除整个插件
                if (empty($pluginInfo['interceptors'])) {
                    $className = $pluginInfo['class'] ?? '';
                    unset($registry['plugins'][$pluginKey]);
                    
                    // 从 class_to_plugins 中移除
                    if (isset($registry['class_to_plugins'][$className])) {
                        $registry['class_to_plugins'][$className] = array_values(array_filter(
                            $registry['class_to_plugins'][$className],
                            fn($key) => $key !== $pluginKey
                        ));
                        if (empty($registry['class_to_plugins'][$className])) {
                            unset($registry['class_to_plugins'][$className]);
                        }
                    }
                }
            }
        }
    }
    
    /**
     * 合并插件注册表
     *
     * @param array &$registry 现有注册表（引用传递）
     * @param array $newRegistry 新注册表数据
     * @return void
     */
    private function rebuildClassToPlugins(array &$registry): void
    {
        $registry['class_to_plugins'] = [];
        foreach ($registry['plugins'] as $pluginKey => $pluginInfo) {
            $className = (string)($pluginInfo['class'] ?? '');
            if ($className === '') {
                continue;
            }
            $registry['class_to_plugins'][$className] ??= [];
            if (!in_array($pluginKey, $registry['class_to_plugins'][$className], true)) {
                $registry['class_to_plugins'][$className][] = $pluginKey;
            }
        }
    }

    private function mergePluginRegistry(array &$registry, array $newRegistry): void
    {
        // 合并 plugins
        foreach (($newRegistry['plugins'] ?? []) as $pluginKey => $pluginInfo) {
            if (isset($registry['plugins'][$pluginKey])) {
                // 插件已存在，合并拦截器
                if (isset($pluginInfo['interceptors'])) {
                    $registry['plugins'][$pluginKey]['interceptors'] = array_merge(
                        $registry['plugins'][$pluginKey]['interceptors'] ?? [],
                        $pluginInfo['interceptors']
                    );
                    
                    // 重新按 sort 值排序
                    usort($registry['plugins'][$pluginKey]['interceptors'], function ($a, $b) {
                        return ((int)($a['sort'] ?? 10000)) <=> ((int)($b['sort'] ?? 10000));
                    });
                }
            } else {
                // 新插件
                $registry['plugins'][$pluginKey] = $pluginInfo;
            }
        }
        
        // 合并 class_to_plugins
        foreach (($newRegistry['class_to_plugins'] ?? []) as $className => $pluginKeys) {
            if (!isset($registry['class_to_plugins'][$className])) {
                $registry['class_to_plugins'][$className] = [];
            }
            foreach ($pluginKeys as $pluginKey) {
                if (!in_array($pluginKey, $registry['class_to_plugins'][$className], true)) {
                    $registry['class_to_plugins'][$className][] = $pluginKey;
                }
            }
        }
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
        RegistryProgress::log('Plugin save registry: generated/plugins.php');
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
            RegistryProgress::log('Plugin save registry finished');
            return true;
        }

        RegistryProgress::log('Plugin save registry failed');
        return false;
    }

    /**
     * 获取类的所有插件
     * 返回的插件数组中的每个拦截器数组已经按 sort 值排序
     *
     * @param string $className 类名
     * @return array
     */
    public function clearMemoryCache(): void
    {
        $this->cachedRegistry = null;
        $this->cachedFileMtime = null;
    }

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
