<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\View\test;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Framework\View\Block;

class BlockTest extends TestCore
{
    public function testFetch()
    {
        /**@var Block $block */
        $block   = ObjectManager::getInstance(Block::class);
        $request = ObjectManager::getInstance(Request::class);

        $block->setTemplate('Weline_Component::message.phtml')->render();
    }
}
