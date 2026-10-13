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
use Weline\Framework\Test\TestCore;
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

    /**
     * @test
     */
    public function testFetchTagSource()
    {        
        $string = '@backend-url("/")';
        $res = $this->template->tmp_replace($string);
        // 建议不要在单元测试中直接dd($res)，可以用断言或输出
        self::assertNotEmpty($res);
        // 版本号（DEV 内容指纹 dev_xxx / 配置全局戳）与路径解析无关，断言只看路径。
        $src = (string)$this->template->fetchTagSource('statics', 'Weline_Framework::1.png');
        $srcPathOnly = preg_replace('#\?v=.*$#', '', $src);
        if (defined('DEV') && DEV) {
            self::assertEquals(
                '/Weline/Framework/view/statics/1.png',
                $srcPathOnly
            );
            return;
        }
        $theme = (Env::getInstance()->getTheme()['path'] ?? 'Weline/Theme/view/theme');
        $theme = str_replace('\\', '/', $theme);
        self::assertEquals(
            '/static/' . $theme . '/Weline/Framework/view/statics/1.png',
            $srcPathOnly
        );

    }
}
