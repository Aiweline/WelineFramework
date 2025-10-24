<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\System\File;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;

class CompressTest extends TestCore
{
    public function testCreateTarGz()
    {
        /**@var $compress Compress */
        $compress = ObjectManager::getInstance(Compress::class);
//        $tar = $compress->compression(APP_CODE_PATH.'Aiweline\\test\\');
        $tar = $compress->deCompression('E:\WelineFramework\app\code\Aiweline_Test.zip');
        p($tar);
    }
}
