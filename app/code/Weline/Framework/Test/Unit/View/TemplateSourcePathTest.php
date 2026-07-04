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

class TemplateSourcePathTest extends TestCore
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

    /**
     * <css> 标签对应路径：Weline_Admin::backend/lib/... 必须输出正确格式（无重复 statics、带模块前缀）
     */
    public function testFetchTagSourceCssStylePath(): void
    {
        $template = Template::getInstance();
        $path = 'Weline_Admin::backend/lib/bootstrap-5.1.3-dist/css/bootstrap.min.css';
        $content = $template->fetchTagSource(
            \Weline\Framework\View\Data\DataInterface::dir_type_STATICS,
            $path
        );
        $pathOnly = preg_replace('#\?v=.*$#', '', $content);
        if (DEV) {
            self::assertEquals(
                '/Weline/Admin/view/statics/backend/lib/bootstrap-5.1.3-dist/css/bootstrap.min.css',
                $pathOnly,
                '开发环境：statics 路径应为 /Weline/Admin/view/statics/backend/lib/...'
            );
        } else {
            $theme = Env::get('theme')['path'] ?? Env::default_theme_DATA['path'];
            $theme = str_replace('\\', '/', $theme);
            self::assertEquals(
                '/static/' . $theme . '/Weline/Admin/view/statics/backend/lib/bootstrap-5.1.3-dist/css/bootstrap.min.css',
                $pathOnly,
                '生产环境：statics 路径应为 /static/{theme}/Weline/Admin/view/statics/...'
            );
        }
    }
}
