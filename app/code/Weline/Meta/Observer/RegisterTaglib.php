<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Meta\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class RegisterTaglib implements ObserverInterface
{
    /**
     * 注册Meta Taglib
     * Taglib会自动通过反射机制注册，此观察者可以用于其他初始化操作
     */
    public function execute(Event &$event): void
    {
        // Taglib会自动通过反射机制注册
        // 这里可以添加其他初始化逻辑
    }
}

