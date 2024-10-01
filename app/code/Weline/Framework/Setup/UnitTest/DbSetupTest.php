<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Setup\UnitTest;

require __DIR__ . '/../../../../index.php';

use Weline\Framework\Setup\Db\Setup;
use PHPUnit\Framework\TestCase;

class DbSetupTest extends TestCase
{
    private ModelSetup $setup;

    protected function setUp(): void
    {
        $this->setup = new Setup();
    }

    protected function tearDown(): void
    {
        unset($this->setup);
    }

    public function testIsExistTable()
    {
        $result = $this->setup->tableExist('m_aiweline_news');
        p($result);
//        $this->assertEquals(4, $result);
    }
}
