<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\View;

use Weline\Framework\UnitTest\TestCore;

class TemplateTest extends TestCore
{
    public function testGetFile()
    {
        /**@var Template $template */
        $template = Template::getInstance();
        p($template->fetchTagSource(\Weline\Framework\View\Data\DataInterface::dir_type_STATICS, trim("Aiweline_Index::/css/index.css")));
    }
}
