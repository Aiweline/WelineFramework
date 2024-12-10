<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Event;

interface ObserverInterface
{
    /**
     * @DESC         |实现观察者的执行方法
     *
     * 参数区：
     *
     * @param Event $event
     *
     */
    public function execute(Event $event);
}
