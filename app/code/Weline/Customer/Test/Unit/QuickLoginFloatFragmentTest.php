<?php
declare(strict_types=1);

namespace Weline\Customer\test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 右上快捷登录必须走「归属模块 base 片段 + @global-region.float-slot」这一条浮层链路，
 * 不得再由 Theme 的手搓 JS 造宿主（createElement 造出来的节点缺 data-widget-* 身份，
 * 会被 FragmentRegionProvider::wrapWithWidgetIdentity() 当「缺少身份的部件」）。
 */
class QuickLoginFloatFragmentTest extends TestCase
{
    private const FRAGMENT = 'app/code/Weline/Customer/view/theme/base/body_end/marketing/03-quick-login.phtml';

    private function repoPath(string $relative): string
    {
        // Customer/test/Unit → 6 级上溯 = 仓根（1=test 2=Customer 3=Weline 4=code 5=app 6=根）
        return \dirname(__DIR__, 6) . '/' . $relative;
    }

    public function testFragmentDeclaresTopEndFloatSlot(): void
    {
        $src = (string)\file_get_contents($this->repoPath(self::FRAGMENT));
        self::assertStringContainsString('@global-region.float-slot {top-end}', $src);
        self::assertStringContainsString('@widget.code {quick-login}', $src);
        self::assertStringContainsString('@widget.type {content}', $src);
        self::assertStringContainsString('@widget.page_layouts {["*"]}', $src);
    }

    public function testFragmentEmitsDeclarativeMountHostOnly(): void
    {
        $src = (string)\file_get_contents($this->repoPath(self::FRAGMENT));
        self::assertStringContainsString('data-weline-mount="customer/social-quick"', $src);
        self::assertStringContainsString('data-weline-mount-load="account,customerSocialQuick"', $src);
        self::assertStringContainsString('data-weline-mount-variant="inline"', $src);
        self::assertStringContainsString('class="w-float-quick-login"', $src);
    }

    public function testHandRolledChannelIsRetired(): void
    {
        $footer = (string)\file_get_contents(
            $this->repoPath('app/code/Weline/Theme/view/theme/frontend/partials/footer/default.phtml')
        );
        self::assertStringNotContainsString('data-storefront-float-quick-login', $footer);

        // Task 9 末态：桩文件已删除（重烘后无任何编译产物引用该 URL）。
        // 若有人重新引入手搓通道，footer 会先被上一条断言拦住；这里守住「不复活旧 JS」。
        $stubs = \glob($this->repoPath('app/code/Weline/Theme/view/statics/js/storefront-float-quick-login*')) ?: [];
        self::assertSame([], $stubs, 'retired stub must stay deleted');
    }
}
