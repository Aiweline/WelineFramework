<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\View;

use Weline\Framework\Test\TestCore;

class TemplateTest extends TestCore
{
    public function testGetFile()
    {
        /**@var Template $template */
        $template = Template::getInstance();
        $content = $template->fetchTagSource(
            \Weline\Framework\View\Data\DataInterface::dir_type_STATICS,
            trim("Weline_Admin::/css/index.css"));
        $pathOnly = preg_replace('#\?v=.*$#', '', (string)$content);
        if (\Weline\Framework\Deploy\DeployPublicUrlMode::useProduction()) {
            self::assertEquals('/static/Weline/Admin/css/index.css', $pathOnly, '生产：模块静态主路径为 Upgrade flat');
        } else {
            self::assertEquals('/Weline/Admin/view/statics/css/index.css', $pathOnly, '开发：模块前缀路径');
        }
    }

    /** <css> 标签的模块级 UI 资源必须输出无重复 statics 的正确路径。 */
    public function testFetchTagSourceCssStylePath(): void
    {
        $template = Template::getInstance();
        $path = 'Weline_Theme::ui/weline-foundation.css';
        $content = $template->fetchTagSource(
            \Weline\Framework\View\Data\DataInterface::dir_type_STATICS,
            $path
        );
        $pathOnly = preg_replace('#\?v=.*$#', '', $content);
        if (\Weline\Framework\Deploy\DeployPublicUrlMode::useProduction()) {
            self::assertEquals(
                '/static/Weline/Theme/ui/weline-foundation.css',
                $pathOnly,
                '生产：模块 UI 静态主路径为 Upgrade flat'
            );
        } else {
            self::assertEquals(
                '/Weline/Theme/view/statics/ui/weline-foundation.css',
                $pathOnly,
                '开发：UI 资源应使用模块 statics 路径'
            );
        }
    }
}
