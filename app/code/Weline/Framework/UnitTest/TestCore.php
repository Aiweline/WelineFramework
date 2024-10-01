<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\UnitTest;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;

require BP . 'index.php';

class TestCore extends TestCase
{
//    use Boot;
    public static function getInstance(string $class)
    {
        return ObjectManager::getInstance($class);
    }
}
