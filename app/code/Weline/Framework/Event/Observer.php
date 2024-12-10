<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Event;

use Weline\Framework\DataObject\DataObject;

class Observer extends DataObject
{
    public function getName()
    {
        return $this->getData('name');
    }

    public function setName(string $name)
    {
        $this->setData('name', $name);

        return $this;
    }

    public function setInstance($instance)
    {
        return $this->setData('instance', $instance);
    }

    public function getInstance()
    {
        return $this->setData('instance');
    }

    public function getEvent()
    {
        return $this->getData('event');
    }

    public function setEvent(\Weline\Framework\Event\Event $event)
    {
        $this->setData('event', $event);

        return $this;
    }
}
