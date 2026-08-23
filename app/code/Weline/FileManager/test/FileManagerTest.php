<?php

namespace Weline\FileManager\Test;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
use Weline\Framework\View\Template;

class FileManagerTest extends TestCore
{
    public function testFileManager()
    {

        $str = '<file-manager code="weline_media" title="从图库选择" vars="store" target="#image" path="bbs/site/logo"
                                          value="{{site.logo.filename}}" w="50" h="50" multi="0"
                                          ext="png,jpeg,jpg,webp,svg,ico"/>';
        /**@var \Weline\Framework\View\Template $tmp */
        $tmp = ObjectManager::getInstance(Template::class);
        $res = $tmp->tmp_replace($str);
        $this->assertStringContainsString('framework_view_process_block(', $res);
        $this->assertStringContainsString('Weline\\\\MediaManager\\\\Block\\\\WelineMedia', $res);
        $this->assertStringContainsString("'target' => 'image'", $res);
        $this->assertStringContainsString("'path' => 'bbs/site/logo'", $res);
        $this->assertStringContainsString("'ext' => 'png,jpeg,jpg,webp,svg,ico'", $res);
    }
}
