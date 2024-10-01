<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\View\test;

use Aiweline\Index\Controller\Index;
use Weline\Framework\Controller\PcController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Framework\View\Template;

class TemplateTest extends TestCore
{
    private Template $template;

    public function setUp(): void
    {
        $indexController = ObjectManager::getInstance(Index::class);
        $this->template  = ObjectManager::getInstance(Template::class, [$indexController]);
    }

    public function testfetchTagSource()
    {
//        p($this->template->fetchTagSource('statics', '/1.png'));
        p($this->template->fetchTagSource('statics', 'Aiweline_Bbs::/1.png'));
    }
}
