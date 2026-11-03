<?php

declare(strict_types=1);

namespace Weline\Theme\test\Unit\Region;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Region\GlobalRegionSourceRewriter;

/**
 * 布局来源有**两种**（主题布局 / 原生框架布局），并且主题可以覆写别的主题/模块的布局；
 * 加上请求执行的是固化产物。这个用例把「哪些路径属于页面布局」钉死，避免以后
 * 只覆盖其中一种（线上曾因此出现过「同样的主题，有的页面有全局区、有的没有」）。
 */
class GlobalRegionLayoutSourcesTest extends TestCase
{
    public function testThemeLayoutIsCovered(): void
    {
        self::assertTrue(GlobalRegionSourceRewriter::appliesTo(
            '/www/app/design/Weline/hanfu/frontend/layouts/products/default.phtml'
        ));
    }

    public function testThemeOverrideOfAnotherThemeLayoutIsCovered(): void
    {
        self::assertTrue(GlobalRegionSourceRewriter::appliesTo(
            '/www/app/design/Weline/hanfu/Weline_Shop/theme/frontend/layouts/products/default.phtml'
        ));
    }

    public function testNativeFrameworkLayoutIsCovered(): void
    {
        self::assertTrue(GlobalRegionSourceRewriter::appliesTo(
            '/www/app/code/Weline/Theme/view/theme/frontend/layouts/homepage/default.phtml'
        ));
    }

    public function testBakedArtifactLayoutIsCovered(): void
    {
        self::assertTrue(GlobalRegionSourceRewriter::appliesTo(
            '/www/generated/theme/Weline/hanfu/default.default.default/formal/v2867/pages/layouts/products/default.phtml'
        ));
    }

    public function testHookPartialIsExcluded(): void
    {
        self::assertFalse(GlobalRegionSourceRewriter::appliesTo(
            '/www/app/code/Weline/Consent/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml'
        ), 'hook 片段没有 head/body，不能被改写');
    }

    public function testCompiledArtifactIsExcluded(): void
    {
        self::assertFalse(GlobalRegionSourceRewriter::appliesTo(
            '/www/app/code/Weline/Theme/view/tpl/frontend_w0_zh_Hans_CN/theme/frontend/layouts/homepage/com_default.phtml'
        ), 'com 编译产物再改写会让锚点嵌套');
    }

    public function testNonPhtmlIsExcluded(): void
    {
        self::assertFalse(GlobalRegionSourceRewriter::appliesTo('/www/app/design/x/frontend/layouts/products/default.json'));
    }
}
