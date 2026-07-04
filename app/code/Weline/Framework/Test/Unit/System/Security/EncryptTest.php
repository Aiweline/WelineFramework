<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\System\Security;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;

class EncryptTest extends TestCore
{
    public function testMd5_salt()
    {
        p(Encrypt::md5_salt('admin', 'remain'));
    }
}
