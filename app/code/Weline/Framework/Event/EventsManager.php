<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Event;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\RequestLifecycleTrace;
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
    
    /**
     * 性能优化：缓存模块状态检查结果
     */
    private static array $moduleStatusCache = [];

    /**
     * 扫描观察者时的重入保护：避免 dispatch → getEventObservers → scanEvents → reader->read()
     * 过程中再次触发事件导致递归扫描各模块 event.xml，造成内存或死循环。
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
    }

    /**
     * WLS 每请求重置事件运行期缓存，防止跨请求边界污染。
     */
    public function resetRequestState(): void
    {
        $this->events = [];
        // observer/module 状态属于进程级只读缓存，频繁清空会导致每请求重扫事件配置。
        // 仅重置请求内 Event 实例数据，避免跨请求污染同时保留热缓存性能。
    }

    public function scanEvents()
    {
        if (self::$scanningObservers) {
            return $this->eventsObservers;
        }
        if (empty($this->eventsObservers)) {
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
            } finally {
                self::$scanningObservers = false;
            }
        }

        return $this->eventsObservers;
    }

    public function getEventObservers(string $eventName, bool $registryKnownToHaveObservers = false)
    {
        // 性能优化：检查缓存
        if (isset($this->observerCache[$eventName])) {
            return $this->observerCache[$eventName];
        }

        // 性能优化：注册表明确无观察者时直接返回，避免 getRegistry + 动态模式匹配 + scanEvents 回退
        if (!$registryKnownToHaveObservers && !$this->eventRegistry->hasObservers($eventName)) {
            $this->observerCache[$eventName] = [];
            return [];
        }
        
        // 优先从 generated/events.php 读取观察者（性能优化）
        $registry = $this->eventRegistry->getRegistry();
        if(empty($registry['events'])) {
            return [];
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
            
            // 如果还没有找到，回退到扫描所有模块的 event.xml（确保新增/未写入缓存的观察者也能生效）
            // 重入保护：若当前正在 scanEvents() 中，不再递归扫描，避免事件链触发“收集各模块 event.xml”导致内存/循环
            if (empty($observers)) {
                $evenObserverLists = $this->scanEvents();
                $observers = $evenObserverLists[$eventName] ?? [];
            }
        }
        
        // 过滤掉模块被禁用的观察者并缓存结果
        $filteredObservers = $this->filterActiveObservers($observers);
        $this->observerCache[$eventName] = $filteredObservers;
        
        return $filteredObservers;
    }

    public function hasObservers(string $eventName): bool
    {
        if (isset($this->observerCache[$eventName])) {
            return $this->observerCache[$eventName] !== [];
        }

        return $this->eventRegistry->hasObservers($eventName);
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
        $traceStart = RequestLifecycleTrace::isEnabled() ? microtime(true) : 0.0;

        // 快速检测是否有观察者（仅基于注册表，避免为「无监听事件」触发昂贵的扫描）
        if (!$this->hasObservers($eventName)) {
            if ($traceStart > 0) {
                RequestLifecycleTrace::recordSpan('event::' . $eventName, (microtime(true) - $traceStart) * 1000, 'event');
            }
            return $this;
        }

        // 获取事件监听器（观察者）
        $observers = $this->getEventObservers($eventName, true);

        // 检查是否有监听器，没有则直接跳过
        if (empty($observers)) {
            if ($traceStart > 0) {
                RequestLifecycleTrace::recordSpan('event::' . $eventName, (microtime(true) - $traceStart) * 1000, 'event');
            }
            return $this;
        }

        if (is_array($data)) {
            $data['observers'] = $observers;
            $this->events[$eventName] = (new Event($data))->setName($eventName);
        } else {
            $this->events[$eventName] = (new Event(['data' => &$data, 'observers' => $observers]))->setName($eventName);
        }

        $this->events[$eventName]->dispatch();

        // 将 Observer 修改的 Event 数据回写到调用方的 $data（通过引用）
        // Event 构造时复制了 $data，Observer 修改的是 Event 内部的 _data，
        // 必须在 dispatch 完成后同步回去，否则调用方永远读不到 Observer 设置的 result/error 等
        if (is_array($data)) {
            $modifiedInner = $this->events[$eventName]->getEvenData();
            if ($modifiedInner !== null) {
                if (array_key_exists('data', $data)) {
                    // 结构化事件数据（如 ['data' => ['operation' => ...]]）
                    $data['data'] = $modifiedInner;
                } elseif (is_array($modifiedInner)) {
                    // 扁平事件数据（如 ['provider' => ..., 'result' => null]）
                    foreach ($modifiedInner as $k => $v) {
                        $data[$k] = $v;
                    }
                }
            }
        } else {
            // 标量（如 seo_decode 的 $url）：观察者 setData('data', $decoded) 后需回写给调用方
            $modified = $this->events[$eventName]->getEvenData('data');
            if ($modified !== null) {
                $data = $modified;
            }
        }

        if ($traceStart > 0) {
            RequestLifecycleTrace::recordSpan('event::' . $eventName, (microtime(true) - $traceStart) * 1000, 'event');
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
