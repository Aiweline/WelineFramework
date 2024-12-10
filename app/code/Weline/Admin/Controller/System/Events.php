<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Admin\Controller\System;

use Weline\Framework\Event\Config\XmlReader;
use Weline\Framework\Manager\ObjectManager;

class Events extends \Weline\Admin\Controller\BaseController
{
    public ?XmlReader $reader;

    public function getIndex()
    {
        $events = $this->getReader()->read();
        $this->assign('events', $events);
        return $this->fetch();
    }

    private function getReader(): XmlReader
    {
        if (empty($this->reader)) {
            $this->reader = ObjectManager::getInstance(XmlReader::class);
        }
        return $this->reader;
    }
}
