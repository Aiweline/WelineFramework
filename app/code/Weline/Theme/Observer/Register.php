<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Register\RegisterInterface;
use Weline\Theme\Register\Installer;

class Register implements ObserverInterface
{
    public function execute(Event $event)
    {
        /**@var DataObject $data */
        $data           = $event->getData('data');
        $func_arguments = $data->getData('register_arguments');
        $type           = $func_arguments[0];
        if ($type === RegisterInterface::THEME) {
            $data->setData('installer', Installer::class);
            $data->setData('register_arguments', $func_arguments);
        }
    }
}
