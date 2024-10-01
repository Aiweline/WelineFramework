<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database\test\Model;

use Weline\Framework\Database\Model\ModelManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;

class ModelManagerTest extends TestCore
{
    private ModelManager $model;

    public function setUp(): void
    {
        $this->model = ObjectManager::getInstance(ModelManager::class);
    }

    public function testUpdate()
    {
        p($this->model->update());
    }
}
