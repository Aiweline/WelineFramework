<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Css;

use PHPUnit\Framework\TestCase;

final class HanfuQuickLoginPhoneVisibilityTest extends TestCase
{
    // Theme/test/Unit/Css → 3 = Theme(模块根) → 6 = app，故两个基准分开取。
    private function themePath(string $rel): string
    {
        return \dirname(__DIR__, 3) . '/' . $rel;
    }

    private function appPath(string $rel): string
    {
        return \dirname(__DIR__, 6) . '/' . $rel;
    }

    public function testAnchorsOverlayUnparksTopEndOnHomepagePhone(): void
    {
        $css = (string)\file_get_contents(
            $this->appPath('design/Weline/hanfu/frontend/assets/css/hanfu-float-anchors.css')
        );
        if ($css === '') {
            self::markTestSkipped('本机无 hanfu 设计主题（/app/design 不入 Framework Git）');
        }
        self::assertStringContainsString('hanfu-quick-login-phone-exception-v1', $css);
        self::assertStringContainsString('body.theme-layout-homepage', $css);
        // 权重必须高于 park 的 (1,3,0)：多一个属性选择器 = (1,4,0)。
        self::assertStringContainsString(
            '.w-storefront-float-layer__slot--top-end.w-storefront-float-layer__slot[data-slot-id="storefront-float-top-end"]',
            $css
        );
        self::assertMatchesRegularExpression(
            '/#w-storefront-float-layer .w-storefront-float-layer__slot--top-end[^}]*display:\s*block\s*!important/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/#w-storefront-float-layer .w-storefront-float-layer__slot--top-end[^}]*visibility:\s*visible\s*!important/s',
            $css
        );
    }

    public function testProductsAndOtherSlotsStayParked(): void
    {
        $commerce = (string)\file_get_contents(
            $this->appPath('design/Weline/hanfu/frontend/assets/css/hanfu-commerce.css')
        );
        if ($commerce === '') {
            self::markTestSkipped('本机无 hanfu 设计主题（/app/design 不入 Framework Git）');
        }
        // 原有 park 一律不得被删；只允许「加法」例外。
        self::assertStringContainsString('body.theme-layout-homepage :is(', $commerce);
        self::assertStringContainsString('body.theme-layout-products :is(', $commerce);
        $css = (string)\file_get_contents(
            $this->appPath('design/Weline/hanfu/frontend/assets/css/hanfu-float-anchors.css')
        );
        if ($css === '') {
            self::markTestSkipped('本机无 hanfu 设计主题（/app/design 不入 Framework Git）');
        }
        // 例外块必须追加在文件末尾，故 marker 之后的内容就是本块；只据此判定不泛化。
        // （不能用全文断言：该文件 L146-148 本就有 start/end/bottom-center 的锚点规则。）
        $pos = \strpos($css, 'hanfu-quick-login-phone-exception-v1');
        self::assertNotFalse($pos, '例外块缺失（marker 未找到）');
        $tail = \substr($css, (int)$pos);
        foreach (['--bottom-center', '--side', '--start', '--end'] as $dir) {
            self::assertStringNotContainsString(
                'w-storefront-float-layer__slot' . $dir,
                $tail,
                '例外不得泛化到 ' . $dir . ' 槽（会连带遮住结算/页脚）'
            );
        }
    }
}
