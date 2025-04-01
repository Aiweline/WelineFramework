<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Event;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Console\Event\Data;
use Weline\Framework\Exception\Core;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Debug\Printing;

class Event extends \Weline\Framework\DataObject\DataObject
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);
        if (isset($data['observers'])) {
            foreach ($data['observers'] as $key => $observer) {
                $observer = ObjectManager::getInstance($observer['instance']);
                if ($observer instanceof ObserverInterface) {
                    $data['observers'][$key] = $observer;
                } elseif (DEV) {
                    throw new Core(__('观察者必须继承于：') . ObserverInterface::class);
                } else {
                    $debug = ObjectManager::getInstance(Printing::class);
                    $debug->debug(__('观察者必须继承于：') . ObserverInterface::class);
                }
            }
        }
        $this->setData($data);
    }

    public function getData(string $key = '', $index = null): mixed
    {
        $res = $this->getEvenData($key);
        if ($res === null) {
            return parent::getData($key, $index);
        }
        return $res;
    }

    public function getEvenData(string $key = ''): mixed
    {
        $eventData = $this->_getData('data');
        if ($key and $eventData instanceof DataObject) {
            return $eventData->getData($key);
        }
        if (isset($eventData[$key])) {
            return $eventData[$key];
        }
        return $eventData;
    }

    private string $name;

    /**
     * @DESC         |添加观察者
     *
     * 参数区：
     *
     * @param Observer $observer
     *
     * @return \Weline\Framework\Event\Event
     */
    public function addObserver(Observer $observer)
    {
        $observers = $this->_getData('observers');
        $observers[] = $observer;
        $this->setData('observers', $observers);

        return $this;
    }

    /**
     * @DESC         |获取观察者
     *
     * 参数区：
     *
     * @return ObserverInterface []
     */
    public function getObservers(): array
    {
        return $this->_getData('observers');
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @DESC         |方法描述
     *
     * 参数区：
     *
     * @param string $name
     *
     * @return $this
     */
    public function setName(string $name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @DESC         |派遣
     *
     * 参数区：
     */
    public function dispatch()
    {
        foreach ($this->getObservers() as $observer) {
            $observer->execute($this);
        }
    }
}
