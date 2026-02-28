<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Event;

/**
 * 事件注册表管理
 * 管理 generated/events.php 文件的读取和写入
 */
class EventRegistry
{
    private const REGISTRY_FILE = BP . 'generated' . DIRECTORY_SEPARATOR . 'events.php';

    private ?array $cachedRegistry = null;
    private ?int $cachedFileMtime = null;
    private EventScanner $scanner;
    private Config\XmlReader $xmlReader;

    public function __construct(
        EventScanner $scanner,
        Config\XmlReader $xmlReader
    ) {
        $this->scanner = $scanner;
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
            $this->cachedRegistry = ['events' => [], 'event_to_module' => []];
            $this->cachedFileMtime = 0;
            return $this->cachedRegistry;
        }

        $registry = include self::REGISTRY_FILE;
        if (!is_array($registry)) {
            $registry = ['events' => [], 'event_to_module' => []];
        }

        // 兼容旧格式（如果没有 event_to_module，则从 events 中提取）
        if (!isset($registry['event_to_module']) && isset($registry['events'])) {
            $eventToModule = [];
            foreach ($registry['events'] as $eventName => $eventInfo) {
                if (isset($eventInfo['module'])) {
                    $eventToModule[$eventName] = $eventInfo['module'];
                }
            }
            $registry['event_to_module'] = $eventToModule;
        } elseif (!isset($registry['events'])) {
            // 兼容旧格式（如果直接是事件数组）
            $events = $registry;
            $eventToModule = [];
            foreach ($events as $eventName => $eventInfo) {
                if (isset($eventInfo['module'])) {
                    $eventToModule[$eventName] = $eventInfo['module'];
                }
            }
            $registry = ['events' => $events, 'event_to_module' => $eventToModule];
        }
        
        // 确保 dynamic_patterns 存在
        if (!isset($registry['dynamic_patterns'])) {
            $registry['dynamic_patterns'] = [];
        }

        $this->cachedRegistry = $registry;
        $this->cachedFileMtime = file_exists(self::REGISTRY_FILE) ? filemtime(self::REGISTRY_FILE) : 0;

        return $registry;
    }

    /**
     * 刷新注册表（重新扫描并保存）
     *
     * @return bool
     * @throws \RuntimeException 如果多个模块定义了相同的事件名
     */
    public function refresh(): bool
    {
        // 扫描所有事件规约
        $scannedData = $this->scanner->scanAllEvents();

        // 收集所有观察者信息
        $observersData = $this->collectObservers();

        // 组织数据结构，按事件名索引（如果发现冲突会抛出异常）
        $registry = $this->organizeRegistryData($scannedData, $observersData);

        // 扫描所有观察者并合并到事件信息中
        $this->mergeObserversIntoRegistry($registry);

        // 保存注册表
        return $this->saveRegistry($registry);
    }
    
    /**
     * 增量刷新指定模块的事件注册表
     * 仅重新扫描指定模块的事件和观察者，合并到现有注册表
     *
     * @param array $moduleNames 需要刷新的模块名列表
     * @return bool
     * @throws \RuntimeException 如果发现事件名冲突
     */
    public function refreshForModules(array $moduleNames): bool
    {
        // 1. 加载现有注册表
        $registry = $this->getRegistry(true);
        
        // 确保注册表结构完整
        if (!isset($registry['events'])) {
            $registry['events'] = [];
        }
        if (!isset($registry['event_to_module'])) {
            $registry['event_to_module'] = [];
        }
        if (!isset($registry['dynamic_patterns'])) {
            $registry['dynamic_patterns'] = [];
        }
        
        // 2. 清除目标模块的旧数据
        $this->clearModuleData($registry, $moduleNames);
        
        // 3. 扫描目标模块的新数据
        $newScannedData = $this->scanner->scanModules($moduleNames);
        $newObserversData = $this->collectObserversForModules($moduleNames);
        
        // 4. 合并新数据到注册表
        $this->mergeScannedDataIntoRegistry($registry, $newScannedData, $newObserversData);
        
        // 5. 重新排序所有观察者
        foreach ($registry['events'] as &$eventInfo) {
            if (isset($eventInfo['observers']) && count($eventInfo['observers']) > 1) {
                usort($eventInfo['observers'], function ($a, $b) {
                    $sortA = (int)($a['sort'] ?? 10000);
                    $sortB = (int)($b['sort'] ?? 10000);
                    return $sortA <=> $sortB;
                });
            }
        }
        
        // 6. 保存注册表
        return $this->saveRegistry($registry);
    }
    
    /**
     * 清除指定模块的事件和观察者数据
     *
     * @param array &$registry 注册表数据（引用传递）
     * @param array $moduleNames 要清除的模块名列表
     * @return void
     */
    private function clearModuleData(array &$registry, array $moduleNames): void
    {
        // 1. 清除目标模块定义的事件
        foreach ($registry['events'] as $eventName => $eventInfo) {
            if (in_array($eventInfo['module'] ?? '', $moduleNames, true)) {
                unset($registry['events'][$eventName]);
                unset($registry['event_to_module'][$eventName]);
            } else {
                // 2. 清除目标模块注册的观察者（从所有事件中移除）
                if (isset($eventInfo['observers']) && is_array($eventInfo['observers'])) {
                    $registry['events'][$eventName]['observers'] = array_values(array_filter(
                        $eventInfo['observers'],
                        fn($obs) => !in_array($obs['module'] ?? '', $moduleNames, true)
                    ));
                }
            }
        }
        
        // 3. 清除目标模块的动态事件模式
        foreach ($registry['dynamic_patterns'] as $pattern => $patternInfo) {
            if (in_array($patternInfo['module'] ?? '', $moduleNames, true)) {
                unset($registry['dynamic_patterns'][$pattern]);
            } else {
                // 清除动态事件模式中目标模块的观察者
                if (isset($patternInfo['observers']) && is_array($patternInfo['observers'])) {
                    $registry['dynamic_patterns'][$pattern]['observers'] = array_values(array_filter(
                        $patternInfo['observers'],
                        fn($obs) => !in_array($obs['module'] ?? '', $moduleNames, true)
                    ));
                }
            }
        }
    }
    
    /**
     * 收集指定模块的观察者信息
     *
     * @param array $moduleNames 模块名列表
     * @return array 观察者数据，格式：['EventName' => [observer1, observer2, ...]]
     */
    private function collectObserversForModules(array $moduleNames): array
    {
        $observersData = [];
        
        try {
            $eventObserversList = $this->xmlReader->read();
            $env = \Weline\Framework\App\Env::getInstance();
            
            foreach ($eventObserversList as $module_and_file => $moduleEventObservers) {
                $moduleName = explode('::', $module_and_file)[0] ?? '';
                
                // 只处理目标模块
                if (!in_array($moduleName, $moduleNames, true)) {
                    continue;
                }
                
                // 检查模块状态
                if (!$env->getModuleStatus($moduleName)) {
                    continue;
                }
                
                foreach ($moduleEventObservers as $eventName => $eventObservers) {
                    if (!isset($observersData[$eventName])) {
                        $observersData[$eventName] = [];
                    }
                    foreach ($eventObservers as $observer) {
                        $observer['module'] = $moduleName;
                        $observer['module_status'] = true;
                        $observersData[$eventName][] = $observer;
                    }
                }
            }
            
            // 按 sort 值排序
            foreach ($observersData as $eventName => $observers) {
                if (count($observers) > 1) {
                    usort($observersData[$eventName], function ($a, $b) {
                        return ((int)($a['sort'] ?? 10000)) <=> ((int)($b['sort'] ?? 10000));
                    });
                }
            }
        } catch (\Exception $e) {
            \Weline\Framework\App\Env::log_warning('event_registry.log', __('收集模块观察者失败: %{1}', [$e->getMessage()]));
        }
        
        return $observersData;
    }
    
    /**
     * 将扫描的数据合并到现有注册表
     *
     * @param array &$registry 现有注册表（引用传递）
     * @param array $scannedData 扫描的事件数据
     * @param array $observersData 观察者数据
     * @return void
     * @throws \RuntimeException 如果发现事件名冲突
     */
    private function mergeScannedDataIntoRegistry(array &$registry, array $scannedData, array $observersData): void
    {
        foreach ($scannedData as $moduleName => $events) {
            foreach ($events as $eventName => $eventInfo) {
                // 检查是否是动态事件模式
                if ($this->isDynamicEventPattern($eventName)) {
                    // 收集匹配的观察者
                    $matchedObservers = [];
                    foreach ($observersData as $actualEventName => $eventObservers) {
                        if ($this->matchPattern($eventName, $actualEventName)) {
                            $matchedObservers = array_merge($matchedObservers, $eventObservers);
                        }
                    }
                    
                    // 合并到现有动态模式的观察者
                    if (isset($registry['dynamic_patterns'][$eventName])) {
                        $existingObservers = $registry['dynamic_patterns'][$eventName]['observers'] ?? [];
                        $matchedObservers = array_merge($existingObservers, $matchedObservers);
                    }
                    
                    // 排序观察者
                    if (count($matchedObservers) > 1) {
                        usort($matchedObservers, fn($a, $b) => 
                            ((int)($a['sort'] ?? 10000)) <=> ((int)($b['sort'] ?? 10000))
                        );
                    }
                    
                    $registry['dynamic_patterns'][$eventName] = [
                        'name' => $eventInfo['name'] ?? $eventName,
                        'description' => $eventInfo['description'] ?? '',
                        'doc' => $eventInfo['doc'] ?? '',
                        'doc_path' => $eventInfo['doc_path'] ?? '',
                        'has_spec' => $eventInfo['has_spec'] ?? false,
                        'has_doc' => $eventInfo['has_doc'] ?? false,
                        'module' => $moduleName,
                        'pattern' => $eventName,
                        'observers' => $matchedObservers,
                    ];
                    continue;
                }
                
                // 检查事件名冲突（与其他模块冲突）
                if (isset($registry['events'][$eventName])) {
                    $existingModule = $registry['event_to_module'][$eventName] ?? '';
                    if ($existingModule !== $moduleName) {
                        $errorMessage = $this->buildConflictErrorMessage(
                            $eventName,
                            $existingModule,
                            $moduleName,
                            $registry['events'][$eventName],
                            $eventInfo
                        );
                        throw new \RuntimeException($errorMessage);
                    }
                }
                
                // 合并观察者
                $eventObservers = $observersData[$eventName] ?? [];
                if (isset($registry['events'][$eventName]['observers'])) {
                    $eventObservers = array_merge($registry['events'][$eventName]['observers'], $eventObservers);
                }
                
                // 添加/更新事件
                $registry['events'][$eventName] = [
                    'name' => $eventInfo['name'] ?? $eventName,
                    'description' => $eventInfo['description'] ?? '',
                    'doc' => $eventInfo['doc'] ?? '',
                    'doc_path' => $eventInfo['doc_path'] ?? '',
                    'has_spec' => $eventInfo['has_spec'] ?? false,
                    'has_doc' => $eventInfo['has_doc'] ?? false,
                    'module' => $moduleName,
                    'modules' => [
                        $moduleName => [
                            'module' => $moduleName,
                            'doc_path' => $eventInfo['doc_path'] ?? '',
                            'has_doc' => $eventInfo['has_doc'] ?? false
                        ]
                    ],
                    'observers' => $eventObservers
                ];
                
                $registry['event_to_module'][$eventName] = $moduleName;
            }
        }
        
        // 合并观察者到现有事件（目标模块可能监听其他模块的事件）
        foreach ($observersData as $eventName => $observers) {
            if (isset($registry['events'][$eventName])) {
                // 去重后添加
                foreach ($observers as $observer) {
                    $observerKey = ($observer['instance'] ?? '') . '::' . ($observer['name'] ?? '');
                    $isDuplicate = false;
                    foreach ($registry['events'][$eventName]['observers'] as $existingObserver) {
                        $existingKey = ($existingObserver['instance'] ?? '') . '::' . ($existingObserver['name'] ?? '');
                        if ($observerKey === $existingKey && !empty($observerKey)) {
                            $isDuplicate = true;
                            break;
                        }
                    }
                    if (!$isDuplicate) {
                        $registry['events'][$eventName]['observers'][] = $observer;
                    }
                }
            }
        }
    }
    
    /**
     * 收集所有观察者信息
     * 
     * @return array 观察者数据，格式：['EventName' => [observer1, observer2, ...]]
     */
    private function collectObservers(): array
    {
        $observersData = [];
        
        try {
            /** @var \Weline\Framework\Event\Config\XmlReader $xmlReader */
            $xmlReader = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Event\Config\XmlReader::class);
            $eventObserversList = $xmlReader->read();
            
            $env = \Weline\Framework\App\Env::getInstance();
            $moduleList = $env->getModuleList();
            // 首次安装时 modules.php 可能尚未生成，moduleList 为空，此时不过滤观察者，否则 register_installer 等观察者会全部被跳过，导致 Theme/I18n Handle 不存在
            $skipByStatus = !empty($moduleList);
            
            // 合并所有模块的观察者
            foreach ($eventObserversList as $module_and_file => $moduleEventObservers) {
                // 提取模块名并检查模块状态
                $moduleName = explode('::', $module_and_file)[0] ?? '';
                if (empty($moduleName)) {
                    continue;
                }
                if ($skipByStatus && !$env->getModuleStatus($moduleName)) {
                    // 模块列表已存在时，跳过禁用的模块
                    continue;
                }
                
                foreach ($moduleEventObservers as $eventName => $eventObservers) {
                    if (!isset($observersData[$eventName])) {
                        $observersData[$eventName] = [];
                    }
                    // 为每个观察者添加模块信息
                    foreach ($eventObservers as $observer) {
                        $observer['module'] = $moduleName;
                        $observer['module_status'] = true; // 已通过状态检查
                        $observersData[$eventName][] = $observer;
                    }
                }
            }
            
            // 对每个事件的观察者按sort值排序
            foreach ($observersData as $eventName => $observers) {
                if (count($observers) > 1) {
                    usort($observersData[$eventName], function ($a, $b) {
                        $sortA = (int)($a['sort'] ?? 10000);
                        $sortB = (int)($b['sort'] ?? 10000);
                        return $sortA <=> $sortB;
                    });
                }
            }
        } catch (\Exception $e) {
            // 如果收集观察者失败，记录错误但不中断流程
            error_log('收集观察者失败: ' . $e->getMessage());
        }
        
        return $observersData;
    }

    /**
     * 组织注册表数据
     * 将模块级别的事件信息转换为事件名索引的结构
     *
     * @param array $scannedData 扫描的数据
     * @param array $observersData 观察者数据
     * @return array
     * @throws \RuntimeException 如果多个模块定义了相同的事件名
     */
    private function organizeRegistryData(array $scannedData, array $observersData = []): array
    {
        $registry = [];
        // 快速查询：事件名到模块名的映射（用于性能优化）
        $eventToModuleMap = [];
        // 动态事件模式列表（用于匹配动态事件）
        $dynamicEventPatterns = [];

        foreach ($scannedData as $moduleName => $events) {
            foreach ($events as $eventName => $eventInfo) {
                // 检查是否是动态事件模式（包含 {}）
                if ($this->isDynamicEventPattern($eventName)) {
                    // 动态事件模式，存储到 patterns 中
                    // 查找所有匹配该动态事件模式的实际事件名的观察者
                    $matchedObservers = [];
                    foreach ($observersData as $actualEventName => $eventObservers) {
                        if ($this->matchPattern($eventName, $actualEventName)) {
                            // 匹配成功，合并观察者
                            $matchedObservers = array_merge($matchedObservers, $eventObservers);
                        }
                    }
                    
                    // 对观察者按sort值排序
                    if (count($matchedObservers) > 1) {
                        usort($matchedObservers, function ($a, $b) {
                            $sortA = (int)($a['sort'] ?? 10000);
                            $sortB = (int)($b['sort'] ?? 10000);
                            return $sortA <=> $sortB;
                        });
                    }
                    
                    // 验证动态事件模式的规约和文档（收集阶段检查）
                    $hasSpec = $eventInfo['has_spec'] ?? false;
                    $hasDoc = $eventInfo['has_doc'] ?? false;
                    
                    // 如果缺少规约或文档，记录警告
                    if (!$hasSpec || !$hasDoc) {
                        $warnings = [];
                        if (!$hasSpec) {
                            $warnings[] = '缺少事件规约文件 (event.php)';
                        }
                        if (!$hasDoc) {
                            $warnings[] = '缺少事件文档文件 (doc/event/*.md)';
                        }
                        $warningMessage = sprintf(
                            "[事件注册警告] 动态事件模式 '%s' (%s 模块) %s。建议在构建注册表时修复。",
                            $eventName,
                            $moduleName,
                            implode('，', $warnings)
                        );
                        error_log($warningMessage);
                    }
                    
                    $dynamicEventPatterns[$eventName] = [
                        'name' => $eventInfo['name'] ?? $eventName,
                        'description' => $eventInfo['description'] ?? '',
                        'doc' => $eventInfo['doc'] ?? '',
                        'doc_path' => $eventInfo['doc_path'] ?? '',
                        'has_spec' => $hasSpec,
                        'has_doc' => $hasDoc,
                        'module' => $moduleName,
                        'pattern' => $eventName, // 存储原始模式
                        'observers' => $matchedObservers, // 添加匹配的观察者
                    ];
                    continue; // 动态事件模式不添加到普通事件列表
                }

                // 检查事件名是否已被其他模块定义
                if (isset($registry[$eventName])) {
                    $existingModule = $eventToModuleMap[$eventName];
                    $existingEventInfo = $registry[$eventName];
                    
                    // 构建详细的错误信息和解决方案
                    $errorMessage = $this->buildConflictErrorMessage(
                        $eventName,
                        $existingModule,
                        $moduleName,
                        $existingEventInfo,
                        $eventInfo
                    );
                    
                    throw new \RuntimeException($errorMessage);
                }

                // 验证规约和文档（收集阶段检查）
                $hasSpec = $eventInfo['has_spec'] ?? false;
                $hasDoc = $eventInfo['has_doc'] ?? false;
                
                // 如果缺少规约或文档，记录警告（但不阻止注册表的构建）
                if (!$hasSpec || !$hasDoc) {
                    $warnings = [];
                    if (!$hasSpec) {
                        $warnings[] = '缺少事件规约文件 (event.php)';
                    }
                    if (!$hasDoc) {
                        $warnings[] = '缺少事件文档文件 (doc/event/*.md)';
                    }
                    $warningMessage = sprintf(
                        "[事件注册警告] 事件 '%s' (%s 模块) %s。建议在构建注册表时修复。",
                        $eventName,
                        $moduleName,
                        implode('，', $warnings)
                    );
                    error_log($warningMessage);
                }
                
                // 添加新事件
                $registry[$eventName] = [
                    'name' => $eventInfo['name'] ?? $eventName,
                    'description' => $eventInfo['description'] ?? '',
                    'doc' => $eventInfo['doc'] ?? '',
                    'doc_path' => $eventInfo['doc_path'] ?? '',
                    'has_spec' => $hasSpec,
                    'has_doc' => $hasDoc,
                    'module' => $moduleName, // 定义该事件的模块
                    'modules' => [],
                    'observers' => $observersData[$eventName] ?? [] // 添加观察者信息
                ];
                // 添加到快速查询映射
                $eventToModuleMap[$eventName] = $moduleName;

                // 添加提供该事件的模块信息（当前只有一个模块）
                $registry[$eventName]['modules'][$moduleName] = [
                    'module' => $moduleName,
                    'doc_path' => $eventInfo['doc_path'] ?? '',
                    'has_doc' => $eventInfo['has_doc'] ?? false
                ];
            }
        }

        // 返回包含快速查询映射的数据结构
        return [
            'events' => $registry,
            'event_to_module' => $eventToModuleMap, // 快速查询：事件名 => 模块名
            'dynamic_patterns' => $dynamicEventPatterns // 动态事件模式
        ];
    }

    /**
     * 合并观察者到注册表中
     *
     * @param array $registry 注册表数据（引用传递，会修改原数组）
     * @return void
     */
    private function mergeObserversIntoRegistry(array &$registry): void
    {
        // 扫描所有观察者
        $observersData = $this->xmlReader->read();
        
        $env = \Weline\Framework\App\Env::getInstance();
        
        // 合并所有观察者到对应的事件中
        foreach ($observersData as $module_and_file => $eventObservers) {
            // 提取模块名并检查模块状态
            $moduleName = explode('::', $module_and_file)[0] ?? '';
            if (empty($moduleName) || !$env->getModuleStatus($moduleName)) {
                // 跳过禁用的模块
                continue;
            }
            
            foreach ($eventObservers as $eventName => $observers) {
                // 如果事件不存在，跳过（可能是动态事件或未定义的事件）
                if (!isset($registry['events'][$eventName])) {
                    continue;
                }
                
                // 初始化观察者数组
                if (!isset($registry['events'][$eventName]['observers'])) {
                    $registry['events'][$eventName]['observers'] = [];
                }
                
                // 合并观察者（过滤掉禁用的观察者，并去重）
                foreach ($observers as $observer) {
                    // 跳过禁用的观察者
                    if (($observer['disabled'] ?? 'false') === 'true') {
                        continue;
                    }
                    
                    // 添加模块信息
                    $observer['module'] = $moduleName;
                    $observer['module_status'] = true; // 已通过状态检查
                    
                    // 检查是否已存在相同的观察者（根据 instance 和 name 判断）
                    $observerKey = ($observer['instance'] ?? '') . '::' . ($observer['name'] ?? '');
                    $isDuplicate = false;
                    foreach ($registry['events'][$eventName]['observers'] as $existingObserver) {
                        $existingKey = ($existingObserver['instance'] ?? '') . '::' . ($existingObserver['name'] ?? '');
                        if ($observerKey === $existingKey && !empty($observerKey)) {
                            $isDuplicate = true;
                            break;
                        }
                    }
                    
                    // 如果不是重复的，添加到观察者列表
                    if (!$isDuplicate) {
                        $registry['events'][$eventName]['observers'][] = $observer;
                    }
                }
            }
        }
        
        // 对所有事件的观察者按 sort 值排序
        foreach ($registry['events'] as $eventName => &$eventInfo) {
            if (isset($eventInfo['observers']) && count($eventInfo['observers']) > 1) {
                usort($eventInfo['observers'], function ($a, $b) {
                    $sortA = (int)($a['sort'] ?? 10000);
                    $sortB = (int)($b['sort'] ?? 10000);
                    return $sortA <=> $sortB;
                });
            }
        }
    }

    /**
     * 检查是否是动态事件模式
     *
     * @param string $eventName 事件名
     * @return bool
     */
    private function isDynamicEventPattern(string $eventName): bool
    {
        return str_contains($eventName, '{') && str_contains($eventName, '}');
    }

    /**
     * 保存注册表
     *
     * @param array $registry 注册表数据
     * @return bool
     */
    public function saveRegistry(array $registry): bool
    {
        $content = "<?php return " . var_export($registry, true) . ";\n";

        // 确保目录存在
        $dir = dirname(self::REGISTRY_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $result = file_put_contents(self::REGISTRY_FILE, $content, LOCK_EX);

        if ($result !== false) {
            // 更新实例缓存
            $this->cachedRegistry = $registry;
            $this->cachedFileMtime = file_exists(self::REGISTRY_FILE) ? filemtime(self::REGISTRY_FILE) : 0;
            
            // 清除 EventData 的静态缓存，确保其他使用 EventData 的代码能立即看到新生成的文件
            EventData::clearCache();
            
            return true;
        }

        return false;
    }

    /**
     * 构建事件冲突错误信息
     *
     * @param string $eventName 冲突的事件名
     * @param string $existingModule 已定义该事件的模块
     * @param string $conflictModule 冲突的模块
     * @param array $existingEventInfo 已定义事件的信息
     * @param array $conflictEventInfo 冲突事件的信息
     * @return string
     */
    private function buildConflictErrorMessage(
        string $eventName,
        string $existingModule,
        string $conflictModule,
        array $existingEventInfo,
        array $conflictEventInfo
    ): string {
        // 获取模块路径
        $existingModulePath = $this->getModulePath($existingModule);
        $conflictModulePath = $this->getModulePath($conflictModule);
        
        // 生成建议的新事件名（使用模块名作为前缀）
        $suggestedEventName = $this->suggestEventName($conflictModule, $eventName);
        
        $message = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "【致命错误】事件名冲突检测\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        $message .= "❌ 冲突事件名：{$eventName}\n\n";
        
        $message .= "📦 已注册模块信息：\n";
        $message .= "   模块名称：{$existingModule}\n";
        if ($existingModulePath) {
            $message .= "   模块路径：{$existingModulePath}\n";
        }
        $message .= "   规约文件：{$existingModulePath}/event.php\n";
        if (!empty($existingEventInfo['name'])) {
            $message .= "   事件显示名：{$existingEventInfo['name']}\n";
        }
        if (!empty($existingEventInfo['doc_path'])) {
            $message .= "   文档路径：{$existingModulePath}/{$existingEventInfo['doc_path']}\n";
        }
        $message .= "\n";
        
        $message .= "⚠️  冲突模块信息：\n";
        $message .= "   模块名称：{$conflictModule}\n";
        if ($conflictModulePath) {
            $message .= "   模块路径：{$conflictModulePath}\n";
        }
        $message .= "   规约文件：{$conflictModulePath}/event.php\n";
        if (!empty($conflictEventInfo['name'])) {
            $message .= "   事件显示名：{$conflictEventInfo['name']}\n";
        }
        if (!empty($conflictEventInfo['doc_path'])) {
            $message .= "   文档路径：{$conflictModulePath}/{$conflictEventInfo['doc_path']}\n";
        }
        $message .= "\n";
        
        $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "💡 解决方案\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        $message .= "方案 1：修改冲突模块的事件名（推荐）\n";
        $message .= "   1. 打开文件：{$conflictModulePath}/event.php\n";
        $message .= "   2. 将事件名 '{$eventName}' 修改为：'{$suggestedEventName}'\n";
        $message .= "   3. 如果存在文档文件，重命名文档文件以匹配新事件名\n";
        $message .= "   4. 更新所有使用该事件的代码，将事件名改为 '{$suggestedEventName}'\n";
        $message .= "   5. 运行 'php bin/w event:rebuild' 重建事件注册表\n\n";
        
        $message .= "方案 2：删除冲突模块的事件定义\n";
        $message .= "   如果 {$conflictModule} 模块不需要定义此事件，可以：\n";
        $message .= "   1. 删除或注释掉 {$conflictModulePath}/event.php 中的事件定义\n";
        $message .= "   2. 如果存在文档文件，可以删除或保留（不影响功能）\n";
        $message .= "   3. 运行 'php bin/w event:rebuild' 重建事件注册表\n\n";
        
        $message .= "方案 3：联系已注册模块的维护者\n";
        $message .= "   如果 {$conflictModule} 模块确实需要使用此事件名，可以：\n";
        $message .= "   1. 联系 {$existingModule} 模块的维护者，协商事件名的使用\n";
        $message .= "   2. 或者考虑使用不同的事件名来避免冲突\n\n";
        
        $message .= "📝 事件命名规范建议：\n";
        $message .= "   推荐格式：{模块名}::{事件功能名}\n";
        $message .= "   示例：Weline_Admin::user_login, Weline_Order::order_created\n";
        $message .= "   这样可以有效避免事件名冲突\n\n";
        
        $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        
        return $message;
    }

    /**
     * 获取模块路径
     *
     * @param string $moduleName 模块名
     * @return string
     */
    private function getModulePath(string $moduleName): string
    {
        try {
            $env = \Weline\Framework\App\Env::getInstance();
            $moduleInfo = $env->getModuleInfo($moduleName);
            return $moduleInfo['base_path'] ?? '';
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * 建议新的事件名（使用模块名作为前缀）
     *
     * @param string $moduleName 模块名
     * @param string $originalEventName 原始事件名
     * @return string
     */
    private function suggestEventName(string $moduleName, string $originalEventName): string
    {
        // 如果事件名已经包含模块名，尝试提取功能名
        if (str_contains($originalEventName, '::')) {
            $parts = explode('::', $originalEventName, 2);
            $functionName = $parts[1] ?? $originalEventName;
        } else {
            $functionName = $originalEventName;
        }
        
        // 生成新的事件名：模块名::功能名
        return $moduleName . '::' . $functionName;
    }

    /**
     * 获取事件列表
     *
     * @return array
     */
    public function getEvents(): array
    {
        $registry = $this->getRegistry();
        return $registry['events'] ?? [];
    }

    /**
     * 获取动态事件模式列表
     *
     * @return array
     */
    public function getDynamicPatterns(): array
    {
        $registry = $this->getRegistry();
        return $registry['dynamic_patterns'] ?? [];
    }

    /**
     * 获取事件名到模块名的映射（快速查询）
     *
     * @return array
     */
    public function getEventToModuleMap(): array
    {
        $registry = $this->getRegistry();
        return $registry['event_to_module'] ?? [];
    }

    /**
     * 检查事件是否有规约
     *
     * @param string $eventName 事件名
     * @return bool
     */
    public function hasSpec(string $eventName): bool
    {
        $events = $this->getEvents();
        
        // 先检查精确匹配
        if (isset($events[$eventName]) && ($events[$eventName]['has_spec'] ?? false)) {
            return true;
        }
        
        // 检查动态事件模式匹配
        $matchedPattern = $this->matchDynamicEventPattern($eventName);
        if ($matchedPattern && ($matchedPattern['has_spec'] ?? false)) {
            return true;
        }
        
        return false;
    }

    /**
     * 检查事件是否有文档
     *
     * @param string $eventName 事件名
     * @return bool
     */
    public function hasDoc(string $eventName): bool
    {
        $events = $this->getEvents();
        
        // 先检查精确匹配
        if (isset($events[$eventName]) && ($events[$eventName]['has_doc'] ?? false)) {
            return true;
        }
        
        // 检查动态事件模式匹配
        $matchedPattern = $this->matchDynamicEventPattern($eventName);
        if ($matchedPattern && ($matchedPattern['has_doc'] ?? false)) {
            return true;
        }
        
        return false;
    }

    /**
     * 获取事件信息
     *
     * @param string $eventName 事件名
     * @return array|null
     */
    public function getEventInfo(string $eventName): ?array
    {
        $events = $this->getEvents();
        
        // 先检查精确匹配
        if (isset($events[$eventName])) {
            return $events[$eventName];
        }
        
        // 检查动态事件模式匹配
        $matchedPattern = $this->matchDynamicEventPattern($eventName);
        if ($matchedPattern) {
            return $matchedPattern;
        }
        
        return null;
    }

    /**
     * 获取事件所属的模块名
     *
     * @param string $eventName 事件名
     * @return string|null
     */
    public function getEventModule(string $eventName): ?string
    {
        $eventToModule = $this->getEventToModuleMap();
        
        // 先检查精确匹配
        if (isset($eventToModule[$eventName])) {
            return $eventToModule[$eventName];
        }
        
        // 检查动态事件模式匹配
        $matchedPattern = $this->matchDynamicEventPattern($eventName);
        if ($matchedPattern && isset($matchedPattern['module'])) {
            return $matchedPattern['module'];
        }
        
        return null;
    }

    /**
     * 匹配动态事件模式
     * 将实际事件名与动态事件模式进行匹配
     * 
     * @param string $eventName 实际事件名，如 "Framework_View::head"
     * @return array|null 匹配到的模式信息，如果不匹配返回 null
     */
    private function matchDynamicEventPattern(string $eventName): ?array
    {
        $registry = $this->getRegistry();
        $dynamicPatterns = $registry['dynamic_patterns'] ?? [];
        
        foreach ($dynamicPatterns as $pattern => $patternInfo) {
            if ($this->matchPattern($pattern, $eventName)) {
                return $patternInfo;
            }
        }
        
        return null;
    }

    /**
     * 匹配事件名与模式
     * 例如：模式 "Framework_View::{position}" 可以匹配 "Framework_View::head"
     * 
     * @param string $pattern 模式，如 "Framework_View::{position}"
     * @param string $eventName 实际事件名，如 "Framework_View::head"
     * @return bool
     */
    public function matchPattern(string $pattern, string $eventName): bool
    {
        // 将模式转换为正则表达式
        // 例如：Framework_View::{position} -> Framework_View::(.+)
        // 例如：{table_name}_model_load_before -> (.+)_model_load_before
        
        // 先转义特殊字符
        $regex = preg_quote($pattern, '/');
        
        // 将转义后的 {变量名} 替换为正则表达式的 (.+)
        // preg_quote 会将 { 转义为 \{，} 转义为 \}
        // 在正则表达式中，要匹配 \{，需要使用 \\\{
        // 在字符串中，\\\\\\{ 表示正则表达式中的 \\\{
        $regex = preg_replace('/\\\\\\{[^}]+\\\\\\}/', '(.+)', $regex);
        
        // 匹配整个字符串
        $regex = '/^' . $regex . '$/';
        
        return (bool) preg_match($regex, $eventName);
    }
}

