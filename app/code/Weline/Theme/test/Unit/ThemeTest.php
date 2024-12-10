<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Test\Unit;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Theme\Model\WelineTheme;

use function PHPUnit\Framework\assertIsArray;
use function PHPUnit\Framework\assertIsObject;

class ThemeTest extends TestCore
{
    /**
     * @var WelineTheme
     */
    private WelineTheme $theme;

    public function setUp(): void
    {
        $this->theme = ObjectManager::getInstance(WelineTheme::class);
    }

    public function testGetMode()
    {
//        $theme       = $this->theme->load(1);
        assertIsObject($this->theme->getActiveTheme(), __('Weline_Theme:主题模型获取默认主题。'));
//        p($this->theme->getActiveTheme());
//        $save_result = $this->theme->setData(Env::default_theme_DATA)->save();
//        p($save_result);
    }
}
