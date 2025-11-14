<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Event;

use Weline\Framework\App\Debug;
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
     * 循环检测：记录每个事件名调用 Weline_Framework::msg 的次数
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

    /**
     * 是否正在检查事件规约和文档（防止循环调用）
     */
    private static bool $isCheckingEvent = false;

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
            foreach ($this->reader->read() as $module_and_file => $eventObservers) {
                foreach ($eventObservers as $event_name => $eventObserver) {
                    // 二维数组$eventObserver根据sort字段排序
                    usort($eventObserver, function ($a, $b) {
                        return strnatcasecmp($a['sort'], $b['sort']);
                    });
                    if (isset($this->eventsObservers[$event_name])) {
                        $this->eventsObservers[$event_name] = array_merge($this->eventsObservers[$event_name], $eventObserver);
                    } else {
                        $this->eventsObservers[$event_name] = $eventObserver;
                    }
                }
//                $this->eventsObservers = array_merge($this->eventsObservers, $eventObservers);
            }
        }

        return $this->eventsObservers;
    }

    public function getEventObservers(string $eventName)
    {
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
            // 检查事件是否有规约和文档
            if (!$this->checkEventSpecAndDoc($eventName)) {
                // 如果没有规约或文档，不执行事件
                // 但仍然创建一个空的 Event 对象，以便 getEventData() 不会返回 null
                if (is_array($data)) {
                    $this->events[$eventName] = (new Event($data))->setName($eventName);
                } else {
                    $this->events[$eventName] = (new Event(['data' => &$data, 'observers' => []]))->setName($eventName);
                }
                return $this;
            }

            if (is_array($data)) {
                $data['observers'] = $this->getEventObservers($eventName);
                $this->events[$eventName] = (new Event($data))->setName($eventName);
            } else {
                $this->events[$eventName] = (new Event(['data' => &$data, 'observers' => $this->getEventObservers($eventName)]))->setName($eventName);
            }
            $this->events[$eventName]->dispatch();
        } finally {
            // 事件执行完毕，弹出栈
            array_pop(self::$currentEventStack);
        }

        return $this;
    }

    /**
     * 检查事件是否有规约和文档
     *
     * @param string $eventName 事件名
     * @return bool 如果有规约和文档返回 true，否则返回 false
     */
    private function checkEventSpecAndDoc(string $eventName): bool
    {
        // Weline_Framework::msg 事件特殊处理：只检查循环，不检查规约和文档
        // 因为我们需要用它来发送错误消息，即使它本身没有规约和文档
        if ($eventName === 'Weline_Framework::msg') {
            return $this->checkCircularCall();
        }

        // 如果正在检查事件，直接返回 true，避免循环调用
        // 这可以防止 __() 函数触发的事件再次进入检查流程
        if (self::$isCheckingEvent) {
            return true;
        }

        try {
            // 设置检查标志，防止循环调用
            self::$isCheckingEvent = true;

            // 检查是否有规约
            $hasSpec = $this->eventRegistry->hasSpec($eventName);
            // 检查是否有文档
            $hasDoc = $this->eventRegistry->hasDoc($eventName);

            // 如果都有，直接返回 true
            if ($hasSpec && $hasDoc) {
                return true;
            }

            // 获取事件信息
            $eventInfo = $this->eventRegistry->getEventInfo($eventName);
            // 从事件注册表中获取模块信息
            // 注意：这里调用 __() 可能会触发 Framework_phrase::get_words_file 事件
            // 但由于设置了 $isCheckingEvent 标志，该事件会直接返回 true，避免循环
            $sourceModule = EventData::getEventModule($eventName) ?? __('未知模块');
            
            // 构建错误消息
            $errors = [];
            if (!$hasSpec) {
                $errors[] = __('缺少事件规约文件 (event.php)');
            }
            if (!$hasDoc) {
                $errors[] = __('缺少事件文档文件 (doc/event/*.md)');
            }

            $errorMessage = implode('，', $errors);
            
            // 发送系统消息（严重程度：超级严重）
            $this->sendSpecDocErrorMsg($eventName, $errorMessage, $eventInfo, $sourceModule);

            // 返回 false，不执行事件
            return false;
        } finally {
            // 清除检查标志
            self::$isCheckingEvent = false;
        }
    }

    /**
     * 检查循环调用（仅用于 Weline_Framework::msg 事件）
     * 检测触发 Weline_Framework::msg 的事件名，防止循环调用
     *
     * @return bool 如果没有循环返回 true，否则返回 false
     */
    private function checkCircularCall(): bool
    {
        // 获取当前正在执行的事件名（栈中倒数第二个，因为最后一个是我们自己）
        $stackCount = count(self::$currentEventStack);
        if ($stackCount < 2) {
            // 如果栈中只有 Weline_Framework::msg，说明是直接调用，允许执行
            return true;
        }

        // 获取触发 Weline_Framework::msg 的事件名（栈中倒数第二个）
        $sourceEventName = self::$currentEventStack[$stackCount - 2];

        // 如果触发事件是 Weline_Framework::msg 本身，允许执行（避免误判）
        if ($sourceEventName === 'Weline_Framework::msg') {
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
     * 发送规约和文档错误消息
     *
     * @param string $eventName 事件名
     * @param string $errorMessage 错误消息
     * @param array|null $eventInfo 事件信息
     * @param string $sourceModule 源模块
     */
    private function sendSpecDocErrorMsg(string $eventName, string $errorMessage, ?array $eventInfo, string $sourceModule): void
    {
        // 防止循环调用
        if (self::$isSendingMsg) {
            return;
        }

        try {
            self::$isSendingMsg = true;

            // 构建消息内容
            $title = __('【超级严重】事件缺少规约或文档');
            $content = __(
                "事件执行被阻止：事件 '%{eventName}' 缺少规约或文档。\n\n" .
                "错误详情：%{error}\n\n" .
                "源模块：%{sourceModule}\n\n" .
                "事件信息：%{eventInfo}\n\n" .
                "请确保：\n" .
                "1. 在提供该事件的模块根目录下创建 event.php 规约文件\n" .
                "2. 在 doc/event/ 目录下创建对应的文档文件\n" .
                "3. 运行 'php bin/w event:rebuild' 重建事件注册表\n\n" .
                "参考示例：\n" .
                "- 规约文件：app/code/Weline/Admin/event.php\n" .
                "- 文档文件：app/code/Weline/Admin/doc/event/系统消息通知.md",
                [
                    'eventName' => $eventName,
                    'error' => $errorMessage,
                    'sourceModule' => $sourceModule,
                    'eventInfo' => $eventInfo ? json_encode($eventInfo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : __('无')
                ]
            );

            // 直接调用观察者，避免再次触发事件检查
            $this->sendSystemMessageDirectly($title, $content);
        } catch (\Exception $e) {
            // 如果发送失败，记录到错误日志
            if (defined('DEV') && DEV) {
                error_log(__('EventsManager Error: Failed to send spec/doc error message - %{error}', ['error' => $e->getMessage()]));
            }
        } finally {
            self::$isSendingMsg = false;
        }
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
                "检测到事件 '%{sourceEventName}' 循环调用 Weline_Framework::msg 事件。\n\n" .
                "该事件已被阻止执行，以防止系统阻塞。\n\n" .
                "调用次数：%{count}\n\n" .
                "请检查该事件的代码，确保：\n" .
                "1. 事件处理逻辑中不会再次触发 Weline_Framework::msg 事件\n" .
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
            $observers = $this->getEventObservers('Weline_Framework::msg');
            
            if (empty($observers)) {
                // 如果没有观察者，记录到错误日志
                if (defined('DEV') && DEV) {
                    error_log(__('EventsManager Warning: No observer found for Weline_Framework::msg'));
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
            $event->setName('Weline_Framework::msg');

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
