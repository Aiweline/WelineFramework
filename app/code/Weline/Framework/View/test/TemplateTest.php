<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\View\test;

use Weline\Admin\Controller\Index;
use Weline\Framework\App\Debug;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Framework\View\Template;

class TemplateTest extends TestCore
{
    private Template $template;

    public function setUp(): void
    {
        # 模拟请求
        self::initRequest();
        $this->template = ObjectManager::getInstance(Template::class);
    }

    public function testFetchTagSource()
    {
        if (DEV) {
            self::assertEquals(
                '/Weline/Framework/view/statics/1.png',
                $this->template->fetchTagSource('statics', 'Weline_Framework::1.png')
            );
            return;
        }
        $theme = Env::get('theme')['path'] ?? Env::default_theme_DATA['path'];
        $theme = str_replace('\\', '/', $theme);
        self::assertEquals(
            '/static/' . $theme . '/Weline/Framework/view/statics/1.png',
            $this->template->fetchTagSource('statics', 'Weline_Framework::1.png')
        );
    }
}
