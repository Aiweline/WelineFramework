<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 内联快捷登录面消费 Theme 浮层宿主下发的 `--w-social-quick-*` 变量。
 *
 * Fallback 取改动前的字面值：同一份内联样式由页角浮动条与浮层宿主内联面共用
 * （`weline-social-quick-fallback-style` 只有一个 id，谁先注入谁生效），
 * 若把 fallback 也写成紧凑值，未接到宿主的页角浮动条会跟着缩水。
 */
final class QuickLoginInlineVariantContractTest extends TestCase
{
    // Customer/Test/Unit → 2 = Customer（模块根）。
    private function jsPath(): string
    {
        return \dirname(__DIR__, 2) . '/view/statics/js/account-social-quick.js';
    }

    public function testInlineSurfaceConsumesSizingVariablesWithFallbacks(): void
    {
        $js = (string)\file_get_contents($this->jsPath());
        foreach ([
            'var(--w-social-quick-btn-min-height,',
            'var(--w-social-quick-btn-padding-block,',
            'var(--w-social-quick-btn-padding-inline,',
            'var(--w-social-quick-btn-font-size,',
            'var(--w-social-quick-icon-size,',
            'var(--w-social-quick-actions-gap,',
        ] as $use) {
            self::assertStringContainsString($use, $js);
        }
        // 原字面值必须降级为 var() 的 fallback，不得再单独生效。
        self::assertStringContainsString('min-height:var(--w-social-quick-btn-min-height,2.75rem)', $js);
        self::assertStringContainsString(
            'padding:var(--w-social-quick-btn-padding-block,.625rem) var(--w-social-quick-btn-padding-inline,.875rem)',
            $js
        );
        self::assertStringContainsString('font-size:var(--w-social-quick-btn-font-size,.875rem)', $js);
        self::assertStringContainsString('flex:0 0 var(--w-social-quick-icon-size,1.25rem)', $js);
        self::assertStringContainsString('gap:var(--w-social-quick-actions-gap,8px)', $js);
    }

    public function testPageCornerStyleBlockStaysUntouched(): void
    {
        // 第二套样式块（`ensureInlineStyles()`）不改：它同样带着裸字面值。
        $js = (string)\file_get_contents($this->jsPath());
        self::assertSame(
            1,
            \substr_count($js, 'min-height:44px;'),
            '裸 min-height:44px 应只剩页角块一处（内联面已改为变量驱动）'
        );
    }
}
