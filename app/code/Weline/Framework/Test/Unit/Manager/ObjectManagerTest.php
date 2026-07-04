<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Manager\UnitTest;

use Aiweline\Admin\Controller\Index;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\Boot;
use PHPUnit\Framework\TestCase;

class ObjectManagerTest extends TestCase
{
    use Boot;

    private ObjectManager $instance;

    public function setUp(): void
    {
        $this->instance = ObjectManager::getInstance();
    }

    public function testGetInstance()
    {
        /**
         * @var $index Index
         */
        $index = $this->instance->getInstance(Index::class);
        p($index->test());
    }
}
