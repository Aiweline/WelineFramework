<?php

namespace Weline\FileManager\Test;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Framework\View\Template;

class FileManagerTest extends TestCore
{
    public function testFileManager()
    {
        $str = '<file-manager title="从图库选择" vars="store" target="#image" path="bbs/site/logo"
                                          value="{{site.logo.filename}}" w="50" h="50" multi="0"
                                          ext="png,jpeg,jpg,webp,svg,ico"/>';
        /**@var \Weline\Framework\View\Template $tmp */
        $tmp = ObjectManager::getInstance(Template::class);
        $res = $tmp->tmp_replace($str);
        $res = str_replace('"', '\'', $res);
        $res = str_replace('$', '\$', $res);
        $res = str_replace("\r\n", '', $res);
        $res = str_replace(' ', '', $res);
        $md5 = md5($res);
        $this->assertTrue($md5 == 'c5e7bb2fe3c05c7b28e8d86853277610');
    }
}