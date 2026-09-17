<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 店面登录面板须用标准 w-field + w-field__label（Outlined notch），禁止 w-label 徽章皮与 stacked 胶囊条。
 */
final class AccountLoginPanelOutlinedFieldContractTest extends TestCase
{
    public function testLoginPanelFieldsUseStandardOutlinedFieldMarkup(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/js/account-login-panel.js';
        $src = (string)file_get_contents($path);
        self::assertNotSame('', $src);

        self::assertStringContainsString("class=\"w-field__label\"", $src);
        self::assertStringContainsString('<div class="w-field"><label class="w-field__label">\' + userLabel', $src);
        self::assertStringContainsString('<div class="w-field"><label class="w-field__label">\' + passLabel', $src);

        self::assertStringNotContainsString('data-label-layout="stacked"', $src);
        self::assertStringNotContainsString('border-radius:999px', $src);
        self::assertStringNotContainsString('<div class="w-field"><label class="w-label">\' + userLabel', $src);
        self::assertStringNotContainsString('<label class="w-label" style="margin:0">\' + rememberLabel', $src);
        self::assertStringNotContainsString('<label class="weline-login-panel__remember">\' + rememberLabel', $src);
        self::assertStringContainsString('<div class="w-field weline-login-panel__remember">', $src);
        self::assertStringContainsString('<label class="w-field__label" for="weline-login-panel-remember">', $src);
        self::assertStringContainsString('data-icon="clock"', $src);
        self::assertStringContainsString('rememberLabel + \'</label>\'', $src);
        self::assertStringContainsString('weline-login-panel__remember', $src);
        self::assertStringContainsString('weline-login-panel__meta-tools', $src);
        self::assertStringContainsString('min-height:var(--weline-control-height-sm,1.75rem)', $src);
        self::assertStringContainsString("data-weline-login-panel-heading", $src);
        self::assertStringNotContainsString('display:inline-flex;align-items:center;gap:6px', $src);
        self::assertStringContainsString('hideHeading', $src);
        self::assertStringContainsString('w-auth-login__forgot-link', $src);
        self::assertStringContainsString('white-space:nowrap', $src);
        self::assertStringContainsString('withAuthRefreshSignal', $src);
        self::assertStringContainsString("searchParams.set('w_auth', '1')", $src);
        self::assertStringContainsString('markAuthPending', $src);
    }
}
