<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Console\Resource\Compiler;

class CompileResource implements \Weline\Framework\Event\ObserverInterface
{
    /**
     * @inheritDoc
     */
    public function execute(Event $event)
    {
        /**@var Compiler $statics */
        $statics = ObjectManager::getInstance(Compiler::class);
        $statics->execute();
    }
}
