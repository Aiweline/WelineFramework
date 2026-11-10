<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Css;

use PHPUnit\Framework\TestCase;

/**
 * 浮层快捷登录宿主的 CSS 契约：
 * 紧凑尺寸必须通过自定义属性交给 Weline_Customer 的内联面消费，
 * Theme 不得直接选择外模块的内部类（theme_mechanism_not_foreign_content）。
 */
final class StorefrontFloatLayerQuickLoginContractTest extends TestCase
{
    // Theme/test/Unit/Css → 3 = Theme（模块根）。
    private function cssPath(): string
    {
        return \dirname(__DIR__, 3) . '/view/statics/css/storefront-float-layer.css';
    }

    public function testThemeDoesNotStyleForeignQuickBarClasses(): void
    {
        $css = (string)\file_get_contents($this->cssPath());
        self::assertStringNotContainsString('weline-social-quick-bar__btn', $css);
        self::assertStringNotContainsString('weline-social-quick-bar__actions', $css);
        self::assertStringNotContainsString('weline-social-quick-inline', $css);
    }

    public function testQuickLoginHostCarriesSizingVariables(): void
    {
        $css = (string)\file_get_contents($this->cssPath());
        foreach ([
            '--w-social-quick-btn-min-height:',
            '--w-social-quick-btn-padding-block:',
            '--w-social-quick-btn-font-size:',
            '--w-social-quick-icon-size:',
        ] as $decl) {
            self::assertStringContainsString($decl, $css);
        }
    }

    public function testDeclaredEmptyHostCollapsesByElementCount(): void
    {
        $css = (string)\file_get_contents($this->cssPath());
        self::assertMatchesRegularExpression(
            '/#w-storefront-float-layer\s+\[data-weline-collapse-empty\]:not\(:has\(\*\)\)\s*\{\s*display:\s*none;/',
            $css,
            '声明 data-weline-collapse-empty 的宿主必须按「没有元素子节点」自判空收起（不等水合状态）'
        );
    }

    public function testEmptySlotContainerHidesItself(): void
    {
        $css = (string)\file_get_contents($this->cssPath());
        self::assertMatchesRegularExpression(
            '/#w-storefront-float-layer\s+\.w-storefront-float-layer__slot:has\(\[data-weline-collapse-empty\]:empty\)\s*\{\s*display:\s*none;/',
            $css,
            '八个方向的槽位（容器）必须在装着空宿主时整槽隐藏'
        );
    }

    public function testContentReservesChromeClearanceAtTop(): void
    {
        $css = (string)\file_get_contents($this->cssPath());
        self::assertStringContainsString(
            'padding-block-start: calc(var(--weline-space-2, 8px) + var(--w-storefront-float-chrome-clearance, 0.65rem))',
            $css
        );
    }
}
