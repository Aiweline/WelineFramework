<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Router\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Register\RegisterInterface;
use Weline\Framework\Router\Handle;

class RegisterInstaller implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        /**@var DataObject $data */
        $data = $event->getData('data');
        $func_arguments = $data->getData('register_arguments');
        $type = $func_arguments[0] ?? '';
        
        // 确保 ROUTER 类型使用 app/code 下的 Router\Handle 类
        if ($type === RegisterInterface::ROUTER) {
            $data->setData('installer', Handle::class);
            $data->setData('register_arguments', $func_arguments);
        }
    }
}
