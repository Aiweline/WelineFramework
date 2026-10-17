<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Event;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\TraceGate;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Config\XmlReader;
use Weline\Framework\App\Exception;

class EventsManager
{
//    protected  WeakMaps $observers;# php8
    /**@var $events Event[] */
    protected array $events = [];

    protected array $eventsObservers = [];

    /**
     * @var XmlReader
     */
    private XmlReader $reader;

    /**
     * 事件注册表（依赖接口，便于测试与扩展）
     * @var EventRegistryInterface
     */
    private EventRegistryInterface $eventRegistry;
    
    /**
     * 性能优化：缓存事件观察者查找结果
     */
    private array $observerCache = [];
    private ?array $observerRegistrySnapshot = null;
    private bool $observersScanned = false;
    
    /**
     * 性能优化：缓存模块状态检查结果
     */
    private static array $moduleStatusCache = [];

    /**
     * 显式扫描观察者时的重入保护：reader->read() 中再次请求扫描时，
     * 复用正在构建的列表，避免递归读取各模块 event.xml。
     */
    private static bool $scanningObservers = false;

    public function __construct(
        XmlReader $reader,
        EventRegistryInterface $eventRegistry
    )
    {
        $this->reader = $reader;
        $this->eventRegistry = $eventRegistry;
    }

    /**
     * 清空观察者缓存（事件注册表刷新后调用，确保后续从新注册表读取）
     */
    public function clearObserverCache(): void
    {
        $this->observerCache = [];
        $this->eventsObservers = [];
        $this->observerRegistrySnapshot = null;
        $this->observersScanned = false;
        self::$moduleStatusCache = [];
    }

    /**
     * WLS 每请求重置事件运行期缓存，防止跨请求边界污染。
     */
    public function resetRequestState(): void
    {
        foreach ($this->events as $event) {
            if ($event instanceof Event) {
                $event->releaseBoundPayload();
            }
        }
        $this->events = [];
        // observer/module 状态属于进程级只读缓存，频繁清空会导致每请求重扫事件配置。
        // 仅重置请求内 Event 实例数据，避免跨请求污染同时保留热缓存性能。
    }

    public function scanEvents()
    {
        $this->currentObserverRegistry();
        if (self::$scanningObservers) {
            return $this->eventsObservers;
        }
        if (!$this->observersScanned) {
            self::$scanningObservers = true;
            try {
                foreach ($this->reader->read() as $module_and_file => $eventObservers) {
                    foreach ($eventObservers as $event_name => $eventObserver) {
                        if (isset($this->eventsObservers[$event_name])) {
                            $this->eventsObservers[$event_name] = array_merge($this->eventsObservers[$event_name], $eventObserver);
                        } else {
                            $this->eventsObservers[$event_name] = $eventObserver;
                        }
                    }
                }

                // 所有模块的观察者合并完成后，对每个事件的观察者数组按sort值排序
                // 使用整数比较，实现"越小越优先"
                // 只有当观察者数量大于1时才排序，节省性能
                foreach ($this->eventsObservers as $event_name => $eventObserver) {
                    if (count($this->eventsObservers[$event_name]) > 1) {
                        usort($this->eventsObservers[$event_name], function ($a, $b) {
                            $sortA = (int)($a['sort'] ?? 10000);
                            $sortB = (int)($b['sort'] ?? 10000);
                            return $sortA <=> $sortB;
                        });
                    }
                }
                $this->observersScanned = true;
                $this->observerCache = [];
            } finally {
                self::$scanningObservers = false;
            }
        }

        return $this->eventsObservers;
    }

    public function getEventObservers(string $eventName, bool $registryKnownToHaveObservers = false)
    {
        // Registry owns the complete runtime snapshot, including dynamic patterns.
        // A reload/change invalidates positive and negative lookup results together.
        $registry = $this->currentObserverRegistry();
        // 性能优化：检查缓存
        if (isset($this->observerCache[$eventName])) {
            return $this->observerCache[$eventName];
        }

        $observers = [];
        // 先检查精确匹配
        if (isset($registry['events'][$eventName]['observers'])) {
            $observers = $registry['events'][$eventName]['observers'];
        } else {
            // 检查是否匹配动态事件模式
            $dynamicPatterns = $registry['dynamic_patterns'] ?? [];
            foreach ($dynamicPatterns as $pattern => $patternInfo) {
                if ($this->eventRegistry->matchPattern($pattern, $eventName)) {
                    // 匹配到动态事件模式，返回该模式的观察者
                    $observers = $patternInfo['observers'] ?? [];
                    break;
                }
            }
            
            // Explicit scanEvents() callers may supply local observers. An ordinary
            // registry miss must never execute every module's event.php/XML on a request.
            if (empty($observers)) {
                $observers = $this->eventsObservers[$eventName] ?? [];
            }
        }
        
        // 过滤掉模块被禁用的观察者并缓存结果
        $filteredObservers = $this->filterActiveObservers($observers);
        $this->observerCache[$eventName] = $filteredObservers;
        
        return $filteredObservers;
    }

    public function hasObservers(string $eventName): bool
    {
        return $this->getEventObservers($eventName) !== [];
    }

    private function currentObserverRegistry(): array
    {
        $registry = $this->eventRegistry->getRegistry();
        if ($this->observerRegistrySnapshot !== $registry) {
            $this->clearObserverCache();
            $this->observerRegistrySnapshot = $registry;
        }
        return $registry;
    }
    
    /**
     * 过滤掉模块被禁用的观察者
     * 
     * 性能优化：缓存模块状态检查结果
     * 
     * @param array $observers 观察者列表
     * @return array 过滤后的观察者列表
     */
    private function filterActiveObservers(array $observers): array
    {
        if (empty($observers)) {
            return [];
        }
        
        static $env = null;
        if ($env === null) {
            $env = Env::getInstance();
        }
        
        $activeObservers = [];
        
        foreach ($observers as $observer) {
            // 检查观察者是否被禁用（disabled属性）
            if (($observer['disabled'] ?? 'false') === 'true') {
                continue;
            }
            
            // 检查观察者所在模块的激活状态
            $moduleName = $observer['module'] ?? '';
            if (!empty($moduleName)) {
                // 如果注册表中已有module_status字段，直接使用
                if (isset($observer['module_status'])) {
                    if (!$observer['module_status']) {
                        continue;
                    }
                } else {
                    // 性能优化：缓存模块状态检查结果
                    if (!isset(self::$moduleStatusCache[$moduleName])) {
                        self::$moduleStatusCache[$moduleName] = $env->getModuleStatus($moduleName);
                    }
                    if (!self::$moduleStatusCache[$moduleName]) {
                        continue;
                    }
                }
            }
            
            $activeObservers[] = $observer;
        }
        
        return $activeObservers;
    }

    /**
     * @DESC         |添加事件
     *
     * 参数区：
     *
     * @param string $eventName
     * @param array $data
     *
     * @return $this
     * @throws null
     */
    public function dispatch(string $eventName, mixed &$data = []): static
    {
        $traceStart = TraceGate::isArmed() ? microtime(true) : 0.0;

        // Resolve the current registry snapshot once, including cached negative results.
        $observers = $this->getEventObservers($eventName);

        // 检查是否有监听器，没有则直接跳过
        if (empty($observers)) {
            if ($traceStart > 0) {
                TraceGate::recordSpan('event::' . $eventName, (microtime(true) - $traceStart) * 1000, 'event');
            }
            return $this;
        }

        if (is_array($data)) {
            // Array payload is bound by reference; observers stay off the caller bag.
            $event = Event::forArrayPayload($data, $observers, $eventName);
        } else {
            $event = (new Event(['data' => &$data, 'observers' => $observers]))->setName($eventName);
        }

        if (isset($this->events[$eventName]) && $this->events[$eventName] instanceof Event) {
            $this->events[$eventName]->releaseBoundPayload();
        }
        $this->events[$eventName] = $event;
        $event->dispatch();

        if (!is_array($data)) {
            // 标量（如 seo_decode 的 $url）：观察者 setData('data', $decoded) 后需回写给调用方
            $modified = $event->getEvenData('data');
            if ($modified !== null) {
                $data = $modified;
            }
        } else {
            // Caller bag is source of truth after in-place observer mutations; drop Event→bag stickiness.
            $event->releaseBoundPayload();
        }

        if ($traceStart > 0) {
            TraceGate::recordSpan('event::' . $eventName, (microtime(true) - $traceStart) * 1000, 'event');
        }
        return $this;
    }

    /**
     * @DESC          # 读取事件数据 读取非对象数值传输时的事件更改结果 如果是对象数据则不需要这个函数
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/15 21:57
     * 参数区：
     *
     * @param string $eventName
     *
     * @return DataObject|null
     */
    public function getEventData(string $eventName): DataObject|null
    {
        if (isset($this->events[$eventName])) {
            return $this->events[$eventName];
        }
        return null;
    }

    /**
     * @DESC         |添加观察者
     *
     * 参数区：
     *
     * @param string $eventName
     * @param Observer $observer
     *
     * @return $this
     * @throws Exception
     */
    public function addObserver(string $eventName, Observer $observer)
    {
        if (!isset($this->events[$eventName])) {
            throw new Exception(__(sprintf('事件异常：%{1} 事件不存在！', $eventName)));
        }
        $event = $this->events[$eventName];
        $event->addObserver($observer);

        return $this;
    }

    /**
     * @DESC         |触发运行器
     *
     * 参数区：
     *
     * @param string $eventName
     *
     * @throws Exception
     */
    public function trigger(string $eventName)
    {
        if (!isset($this->events[$eventName])) {
            throw new Exception(__(sprintf('事件异常：%{1} 事件不存在！', $eventName)));
        }
        $event = $this->events[$eventName];
        $event->dispatch();
    }
}
