<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Event;

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

    public function __construct(
        XmlReader $reader
    )
    {
        $this->reader = $reader;
    }

    public function scanEvents()
    {
        if (empty($this->eventsObservers)) {
            foreach ($this->reader->read() as $module_and_file => $eventObservers) {
                foreach ($eventObservers as $event_name => $eventObserver) {
                    // 二维数组$eventObserver根据sort字段排序
                    usort($eventObserver, function ($a, $b) {
                        return strnatcasecmp($a['sort'], $b['sort']);
                    } );
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
     * @param array  $data
     *
     * @return $this
     * @throws null
     */
    public function dispatch(string $eventName, mixed $data = []): static
    {
        if (is_array($data)) {
            $data['observers']        = $this->getEventObservers($eventName);
            $this->events[$eventName] = (new Event($data))->setName($eventName);
        } else {
            $this->events[$eventName] = (new Event(['data' => $data, 'observers' => $this->getEventObservers($eventName)]))->setName($eventName);
        }
        $this->events[$eventName]->dispatch();

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
     * @param string   $eventName
     * @param Observer $observer
     *
     * @return $this
     * @throws Exception
     */
    public function addObserver(string $eventName, Observer $observer)
    {
        if (!isset($this->events[$eventName])) {
            throw new Exception(__(sprintf('事件异常：%1 事件不存在！', $eventName)));
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
            throw new Exception(__(sprintf('事件异常：%1 事件不存在！', $eventName)));
        }
        $event = $this->events[$eventName];
        $event->dispatch();
    }
}
