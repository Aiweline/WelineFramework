<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Login social providers slot + Customer application widget default injection.
 */
final class AccountSocialLoginWidgetContractTest extends TestCase
{
    public function testLoginFormExposesSocialProvidersSlotWithoutSiblingFetch(): void
    {
        $loginForm = \dirname(__DIR__, 3) . '/view/templates/frontend/account/login.phtml';
        $widgetPhp = \dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Customer/widget.php';
        $widgetTpl = \dirname(__DIR__, 3) . '/view/templates/frontend/widgets/account-social-login.phtml';

        self::assertFileExists($loginForm);
        self::assertFileExists($widgetPhp);
        self::assertFileExists($widgetTpl);

        $loginSource = (string) \file_get_contents($loginForm);
        self::assertStringContainsString('<w:slot id="account-login-social-providers"', $loginSource);
        self::assertStringContainsString('CustomerAuthReturnUrlService', $loginSource);
        self::assertStringContainsString('data-w-auth-return', $loginSource);
        self::assertStringContainsString('w-auth-login__social--quick', $loginSource);
        self::assertStringContainsString('快捷登录', $loginSource);
        self::assertStringContainsString('multiple="false"', $loginSource);
        // Slot-only: do not fetch the widget beside the declared slot (stacked with required injection).
        self::assertStringNotContainsString(
            "fetch('Weline_Customer::templates/frontend/widgets/account-social-login.phtml')",
            $loginSource
        );
        self::assertStringNotContainsString('social-login-fetch-error', $loginSource);
        $quickPos = strpos($loginSource, 'w-auth-login__social--quick');
        $formPos = strpos($loginSource, 'id="loginForm"');
        self::assertNotFalse($quickPos);
        self::assertNotFalse($formPos);
        self::assertLessThan(
            $formPos,
            $quickPos,
            'Quick social login must appear above the account password form'
        );
        self::assertStringContainsString('Weline_Customer::frontend::account::login::providers', $loginSource);

        $widgetSource = (string) \file_get_contents($widgetPhp);
        self::assertStringContainsString("'account-social-login'", $widgetSource);
        self::assertStringContainsString("'enable_google'", $widgetSource);
        self::assertStringContainsString("'enable_facebook'", $widgetSource);
        self::assertStringContainsString("'enable_instagram'", $widgetSource);
        self::assertStringContainsString('account-login-social-providers', $widgetSource);
        self::assertStringContainsString('default_injections', $widgetSource);
        self::assertStringContainsString("'placement' => 'injection'", $widgetSource);

        $tpl = (string) \file_get_contents($widgetTpl);
        self::assertStringContainsString("\$this->getData('redirect_url')", $tpl);
        self::assertStringContainsString('CustomerAuthReturnUrlService', $tpl);
        self::assertStringContainsString('getParam(\'redirect_url\')', $tpl);
        self::assertStringContainsString('@widget.code {account-social-login}', $tpl);
        self::assertStringContainsString('data-widget-code="account-social-login"', $tpl);
        self::assertStringContainsString('data-w-component="account-social-login"', $tpl);
        self::assertStringContainsString('enable_google', $tpl);
        self::assertStringContainsString('enable_facebook', $tpl);
        self::assertStringContainsString('enable_instagram', $tpl);
        self::assertStringContainsString('account-social-login__list--logos', $tpl);
        self::assertStringContainsString('account-social-login__btn--logo', $tpl);
        self::assertStringContainsString('aria-label=', $tpl);
        self::assertStringContainsString("button['icon_svg']", $tpl);
        self::assertStringContainsString('role="list"', $tpl);
        self::assertStringNotContainsString('auth-form__submit', $tpl);
        self::assertStringNotContainsString('<ul class="account-social-login__list', $tpl);
        self::assertStringNotContainsString('<w:icon', $tpl);
    }
}
