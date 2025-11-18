<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Plugin;

use Weline\Framework\DataObject\DataObject;

class Plugin extends DataObject
{
    public function setName($name)
    {
        $this->setData('name', $name);

        return $this;
    }

    public function getName()
    {
        return $this->getData('name');
    }

    public function setInstance($instance)
    {
        $this->setData('instance', $instance);

        return $this;
    }

    public function getInstance()
    {
        return $this->getData('instance');
    }

    public function setClass(string $class_name)
    {
        $this->setData('class', $class_name);

        return $this;
    }

    public function getClass()
    {
        return $this->getData('class');
    }
}
