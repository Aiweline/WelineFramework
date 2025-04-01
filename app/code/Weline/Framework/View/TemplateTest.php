<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\View;

use Weline\Framework\App\Env;
use Weline\Framework\UnitTest\TestCore;

class TemplateTest extends TestCore
{
    public function testGetFile()
    {
        /**@var Template $template */
        $template = Template::getInstance();
        $content = $template->fetchTagSource(
            \Weline\Framework\View\Data\DataInterface::dir_type_STATICS,
            trim("Weline_Admin::/css/index.css"));
        if (DEV) {
            self::assertEquals('/Weline/Admin/view/statics/css/index.css', $content, '解析静态资源');
        } else {
            $theme = Env::get('theme')['path'] ?? Env::default_theme_DATA['path'];
            $theme = str_replace('\\', '/', $theme);
            self::assertEquals('/static/' . $theme . '/Weline/Admin/view/statics/css/index.css', $content, '解析静态资源');
        }
    }
}
