<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Event;

use Weline\Framework\App\Debug;
use Weline\Framework\App\Env;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Config\XmlReader;
use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Event\EventData;

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
     * @var EventRegistry
     */
    private EventRegistry $eventRegistry;

    /**
     * 当前正在执行的事件名栈（支持事件嵌套）
     * 格式：['Weline_Demo::demo', 'Weline_Other::other']
     */
    private static array $currentEventStack = [];

    /**
     * 循环检测：记录每个事件名调用 Weline_Admin::msg 的次数
     * 格式：['Weline_Demo::demo' => 3]
     */
    private static array $msgCallCounts = [];

    /**
     * 循环检测：记录已检测到循环的事件名
     * 格式：['Weline_Demo::demo' => true]
     */
    private static array $circularDetected = [];

    /**
     * 是否正在发送系统消息（防止循环调用）
     */
    private static bool $isSendingMsg = false;

    public function __construct(
        XmlReader $reader,
        EventRegistry $eventRegistry
    )
    {
        $this->reader = $reader;
        $this->eventRegistry = $eventRegistry;
    }

    public function scanEvents()
    {
        if (empty($this->eventsObservers)) {
            // 先收集所有模块的观察者，不做排序
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
        }

        return $this->eventsObservers;
    }

    public function getEventObservers(string $eventName)
    {
        // 优先从 generated/events.php 读取观察者（性能优化）
        $registry = $this->eventRegistry->getRegistry();
        
        // 先检查精确匹配
        if (isset($registry['events'][$eventName]['observers'])) {
            return $registry['events'][$eventName]['observers'];
        }
        
        // 检查是否匹配动态事件模式
        $dynamicPatterns = $registry['dynamic_patterns'] ?? [];
        foreach ($dynamicPatterns as $pattern => $patternInfo) {
            if ($this->eventRegistry->matchPattern($pattern, $eventName)) {
                // 匹配到动态事件模式，返回该模式的观察者
                return $patternInfo['observers'] ?? [];
            }
        }
        
        // 检查是否启用事件扫描回退机制（默认关闭以提升性能）
        $scanEnabled = Env::getInstance()->getConfig('event.scan_enabled', false);
        if (!$scanEnabled) {
            // 如果扫描功能已关闭，直接返回空数组，不执行扫描
            return [];
        }
        
        // 如果 generated/events.php 中没有观察者信息，回退到扫描方式（兼容旧版本）
        // 注意：动态事件模式的观察者注册在实际事件名上，所以通过扫描方式可以获取到
        $evenObserverLists = $this->scanEvents();

        return $evenObserverLists[$eventName] ?? [];
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
        // 记录当前正在执行的事件名（压入栈）
        self::$currentEventStack[] = $eventName;
        try {
            // 规约和文档检查已在收集阶段（EventRegistry::organizeRegistryData）完成
            // 这里只检查 Weline_Admin::msg 事件的循环调用
            if ($eventName === 'Weline_Admin::msg') {
                $this->checkCircularCall();
            }

            // 获取事件监听器（观察者）
            $observers = $this->getEventObservers($eventName);
            // 检查是否有监听器（Weline_Admin::msg 事件除外，因为它用于发送错误消息）
            if (empty($observers) && $eventName !== 'Weline_Admin::msg') {
                // 如果没有监听器，直接跳过，不执行事件
                return $this;
            }
            if (is_array($data)) {
                $data['observers'] = $observers;
                $this->events[$eventName] = (new Event($data))->setName($eventName);
            } else {
                $this->events[$eventName] = (new Event(['data' => &$data, 'observers' => $observers]))->setName($eventName);
            }
            $this->events[$eventName]->dispatch();
        } finally {
            // 事件执行完毕，弹出栈
            array_pop(self::$currentEventStack);
        }

        return $this;
    }

    /**
     * 检查循环调用（仅用于 Weline_Admin::msg 事件）
     * 检测触发 Weline_Admin::msg 的事件名，防止循环调用
     *
     * @return bool 如果没有循环返回 true，否则返回 false
     */
    private function checkCircularCall(): bool
    {
        // 获取当前正在执行的事件名（栈中倒数第二个，因为最后一个是我们自己）
        $stackCount = count(self::$currentEventStack);
        if ($stackCount < 2) {
            // 如果栈中只有 Weline_Admin::msg，说明是直接调用，允许执行
            return true;
        }

        // 获取触发 Weline_Admin::msg 的事件名（栈中倒数第二个）
        $sourceEventName = self::$currentEventStack[$stackCount - 2];

        // 如果触发事件是 Weline_Admin::msg 本身，允许执行（避免误判）
        if ($sourceEventName === 'Weline_Admin::msg') {
            return true;
        }

        // 检查是否已检测到循环
        if (isset(self::$circularDetected[$sourceEventName]) && self::$circularDetected[$sourceEventName]) {
            // 已检测到循环，直接跳过
            return false;
        }

        // 增加调用计数
        if (!isset(self::$msgCallCounts[$sourceEventName])) {
            self::$msgCallCounts[$sourceEventName] = 0;
        }
        self::$msgCallCounts[$sourceEventName]++;

        // 如果调用次数超过3次，检测到循环
        if (self::$msgCallCounts[$sourceEventName] > 3) {
            // 标记为已检测到循环
            self::$circularDetected[$sourceEventName] = true;

            // 记录一次循环错误消息（只在第4次时记录一次）
            if (self::$msgCallCounts[$sourceEventName] === 4) {
                $this->sendCircularErrorMsg($sourceEventName);
            }

            // 跳过执行
            return false;
        }

        // 没有循环，允许执行
        return true;
    }

    /**
     * 发送循环错误消息
     *
     * @param string $sourceEventName 触发循环的事件名
     */
    private function sendCircularErrorMsg(string $sourceEventName): void
    {
        // 防止循环调用
        if (self::$isSendingMsg) {
            return;
        }

        try {
            self::$isSendingMsg = true;

            $title = __('【超级严重】检测到事件循环调用');
            $content = __(
                "检测到事件 '%{sourceEventName}' 循环调用 Weline_Admin::msg 事件。\n\n" .
                "该事件已被阻止执行，以防止系统阻塞。\n\n" .
                "调用次数：%{count}\n\n" .
                "请检查该事件的代码，确保：\n" .
                "1. 事件处理逻辑中不会再次触发 Weline_Admin::msg 事件\n" .
                "2. 错误处理逻辑不会导致无限循环\n" .
                "3. 条件判断逻辑正确，避免重复触发\n\n" .
                "修复后，需要重启应用以清除循环检测状态。",
                [
                    'sourceEventName' => $sourceEventName,
                    'count' => self::$msgCallCounts[$sourceEventName] ?? 0
                ]
            );

            // 直接调用观察者，避免再次触发事件检查
            $this->sendSystemMessageDirectly($title, $content);
        } catch (\Exception $e) {
            // 如果发送失败，记录到错误日志
            if (defined('DEV') && DEV) {
                error_log(__('EventsManager Error: Failed to send circular error message - %{error}', ['error' => $e->getMessage()]));
            }
        } finally {
            self::$isSendingMsg = false;
        }
    }

    /**
     * 直接发送系统消息（绕过事件检查）
     *
     * @param string $title 标题
     * @param string $content 内容
     */
    private function sendSystemMessageDirectly(string $title, string $content): void
    {
        try {
            // 获取观察者
            $observers = $this->getEventObservers('Weline_Admin::msg');
            
            if (empty($observers)) {
                // 如果没有观察者，记录到错误日志
                if (defined('DEV') && DEV) {
                    error_log(__('EventsManager Warning: No observer found for Weline_Admin::msg'));
                }
                return;
            }

            // 创建事件对象
            $event = new Event([
                'data' => [
                    'title' => $title,
                    'content' => $content,
                    'is_read' => false,
                    'is_icon' => 1,
                    'is_img' => 0,
                    'avatar' => 'ri-error-warning-line'
                ],
                'observers' => $observers
            ]);
            $event->setName('Weline_Admin::msg');

            // 直接执行观察者，不通过 dispatch 方法（避免再次检查）
            foreach ($observers as $observerConfig) {
                if (isset($observerConfig['instance'])) {
                    try {
                        $observer = ObjectManager::getInstance($observerConfig['instance']);
                        if ($observer instanceof ObserverInterface) {
                            $observer->execute($event);
                        }
                    } catch (\Exception $e) {
                        // 忽略观察者执行错误，避免影响主流程
                        if (defined('DEV') && DEV) {
                            error_log(__('EventsManager Warning: Observer execution failed - %{error}', ['error' => $e->getMessage()]));
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // 如果发送失败，记录到错误日志
            if (defined('DEV') && DEV) {
                error_log(__('EventsManager Error: Failed to send system message directly - %{error}', ['error' => $e->getMessage()]));
            }
        }
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
