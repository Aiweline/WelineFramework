<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/11/26 14:57:38
 */

namespace Weline\Theme\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Console\Resource\Compiler;

class UpgradeCompiler implements \Weline\Framework\Event\ObserverInterface
{
    public function execute(Event $event)
    {
        ObjectManager::getInstance(Compiler::class)->execute();
    }
}
