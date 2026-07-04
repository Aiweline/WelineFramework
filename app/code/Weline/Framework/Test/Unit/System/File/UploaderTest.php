<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\System\File;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;

class UploaderTest extends TestCore
{
    public function testCheckFilename()
    {
        /**@var Uploader $uploader */
        $uploader = ObjectManager::getInstance(Uploader::class);
        p($uploader->checkFilename('pub/media/uploader/Aiweline/Bbs/Account/Profile/logo-sm.png'));
    }
}
